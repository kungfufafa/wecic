<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PortalBroadcastService;
use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ends a campus session once it idles out, even when nothing else reads it
 * any more: its keep-alive chain gave up while the portal was down
 * (RefreshPortalSessionJob::failed()), a background job was killed before
 * it could log out, or the student simply never came back.
 *
 * The session's password copy and portal cookies would otherwise stay in a
 * cache store that keeps expired entries until they are read (the database
 * store). Scheduled when the session is created
 * (PortalSessionManager::scheduleIdleExpiry()), the job releases itself
 * until the session's idle expiry, which activity keeps moving, then ends
 * the session like the keep-alive does (PortalSessionManager::expireIdleSession())
 * and reads it once more after its short retention, which removes it. One
 * job per session, released rather than re-dispatched, so the chain needs
 * no unique lock and never stops: `$tries` is unlimited.
 */
class ExpirePortalSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Told to the browser of an online session this job ended, as the
     * keep-alive tells it (RefreshPortalSessionJob).
     */
    public const IDLE_MESSAGE = 'Sesi berakhir karena lama tidak aktif. Silakan login ulang.';

    /**
     * Unlimited: every release counts as an attempt.
     */
    public int $tries = 0;

    /**
     * Seconds before a run that threw is tried again.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * The session lock (10 s) and the portal logout of an idle session: two
     * requests of 10 s each.
     */
    public int $timeout = 60;

    public function __construct(private readonly string $sessionToken)
    {
        $this->onQueue('sessions');
    }

    public function sessionToken(): string
    {
        return $this->sessionToken;
    }

    public function handle(PortalSessionManager $sessionManager, PortalBroadcastService $broadcast): void
    {
        $outcome = $sessionManager->expireIdleSession($this->sessionToken);

        Log::debug('campus_session.idle_expiry_job_'.$outcome, [
            'domain' => 'campus_session',
            'event' => 'idle_expiry_job_'.$outcome,
            'component' => self::class,
        ]);

        if ($outcome === 'idle') {
            $this->tellTheBrowser($sessionManager, $broadcast);
        }

        $delay = $sessionManager->idleExpiryCheckDelay($this->sessionToken);

        if ($delay !== null) {
            $this->release($delay);
        }
    }

    /**
     * An open tab of an online session learns it ended, as when the
     * keep-alive ends it; an offline session's chain never said so either.
     */
    private function tellTheBrowser(PortalSessionManager $sessionManager, PortalBroadcastService $broadcast): void
    {
        $session = $sessionManager->getSession($this->sessionToken) ?? [];
        $sessionId = $sessionManager->laravelSessionId($this->sessionToken);

        if ($sessionId === null || ($session['offline'] ?? false) === true) {
            return;
        }

        $broadcast->sessionNotice($sessionId, $this->sessionToken, 'manual_relogin_required', self::IDLE_MESSAGE, 'warning');
    }
}
