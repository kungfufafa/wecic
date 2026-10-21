<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Keep-alive for one campus session.
 *
 * Unique per session until it starts processing, so the chain can schedule
 * its own successor while extra dispatches (login, manual refresh) are
 * dropped instead of starting a parallel chain. The chain ends when the
 * session expires upstream, is logged out, or the student goes idle.
 */
class RefreshPortalSessionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after the portal was unreachable.
     *
     * @var list<int>
     */
    public array $backoff = [60, 120];

    public function __construct(
        private string $sessionToken,
        private ?string $sessionId = null
    ) {
        $this->onQueue('campus-sessions');
    }

    public function uniqueId(): string
    {
        return $this->sessionToken;
    }

    public function uniqueFor(): int
    {
        return (int) config('portal.session_refresh_interval', 1200) + 600;
    }

    public function handle(PortalSessionManager $sessionManager, PortalBroadcastService $broadcast, PortalDatasetRepository $datasets): void
    {
        $outcome = $sessionManager->keepAlive($this->sessionToken);
        $sessionId = $sessionManager->laravelSessionId($this->sessionToken) ?? $this->sessionId;

        Log::info('campus_session.keepalive_job_'.$outcome, [
            'domain' => 'campus_session',
            'event' => 'keepalive_job_'.$outcome,
            'component' => self::class,
            'attempt' => $this->attempts(),
        ]);

        if (in_array($outcome, ['alive', 'relogged'], true)) {
            $sessionManager->scheduleKeepAlive($this->sessionToken);

            // Top up decision datasets that went stale since the last ping, but
            // only while the student is actually using the app: a session kept
            // alive for a closed tab must not re-scrape portal pages for nobody.
            if (config('portal.sync.keepalive_warmup', true)
                && $sessionManager->wasActiveWithin($this->sessionToken, (int) config('portal.session_refresh_interval', 1200))) {
                $datasets->warmUp($this->sessionToken, $sessionId);
            }
        }

        if ($sessionId === null) {
            return;
        }

        match ($outcome) {
            'relogged' => $broadcast->sessionNotice($sessionId, $this->sessionToken, 'auto_relogin_succeeded', 'Sesi portal berhasil diperbarui otomatis.', 'success'),
            'expired' => $broadcast->sessionNotice($sessionId, $this->sessionToken, 'manual_relogin_required', 'Sesi portal kedaluwarsa. Silakan login ulang.', 'error'),
            'idle' => $broadcast->sessionNotice($sessionId, $this->sessionToken, 'manual_relogin_required', 'Sesi berakhir karena lama tidak aktif. Silakan login ulang.', 'warning'),
            default => null,
        };
    }

    /**
     * The portal stayed unreachable for every attempt: keep the session (the
     * next scrape will tell whether it survived) but stop the chain.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('campus_session.keepalive_job_failed_permanently', [
            'domain' => 'campus_session',
            'event' => 'keepalive_job_failed_permanently',
            'component' => self::class,
            'error' => $exception->getMessage(),
        ]);
    }
}
