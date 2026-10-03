<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalAuthService;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSessionManager;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PortalSessionManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_refresh_session_auto_relogin_reuses_existing_token_without_creating_orphan_jobs_or_cache_entries(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_refresh_',
            'portal.session_refresh_interval' => 1200,
            'portal.auto_relogin' => true,
        ]);

        Cache::flush();
        Queue::fake();
        Log::spy();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('login')
            ->once()
            ->with('20251030030', 'top-secret')
            ->andReturn($this->makeLoginResult([
                'nama' => 'Mahasiswa Refresh',
            ]));

        $encrypter = Mockery::mock(Encrypter::class);
        $encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted-credentials')
            ->andReturn(json_encode([
                'username' => '20251030030',
                'password' => 'top-secret',
            ]));
        $encrypter->shouldReceive('encrypt')
            ->once()
            ->andReturn('re-encrypted-credentials');

        $manager = new PortalSessionManager($authService, $encrypter);
        $cacheKey = config('portal.session_prefix').'existing-token';

        Cache::put($cacheKey, [
            'token' => 'existing-token',
            'username' => '20251030030',
            'user_info' => ['nama' => 'Mahasiswa Lama'],
            'cookies' => [],
            'login_time' => now()->subMinutes(5)->toISOString(),
            'last_activity' => now()->subMinutes(1)->toISOString(),
            'expires_at' => now()->addMinutes(5)->toISOString(),
            'is_active' => true,
            'encrypted_credentials' => 'encrypted-credentials',
        ], 1200);

        $result = $manager->refreshSession('existing-token');
        $storedSession = Cache::get($cacheKey);
        $sessionCacheKeys = array_filter(
            array_keys($this->cacheEntries()),
            static fn (string $key): bool => str_starts_with($key, config('portal.session_prefix'))
        );

        $this->assertSame('existing-token', $result['session_token']);
        $this->assertSame('existing-token', $storedSession['token']);
        $this->assertSame('20251030030', $storedSession['username']);
        $this->assertSame('re-encrypted-credentials', $storedSession['encrypted_credentials']);
        $this->assertCount(1, $sessionCacheKeys);

        Queue::assertPushed(RefreshPortalSessionJob::class, 1);
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'campus_session.refresh_succeeded'
                    && $context['domain'] === 'campus_session'
                    && ($context['metric']['name'] ?? null) === 'campus_session_events_total'
                    && ($context['strategy'] ?? null) === 'auto_relogin'
                    && isset($context['session_token_fingerprint']);
            })
            ->once();
    }

    public function test_get_session_keeps_cache_entry_expiring_when_last_activity_is_updated(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_touch_',
            'portal.session_refresh_interval' => 600,
            'portal.auto_relogin' => false,
        ]);

        Cache::flush();
        Queue::fake();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('login')
            ->once()
            ->with('20251030031', 'touch-pass')
            ->andReturn($this->makeLoginResult([
                'nama' => 'Mahasiswa Touch',
            ]));

        $manager = new PortalSessionManager($authService, Mockery::mock(Encrypter::class));

        $createdSession = $manager->createSession('20251030031', 'touch-pass');
        $cacheKey = config('portal.session_prefix').$createdSession['session_token'];
        $beforeTouch = $this->cacheEntries()[$cacheKey]['expiresAt'];

        $session = $manager->getSession($createdSession['session_token']);
        $afterTouch = $this->cacheEntries()[$cacheKey]['expiresAt'];

        $this->assertNotNull($session);
        $this->assertGreaterThan(0, $afterTouch);
        $this->assertGreaterThanOrEqual($beforeTouch, $afterTouch);
    }

    public function test_destroy_session_calls_upstream_logout_endpoints_before_clearing_cache(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_logout_',
        ]);

        Cache::flush();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('request')
            ->once()
            ->ordered()
            ->with(
                'GET',
                PortalEndpointRegistry::url('student_logout'),
                Mockery::on(fn (array $options): bool => ($options['timeout'] ?? null) === 10 && ($options['cookies'] ?? null) instanceof CookieJar)
            )
            ->andReturn(new Response(200));
        $client->shouldReceive('request')
            ->once()
            ->ordered()
            ->with(
                'GET',
                PortalEndpointRegistry::url('sipandai_logout'),
                Mockery::on(fn (array $options): bool => ($options['timeout'] ?? null) === 10 && ($options['cookies'] ?? null) instanceof CookieJar)
            )
            ->andReturn(new Response(200));

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('setCookieJar')
            ->once()
            ->with(Mockery::type(CookieJar::class));
        $authService->shouldReceive('getClient')
            ->once()
            ->andReturn($client);

        $manager = new PortalSessionManager($authService, Mockery::mock(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'logout-token';

        Cache::put($cacheKey, [
            'token' => 'logout-token',
            'username' => '20251030032',
            'user_info' => ['nama' => 'Mahasiswa Logout'],
            'cookies' => [[
                'Name' => 'PHPSESSID',
                'Value' => 'cookie-value',
                'Domain' => 'my.cic.ac.id',
                'Path' => '/',
                'Expires' => null,
                'Secure' => false,
                'HttpOnly' => true,
            ]],
            'login_time' => now()->subMinutes(5)->toISOString(),
            'last_activity' => now()->subMinute()->toISOString(),
            'expires_at' => now()->addMinutes(5)->toISOString(),
            'is_active' => true,
        ], 1200);

        $this->assertTrue($manager->destroySession('logout-token'));
        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_create_session_does_not_store_encrypted_credentials_by_default(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_no_credentials_',
            'portal.session_refresh_interval' => 1200,
            'portal.auto_relogin' => false,
        ]);

        Cache::flush();
        Queue::fake();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('login')
            ->once()
            ->with('20251030036', 'login-pass')
            ->andReturn($this->makeLoginResult([
                'nama' => 'Mahasiswa No Credentials',
            ]));

        $manager = new PortalSessionManager($authService, Mockery::mock(Encrypter::class));

        $result = $manager->createSession('20251030036', 'login-pass');
        $storedSession = Cache::get(config('portal.session_prefix').$result['session_token']);

        $this->assertArrayNotHasKey('encrypted_credentials', $storedSession);
    }

    public function test_forget_stored_credentials_removes_credentials_without_destroying_session(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_forget_credentials_',
            'portal.session_refresh_interval' => 1200,
        ]);

        Cache::flush();

        $manager = new PortalSessionManager(
            Mockery::mock(PortalAuthService::class),
            Mockery::mock(Encrypter::class)
        );

        Cache::put(config('portal.session_prefix').'token-with-credentials', $this->makeStoredSession('token-with-credentials', [
            'encrypted_credentials' => 'encrypted-credentials',
        ]), 1200);

        $manager->forgetStoredCredentials('token-with-credentials');

        $storedSession = Cache::get(config('portal.session_prefix').'token-with-credentials');

        $this->assertNotNull($storedSession);
        $this->assertArrayNotHasKey('encrypted_credentials', $storedSession);
        $this->assertTrue($storedSession['is_active']);
    }

    public function test_create_session_emits_structured_login_observability_log(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_login_log_',
            'portal.session_refresh_interval' => 1200,
            'portal.auto_relogin' => false,
        ]);

        Cache::flush();
        Queue::fake();
        Log::spy();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('login')
            ->once()
            ->with('20251030035', 'login-pass')
            ->andReturn($this->makeLoginResult([
                'nama' => 'Mahasiswa Login',
            ]));

        $manager = new PortalSessionManager($authService, Mockery::mock(Encrypter::class));

        $result = $manager->createSession('20251030035', 'login-pass');

        $this->assertArrayHasKey('session_token', $result);
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'campus_session.login_succeeded'
                    && $context['domain'] === 'campus_session'
                    && ($context['metric']['name'] ?? null) === 'campus_session_events_total'
                    && isset($context['session_token_fingerprint'])
                    && isset($context['username_fingerprint'])
                    && ! array_key_exists('username', $context);
            })
            ->once();
    }

    public function test_validate_session_checks_upstream_when_session_is_stale(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_validate_',
            'portal.session_refresh_interval' => 1200,
        ]);

        Cache::flush();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->with(
                PortalEndpointRegistry::url('dashboard'),
                Mockery::on(fn (array $options): bool => ($options['timeout'] ?? null) === 10 && ($options['cookies'] ?? null) instanceof CookieJar)
            )
            ->andReturn(new Response(200, [], '<html><body>dashboard</body></html>'));

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('setCookieJar')
            ->once()
            ->with(Mockery::type(CookieJar::class));
        $authService->shouldReceive('getClient')
            ->once()
            ->andReturn($client);
        $authService->shouldReceive('isSessionExpired')
            ->once()
            ->with('<html><body>dashboard</body></html>')
            ->andReturnFalse();

        $manager = new PortalSessionManager($authService, Mockery::mock(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'stale-token';
        $session = $this->makeStoredSession('stale-token', [
            'last_activity' => now()->subMinutes(11)->toISOString(),
        ]);

        Cache::put($cacheKey, $session, 1200);

        $this->assertTrue($manager->validateSession('stale-token'));

        $updatedSession = Cache::get($cacheKey);
        $this->assertTrue($updatedSession['is_active']);
        $this->assertNotSame($session['last_activity'], $updatedSession['last_activity']);
    }

    public function test_validate_session_marks_session_inactive_when_upstream_is_expired(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_expired_',
            'portal.session_refresh_interval' => 1200,
        ]);

        Cache::flush();
        Log::spy();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->with(
                PortalEndpointRegistry::url('dashboard'),
                Mockery::on(fn (array $options): bool => ($options['timeout'] ?? null) === 10 && ($options['cookies'] ?? null) instanceof CookieJar)
            )
            ->andReturn(new Response(200, [], '<html><body>expired</body></html>'));

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('setCookieJar')
            ->once()
            ->with(Mockery::type(CookieJar::class));
        $authService->shouldReceive('getClient')
            ->once()
            ->andReturn($client);
        $authService->shouldReceive('isSessionExpired')
            ->once()
            ->with('<html><body>expired</body></html>')
            ->andReturnTrue();

        $manager = new PortalSessionManager($authService, Mockery::mock(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'expired-token';

        Cache::put($cacheKey, $this->makeStoredSession('expired-token', [
            'last_activity' => now()->subMinutes(11)->toISOString(),
        ]), 1200);

        $this->assertFalse($manager->validateSession('expired-token'));
        $this->assertFalse(Cache::get($cacheKey)['is_active']);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'campus_session.invalidated'
                    && $context['domain'] === 'campus_session'
                    && ($context['reason'] ?? null) === 'upstream_session_expired'
                    && ($context['metric']['name'] ?? null) === 'campus_session_events_total';
            })
            ->once();
    }

    public function test_invalidate_session_skipped_when_cookies_were_rotated_by_relogin(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_rotated_',
        ]);

        Cache::flush();
        Log::spy();

        $manager = new PortalSessionManager(
            Mockery::mock(PortalAuthService::class),
            Mockery::mock(Encrypter::class)
        );

        $staleCookies = [[
            'Name' => 'PHPSESSID',
            'Value' => 'stale-cookie-value',
            'Domain' => 'my.cic.ac.id',
            'Path' => '/',
            'Expires' => null,
            'Secure' => false,
            'HttpOnly' => true,
        ]];

        $freshCookies = [[
            'Name' => 'PHPSESSID',
            'Value' => 'fresh-cookie-value',
            'Domain' => 'my.cic.ac.id',
            'Path' => '/',
            'Expires' => null,
            'Secure' => false,
            'HttpOnly' => true,
        ]];

        $cacheKey = config('portal.session_prefix').'rotated-token';
        Cache::put($cacheKey, $this->makeStoredSession('rotated-token', ['cookies' => $freshCookies]), 1200);

        $manager->invalidateSession('rotated-token', 'session_expired_indicator_detected', [], $staleCookies);

        $storedSession = Cache::get($cacheKey);
        $this->assertTrue($storedSession['is_active']);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'campus_session.invalidation_skipped_session_rotated'
                    && ($context['reason'] ?? null) === 'session_expired_indicator_detected';
            })
            ->once();
    }

    public function test_invalidate_session_deactivates_when_attached_cookies_still_match(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_invalidated_',
        ]);

        Cache::flush();
        Log::spy();

        $manager = new PortalSessionManager(
            Mockery::mock(PortalAuthService::class),
            Mockery::mock(Encrypter::class)
        );

        $cookies = [[
            'Name' => 'PHPSESSID',
            'Value' => 'cookie-value',
            'Domain' => 'my.cic.ac.id',
            'Path' => '/',
            'Expires' => null,
            'Secure' => false,
            'HttpOnly' => true,
        ]];

        $cacheKey = config('portal.session_prefix').'invalidated-token';
        Cache::put($cacheKey, $this->makeStoredSession('invalidated-token'), 1200);

        $manager->invalidateSession('invalidated-token', 'session_expired_indicator_detected', [], $cookies);

        $this->assertFalse(Cache::get($cacheKey)['is_active']);
    }

    public function test_invalidate_session_is_best_effort_when_the_session_lock_times_out(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_lock_timeout_',
        ]);

        Cache::flush();
        Log::spy();

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->andThrow(new LockTimeoutException);

        Cache::shouldReceive('lock')
            ->once()
            ->andReturn($lock);
        Cache::shouldReceive('flush'); // tearDown still flushes while the facade is mocked

        $manager = new PortalSessionManager(
            Mockery::mock(PortalAuthService::class),
            Mockery::mock(Encrypter::class)
        );

        $this->assertNull($manager->invalidateSession('lock-timeout-token', 'session_expired_indicator_detected'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'campus_session.mutation_lock_timeout')
            ->once();
    }

    public function test_get_session_touch_is_best_effort_when_the_session_lock_times_out(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_touch_timeout_',
        ]);

        Cache::flush();
        Log::spy();

        $storedSession = $this->makeStoredSession('touch-timeout-token');

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->andThrow(new LockTimeoutException);

        Cache::shouldReceive('get')
            ->once()
            ->andReturn($storedSession);
        Cache::shouldReceive('put')->never();
        Cache::shouldReceive('lock')
            ->once()
            ->andReturn($lock);
        Cache::shouldReceive('flush'); // tearDown still flushes while the facade is mocked

        $manager = new PortalSessionManager(
            Mockery::mock(PortalAuthService::class),
            Mockery::mock(Encrypter::class)
        );

        $this->assertSame($storedSession, $manager->getSession('touch-timeout-token'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'campus_session.mutation_lock_timeout')
            ->once();
    }

    /**
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    private function cacheEntries(): array
    {
        $store = Cache::getStore();

        $this->assertInstanceOf(ArrayStore::class, $store);

        return $store->all();
    }

    /**
     * @param  array<string, mixed>  $userInfo
     * @return array<string, mixed>
     */
    private function makeLoginResult(array $userInfo): array
    {
        return [
            'success' => true,
            'user_info' => $userInfo,
            'cookies' => [],
            'session_data' => [
                'login_time' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeStoredSession(string $token, array $overrides = []): array
    {
        return array_merge([
            'token' => $token,
            'username' => '20251030030',
            'user_info' => ['nama' => 'Mahasiswa Uji'],
            'cookies' => [[
                'Name' => 'PHPSESSID',
                'Value' => 'cookie-value',
                'Domain' => 'my.cic.ac.id',
                'Path' => '/',
                'Expires' => null,
                'Secure' => false,
                'HttpOnly' => true,
            ]],
            'login_time' => now()->subMinutes(5)->toISOString(),
            'last_activity' => now()->subMinute()->toISOString(),
            'expires_at' => now()->addMinutes(5)->toISOString(),
            'is_active' => true,
        ], $overrides);
    }
}
