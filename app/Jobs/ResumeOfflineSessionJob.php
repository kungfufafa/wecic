<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalOfflineAccess;
use App\Services\PortalOutbox;
use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Brings an offline session back online once the portal answers again.
 *
 * Scheduled when a student logs in offline and re-scheduled by itself with a
 * growing delay while the portal stays unreachable. The session keeps its
 * token: when the stored password logs in, the browser only has to drop the
 * offline banner and re-read its data. Unique per session until it runs, so
 * one chain exists per session; it ends with the session or on a rejected
 * password.
 */
class ResumeOfflineSessionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $sessionToken,
        private readonly int $attempt = 1
    ) {
        $this->onQueue('campus-sessions');
    }

    public function uniqueId(): string
    {
        return $this->sessionToken;
    }

    public function uniqueFor(): int
    {
        return (int) config('portal.archive.offline_resume_max_interval', 900) + 600;
    }

    public function handle(
        PortalSessionManager $sessionManager,
        PortalOfflineAccess $offlineAccess,
        PortalBroadcastService $broadcast,
        PortalDatasetRepository $datasets,
        PortalOutbox $outbox
    ): void {
        $outcome = $sessionManager->resumeOfflineSession($this->sessionToken);
        $sessionId = $sessionManager->laravelSessionId($this->sessionToken);

        Log::info('campus_session.offline_resume_job_'.$outcome, [
            'domain' => 'campus_session',
            'event' => 'offline_resume_job_'.$outcome,
            'component' => self::class,
            'attempt' => $this->attempt,
        ]);

        if ($outcome === 'unreachable') {
            $sessionManager->scheduleOfflineResume($this->sessionToken, $this->attempt + 1);

            return;
        }

        if ($outcome === 'resumed') {
            $offlineAccess->endOfflineSession($this->sessionToken);
            $session = $sessionManager->getSession($this->sessionToken) ?? [];
            $username = $session['username'] ?? null;

            if (is_string($username) && $username !== '') {
                $offlineAccess->markOnlineLogin($username, is_array($session['user_info'] ?? null) ? $session['user_info'] : null);
            }
        }

        if ($sessionId === null) {
            return;
        }

        if ($outcome === 'resumed') {
            $broadcast->sessionNotice($sessionId, $this->sessionToken, 'offline_session_resumed', 'Portal kampus kembali bisa diakses. Sesi tersambung lagi dan data sedang diperbarui.', 'success');
            $datasets->warmUp($this->sessionToken, $sessionId);
            ScanPortalNotificationsJob::dispatch($sessionId, $this->sessionToken);
            // Attendance and uploads queued while offline go out now (FlushPortalOutboxJob).
            $outbox->flushWhenQueued($this->sessionToken, $sessionId);

            return;
        }

        if ($outcome === 'rejected') {
            $broadcast->sessionNotice($sessionId, $this->sessionToken, 'offline_resume_rejected', 'Portal kampus sudah bisa diakses, tapi password yang tersimpan ditolak. Keluar lalu masuk lagi.', 'warning');
        }
    }
}
