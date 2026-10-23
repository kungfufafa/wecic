<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PortalException;
use App\Models\PortalOutboxItem;
use App\Services\PortalNotificationCenter;
use App\Services\PortalOfflineAccess;
use App\Services\PortalOutbox;
use App\Services\PortalOutboxSender;
use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends the student's outbox (PortalOutbox) with a session that is online
 * again: queued by ResumeOfflineSessionJob when an offline session logs
 * itself back in, and by an online login while items are waiting.
 *
 * Items whose deadline passed are marked expired and never sent. Each
 * outcome (sent, failed, expired) becomes a notification. One flush runs per
 * student at a time, so a resume and a fresh login racing each other never
 * send the same file twice; an upstream session that dies mid-way leaves the
 * remaining items queued for the next flush.
 */
class FlushPortalOutboxJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Uploads are not idempotent; a failed flush is reported, never replayed.
     */
    public int $tries = 1;

    /**
     * A few uploads run one after the other, each within the upload time
     * limit; the budget of the worker that runs snapshot refreshes.
     */
    public int $timeout = 600;

    public function __construct(
        private readonly string $sessionToken,
        private readonly ?string $sessionId = null
    ) {
        // Work the student is waiting on after coming back online, and longer
        // than one upload: the refresh worker's queue, not the upload worker's.
        $this->onQueue('campus-refresh');
    }

    public function uniqueId(): string
    {
        return $this->sessionToken;
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(
        PortalOutbox $outbox,
        PortalOutboxSender $sender,
        PortalSessionManager $sessionManager,
        PortalOfflineAccess $offlineAccess,
        PortalNotificationCenter $notifications
    ): void {
        if (! $outbox->enabled()) {
            return;
        }

        if (! $sessionManager->isSessionUsable($this->sessionToken) || $offlineAccess->isOfflineSession($this->sessionToken)) {
            $this->log('outbox_flush_skipped', ['reason' => 'session_not_online']);

            return;
        }

        $ownerHash = $outbox->ownerHash($this->sessionToken);
        $sessionId = $this->sessionId ?? $sessionManager->laravelSessionId($this->sessionToken);
        $lock = Cache::lock('portal_outbox_flush_'.$ownerHash, $this->timeout);

        if (! $lock->get()) {
            $this->log('outbox_flush_skipped', ['reason' => 'already_running']);

            return;
        }

        $outcomes = [
            PortalOutboxItem::STATUS_SENT => 0,
            PortalOutboxItem::STATUS_FAILED => 0,
            PortalOutboxItem::STATUS_EXPIRED => 0,
        ];

        try {
            foreach ($outbox->queued($ownerHash) as $item) {
                if ($item->deadlinePassed()) {
                    $outbox->markExpired($item);
                } else {
                    try {
                        [$sent, $error] = $sender->send($item, $this->sessionToken, $sessionId);
                    } catch (\Throwable $throwable) {
                        if (PortalException::indicatesExpiredSession($throwable)) {
                            $this->log('outbox_flush_interrupted', ['error' => $throwable->getMessage(), 'outbox_item_id' => $item->id], 'warning');

                            break;
                        }

                        [$sent, $error] = [false, $throwable->getMessage()];
                    }

                    $sent ? $outbox->markSent($item) : $outbox->markFailed($item, $error);
                }

                $outcomes[$item->status]++;
                $notifications->publish($this->sessionToken, [$outbox->notificationFor($item)], $sessionId);
            }
        } finally {
            $lock->release();
        }

        $this->log('outbox_flushed', $outcomes);

        if ($sessionId !== null && array_sum($outcomes) > 0) {
            $outbox->announce($sessionId, $this->sessionToken, $this->summary($outcomes));
        }
    }

    /**
     * @param  array<string, int>  $outcomes
     */
    private function summary(array $outcomes): string
    {
        $parts = array_filter([
            $outcomes[PortalOutboxItem::STATUS_SENT] > 0 ? "{$outcomes[PortalOutboxItem::STATUS_SENT]} terkirim ke portal" : null,
            $outcomes[PortalOutboxItem::STATUS_FAILED] > 0 ? "{$outcomes[PortalOutboxItem::STATUS_FAILED]} gagal" : null,
            $outcomes[PortalOutboxItem::STATUS_EXPIRED] > 0 ? "{$outcomes[PortalOutboxItem::STATUS_EXPIRED]} tenggat lewat" : null,
        ]);

        return 'Antrean offline diproses: '.implode(', ', $parts).'.';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, array $context = [], string $level = 'info'): void
    {
        Log::log($level, 'campus_session.'.$event, [
            'domain' => 'campus_session',
            'event' => $event,
            'component' => self::class,
            ...$context,
        ]);
    }
}
