<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Jobs\RefreshPortalSessionJob;
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
 */
class PortalSessionManager
{
    private const SESSION_LOCK_SECONDS = 10;

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
            $sessionToken = $this->generateSessionToken();
            $sessionData = $this->buildSessionData($sessionToken, $username, $loginResult);

            if (config('portal.auto_relogin', false)) {
                $sessionData['encrypted_credentials'] = $this->encryptCredentials($username, $password);
            }

            $this->storeSession($sessionToken, $sessionData);
            $this->scheduleKeepAlive($sessionToken);

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
     * @return 'alive'|'relogged'|'expired'|'idle'|'inactive'|'missing'
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

        $cookies = is_array($session['cookies'] ?? null) ? $session['cookies'] : [];

        if ($cookies !== [] && $this->authService->isSessionAlive($this->recreateCookieJar($cookies))) {
            $this->mutateSession($sessionToken, static function (?array $stored): ?array {
                return $stored === null ? null : [...$stored, 'upstream_checked_at' => now()->toISOString()];
            }, failOnLockTimeout: false);
            $this->logSessionEvent('keepalive_succeeded', level: 'debug', sessionToken: $sessionToken, session: $session);

            return 'alive';
        }

        if (config('portal.auto_relogin', false) && isset($session['encrypted_credentials'])) {
            try {
                $this->performAutoRelogin($sessionToken, $session);

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
     */
    public function destroySession(string $sessionToken): bool
    {
        $startedAt = microtime(true);
        $session = $this->loadSession($sessionToken);

        try {
            if (is_array($session['cookies'] ?? null)) {
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
        } finally {
            // Always drop the local session, even when the upstream logout fails
            $this->deleteSession($sessionToken);
            $this->dataOwner->forget($sessionToken);
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
    private function isIdleExpired(array $session): bool
    {
        $expiresAt = $session['expires_at'] ?? null;

        return ! is_string($expiresAt) || CarbonImmutable::parse($expiresAt)->isPast();
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
     * @param  array<string, mixed>  $session
     */
    private function keepAliveLapsed(array $session): bool
    {
        $checkedAt = $session['upstream_checked_at'] ?? null;

        return ! is_string($checkedAt)
            || CarbonImmutable::parse($checkedAt)
                ->addSeconds(2 * (int) config('portal.session_refresh_interval', 1200))
                ->isPast();
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function endIdleSession(string $sessionToken, array $session): void
    {
        $this->deactivateSession($sessionToken, 'idle_timeout', [
            'expires_at' => $session['expires_at'] ?? null,
        ]);

        if (is_array($session['cookies'] ?? null) && $session['cookies'] !== []) {
            $this->authService->logout($this->recreateCookieJar($session['cookies']));
        }
    }

    /**
     * Store session data in cache
     *
     * The entry outlives the idle timeout slightly, so an idle session is
     * observed as expired (and logged) rather than silently missing.
     */
    private function storeSession(string $sessionToken, array $sessionData, ?int $ttl = null): void
    {
        $ttl ??= (int) config('portal.session_idle_timeout', 7200) + (int) config('portal.session_refresh_interval', 1200);

        Cache::put($this->cacheKey($sessionToken), $sessionData, $ttl);

        if (isset($sessionData['username']) && is_string($sessionData['username'])) {
            $this->dataOwner->remember($sessionToken, $sessionData['username'], $ttl);
        }
    }

    private function loadSession(string $sessionToken): ?array
    {
        $session = Cache::get($this->cacheKey($sessionToken));

        return is_array($session) ? $session : null;
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
     * caller's own error path keeps its original exception.
     */
    private function deactivateSession(string $sessionToken, string $reason = 'manual', array $context = [], ?array $expectedCookies = null): void
    {
        $this->mutateSession($sessionToken, function (?array $session) use ($sessionToken, $reason, $context, $expectedCookies): ?array {
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

            $session['is_active'] = false;

            $this->logSessionEvent('invalidated', level: 'warning', sessionToken: $sessionToken, session: $session, context: array_merge([
                'reason' => $reason,
            ], $context));

            return $session;
        }, 60, failOnLockTimeout: false); // Keep for 1 minute for reference
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
