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
use Illuminate\Support\Str;

/**
 * Sends the student's outbox (PortalOutbox) with a session that is online
 * again: queued by ResumeOfflineSessionJob when an offline session logs
 * itself back in, by an online login while items are waiting, and by
 * portal:send-outbox (directly, or through a background login with the
 * stored password: SendPortalOutboxJob).
 *
 * Only confirmed items are sent (PortalOutbox::confirmWithPassword); items
 * whose deadline passed are marked expired and never sent. Each outcome
 * (sent, failed, unverified, expired) becomes a notification. One flush runs
 * per student at a time, so a resume and a fresh login racing each other
 * never send the same file twice; an upstream session that dies mid-way
 * leaves the remaining items queued for the next flush.
 *
 * An item is marked `sending` while it goes out. A flush that dies with it
 * (worker timeout, out of memory, a deploy) leaves it there, and the next
 * flush, holding the same lock, knows no one is sending it: an attendance
 * code is queued again, an upload is reported as unverified instead of
 * being sent blind a second time. Items only start within a time budget,
 * the rest go to a follow-up flush, so a slow portal never cuts an upload
 * off mid-way.
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

    /**
     * Follow-up runs for items another upload of the same target holds, or
     * after a run that died; the next login or portal:send-outbox takes over
     * after that.
     */
    private const MAX_ATTEMPTS = 5;

    private const RETRY_DELAY_SECONDS = 60;

    /**
     * Owner of the flush lock, so failed() frees only a lock this run holds.
     */
    private readonly string $runId;

    /**
     * `$followUps` false: a run for a session that ends with it (a background
     * login, PortalBackgroundSync::sendOutbox) leaves what is left to the
     * next portal:send-outbox instead of queuing runs for a dead session.
     */
    public function __construct(
        private readonly string $sessionToken,
        private readonly ?string $sessionId = null,
        private readonly int $attempt = 1,
        private readonly bool $followUps = true
    ) {
        $this->runId = (string) Str::uuid();
        // Work the student is waiting on after coming back online, with
        // uploads sent inline: the refresh worker's queue and its 600 s.
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
        // Outlives the run (killed at its timeout), so a held lock always
        // means a flush is still running.
        $lock = Cache::lock(self::lockKey($ownerHash), $this->timeout + 60, $this->runId);

        if (! $lock->get()) {
            $this->log('outbox_flush_skipped', ['reason' => 'already_running']);

            return;
        }

        // No item after the first starts later than this into the run: an
        // upload (a form read, a POST of up to
        // `portal.actions.upload_request_timeout` and a check of the page)
        // still finishes within the timeout.
        $startBudget = (int) config('portal.outbox.flush_start_budget', 240);
        $startedAt = microtime(true);
        $started = 0;
        $followUp = null;
        $outcomes = [
            PortalOutboxItem::STATUS_SENT => 0,
            PortalOutboxItem::STATUS_FAILED => 0,
            PortalOutboxItem::STATUS_UNVERIFIED => 0,
            PortalOutboxItem::STATUS_EXPIRED => 0,
        ];

        try {
            foreach ($outbox->recoverAbandoned($ownerHash) as $item) {
                $outcomes[$item->status]++;
                $notifications->publish($this->sessionToken, [$outbox->notificationFor($item)], $sessionId);
            }

            foreach ($outbox->queued($ownerHash) as $item) {
                if (! $item->isConfirmed() && ! $item->deadlinePassed()) {
                    continue;
                }

                if ($started > 0 && microtime(true) - $startedAt > $startBudget) {
                    $followUp = 0;

                    break;
                }

                // Cancelled, superseded or expired since the queue was read.
                if (! $outbox->claim($item)) {
                    continue;
                }

                if ($item->deadlinePassed()) {
                    $outbox->markExpired($item);
                } else {
                    $started++;

                    try {
                        [$outcome, $error] = $sender->send($item, $this->sessionToken, $sessionId);
                    } catch (\Throwable $throwable) {
                        if (PortalException::indicatesExpiredSession($throwable)) {
                            $outbox->requeue($item);
                            $this->log('outbox_flush_interrupted', ['error' => $throwable->getMessage(), 'outbox_item_id' => $item->id], 'warning');

                            break;
                        }

                        [$outcome, $error] = [PortalOutboxItem::STATUS_FAILED, $throwable->getMessage()];
                    }

                    // An online upload of the same target dropped it meanwhile.
                    if (! $outbox->stillQueued($item)) {
                        continue;
                    }

                    if ($outcome === PortalOutboxSender::DEFERRED) {
                        $outbox->requeue($item);
                        $followUp ??= self::RETRY_DELAY_SECONDS;

                        continue;
                    }

                    match ($outcome) {
                        PortalOutboxItem::STATUS_SENT => $outbox->markSent($item),
                        PortalOutboxItem::STATUS_UNVERIFIED => $outbox->markUnverified($item, $error),
                        default => $outbox->markFailed($item, $error),
                    };
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

        if ($followUp !== null) {
            // A run over its budget made progress and goes on at once; one
            // waiting for another upload of a target counts as an attempt.
            $this->followUp($followUp === 0 ? $this->attempt : $this->attempt + 1, $followUp);
        }
    }

    /**
     * A run that died (its timeout, an error): the lock it held is freed and
     * another run picks up what is left, the item it was sending included.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->log('outbox_flush_failed', ['error' => $exception?->getMessage(), 'attempt' => $this->attempt], 'warning');

        try {
            $ownerHash = app(PortalOutbox::class)->ownerHash($this->sessionToken);
            Cache::restoreLock(self::lockKey($ownerHash), $this->runId)->release();
            $this->followUp($this->attempt + 1, self::RETRY_DELAY_SECONDS);
        } catch (\Throwable $throwable) {
            $this->log('outbox_flush_follow_up_failed', ['error' => $throwable->getMessage()], 'error');
        }
    }

    public static function lockKey(string $ownerHash): string
    {
        return 'portal_outbox_flush_'.$ownerHash;
    }

    private function followUp(int $attempt, int $delaySeconds): void
    {
        if (! $this->followUps) {
            return;
        }

        if ($attempt > self::MAX_ATTEMPTS) {
            $this->log('outbox_flush_given_up', ['attempt' => $attempt]);

            return;
        }

        self::dispatch($this->sessionToken, $this->sessionId, $attempt)->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * @param  array<string, int>  $outcomes
     */
    private function summary(array $outcomes): string
    {
        $parts = array_filter([
            $outcomes[PortalOutboxItem::STATUS_SENT] > 0 ? "{$outcomes[PortalOutboxItem::STATUS_SENT]} terkirim ke portal" : null,
            $outcomes[PortalOutboxItem::STATUS_FAILED] > 0 ? "{$outcomes[PortalOutboxItem::STATUS_FAILED]} gagal" : null,
            $outcomes[PortalOutboxItem::STATUS_UNVERIFIED] > 0 ? "{$outcomes[PortalOutboxItem::STATUS_UNVERIFIED]} belum pasti" : null,
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
