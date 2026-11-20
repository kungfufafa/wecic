<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Jobs\ExpirePortalSessionJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lifecycle of a campus (upstream portal) session.
 *
 * - Validity is decided locally: active and used within the idle timeout.
 *   Request handling never waits on the portal just to validate a session.
 * - User activity slides the expiry (throttled writes).
 * - One keep-alive job per session pings the portal so its PHP session does
 *   not expire while the student is around, re-logs in when allowed, and
 *   ends the session once the student has been idle for too long.
 * - An expired upstream session met while scraping invalidates the session
 *   immediately (see PortalScrapingService::validateResponse).
 * - Every session is also watched by ExpirePortalSessionJob, which ends it
 *   once it idles out even when nothing else reads it any more, so its
 *   password copy and portal cookies never outlive it in the cache.
 */
class PortalSessionManager
{
    private const SESSION_LOCK_SECONDS = 10;

    /**
     * Seconds an ended session stays readable (inactive) before it is gone.
     */
    private const ENDED_SESSION_RETENTION = 60;

    /**
     * Seconds before ExpirePortalSessionJob looks again at a session it could
     * not end (its lock was busy).
     */
    private const IDLE_EXPIRY_RETRY_SECONDS = 30;

    /**
     * Session field marking a session ExpirePortalSessionJob watches.
     */
    private const IDLE_EXPIRY_WATCHED = 'idle_expiry_watched';

    public function __construct(
        private readonly PortalAuthService $authService,
        private readonly Encrypter $encrypter,
        private ?PortalDataOwner $dataOwner = null
    ) {
        $this->dataOwner ??= app(PortalDataOwner::class);
    }

    /**
     * Create and store a new campus session
     */
    public function createSession(string $username, string $password): array
    {
        $startedAt = microtime(true);
        $this->logSessionEvent('login_started', username: $username, context: [
            'auto_relogin_enabled' => config('portal.auto_relogin', false),
        ]);

        try {
            $loginResult = $this->authService->login($username, $password);
            $isReviewer = ($loginResult['reviewer'] ?? false) === true;
            $username = $this->sessionUsername($username, $loginResult);
            $sessionToken = $this->generateSessionToken();
            $sessionData = [...$this->buildSessionData($sessionToken, $username, $loginResult), self::IDLE_EXPIRY_WATCHED => true];
            $keepsPassword = ! $isReviewer && config('portal.auto_relogin', false) && $this->mayKeepPassword($username);

            if ($keepsPassword) {
                $sessionData['encrypted_credentials'] = $this->encryptCredentials($username, $password);
            }

            $this->storeSession($sessionToken, $sessionData);
            $this->trackOwnerSession($sessionToken, $username);

            if ($keepsPassword) {
                $this->trackPasswordCopy($sessionToken, $username);
            }

            $this->scheduleKeepAlive($sessionToken);
            $this->scheduleIdleExpiry($sessionToken);

            $this->logSessionEvent('login_succeeded', sessionToken: $sessionToken, username: $username, session: $sessionData, context: [
                'expires_at' => $sessionData['expires_at'],
                'duration_ms' => $this->durationMs($startedAt),
                'cookie_count' => count($sessionData['cookies'] ?? []),
                'user_info_field_count' => count($loginResult['user_info'] ?? []),
            ]);

            return [
                'success' => true,
                'session_token' => $sessionToken,
                'user_info' => $loginResult['user_info'],
                'expires_at' => $sessionData['expires_at'],
            ];

        } catch (\Exception $e) {
            $this->logSessionEvent('login_failed', level: 'error', username: $username, context: [
                'error' => $e->getMessage(),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            throw $e;
        }
    }

    /**
     * Log in for a background job (PortalBackgroundSync): a full upstream
     * session without keep-alive, never reused for a browser, destroyed by
     * the job once it is done. A job killed before that leaves it to
     * ExpirePortalSessionJob, which logs it out once it idles out.
     *
     * @return array{success: true, session_token: string, user_info: array<string, mixed>, expires_at: string, background: true}
     */
    public function createBackgroundSession(string $username, string $password): array
    {
        $startedAt = microtime(true);
        $this->logSessionEvent('background_login_started', username: $username);

        try {
            $loginResult = $this->authService->login($username, $password);
            $username = $this->sessionUsername($username, $loginResult);
            $sessionToken = $this->generateSessionToken();
            $sessionData = [...$this->buildSessionData($sessionToken, $username, $loginResult), 'background' => true, self::IDLE_EXPIRY_WATCHED => true];

            $this->storeSession($sessionToken, $sessionData);
            $this->trackOwnerSession($sessionToken, $username);
            $this->scheduleIdleExpiry($sessionToken);
            $this->logSessionEvent('background_login_succeeded', sessionToken: $sessionToken, username: $username, session: $sessionData, context: [
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return [
                'success' => true,
                'session_token' => $sessionToken,
                'user_info' => $loginResult['user_info'],
                'expires_at' => $sessionData['expires_at'],
                'background' => true,
            ];
        } catch (\Exception $e) {
            $this->logSessionEvent('background_login_failed', level: 'warning', username: $username, context: [
                'error' => $e->getMessage(),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            throw $e;
        }
    }

    /**
     * The student's current online session, for background work that would
     * otherwise log in a second time; null when there is none (or it is an
     * offline or background session).
     */
    public function activeOnlineSessionTokenForOwner(string $ownerKey): ?string
    {
        $sessionToken = Cache::get($this->ownerSessionKey($ownerKey));

        if (! is_string($sessionToken) || $sessionToken === '') {
            return null;
        }

        $session = $this->loadSession($sessionToken);

        if (! $this->isUsable($sessionToken, $session) || ($session['offline'] ?? false) || ($session['background'] ?? false)) {
            return null;
        }

        return $sessionToken;
    }

    /**
     * Create a read-only session for a student whose password matched their
     * last online login while the portal is unreachable (see
     * PortalOfflineAccess). It carries no portal cookies and no keep-alive,
     * and schedules its own resume (ResumeOfflineSessionJob). With the
     * password it keeps it encrypted, and the resume logs in once the portal
     * is back. A student who switched background sync off has no copy of
     * their password kept anywhere: their session never logs in by itself,
     * the resume only tells them once the portal answers again.
     * `$credentialHash` is the offline-login hash the password matched; the
     * outbox keeps it with what the session queues (PortalOutbox).
     *
     * @param  array<string, mixed>  $userInfo
     * @return array{success: true, session_token: string, user_info: array<string, mixed>, expires_at: string, offline: true, offline_reconnect: 'automatic'|'notify'|'manual'}
     */
    public function createOfflineSession(string $username, array $userInfo, ?string $password = null, ?string $credentialHash = null): array
    {
        $sessionToken = $this->generateSessionToken();
        $resumes = (bool) config('portal.archive.offline_resume', true);
        $canResume = $resumes && $password !== null && $password !== '' && $this->mayKeepPassword($username);
        $sessionData = [
            'token' => $sessionToken,
            'username' => $username,
            'user_info' => $userInfo,
            'cookies' => [],
            'login_time' => now()->toISOString(),
            'last_activity' => now()->toISOString(),
            'expires_at' => $this->idleExpiry(),
            'upstream_checked_at' => null,
            'is_active' => true,
            'offline' => true,
            'credential_hash' => $credentialHash,
            self::IDLE_EXPIRY_WATCHED => true,
            ...($canResume ? ['encrypted_credentials' => $this->encryptCredentials($username, $password)] : []),
        ];

        $this->storeSession($sessionToken, $sessionData);
        $this->trackOwnerSession($sessionToken, $username);

        if ($canResume) {
            $this->trackPasswordCopy($sessionToken, $username);
        }

        $this->scheduleIdleExpiry($sessionToken);
        $this->logSessionEvent('offline_login_succeeded', sessionToken: $sessionToken, username: $username, session: $sessionData, context: [
            'resume_scheduled' => $resumes,
            'password_kept' => $canResume,
        ]);

        if ($resumes) {
            $this->scheduleOfflineResume($sessionToken);
        }

        return [
            'success' => true,
            'session_token' => $sessionToken,
            'user_info' => $userInfo,
            'expires_at' => $sessionData['expires_at'],
            'offline' => true,
            'offline_reconnect' => $this->reconnectMode($sessionData) ?? 'manual',
        ];
    }

    /**
     * How an offline session gets back online, so the student is told the
     * right thing: 'automatic' (it keeps the password and logs in by itself
     * once the portal is back), 'notify' (it keeps no password: MyCIC tells
     * the student once the portal answers again), 'portal_back' (it did: the
     * student logs out and in again to reconnect) or 'manual' (nothing
     * watches the portal for it). Null for a session that is not offline.
     *
     * @return 'automatic'|'notify'|'portal_back'|'manual'|null
     */
    public function offlineReconnectMode(string $sessionToken): ?string
    {
        $session = $this->loadSession($sessionToken);

        return $session === null ? null : $this->reconnectMode($session);
    }

    /**
     * Try to turn an offline session into an online one with the password it
     * keeps, without changing its token.
     *
     * 'rejected' means the portal refused that password: it changed since the
     * session was opened, so the session is ended rather than left readable
     * with a password nobody can log in with any more (and the student's
     * data is no longer served to whoever still holds it). A failed login
     * the portal did not visibly refuse (PortalAuthService::refusedPassword():
     * a recovering portal answers with blank or half-loaded pages) is
     * 'unreachable', like a timeout: tried again later.
     *
     * A session without a password to log in with (the student switched
     * background sync off, or the copy no longer decrypts) never logs in: it
     * only checks whether the portal serves its login page again. While it
     * does not, that is 'unreachable'; once it does, 'unresumable': the
     * session stays offline and readable until it idles out, and the student
     * logs in again to reconnect. A copy kept from before the student
     * switched background sync off, or one that no longer decrypts, is
     * dropped unused.
     *
     * Once online, the session keeps the copy only for auto-relogin;
     * otherwise it goes in the same write. Resuming is not user activity, so
     * the idle clock stays where it was.
     *
     * Every write here is best-effort: while the session lock is busy (a
     * copy to drop, a rejected session to end, the session to bring online)
     * the outcome is 'unreachable' and the next attempt does it.
     *
     * @return 'resumed'|'unreachable'|'rejected'|'unresumable'|'online'|'idle'|'inactive'|'missing'
     */
    public function resumeOfflineSession(string $sessionToken): string
    {
        $session = $this->loadSession($sessionToken);

        if ($session === null) {
            return 'missing';
        }

        if (! ($session['is_active'] ?? false)) {
            return 'inactive';
        }

        if ($this->isIdleExpired($session)) {
            $this->deactivateSession($sessionToken, 'idle_timeout', [
                'expires_at' => $session['expires_at'] ?? null,
            ]);

            return 'idle';
        }

        if (! ($session['offline'] ?? false)) {
            return 'online';
        }

        $credentials = $this->storedCredentials($session);
        $username = (string) ($credentials['username'] ?? $session['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

        if (isset($session['encrypted_credentials'])
            && ($password === '' || $this->optedOutOfBackgroundSync((string) ($session['username'] ?? $username)) === true)) {
            if (! $this->tryForgetStoredCredentials($sessionToken)) {
                return 'unreachable';
            }

            $password = '';
        }

        if ($username === '' || $password === '') {
            return $this->awaitPortalWithoutPassword($sessionToken, $session);
        }

        try {
            $loginResult = $this->authService->login($username, $password);
        } catch (PortalAuthenticationException $exception) {
            if (! PortalAuthService::refusedPassword($exception)) {
                $this->logSessionEvent('offline_resume_unconfirmed', level: 'warning', sessionToken: $sessionToken, session: $session, context: [
                    'error' => $exception->getMessage(),
                ]);

                return 'unreachable';
            }

            $this->deactivateSession($sessionToken, 'offline_resume_rejected', [
                'error' => $exception->getMessage(),
            ]);

            return ($this->loadSession($sessionToken)['is_active'] ?? false) === true ? 'unreachable' : 'rejected';
        } catch (\Throwable $throwable) {
            $this->logSessionEvent('offline_resume_unreachable', level: 'debug', sessionToken: $sessionToken, session: $session, context: [
                'error' => $throwable->getMessage(),
            ]);

            return 'unreachable';
        }

        $keepForAutoRelogin = config('portal.auto_relogin', false) && $this->mayKeepPassword($username);

        try {
            $sessionData = $this->mutateSession($sessionToken, function (?array $stored) use ($sessionToken, $username, $password, $loginResult, $keepForAutoRelogin): ?array {
                if ($stored === null || ! ($stored['offline'] ?? false)) {
                    return null;
                }

                $sessionData = $this->buildSessionData($sessionToken, $username, $loginResult);
                $sessionData['last_activity'] = $stored['last_activity'] ?? $sessionData['last_activity'];
                $sessionData['expires_at'] = $stored['expires_at'] ?? $sessionData['expires_at'];
                $sessionData['laravel_session_id'] = $stored['laravel_session_id'] ?? null;
                $sessionData[self::IDLE_EXPIRY_WATCHED] = $stored[self::IDLE_EXPIRY_WATCHED] ?? null;
                // What an action racing the resume queues still carries it (PortalOutbox).
                $sessionData['credential_hash'] = $stored['credential_hash'] ?? null;
                $sessionData['encrypted_credentials'] = $keepForAutoRelogin
                    ? $this->encryptCredentials($username, $password)
                    : null;

                return array_filter($sessionData, static fn (mixed $value): bool => $value !== null);
            });
        } catch (\RuntimeException) {
            return 'unreachable';
        }

        if ($sessionData === null) {
            $this->authService->logout($this->recreateCookieJar($loginResult['cookies'] ?? []));

            return $this->loadSession($sessionToken) === null ? 'missing' : 'online';
        }

        $this->scheduleKeepAlive($sessionToken);
        $this->logSessionEvent('offline_resume_succeeded', sessionToken: $sessionToken, session: $sessionData);

        return 'resumed';
    }

    /**
     * Schedule the next attempt to bring an offline session online; the job
     * is unique per session, so repeated scheduling never starts a second chain
     */
    public function scheduleOfflineResume(string $sessionToken, int $attempt = 1): void
    {
        if (! config('portal.archive.offline_resume', true)) {
            return;
        }

        $delay = $this->offlineResumeDelay($attempt);

        ResumeOfflineSessionJob::dispatch($sessionToken, $attempt)->delay(now()->addSeconds($delay));

        $this->logSessionEvent('offline_resume_scheduled', level: 'debug', sessionToken: $sessionToken, context: [
            'attempt' => $attempt,
            'delay_seconds' => $delay,
        ]);
    }

    /**
     * Seconds before attempt `$attempt`: the base interval, doubling up to
     * the configured maximum.
     */
    public function offlineResumeDelay(int $attempt): int
    {
        $base = max(30, (int) config('portal.archive.offline_resume_interval', 120));
        $max = max($base, (int) config('portal.archive.offline_resume_max_interval', 900));

        return (int) min($max, $base * (2 ** min(16, max(0, $attempt - 1))));
    }

    /**
     * Seconds a stored session lives in the cache.
     */
    public function sessionTtl(): int
    {
        return (int) config('portal.session_idle_timeout', 7200) + (int) config('portal.session_refresh_interval', 1200);
    }

    /**
     * Read the stored session without recording activity
     */
    public function getSession(string $sessionToken): ?array
    {
        return $this->loadSession($sessionToken);
    }

    /**
     * Validate a session for an incoming user request and record the activity
     *
     * This is a local check only; it never calls the portal.
     */
    public function validateSession(string $sessionToken): bool
    {
        $session = $this->loadSession($sessionToken);

        if (! $this->isUsable($sessionToken, $session)) {
            return false;
        }

        $this->touchActivity($sessionToken, $session);

        return true;
    }

    /**
     * Whether the student used the app within the last `$seconds` (requests
     * record `last_activity`); background pings and jobs never count. Lets
     * background work skip a student who closed the app but whose session
     * has not reached the idle timeout yet.
     */
    public function wasActiveWithin(string $sessionToken, int $seconds): bool
    {
        $lastActivity = $this->loadSession($sessionToken)['last_activity'] ?? null;

        if (! is_string($lastActivity) || $lastActivity === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($lastActivity)->addSeconds(max(0, $seconds))->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the session can be used, without recording user activity; for
     * background work, which must not keep an abandoned session alive
     */
    public function isSessionUsable(string $sessionToken): bool
    {
        return $this->isUsable($sessionToken, $this->loadSession($sessionToken));
    }

    /**
     * A session a background job opened (createBackgroundSession): it ends
     * with the job, so nothing may be queued against it.
     */
    public function isBackgroundSession(string $sessionToken): bool
    {
        return ($this->loadSession($sessionToken)['background'] ?? false) === true;
    }

    /**
     * Refresh the session on the student's request: confirm (or restore) the
     * upstream session now, extend the local expiry, and make sure the
     * keep-alive is scheduled
     */
    public function refreshSession(string $sessionToken): array
    {
        $startedAt = microtime(true);
        $outcome = $this->keepAlive($sessionToken);

        if (! in_array($outcome, ['alive', 'relogged'], true)) {
            $this->logSessionEvent('refresh_rejected', level: 'warning', sessionToken: $sessionToken, context: [
                'outcome' => $outcome,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            throw PortalAuthenticationException::sessionExpired();
        }

        $session = $this->touchActivity($sessionToken, $this->loadSession($sessionToken) ?? [], force: true);
        $this->scheduleKeepAlive($sessionToken);

        $this->logSessionEvent('refresh_succeeded', sessionToken: $sessionToken, session: $session, context: [
            'strategy' => $outcome === 'relogged' ? 'auto_relogin' : 'upstream_ping',
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return [
            'success' => true,
            'session_token' => $sessionToken,
            'expires_at' => $session['expires_at'] ?? null,
        ];
    }

    /**
     * Keep the upstream session alive
     *
     * An offline session has no upstream session to keep alive: it is left
     * untouched ('offline'), since its own chain (ResumeOfflineSessionJob)
     * brings it online once the portal is back.
     *
     * The auto-relogin copy of a student who switched background sync off
     * since the login goes, unused (best-effort: while the session lock is
     * busy, the next ping drops it). A session opened before
     * ExpirePortalSessionJob existed is handed to it here.
     *
     * @return 'alive'|'relogged'|'expired'|'idle'|'inactive'|'missing'|'offline'
     *
     * @throws PortalConnectionException when the portal is unreachable; the
     *                                   session is left untouched so the
     *                                   caller can retry
     */
    public function keepAlive(string $sessionToken): string
    {
        $session = $this->loadSession($sessionToken);

        if ($session === null) {
            return 'missing';
        }

        if (! ($session['is_active'] ?? false)) {
            return 'inactive';
        }

        if ($this->isIdleExpired($session)) {
            $this->endIdleSession($sessionToken, $session);

            return 'idle';
        }

        if ($this->isOffline($session)) {
            return 'offline';
        }

        if (isset($session['encrypted_credentials']) && $this->optedOutOfBackgroundSync((string) ($session['username'] ?? '')) === true) {
            unset($session['encrypted_credentials']);
            $this->tryForgetStoredCredentials($sessionToken);
        }

        $cookies = is_array($session['cookies'] ?? null) ? $session['cookies'] : [];

        if ($cookies !== [] && $this->authService->isSessionAlive($this->recreateCookieJar($cookies))) {
            $this->mutateSession($sessionToken, static function (?array $stored): ?array {
                return $stored === null ? null : [...$stored, 'upstream_checked_at' => now()->toISOString(), self::IDLE_EXPIRY_WATCHED => true];
            }, failOnLockTimeout: false);
            $this->watchIdleExpiry($sessionToken, $session, markWatched: false);
            $this->logSessionEvent('keepalive_succeeded', level: 'debug', sessionToken: $sessionToken, session: $session);

            return 'alive';
        }

        if (config('portal.auto_relogin', false) && isset($session['encrypted_credentials'])) {
            try {
                $this->performAutoRelogin($sessionToken, $session);
                $this->watchIdleExpiry($sessionToken, $session);

                return 'relogged';
            } catch (PortalConnectionException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->deactivateSession($sessionToken, 'auto_relogin_failed', [
                    'error' => $e->getMessage(),
                ]);

                return 'expired';
            }
        }

        $this->deactivateSession($sessionToken, 'upstream_session_expired', [], $cookies);

        return 'expired';
    }

    /**
     * Destroy a session
     *
     * The local session goes first, so it is unusable at once even while the
     * (slow, possibly unreachable) portal logout is still running or fails.
     */
    public function destroySession(string $sessionToken): bool
    {
        $startedAt = microtime(true);
        $session = $this->loadSession($sessionToken);

        $this->deleteSession($sessionToken);
        $this->dataOwner->forget($sessionToken);
        $this->forgetOwnerSession($sessionToken, $session);
        $this->untrackOwnerSession($sessionToken, $session);

        if (isset($session['encrypted_credentials'])) {
            $this->untrackPasswordCopy($sessionToken, $session);
        }

        try {
            // An offline session never reached the portal; there is nothing to log out of.
            if (is_array($session['cookies'] ?? null) && ! ($session['offline'] ?? false)) {
                $this->authService->logout($this->recreateCookieJar($session['cookies']));
            }

            $this->logSessionEvent('logout_succeeded', sessionToken: $sessionToken, session: $session, context: [
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logSessionEvent('logout_failed', level: 'error', sessionToken: $sessionToken, session: $session, context: [
                'error' => $e->getMessage(),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return false;
        }
    }

    public function invalidateSession(string $sessionToken, string $reason = 'manual', array $context = [], ?array $expectedCookies = null): void
    {
        $this->deactivateSession($sessionToken, $reason, $context, $expectedCookies);
    }

    public function rememberLaravelSessionId(string $sessionToken, string $laravelSessionId): void
    {
        $this->mutateSession($sessionToken, static function (?array $session) use ($laravelSessionId): ?array {
            if ($session === null) {
                return null;
            }

            $session['laravel_session_id'] = $laravelSessionId;

            return $session;
        });
    }

    public function laravelSessionId(string $sessionToken): ?string
    {
        $session = $this->loadSession($sessionToken);

        if (! is_array($session)) {
            return null;
        }

        $laravelSessionId = $session['laravel_session_id'] ?? null;

        return is_string($laravelSessionId) && $laravelSessionId !== '' ? $laravelSessionId : null;
    }

    /**
     * Drop the password copy one session keeps.
     *
     * @throws \RuntimeException when the session lock stayed busy
     */
    public function forgetStoredCredentials(string $sessionToken): void
    {
        $this->mutateSession($sessionToken, function (?array $session): ?array {
            if ($session === null || ! array_key_exists('encrypted_credentials', $session)) {
                return null;
            }

            unset($session['encrypted_credentials']);

            $this->logSessionEvent('stored_credentials_removed', sessionToken: $session['token'], session: $session);

            return $session;
        });
    }

    /**
     * Drop the password copy of every session the student has open, at
     * once: for a student who switched background sync off (MyCIC then
     * keeps no copy of their password anywhere) or changed their password
     * (the copies hold the old one). False when a session could not be
     * reached (a lock stayed busy): its copy still goes unused, at that
     * session's next keep-alive ping or resume attempt.
     */
    public function forgetStoredCredentialsForOwner(string $ownerKey): bool
    {
        $forgotten = true;

        $indexed = $this->mutatePasswordCopyIndex($ownerKey, function (array $sessionTokens) use (&$forgotten): array {
            $remaining = [];

            foreach ($sessionTokens as $sessionToken) {
                if (! $this->tryForgetStoredCredentials($sessionToken)) {
                    $remaining[] = $sessionToken;
                    $forgotten = false;
                }
            }

            return $remaining;
        });

        return $indexed && $forgotten;
    }

    /**
     * Every session the student still has (online, offline or background),
     * for ending them all at once (PortalAccountEraser). Sessions opened
     * before they were listed are found through the owner's online slot and
     * password copy list.
     *
     * @return list<string>
     */
    public function sessionTokensForOwner(string $ownerKey): array
    {
        $candidates = [];

        foreach ([$this->ownerSessionsKey($ownerKey), $this->passwordCopiesKey($ownerKey)] as $key) {
            $listed = Cache::get($key);

            if (is_array($listed)) {
                array_push($candidates, ...array_filter($listed, 'is_string'));
            }
        }

        $current = Cache::get($this->ownerSessionKey($ownerKey));

        if (is_string($current) && $current !== '') {
            $candidates[] = $current;
        }

        return array_values(array_filter(
            array_unique($candidates),
            fn (string $sessionToken): bool => $this->loadSession($sessionToken) !== null
        ));
    }

    /**
     * Get cookie jar for a session
     */
    public function getSessionCookieJar(string $sessionToken, bool $touch = false): ?CookieJar
    {
        $session = $this->loadSession($sessionToken);

        if (! $session || ! isset($session['cookies'])) {
            return null;
        }

        if ($touch) {
            $this->touchActivity($sessionToken, $session);
        }

        return $this->recreateCookieJar($session['cookies']);
    }

    /**
     * Schedule the next keep-alive ping; the job is unique per session, so
     * repeated scheduling never starts a second chain
     */
    public function scheduleKeepAlive(string $sessionToken): void
    {
        $delay = $this->keepAliveDelay();

        RefreshPortalSessionJob::dispatch($sessionToken)->delay(now()->addSeconds($delay));

        $this->logSessionEvent('keepalive_scheduled', level: 'debug', sessionToken: $sessionToken, context: [
            'delay_seconds' => $delay,
        ]);
    }

    public function keepAliveDelay(): int
    {
        return max((int) config('portal.session_refresh_interval', 1200) - 60, 60);
    }

    /**
     * Start the ExpirePortalSessionJob of a session: it first looks at the
     * session just after its idle expiry, then releases itself for as long
     * as the session lives. Started once per session, when it is created.
     */
    public function scheduleIdleExpiry(string $sessionToken): void
    {
        $delay = $this->idleExpiryCheckDelay($sessionToken) ?? (int) config('portal.session_idle_timeout', 7200) + 1;

        ExpirePortalSessionJob::dispatch($sessionToken)->delay(now()->addSeconds($delay));

        $this->logSessionEvent('idle_expiry_scheduled', level: 'debug', sessionToken: $sessionToken, context: [
            'delay_seconds' => $delay,
        ]);
    }

    /**
     * The check ExpirePortalSessionJob runs, for a session that may have
     * nobody else watching it (its keep-alive chain gave up while the portal
     * was down, a background job was killed): once it has idled out it ends
     * as keepAlive() ends it, its password copy and portal cookies gone and
     * its portal session logged out. An ended session is deleted once its
     * retention is over, since a cache store that keeps expired entries
     * until they are read (the database store) would otherwise keep it.
     *
     * 'idle' when this check ended the session, 'active' while it has not
     * idled out yet (or could not be ended: its lock was busy), 'inactive'
     * for a session that already ended and is still within its retention,
     * 'missing' once it is gone.
     *
     * @return 'active'|'idle'|'inactive'|'missing'
     */
    public function expireIdleSession(string $sessionToken): string
    {
        $session = $this->loadSession($sessionToken);

        if ($session === null) {
            return 'missing';
        }

        if (! ($session['is_active'] ?? false)) {
            if ($this->secondsUntilRetentionEnds($session) > 0) {
                return 'inactive';
            }

            $this->deleteSession($sessionToken);
            $this->dataOwner->forget($sessionToken);
            $this->logSessionEvent('ended_session_removed', level: 'debug', sessionToken: $sessionToken, session: $session);

            return 'missing';
        }

        if (! $this->isIdleExpired($session)) {
            return 'active';
        }

        $this->endIdleSession($sessionToken, $session, ['observed_by' => 'idle_expiry_check']);

        return ($this->loadSession($sessionToken)['is_active'] ?? false) === true ? 'active' : 'idle';
    }

    /**
     * Seconds until ExpirePortalSessionJob should look at the session again:
     * just past its idle expiry while it is active (soon, when it is past it
     * already), just past its retention once it ended; null once it is gone.
     */
    public function idleExpiryCheckDelay(string $sessionToken): ?int
    {
        $session = $this->loadSession($sessionToken);

        if ($session === null) {
            return null;
        }

        if (! ($session['is_active'] ?? false)) {
            return max(1, $this->secondsUntilRetentionEnds($session) + 1);
        }

        return max(self::IDLE_EXPIRY_RETRY_SECONDS, $this->secondsUntil($session['expires_at'] ?? null) + 1);
    }

    /**
     * forgetStoredCredentials() for a caller that goes on while the session
     * lock stays busy: false then, and a later attempt drops the copy.
     */
    private function tryForgetStoredCredentials(string $sessionToken): bool
    {
        try {
            $this->forgetStoredCredentials($sessionToken);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>|null  $session
     */
    private function isUsable(string $sessionToken, ?array $session): bool
    {
        if ($session === null || ! ($session['is_active'] ?? false)) {
            return false;
        }

        if ($this->isIdleExpired($session)) {
            $this->deactivateSession($sessionToken, 'idle_timeout', [
                'expires_at' => $session['expires_at'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function isOffline(array $session): bool
    {
        return ($session['offline'] ?? false) === true;
    }

    /**
     * See offlineReconnectMode().
     *
     * @param  array<string, mixed>  $session
     * @return 'automatic'|'notify'|'portal_back'|'manual'|null
     */
    private function reconnectMode(array $session): ?string
    {
        if (! $this->isOffline($session)) {
            return null;
        }

        return match (true) {
            ! config('portal.archive.offline_resume', true) => 'manual',
            isset($session['portal_back_at']) => 'portal_back',
            isset($session['encrypted_credentials']) => 'automatic',
            default => 'notify',
        };
    }

    /**
     * The resume of an offline session without a password: it never logs
     * in, it only asks the portal for its login page (no credentials are
     * sent). Once the portal serves it, the session notes so (see
     * offlineReconnectMode()) and the student is told to log in again. The
     * note is written before the student is told: while the session lock is
     * busy the outcome stays 'unreachable', so the next attempt writes it,
     * and a session that went online, ended or went away meanwhile says so
     * instead.
     *
     * @param  array<string, mixed>  $session
     * @return 'unreachable'|'unresumable'|'online'|'inactive'|'missing'
     */
    private function awaitPortalWithoutPassword(string $sessionToken, array $session): string
    {
        try {
            $this->authService->analyzeLoginForm();
        } catch (\Throwable $throwable) {
            $this->logSessionEvent('offline_resume_portal_unreachable', level: 'debug', sessionToken: $sessionToken, session: $session, context: [
                'reason' => 'no_stored_credentials',
                'error' => $throwable->getMessage(),
            ]);

            return 'unreachable';
        }

        $stamped = $this->mutateSession($sessionToken, static function (?array $stored): ?array {
            return $stored === null || ! ($stored['is_active'] ?? false) || ! ($stored['offline'] ?? false)
                ? null
                : [...$stored, 'portal_back_at' => now()->toISOString()];
        }, failOnLockTimeout: false);

        if ($stamped === null) {
            $current = $this->loadSession($sessionToken);

            return match (true) {
                $current === null => 'missing',
                ! ($current['is_active'] ?? false) => 'inactive',
                ! $this->isOffline($current) => 'online',
                default => 'unreachable',
            };
        }

        $this->logSessionEvent('offline_resume_unavailable', sessionToken: $sessionToken, session: $session, context: [
            'reason' => 'no_stored_credentials',
            'portal_reachable' => true,
        ]);

        return 'unresumable';
    }

    /**
     * Whether background sync is off for the student (opt-in: off until
     * they switch it on, PortalOfflineAccount::isBackgroundSyncEnabled()),
     * null when that could not be checked. MyCIC then keeps no copy of
     * their password, sessions included.
     */
    private function optedOutOfBackgroundSync(string $username): ?bool
    {
        if ($username === '') {
            return null;
        }

        try {
            return app(PortalOfflineAccess::class)->findForOwner($this->dataOwner->keyForUsername($username))?->isBackgroundSyncEnabled() !== true;
        } catch (\Throwable $throwable) {
            $this->logSessionEvent('background_sync_choice_unreadable', level: 'warning', username: $username, context: [
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A session stores a copy of the password only for a student known to
     * have switched background sync on.
     */
    private function mayKeepPassword(string $username): bool
    {
        return $this->optedOutOfBackgroundSync($username) === false;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function isIdleExpired(array $session): bool
    {
        $expiresAt = $session['expires_at'] ?? null;

        return ! is_string($expiresAt) || CarbonImmutable::parse($expiresAt)->isPast();
    }

    /**
     * Seconds from now until `$moment` (negative once it passed); 0 for a
     * moment that is missing or unreadable.
     */
    private function secondsUntil(mixed $moment): int
    {
        if (! is_string($moment) || $moment === '') {
            return 0;
        }

        try {
            return CarbonImmutable::parse($moment)->getTimestamp() - now()->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Seconds an ended session stays readable for (see deactivateSession());
     * 0 or less once its retention is over, or when it does not say when it
     * ended.
     *
     * @param  array<string, mixed>  $session
     */
    private function secondsUntilRetentionEnds(array $session): int
    {
        if (! is_string($session['ended_at'] ?? null)) {
            return 0;
        }

        return $this->secondsUntil($session['ended_at']) + self::ENDED_SESSION_RETENTION;
    }

    /**
     * The username and password the session keeps a copy of; empty when it
     * keeps none or the copy no longer decrypts (a rotated app key).
     *
     * @param  array<string, mixed>  $session
     * @return array{username?: string, password?: string}
     */
    private function storedCredentials(array $session): array
    {
        if (! is_string($session['encrypted_credentials'] ?? null)) {
            return [];
        }

        try {
            $credentials = $this->decryptCredentials($session['encrypted_credentials']);
        } catch (\Throwable) {
            return [];
        }

        return is_array($credentials) ? $credentials : [];
    }

    /**
     * Record user activity and slide the expiry; writes at most once per
     * touch interval and never fails the request on lock contention
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function touchActivity(string $sessionToken, array $session, bool $force = false): array
    {
        $lastActivity = isset($session['last_activity']) ? CarbonImmutable::parse($session['last_activity']) : null;
        $touchInterval = (int) config('portal.session_activity_touch_interval', 60);

        if (! $force && $lastActivity !== null && $lastActivity->addSeconds($touchInterval)->isFuture()) {
            return $session;
        }

        $touched = $this->mutateSession($sessionToken, function (?array $stored): ?array {
            if ($stored === null) {
                return null;
            }

            return [
                ...$stored,
                'last_activity' => now()->toISOString(),
                'expires_at' => $this->idleExpiry(),
            ];
        }, failOnLockTimeout: false) ?? $session;

        if (! $force && $this->keepAliveLapsed($touched)) {
            // The keep-alive chain stopped (e.g. the portal was unreachable
            // for every retry) while the student is still active: restart it.
            $this->scheduleKeepAlive($sessionToken);
        }

        return $touched;
    }

    /**
     * Whether the keep-alive chain needs a restart; never for an offline
     * session, which has no portal session to keep alive (see keepAlive()).
     *
     * @param  array<string, mixed>  $session
     */
    private function keepAliveLapsed(array $session): bool
    {
        if ($this->isOffline($session)) {
            return false;
        }

        $checkedAt = $session['upstream_checked_at'] ?? null;

        return ! is_string($checkedAt)
            || CarbonImmutable::parse($checkedAt)
                ->addSeconds(2 * (int) config('portal.session_refresh_interval', 1200))
                ->isPast();
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $context
     */
    private function endIdleSession(string $sessionToken, array $session, array $context = []): void
    {
        $this->deactivateSession($sessionToken, 'idle_timeout', [
            'expires_at' => $session['expires_at'] ?? null,
            ...$context,
        ]);

        if (is_array($session['cookies'] ?? null) && $session['cookies'] !== []) {
            $this->authService->logout($this->recreateCookieJar($session['cookies']));
        }
    }

    /**
     * Store session data in cache
     *
     * The entry outlives the idle timeout slightly, so an idle session is
     * observed as expired (and logged) rather than silently missing. The
     * portal cookies are stored encrypted (see sealCookies()).
     */
    private function storeSession(string $sessionToken, array $sessionData, ?int $ttl = null): void
    {
        $ttl ??= $this->sessionTtl();

        Cache::put($this->cacheKey($sessionToken), $this->sealCookies($sessionData), $ttl);

        if (isset($sessionData['username']) && is_string($sessionData['username'])) {
            $this->dataOwner->remember($sessionToken, $sessionData['username'], $ttl);
            $this->indexOwnerSession($sessionToken, $sessionData, $ttl);
        }
    }

    /**
     * Owner → current online session, for activeOnlineSessionTokenForOwner().
     * Offline, background and deactivated sessions never take the slot, and
     * a deactivated one gives it back.
     *
     * @param  array<string, mixed>  $sessionData
     */
    private function indexOwnerSession(string $sessionToken, array $sessionData, int $ttl): void
    {
        $key = $this->ownerSessionKey($this->dataOwner->keyForUsername((string) $sessionData['username']));

        if (($sessionData['is_active'] ?? false) && ! ($sessionData['offline'] ?? false) && ! ($sessionData['background'] ?? false)) {
            Cache::put($key, $sessionToken, $ttl);
        } elseif (Cache::get($key) === $sessionToken) {
            Cache::forget($key);
        }
    }

    /**
     * @param  array<string, mixed>|null  $session
     */
    private function forgetOwnerSession(string $sessionToken, ?array $session): void
    {
        $username = $session['username'] ?? null;

        if (! is_string($username) || $username === '') {
            return;
        }

        $key = $this->ownerSessionKey($this->dataOwner->keyForUsername($username));

        if (Cache::get($key) === $sessionToken) {
            Cache::forget($key);
        }
    }

    private function ownerSessionKey(string $ownerKey): string
    {
        return 'portal_owner_session_'.hash('sha256', $ownerKey);
    }

    /**
     * Hand a session opened before ExpirePortalSessionJob existed to it, and
     * list its password copy for forgetStoredCredentialsForOwner(); nothing
     * for a session it already watches. Marking it (here, or by the caller
     * in a write of its own) is best-effort: a skipped mark only starts a
     * second, harmless check.
     *
     * @param  array<string, mixed>  $session  as read before the caller's own writes
     */
    private function watchIdleExpiry(string $sessionToken, array $session, bool $markWatched = true): void
    {
        if (isset($session[self::IDLE_EXPIRY_WATCHED])) {
            return;
        }

        if ($markWatched) {
            $this->mutateSession($sessionToken, static function (?array $stored): ?array {
                return $stored === null ? null : [...$stored, self::IDLE_EXPIRY_WATCHED => true];
            }, failOnLockTimeout: false);
        }

        $this->scheduleIdleExpiry($sessionToken);

        if (isset($session['encrypted_credentials']) && is_string($session['username'] ?? null) && $session['username'] !== '') {
            $this->trackPasswordCopy($sessionToken, $session['username']);
        }
    }

    /**
     * List a new session under its student, so sessionTokensForOwner()
     * reaches it. Each addition drops the listed sessions that are gone.
     */
    private function trackOwnerSession(string $sessionToken, string $username): void
    {
        $this->mutateOwnerIndex($this->ownerSessionsKey($this->dataOwner->keyForUsername($username)), function (array $sessionTokens) use ($sessionToken): array {
            $listed = array_filter(
                $sessionTokens,
                fn (string $listedToken): bool => $listedToken !== $sessionToken && Cache::has($this->cacheKey($listedToken))
            );

            return [...array_values($listed), $sessionToken];
        }, 'owner_session_index_lock_timeout');
    }

    /**
     * @param  array<string, mixed>|null  $session
     */
    private function untrackOwnerSession(string $sessionToken, ?array $session): void
    {
        $username = $session['username'] ?? null;

        if (! is_string($username) || $username === '') {
            return;
        }

        $this->mutateOwnerIndex($this->ownerSessionsKey($this->dataOwner->keyForUsername($username)), static function (array $sessionTokens) use ($sessionToken): array {
            return array_values(array_diff($sessionTokens, [$sessionToken]));
        }, 'owner_session_index_lock_timeout');
    }

    private function ownerSessionsKey(string $ownerKey): string
    {
        return 'portal_owner_sessions_'.hash('sha256', $ownerKey);
    }

    private function passwordCopiesKey(string $ownerKey): string
    {
        return 'portal_owner_password_copies_'.hash('sha256', $ownerKey);
    }

    /**
     * List a session that keeps a copy of the student's password, so
     * forgetStoredCredentialsForOwner() reaches it. The list has no expiry,
     * since activity keeps a session alive for as long as the student uses
     * it: a session leaves it when it ends or its copy is dropped through
     * it, and each addition drops the listed sessions that no longer keep a
     * copy.
     */
    private function trackPasswordCopy(string $sessionToken, string $username): void
    {
        $this->mutatePasswordCopyIndex($this->dataOwner->keyForUsername($username), function (array $sessionTokens) use ($sessionToken): array {
            $listed = array_filter(
                $sessionTokens,
                fn (string $listedToken): bool => $listedToken !== $sessionToken && isset($this->loadSession($listedToken)['encrypted_credentials'])
            );

            return [...array_values($listed), $sessionToken];
        });
    }

    /**
     * @param  array<string, mixed>|null  $session
     */
    private function untrackPasswordCopy(string $sessionToken, ?array $session): void
    {
        $username = $session['username'] ?? null;

        if (! is_string($username) || $username === '') {
            return;
        }

        $this->mutatePasswordCopyIndex($this->dataOwner->keyForUsername($username), static function (array $sessionTokens) use ($sessionToken): array {
            return array_values(array_diff($sessionTokens, [$sessionToken]));
        });
    }

    /**
     * Rewrite the student's list of sessions that keep a password copy
     * under its own lock; false when the lock stayed busy (nothing written).
     * Never taken while a session lock is held, so the two cannot deadlock.
     *
     * @param  callable(list<string>): list<string>  $mutator
     */
    private function mutatePasswordCopyIndex(string $ownerKey, callable $mutator): bool
    {
        return $this->mutateOwnerIndex($this->passwordCopiesKey($ownerKey), $mutator, 'password_copy_index_lock_timeout');
    }

    /**
     * Rewrite one of the student's session lists under its own lock; false
     * when the lock stayed busy (nothing written).
     *
     * @param  callable(list<string>): list<string>  $mutator
     */
    private function mutateOwnerIndex(string $key, callable $mutator, string $lockTimeoutEvent): bool
    {
        try {
            Cache::lock($key.'_lock', self::SESSION_LOCK_SECONDS)->block(self::SESSION_LOCK_SECONDS, static function () use ($key, $mutator): void {
                $stored = Cache::get($key);
                $sessionTokens = $mutator(is_array($stored) ? array_values(array_filter($stored, 'is_string')) : []);

                if ($sessionTokens === []) {
                    Cache::forget($key);
                } else {
                    Cache::forever($key, $sessionTokens);
                }
            });

            return true;
        } catch (LockTimeoutException $exception) {
            $this->logSessionEvent($lockTimeoutEvent, level: 'warning', context: [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function loadSession(string $sessionToken): ?array
    {
        $session = Cache::get($this->cacheKey($sessionToken));

        return is_array($session) ? $this->openCookies($sessionToken, $session) : null;
    }

    /**
     * The portal cookies are a live login: whoever reads them from the cache
     * (the shared database store by default, or a backup of it) could act as
     * the student on the portal. They are kept encrypted with the app key.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function sealCookies(array $session): array
    {
        $cookies = $session['cookies'] ?? null;

        if (is_array($cookies) && $cookies !== []) {
            $session['cookies'] = $this->encrypter->encrypt(json_encode($cookies, JSON_THROW_ON_ERROR), false);
        }

        return $session;
    }

    /**
     * Reverse sealCookies(). A session stored before the cookies were sealed
     * still holds them as a plain array and is read as is; cookies that no
     * longer decrypt (a rotated app key) read as none, so the portal simply
     * asks for a new login.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function openCookies(string $sessionToken, array $session): array
    {
        $cookies = $session['cookies'] ?? null;

        if (! is_string($cookies)) {
            return $session;
        }

        try {
            $decoded = json_decode($this->encrypter->decrypt($cookies, false), true, 512, JSON_THROW_ON_ERROR);
            $session['cookies'] = is_array($decoded) ? $decoded : [];
        } catch (\Throwable $throwable) {
            $session['cookies'] = [];
            $this->logSessionEvent('cookies_unreadable', level: 'warning', sessionToken: $sessionToken, context: [
                'error' => $throwable->getMessage(),
            ]);
        }

        return $session;
    }

    /**
     * Apply a mutation to the stored session while holding an atomic lock.
     *
     * Parallel workers (scraping jobs, keep-alive pings, activity touches)
     * each read the whole session array and write it back; without the lock a
     * late write would resurrect stale cookies over a concurrent auto-relogin.
     * The mutator receives the freshly loaded session (null when missing) and
     * returns the session to store, or null to leave the cache untouched.
     *
     * Mutations that protect cookies (auto-relogin swap) fail loudly on a lock
     * timeout; best-effort mutations (activity touches, invalidations) pass
     * false and skip the write instead.
     *
     * @param  callable(?array<string, mixed>): ?array<string, mixed>  $mutator
     * @return array<string, mixed>|null
     */
    private function mutateSession(string $sessionToken, callable $mutator, ?int $ttl = null, bool $failOnLockTimeout = true): ?array
    {
        $lock = Cache::lock($this->sessionLockKey($sessionToken), self::SESSION_LOCK_SECONDS);

        try {
            return $lock->block(self::SESSION_LOCK_SECONDS, function () use ($sessionToken, $mutator, $ttl): ?array {
                $session = $mutator($this->loadSession($sessionToken));

                if ($session !== null) {
                    $this->storeSession($sessionToken, $session, $ttl);
                }

                return $session;
            });
        } catch (LockTimeoutException $exception) {
            $this->logSessionEvent('mutation_lock_timeout', level: 'warning', sessionToken: $sessionToken, context: [
                'error' => $exception->getMessage(),
            ]);

            if ($failOnLockTimeout) {
                throw new \RuntimeException('Campus session mutation timed out waiting for the session lock.', 0, $exception);
            }

            return null;
        }
    }

    private function sessionLockKey(string $sessionToken): string
    {
        // Deliberately outside the session key prefix so it can never be
        // mistaken for a stored session.
        return 'campus_session_lock_'.$sessionToken;
    }

    /**
     * Generate a secure session token
     */
    private function generateSessionToken(): string
    {
        return Str::random(64);
    }

    /**
     * Encrypt user credentials for storage
     */
    private function encryptCredentials(string $username, string $password): string
    {
        $credentials = json_encode([
            'username' => $username,
            'password' => $password,
            'created_at' => now()->toISOString(),
        ]);

        return $this->encrypter->encrypt($credentials);
    }

    /**
     * Decrypt stored credentials
     */
    private function decryptCredentials(string $encryptedCredentials): array
    {
        try {
            $decrypted = $this->encrypter->decrypt($encryptedCredentials);

            return json_decode($decrypted, true);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to decrypt credentials');
        }
    }

    /**
     * Recreate cookie jar from stored cookie data
     */
    private function recreateCookieJar(array $cookies): CookieJar
    {
        $cookieJar = new CookieJar;

        foreach ($cookies as $cookieData) {
            $cookie = SetCookie::fromString($cookieData['Name'].'='.$cookieData['Value']);
            $cookie->setDomain($cookieData['Domain']);
            $cookie->setPath($cookieData['Path']);
            $cookie->setExpires($cookieData['Expires']);
            $cookie->setSecure($cookieData['Secure']);
            $cookie->setHttpOnly($cookieData['HttpOnly']);

            $cookieJar->setCookie($cookie);
        }

        return $cookieJar;
    }

    /**
     * Log in again with the stored credentials and swap the session cookies,
     * keeping the same session token
     */
    private function performAutoRelogin(string $sessionToken, array $session): void
    {
        $credentials = $this->decryptCredentials($session['encrypted_credentials']);
        $username = (string) ($credentials['username'] ?? $session['username']);
        $password = (string) ($credentials['password'] ?? '');

        if ($password === '') {
            throw new \RuntimeException('Stored session credentials are incomplete.');
        }

        // The login request is a slow network operation, so it runs outside
        // the session lock; the lock only guards the read-modify-write swap.
        $loginResult = $this->authService->login($username, $password);

        $sessionData = $this->mutateSession($sessionToken, function (?array $stored) use ($sessionToken, $username, $password, $loginResult): ?array {
            if ($stored === null) {
                return null;
            }

            $sessionData = $this->buildSessionData($sessionToken, $username, $loginResult);
            // Re-login is not user activity: keep the idle clock where it was.
            $sessionData['last_activity'] = $stored['last_activity'] ?? $sessionData['last_activity'];
            $sessionData['expires_at'] = $stored['expires_at'] ?? $sessionData['expires_at'];
            $sessionData['laravel_session_id'] = $stored['laravel_session_id'] ?? null;
            $sessionData[self::IDLE_EXPIRY_WATCHED] = $stored[self::IDLE_EXPIRY_WATCHED] ?? null;
            $sessionData['encrypted_credentials'] = $this->encryptCredentials($username, $password);

            return array_filter($sessionData, static fn (mixed $value): bool => $value !== null);
        });

        if ($sessionData === null) {
            throw PortalAuthenticationException::sessionExpired();
        }

        $this->logSessionEvent('auto_relogin_succeeded', sessionToken: $sessionToken, session: $sessionData);
    }

    /**
     * Deactivate a session
     *
     * When the failing operation reports the cookies it attached with, an
     * invalidation is skipped if the stored cookies have since been rotated by
     * another worker's auto-relogin — the stale report would otherwise kill a
     * freshly rotated session. Invalidation itself is best-effort: a lock
     * timeout is logged and the stored session is left untouched so the
     * caller's own error path keeps its original exception. A deactivated
     * session is never used again, so a stored copy of the password goes in
     * the same write (the idle timeout among other reasons). It stays
     * readable for ENDED_SESSION_RETENTION seconds, for reference.
     */
    private function deactivateSession(string $sessionToken, string $reason = 'manual', array $context = [], ?array $expectedCookies = null): void
    {
        $hadPasswordCopy = false;

        $deactivated = $this->mutateSession($sessionToken, function (?array $session) use ($sessionToken, $reason, $context, $expectedCookies, &$hadPasswordCopy): ?array {
            if ($session === null) {
                $this->logSessionEvent('invalidated_missing_session', level: 'warning', sessionToken: $sessionToken, context: array_merge([
                    'reason' => $reason,
                ], $context));

                return null;
            }

            if ($expectedCookies !== null && $this->cookiesRotated($session['cookies'] ?? [], $expectedCookies)) {
                $this->logSessionEvent('invalidation_skipped_session_rotated', level: 'info', sessionToken: $sessionToken, session: $session, context: array_merge([
                    'reason' => $reason,
                ], $context));

                return null;
            }

            $hadPasswordCopy = isset($session['encrypted_credentials']);
            $session['is_active'] = false;
            $session['ended_at'] = now()->toISOString();
            unset($session['encrypted_credentials']);

            $this->logSessionEvent('invalidated', level: 'warning', sessionToken: $sessionToken, session: $session, context: array_merge([
                'reason' => $reason,
            ], $context));

            return $session;
        }, self::ENDED_SESSION_RETENTION, failOnLockTimeout: false);

        if ($deactivated !== null && $hadPasswordCopy) {
            $this->untrackPasswordCopy($sessionToken, $deactivated);
        }
    }

    /**
     * Whether the stored cookies no longer match the ones the failing
     * operation started with, i.e. the session was rotated in the meantime
     *
     * @param  array<int, array<string, mixed>>  $storedCookies
     * @param  array<int, array<string, mixed>>  $attachedCookies
     */
    private function cookiesRotated(array $storedCookies, array $attachedCookies): bool
    {
        return $this->cookieFingerprint($storedCookies) !== $this->cookieFingerprint($attachedCookies);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cookies
     */
    private function cookieFingerprint(array $cookies): string
    {
        $identities = [];

        foreach ($cookies as $cookie) {
            $identities[] = implode('|', [
                (string) ($cookie['Name'] ?? ''),
                (string) ($cookie['Domain'] ?? ''),
                (string) ($cookie['Path'] ?? ''),
                (string) ($cookie['Value'] ?? ''),
            ]);
        }

        sort($identities);

        return sha1(implode("\n", $identities));
    }

    /**
     * Remove the session cache entry while holding the session lock so it
     * cannot interleave with a concurrent read-modify-write cycle
     */
    private function deleteSession(string $sessionToken): void
    {
        $lock = Cache::lock($this->sessionLockKey($sessionToken), self::SESSION_LOCK_SECONDS);

        try {
            $lock->block(self::SESSION_LOCK_SECONDS, function () use ($sessionToken): void {
                Cache::forget($this->cacheKey($sessionToken));
            });
        } catch (LockTimeoutException $exception) {
            // Cache::forget is atomic; a missed lock must not keep a session alive
            Cache::forget($this->cacheKey($sessionToken));
        }
    }

    /**
     * The username a new session is stored under: the one the login answer
     * names (the store reviewer's fixed student, PortalReviewerAccount),
     * else the one typed.
     *
     * @param  array<string, mixed>  $loginResult
     */
    private function sessionUsername(string $username, array $loginResult): string
    {
        $named = $loginResult['username'] ?? null;

        return is_string($named) && $named !== '' ? $named : $username;
    }

    private function buildSessionData(string $sessionToken, string $username, array $loginResult): array
    {
        return [
            'token' => $sessionToken,
            'username' => $username,
            'user_info' => $loginResult['user_info'],
            'cookies' => $loginResult['cookies'],
            'login_time' => $loginResult['session_data']['login_time'],
            'last_activity' => now()->toISOString(),
            'expires_at' => $this->idleExpiry(),
            'upstream_checked_at' => now()->toISOString(),
            'is_active' => true,
        ];
    }

    private function idleExpiry(): string
    {
        return now()->addSeconds((int) config('portal.session_idle_timeout', 7200))->toISOString();
    }

    private function cacheKey(string $sessionToken): string
    {
        return config('portal.session_prefix').$sessionToken;
    }

    private function logSessionEvent(
        string $event,
        string $level = 'info',
        ?string $sessionToken = null,
        ?string $username = null,
        ?array $session = null,
        array $context = []
    ): void {
        $eventContext = array_merge([
            'domain' => 'campus_session',
            'event' => $event,
            'component' => self::class,
            'session_token_fingerprint' => $sessionToken !== null
                ? $this->fingerprint($sessionToken)
                : (isset($session['token']) ? $this->fingerprint((string) $session['token']) : null),
            'username_fingerprint' => $username !== null
                ? $this->fingerprint($username)
                : (isset($session['username']) ? $this->fingerprint((string) $session['username']) : null),
            'session_state' => $session !== null ? $this->sessionStateSnapshot($session) : null,
            'metric' => [
                'name' => 'campus_session_events_total',
                'type' => 'counter',
                'value' => 1,
                'tags' => [
                    'event' => $event,
                    'component' => class_basename(static::class),
                    'level' => $level,
                ],
            ],
        ], $context);

        $eventContext = array_filter($eventContext, static fn (mixed $value): bool => $value !== null);

        match ($level) {
            'debug' => Log::debug("campus_session.{$event}", $eventContext),
            'warning' => Log::warning("campus_session.{$event}", $eventContext),
            'error' => Log::error("campus_session.{$event}", $eventContext),
            default => Log::info("campus_session.{$event}", $eventContext),
        };
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function sessionStateSnapshot(array $session): array
    {
        return array_filter([
            'is_active' => $session['is_active'] ?? null,
            'expires_at' => $session['expires_at'] ?? null,
            'last_activity' => $session['last_activity'] ?? null,
            'upstream_checked_at' => $session['upstream_checked_at'] ?? null,
            'login_time' => $session['login_time'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function fingerprint(string $value): string
    {
        return PortalLogContext::fingerprint($value);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
