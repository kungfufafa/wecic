<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalAuthenticationException;
use App\Jobs\RefreshPortalSessionJob;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalSessionManager
{
    private const SESSION_LOCK_SECONDS = 10;

    private PortalAuthService $authService;

    private Encrypter $encrypter;

    public function __construct(PortalAuthService $authService, Encrypter $encrypter)
    {
        $this->authService = $authService;
        $this->encrypter = $encrypter;
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
            // Login to campus system
            $loginResult = $this->authService->login($username, $password);

            // Generate session token
            $sessionToken = $this->generateSessionToken();

            // Prepare session data
            $sessionData = $this->buildSessionData($sessionToken, $username, $loginResult);

            // Encrypt credentials for auto-relogin
            if (config('portal.auto_relogin', false)) {
                $sessionData['encrypted_credentials'] = $this->encryptCredentials($username, $password);
            }

            // Store session in cache
            $this->storeSession($sessionToken, $sessionData);

            // Schedule session refresh job
            $this->scheduleSessionRefresh($sessionToken);

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
     * Get existing session by token
     */
    public function getSession(string $sessionToken): ?array
    {
        $cachedSession = $this->loadSession($sessionToken);

        if (! $cachedSession) {
            return null;
        }

        // Update last activity
        return $this->updateLastActivity($sessionToken, $cachedSession);
    }

    /**
     * Validate if session is still active and valid
     */
    public function validateSession(string $sessionToken): bool
    {
        $startedAt = microtime(true);
        $session = $this->loadSession($sessionToken);

        if (! $session) {
            $this->logSessionEvent('validate_cache_miss', level: 'warning', sessionToken: $sessionToken, context: [
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return false;
        }

        // Check if session is marked as inactive
        if (! $session['is_active']) {
            $this->logSessionEvent('validate_inactive', level: 'info', sessionToken: $sessionToken, session: $session, context: [
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return false;
        }

        // Check if session has expired
        if (now()->isAfter($session['expires_at'])) {
            $this->deactivateSession($sessionToken, 'ttl_expired', [
                'expires_at' => $session['expires_at'] ?? null,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return false;
        }

        // Optionally test session by making a request to campus
        if ($this->shouldTestSessionLiveliness($session) && ! $this->testSessionLiveliness($sessionToken, $session)) {
            $this->logSessionEvent('validate_upstream_invalid', level: 'warning', sessionToken: $sessionToken, session: $session, context: [
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return false;
        }

        $updatedSession = $this->updateLastActivity($sessionToken, $session);
        $this->logSessionEvent('validate_succeeded', level: 'debug', sessionToken: $sessionToken, session: $updatedSession, context: [
            'upstream_check_performed' => $this->shouldTestSessionLiveliness($session),
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return true;
    }

    /**
     * Refresh an existing session
     */
    public function refreshSession(string $sessionToken): array
    {
        $startedAt = microtime(true);
        $session = $this->loadSession($sessionToken);

        if (! $session) {
            $this->logSessionEvent('refresh_rejected_missing_session', level: 'warning', sessionToken: $sessionToken, context: [
                'duration_ms' => $this->durationMs($startedAt),
            ]);
            throw PortalAuthenticationException::sessionExpired();
        }

        try {
            // If auto-relogin is enabled and credentials are available
            if (config('portal.auto_relogin', false) && isset($session['encrypted_credentials'])) {
                $result = $this->performAutoRelogin($sessionToken, $session);
                $this->logSessionEvent('refresh_succeeded', sessionToken: $sessionToken, session: $this->loadSession($sessionToken), context: [
                    'strategy' => 'auto_relogin',
                    'duration_ms' => $this->durationMs($startedAt),
                ]);

                return $result;
            }

            // Otherwise, try to extend current session
            $result = $this->extendCurrentSession($sessionToken);
            $this->logSessionEvent('refresh_succeeded', sessionToken: $sessionToken, session: $this->loadSession($sessionToken), context: [
                'strategy' => 'extend_existing',
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logSessionEvent('refresh_failed', level: 'error', sessionToken: $sessionToken, session: $session, context: [
                'error' => $e->getMessage(),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            $this->deactivateSession($sessionToken, 'refresh_failed', [
                'error' => $e->getMessage(),
            ]);
            throw PortalAuthenticationException::sessionExpired();
        }
    }

    /**
     * Destroy a session
     */
    public function destroySession(string $sessionToken): bool
    {
        $startedAt = microtime(true);
        try {
            $session = $this->loadSession($sessionToken);

            if ($session) {
                // Attempt to logout from campus system
                $this->performLogout($session);
            }

            $this->deleteSession($sessionToken);

            $this->logSessionEvent('logout_succeeded', sessionToken: $sessionToken, session: $session, context: [
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logSessionEvent('logout_failed', level: 'error', sessionToken: $sessionToken, session: $this->loadSession($sessionToken), context: [
                'error' => $e->getMessage(),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            // Still remove from cache even if logout fails
            $this->deleteSession($sessionToken);

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
    public function getSessionCookieJar(string $sessionToken, bool $touch = true): ?CookieJar
    {
        $session = $touch ? $this->getSession($sessionToken) : $this->loadSession($sessionToken);

        if (! $session || ! isset($session['cookies'])) {
            return null;
        }

        return $this->recreateCookieJar($session['cookies']);
    }

    /**
     * Store session data in cache
     */
    private function storeSession(string $sessionToken, array $sessionData, ?int $ttl = null): void
    {
        $ttl ??= config('portal.session_refresh_interval', 1200) * 2; // Double the refresh interval

        Cache::put($this->cacheKey($sessionToken), $sessionData, $ttl);
    }

    private function loadSession(string $sessionToken): ?array
    {
        $session = Cache::get($this->cacheKey($sessionToken));

        return is_array($session) ? $session : null;
    }

    /**
     * Apply a mutation to the stored session while holding an atomic lock.
     *
     * Parallel workers (scraping jobs, session refreshes, activity touches)
     * each read the whole session array and write it back; without the lock a
     * late write would resurrect stale cookies over a concurrent auto-relogin.
     * The mutator receives the freshly loaded session (null when missing) and
     * returns the session to store, or null to leave the cache untouched.
     *
     * Mutations that protect cookies (auto-relogin swap, session extension)
     * fail loudly on a lock timeout; best-effort mutations (activity touches,
     * invalidations) pass false and skip the write instead.
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
        // Deliberately outside the session key prefix: getActiveSessions scans
        // the prefix and must not stumble over raw lock entries.
        return 'campus_session_lock_'.$sessionToken;
    }

    /**
     * Update last activity time for session
     */
    private function updateLastActivity(string $sessionToken, array $session): array
    {
        return $this->mutateSession($sessionToken, static function (?array $stored): ?array {
            if ($stored === null) {
                return null;
            }

            return [...$stored, 'last_activity' => now()->toISOString()];
        }, failOnLockTimeout: false) ?? $session;
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
     * Schedule session refresh job
     */
    private function scheduleSessionRefresh(string $sessionToken): void
    {
        $delay = max(config('portal.session_refresh_interval', 1200) - 60, 0); // 1 minute before expiry

        RefreshPortalSessionJob::dispatch($sessionToken, $this->laravelSessionId($sessionToken))
            ->delay(now()->addSeconds($delay));

        $this->logSessionEvent('refresh_scheduled', level: 'debug', sessionToken: $sessionToken, session: $this->loadSession($sessionToken), context: [
            'delay_seconds' => $delay,
        ]);
    }

    /**
     * Check if session liveliness should be tested
     */
    private function shouldTestSessionLiveliness(array $session): bool
    {
        $lastActivity = now()->parse($session['last_activity']);
        $testInterval = config('portal.session_refresh_interval', 1200) / 2; // Half the refresh interval

        return $lastActivity->diffInSeconds(now()) > $testInterval;
    }

    /**
     * Test if session is still alive by making a request
     */
    private function testSessionLiveliness(string $sessionToken, array $session): bool
    {
        $startedAt = microtime(true);

        try {
            if (! isset($session['cookies']) || ! is_array($session['cookies']) || $session['cookies'] === []) {
                $this->logSessionEvent('liveness_check_failed_missing_cookies', level: 'warning', sessionToken: $sessionToken, session: $session, context: [
                    'duration_ms' => $this->durationMs($startedAt),
                ]);

                return false;
            }

            $cookieJar = $this->recreateCookieJar($session['cookies'] ?? []);
            $this->authService->setCookieJar($cookieJar);

            // Make a test request to dashboard
            $dashboardUrl = PortalEndpointRegistry::url('dashboard');
            $response = $this->authService->getClient()->get($dashboardUrl, [
                'cookies' => $cookieJar,
                'timeout' => 10,
            ]);

            $html = (string) $response->getBody();

            // Check if session has expired
            if ($this->authService->isSessionExpired($html)) {
                $this->deactivateSession($sessionToken, 'upstream_session_expired', [
                    'duration_ms' => $this->durationMs($startedAt),
                ]);

                return false;
            }

            $this->logSessionEvent('liveness_check_succeeded', level: 'debug', sessionToken: $sessionToken, session: $session, context: [
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logSessionEvent('liveness_check_failed', level: 'warning', sessionToken: $sessionToken, session: $session, context: [
                'error' => $e->getMessage(),
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return false;
        }
    }

    /**
     * Perform automatic relogin
     */
    private function performAutoRelogin(string $sessionToken, array $session): array
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
            $sessionData['laravel_session_id'] = $stored['laravel_session_id'] ?? null;
            $sessionData['encrypted_credentials'] = $stored['encrypted_credentials'] ?? null;

            if (config('portal.auto_relogin', false)) {
                $sessionData['encrypted_credentials'] = $this->encryptCredentials($username, $password);
            }

            return array_filter($sessionData, static fn (mixed $value): bool => $value !== null);
        });

        if ($sessionData === null) {
            throw PortalAuthenticationException::sessionExpired();
        }

        // Schedule next refresh
        $this->scheduleSessionRefresh($sessionToken);

        return [
            'success' => true,
            'session_token' => $sessionToken,
            'expires_at' => $sessionData['expires_at'],
        ];
    }

    /**
     * Extend current session without relogin
     */
    private function extendCurrentSession(string $sessionToken): array
    {
        $sessionData = $this->mutateSession($sessionToken, static function (?array $stored): ?array {
            if ($stored === null) {
                return null;
            }

            $stored['expires_at'] = now()->addSeconds(config('portal.session_refresh_interval', 1200))->toISOString();
            $stored['last_activity'] = now()->toISOString();

            return $stored;
        });

        if ($sessionData === null) {
            throw PortalAuthenticationException::sessionExpired();
        }

        $this->scheduleSessionRefresh($sessionToken);

        return [
            'success' => true,
            'session_token' => $sessionToken,
            'expires_at' => $sessionData['expires_at'],
        ];
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

    /**
     * Perform logout from campus system
     */
    private function performLogout(array $session): void
    {
        try {
            $cookieJar = $this->recreateCookieJar($session['cookies']);
            $this->authService->setCookieJar($cookieJar);
            $client = $this->authService->getClient();

            foreach (['student_logout', 'sipandai_logout'] as $endpoint) {
                try {
                    $client->request(PortalEndpointRegistry::method($endpoint), PortalEndpointRegistry::url($endpoint), [
                        'cookies' => $cookieJar,
                        'timeout' => 10,
                    ]);
                } catch (\Exception $e) {
                    $this->logSessionEvent('logout_endpoint_failed', level: 'warning', session: $session, context: [
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            $this->logSessionEvent('logout_request_failed', level: 'warning', session: $session, context: [
                'error' => $e->getMessage(),
            ]);
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
            'expires_at' => now()->addSeconds(config('portal.session_refresh_interval', 1200))->toISOString(),
            'is_active' => true,
        ];
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

    /**
     * Get all active sessions (for admin/debugging)
     */
    public function getActiveSessions(): array
    {
        $sessions = [];
        $prefix = config('portal.session_prefix');

        // This is a simplified approach - in production, you might want to use
        // a more efficient method to track active sessions
        $cacheKeys = Cache::getRedis()?->keys("{$prefix}*") ?? [];

        foreach ($cacheKeys as $key) {
            $session = Cache::get($key);
            if ($session && $session['is_active']) {
                $sessions[] = [
                    'token' => $session['token'],
                    'username' => $session['username'],
                    'login_time' => $session['login_time'],
                    'last_activity' => $session['last_activity'],
                    'expires_at' => $session['expires_at'],
                ];
            }
        }

        return $sessions;
    }
}
