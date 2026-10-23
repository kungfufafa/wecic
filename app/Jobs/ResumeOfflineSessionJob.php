<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalOfflineAccess;
use App\Services\PortalOutbox;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
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
 * password, which also ends the session and stops that password from
 * opening MyCIC offline. A session that keeps no password (the student
 * switched background sync off, or whose copy no longer decrypts) never logs in: once the portal answers
 * again, the chain tells the student to log in again and ends, and the
 * session stays readable until it idles out.
 */
class ResumeOfflineSessionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Told to a student whose offline session keeps no password, once the
     * portal answers again.
     */
    public const PORTAL_BACK_MESSAGE = 'Portal kampus sudah bisa diakses lagi. Keluar lalu masuk lagi untuk menyambungkan sesi; sementara itu, data arsip MyCIC tetap bisa dibaca.';

    public int $tries = 1;

    /**
     * A login is up to four portal requests (login page, login, a "session
     * loading" refresh, dashboard) at `portal.request_timeout` (30 s) each,
     * plus the portal logout of a session that was resumed elsewhere.
     */
    public int $timeout = 180;

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
        // The offline-login hash the session was opened with; the resume
        // rebuilds the stored session without it.
        $credentialHash = $sessionManager->getSession($this->sessionToken)['credential_hash'] ?? null;
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

        $session = $sessionManager->getSession($this->sessionToken) ?? [];
        $username = is_string($session['username'] ?? null) && $session['username'] !== '' ? $session['username'] : null;

        if ($outcome === 'resumed') {
            $offlineAccess->endOfflineSession($this->sessionToken);

            if ($username !== null) {
                $offlineAccess->markOnlineLogin($username, is_array($session['user_info'] ?? null) ? $session['user_info'] : null);
            }

            // The portal took the password this session was opened with:
            // what was queued with it may go out (sent below).
            $outbox->confirmForCredential($outbox->ownerHash($this->sessionToken), is_string($credentialHash) ? $credentialHash : null);
        }

        if ($outcome === 'rejected') {
            $offlineAccess->endOfflineSession($this->sessionToken);

            if ($username !== null) {
                $offlineAccess->markPasswordRejected($username, $this->offlineLoginTime($session));
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
            $broadcast->sessionNotice($sessionId, $this->sessionToken, 'manual_relogin_required', 'Portal kampus sudah bisa diakses, tapi password yang dipakai untuk masuk ditolak. Silakan masuk lagi dengan password terbaru.', 'error');

            return;
        }

        if ($outcome === 'unresumable') {
            $broadcast->sessionNotice($sessionId, $this->sessionToken, 'offline_resume_unavailable', self::PORTAL_BACK_MESSAGE, 'warning');
        }
    }

    /**
     * An attempt that crashed or was killed for running too long says
     * nothing about the password: the chain goes on, as when the portal was
     * unreachable. Its next run ends it if the session is gone by then.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::warning('campus_session.offline_resume_job_failed', [
            'domain' => 'campus_session',
            'event' => 'offline_resume_job_failed',
            'component' => self::class,
            'attempt' => $this->attempt,
            'error' => $exception?->getMessage(),
        ]);

        try {
            app(PortalSessionManager::class)->scheduleOfflineResume($this->sessionToken, $this->attempt + 1);
        } catch (\Throwable $throwable) {
            Log::error('campus_session.offline_resume_reschedule_failed', [
                'domain' => 'campus_session',
                'event' => 'offline_resume_reschedule_failed',
                'component' => self::class,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * When the offline session was opened, i.e. when its password was last
     * known to match the offline account.
     *
     * @param  array<string, mixed>  $session
     */
    private function offlineLoginTime(array $session): CarbonImmutable
    {
        try {
            return is_string($session['login_time'] ?? null) ? CarbonImmutable::parse($session['login_time']) : CarbonImmutable::now();
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }
}
