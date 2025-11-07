<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalAuthenticationException;
use App\Jobs\RefreshPortalSessionJob;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalSessionManager
{
    private PortalAuthService $authService;

    private Encrypter $encrypter;

    private array $sessionData = [];

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
        try {
            // Login to campus system
            $loginResult = $this->authService->login($username, $password);

            // Generate session token
            $sessionToken = $this->generateSessionToken();

            // Prepare session data
            $this->sessionData = [
                'token' => $sessionToken,
                'username' => $username,
                'user_info' => $loginResult['user_info'],
                'cookies' => $loginResult['cookies'],
                'login_time' => $loginResult['session_data']['login_time'],
                'last_activity' => now()->toISOString(),
                'expires_at' => now()->addSeconds(config('portal.session_refresh_interval', 1200))->toISOString(),
                'is_active' => true,
            ];

            // Encrypt credentials for auto-relogin
            if (config('portal.auto_relogin', true)) {
                $this->sessionData['encrypted_credentials'] = $this->encryptCredentials($username, $password);
            }

            // Store session in cache
            $this->storeSession($sessionToken, $this->sessionData);

            // Schedule session refresh job
            $this->scheduleSessionRefresh($sessionToken);

            Log::info('Campus session created successfully', [
                'session_token' => $sessionToken,
                'username' => $username,
                'expires_at' => $this->sessionData['expires_at'],
            ]);

            return [
                'success' => true,
                'session_token' => $sessionToken,
                'user_info' => $loginResult['user_info'],
                'expires_at' => $this->sessionData['expires_at'],
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create campus session', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get existing session by token
     */
    public function getSession(string $sessionToken): ?array
    {
        $cachedSession = Cache::get(config('portal.session_prefix').$sessionToken);

        if (! $cachedSession) {
            return null;
        }

        // Update last activity
        $this->updateLastActivity($sessionToken);

        return $cachedSession;
    }

    /**
     * Validate if session is still active and valid
     */
    public function validateSession(string $sessionToken): bool
    {
        $session = $this->getSession($sessionToken);

        if (! $session) {
            return false;
        }

        // Check if session is marked as inactive
        if (! $session['is_active']) {
            return false;
        }

        // Check if session has expired
        if (now()->isAfter($session['expires_at'])) {
            $this->deactivateSession($sessionToken);

            return false;
        }

        // Optionally test session by making a request to campus
        if ($this->shouldTestSessionLiveliness($session)) {
            return $this->testSessionLiveliness($sessionToken);
        }

        return true;
    }

    /**
     * Refresh an existing session
     */
    public function refreshSession(string $sessionToken): array
    {
        $session = $this->getSession($sessionToken);

        if (! $session) {
            throw PortalAuthenticationException::sessionExpired();
        }

        try {
            // If auto-relogin is enabled and credentials are available
            if (config('portal.auto_relogin', true) && isset($session['encrypted_credentials'])) {
                return $this->performAutoRelogin($sessionToken, $session);
            }

            // Otherwise, try to extend current session
            return $this->extendCurrentSession($sessionToken, $session);

        } catch (\Exception $e) {
            Log::error('Failed to refresh campus session', [
                'session_token' => $sessionToken,
                'error' => $e->getMessage(),
            ]);

            $this->deactivateSession($sessionToken);
            throw PortalAuthenticationException::sessionExpired();
        }
    }

    /**
     * Destroy a session
     */
    public function destroySession(string $sessionToken): bool
    {
        try {
            $session = $this->getSession($sessionToken);

            if ($session) {
                // Attempt to logout from campus system
                $this->performLogout($session);
            }

            // Remove from cache
            Cache::forget(config('portal.session_prefix').$sessionToken);

            Log::info('Campus session destroyed', ['session_token' => $sessionToken]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error destroying campus session', [
                'session_token' => $sessionToken,
                'error' => $e->getMessage(),
            ]);

            // Still remove from cache even if logout fails
            Cache::forget(config('portal.session_prefix').$sessionToken);

            return false;
        }
    }

    /**
     * Get cookie jar for a session
     */
    public function getSessionCookieJar(string $sessionToken): ?CookieJar
    {
        $session = $this->getSession($sessionToken);

        if (! $session || ! isset($session['cookies'])) {
            return null;
        }

        return $this->recreateCookieJar($session['cookies']);
    }

    /**
     * Store session data in cache
     */
    private function storeSession(string $sessionToken, array $sessionData): void
    {
        $cacheKey = config('portal.session_prefix').$sessionToken;
        $ttl = config('portal.session_refresh_interval', 1200) * 2; // Double the refresh interval

        Cache::put($cacheKey, $sessionData, $ttl);
    }

    /**
     * Update last activity time for session
     */
    private function updateLastActivity(string $sessionToken): void
    {
        $cacheKey = config('portal.session_prefix').$sessionToken;
        $session = Cache::get($cacheKey);

        if ($session) {
            $session['last_activity'] = now()->toISOString();
            Cache::put($cacheKey, $session);
        }
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
        $delay = config('portal.session_refresh_interval', 1200) - 60; // 1 minute before expiry

        RefreshPortalSessionJob::dispatch($sessionToken)
            ->delay(now()->addSeconds($delay));
    }

    /**
     * Check if session liveliness should be tested
     */
    private function shouldTestSessionLiveliness(array $session): bool
    {
        $lastActivity = now()->parse($session['last_activity']);
        $testInterval = config('portal.session_refresh_interval', 1200) / 2; // Half the refresh interval

        return now()->diffInSeconds($lastActivity) > $testInterval;
    }

    /**
     * Test if session is still alive by making a request
     */
    private function testSessionLiveliness(string $sessionToken): bool
    {
        try {
            $cookieJar = $this->getSessionCookieJar($sessionToken);
            if (! $cookieJar) {
                return false;
            }

            $this->authService->setCookieJar($cookieJar);

            // Make a test request to dashboard
            $response = $this->authService->getClient()->get(config('portal.dashboard_url'), [
                'cookies' => $cookieJar,
                'timeout' => 10,
            ]);

            $html = (string) $response->getBody();

            // Check if session has expired
            if ($this->authService->isSessionExpired($html)) {
                $this->deactivateSession($sessionToken);

                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::warning('Session liveliness test failed', [
                'session_token' => $sessionToken,
                'error' => $e->getMessage(),
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

        // Create new session with same credentials
        $newSessionResult = $this->createSession(
            $credentials['username'],
            $credentials['password']
        );

        // Update existing session token with new data
        $this->sessionData['token'] = $sessionToken; // Keep original token
        $this->sessionData['username'] = $session['username']; // Keep original username
        $this->storeSession($sessionToken, $this->sessionData);

        // Schedule next refresh
        $this->scheduleSessionRefresh($sessionToken);

        Log::info('Campus session auto-relogin successful', [
            'session_token' => $sessionToken,
            'username' => $credentials['username'],
        ]);

        return $newSessionResult;
    }

    /**
     * Extend current session without relogin
     */
    private function extendCurrentSession(string $sessionToken, array $session): array
    {
        // Update expiry time
        $session['expires_at'] = now()->addSeconds(config('portal.session_refresh_interval', 1200))->toISOString();
        $session['last_activity'] = now()->toISOString();

        $this->storeSession($sessionToken, $session);
        $this->scheduleSessionRefresh($sessionToken);

        return [
            'success' => true,
            'session_token' => $sessionToken,
            'expires_at' => $session['expires_at'],
        ];
    }

    /**
     * Deactivate a session
     */
    private function deactivateSession(string $sessionToken): void
    {
        $cacheKey = config('portal.session_prefix').$sessionToken;
        $session = Cache::get($cacheKey);

        if ($session) {
            $session['is_active'] = false;
            Cache::put($cacheKey, $session, 60); // Keep for 1 minute for reference
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

            // Try to logout (this may not always be available or necessary)
            $this->authService->getClient()->get(config('portal.login_url'), [
                'cookies' => $cookieJar,
                'timeout' => 10,
            ]);

        } catch (\Exception $e) {
            Log::warning('Campus logout request failed', [
                'error' => $e->getMessage(),
            ]);
        }
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
