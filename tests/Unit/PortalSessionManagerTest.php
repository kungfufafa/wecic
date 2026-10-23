<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalAuthService;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
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
            ->with('20990000001', 'top-secret')
            ->andReturn($this->makeLoginResult([
                'nama' => 'Mahasiswa Refresh',
            ]));

        $encrypter = Mockery::mock(Encrypter::class);
        $encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted-credentials')
            ->andReturn(json_encode([
                'username' => '20990000001',
                'password' => 'top-secret',
            ]));
        $encrypter->shouldReceive('encrypt')
            ->once()
            ->andReturn('re-encrypted-credentials');

        $manager = new PortalSessionManager($authService, $encrypter);
        $cacheKey = config('portal.session_prefix').'existing-token';

        Cache::put($cacheKey, [
            'token' => 'existing-token',
            'username' => '20990000001',
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
        $this->assertSame('20990000001', $storedSession['username']);
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

    public function test_destroy_session_calls_upstream_logout_endpoints_and_clears_cache(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_logout_',
        ]);

        Cache::flush();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('logout')
            ->once()
            ->with(Mockery::on(fn (CookieJar $jar): bool => $jar->getCookieByName('PHPSESSID')?->getValue() === 'cookie-value'));

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'logout-token';

        Cache::put($cacheKey, [
            'token' => 'logout-token',
            'username' => '20990000032',
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
            ->with('20990000036', 'login-pass')
            ->andReturn($this->makeLoginResult([
                'nama' => 'Mahasiswa No Credentials',
            ]));

        $manager = new PortalSessionManager($authService, app(Encrypter::class));

        $result = $manager->createSession('20990000036', 'login-pass');
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
            app(Encrypter::class)
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
            ->with('20990000035', 'login-pass')
            ->andReturn($this->makeLoginResult([
                'nama' => 'Mahasiswa Login',
            ]));

        $manager = new PortalSessionManager($authService, app(Encrypter::class));

        $result = $manager->createSession('20990000035', 'login-pass');

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

    public function test_validate_session_is_local_and_slides_the_idle_expiry(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_validate_',
            'portal.session_idle_timeout' => 7200,
            'portal.session_activity_touch_interval' => 60,
        ]);

        Queue::fake();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldNotReceive('isSessionAlive');

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'active-token';
        $session = $this->makeStoredSession('active-token', [
            'last_activity' => now()->subMinutes(11)->toISOString(),
            'upstream_checked_at' => now()->subMinutes(5)->toISOString(),
        ]);

        Cache::put($cacheKey, $session, 1200);

        $this->assertTrue($manager->validateSession('active-token'));

        $updatedSession = Cache::get($cacheKey);
        $this->assertNotSame($session['last_activity'], $updatedSession['last_activity']);
        $this->assertTrue(CarbonImmutable::parse($updatedSession['expires_at'])->greaterThan(now()->addMinutes(119)));
        Queue::assertNothingPushed();
    }

    public function test_validate_session_writes_activity_at_most_once_per_touch_interval(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_touch_throttle_',
            'portal.session_activity_touch_interval' => 60,
        ]);

        $manager = new PortalSessionManager(Mockery::mock(PortalAuthService::class), app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'recent-token';
        $session = $this->makeStoredSession('recent-token', [
            'last_activity' => now()->subSeconds(10)->toISOString(),
            'upstream_checked_at' => now()->toISOString(),
        ]);

        Cache::put($cacheKey, $session, 1200);

        $this->assertTrue($manager->validateSession('recent-token'));
        $this->assertSame($session, Cache::get($cacheKey));
    }

    public function test_was_active_within_reads_the_last_recorded_activity_without_touching_it(): void
    {
        config(['portal.session_prefix' => 'portal_session_activity_window_']);

        $manager = new PortalSessionManager(Mockery::mock(PortalAuthService::class), app(Encrypter::class));
        $prefix = config('portal.session_prefix');
        Cache::put($prefix.'recent-token', $this->makeStoredSession('recent-token', ['last_activity' => now()->subMinutes(5)->toISOString()]), 1200);
        Cache::put($prefix.'closed-tab-token', $this->makeStoredSession('closed-tab-token', ['last_activity' => now()->subMinutes(45)->toISOString()]), 1200);
        Cache::put($prefix.'no-activity-token', $this->makeStoredSession('no-activity-token', ['last_activity' => null]), 1200);
        $before = Cache::get($prefix.'closed-tab-token');

        $this->assertTrue($manager->wasActiveWithin('recent-token', 1200));
        $this->assertFalse($manager->wasActiveWithin('closed-tab-token', 1200));
        $this->assertFalse($manager->wasActiveWithin('no-activity-token', 1200));
        $this->assertFalse($manager->wasActiveWithin('unknown-token', 1200));
        // A background check is not activity: nothing is written.
        $this->assertSame($before, Cache::get($prefix.'closed-tab-token'));
    }

    public function test_validate_session_ends_a_session_idle_past_the_timeout(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_idle_',
        ]);

        Log::spy();

        $manager = new PortalSessionManager(Mockery::mock(PortalAuthService::class), app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'idle-token';

        Cache::put($cacheKey, $this->makeStoredSession('idle-token', [
            'expires_at' => now()->subMinute()->toISOString(),
        ]), 1200);

        $this->assertFalse($manager->validateSession('idle-token'));
        $this->assertFalse(Cache::get($cacheKey)['is_active']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'campus_session.invalidated'
                && ($context['reason'] ?? null) === 'idle_timeout')
            ->once();
    }

    public function test_validate_session_touch_is_best_effort_when_the_session_lock_times_out(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_touch_timeout_',
        ]);

        Log::spy();

        $storedSession = $this->makeStoredSession('touch-timeout-token', [
            'last_activity' => now()->subMinutes(5)->toISOString(),
            'upstream_checked_at' => now()->toISOString(),
        ]);

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
            app(Encrypter::class),
            Mockery::mock(PortalDataOwner::class)
        );

        $this->assertTrue($manager->validateSession('touch-timeout-token'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'campus_session.mutation_lock_timeout')
            ->once();
    }

    public function test_keep_alive_pings_the_portal_and_records_the_check(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_keepalive_',
        ]);

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('isSessionAlive')->once()->with(Mockery::type(CookieJar::class))->andReturnTrue();

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'alive-token';
        $session = $this->makeStoredSession('alive-token', [
            'upstream_checked_at' => now()->subMinutes(20)->toISOString(),
        ]);

        Cache::put($cacheKey, $session, 1200);

        $this->assertSame('alive', $manager->keepAlive('alive-token'));

        $updatedSession = Cache::get($cacheKey);
        $this->assertNotSame($session['upstream_checked_at'], $updatedSession['upstream_checked_at']);
        // A keep-alive ping is not user activity.
        $this->assertSame($session['last_activity'], $updatedSession['last_activity']);
        $this->assertSame($session['expires_at'], $updatedSession['expires_at']);
    }

    public function test_keep_alive_marks_session_inactive_when_upstream_is_expired(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_expired_',
            'portal.auto_relogin' => false,
        ]);

        Log::spy();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('isSessionAlive')->once()->andReturnFalse();

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'expired-token';

        Cache::put($cacheKey, $this->makeStoredSession('expired-token'), 1200);

        $this->assertSame('expired', $manager->keepAlive('expired-token'));
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

    public function test_keep_alive_stops_and_logs_out_an_idle_session(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_keepalive_idle_',
        ]);

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldNotReceive('isSessionAlive');
        $authService->shouldReceive('logout')->once()->with(Mockery::type(CookieJar::class));

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'abandoned-token';

        Cache::put($cacheKey, $this->makeStoredSession('abandoned-token', [
            'expires_at' => now()->subMinute()->toISOString(),
        ]), 1200);

        $this->assertSame('idle', $manager->keepAlive('abandoned-token'));
        $this->assertFalse(Cache::get($cacheKey)['is_active']);
    }

    public function test_keep_alive_leaves_session_untouched_when_portal_is_unreachable(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_keepalive_network_',
        ]);

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('isSessionAlive')->once()->andThrow(PortalConnectionException::networkError('https://my.cic.ac.id', 'timeout'));

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'network-token';
        $session = $this->makeStoredSession('network-token');

        Cache::put($cacheKey, $session, 1200);

        try {
            $manager->keepAlive('network-token');
            $this->fail('A network failure must surface so the keep-alive job can retry.');
        } catch (PortalConnectionException) {
            $this->assertSame($session, Cache::get($cacheKey));
        }
    }

    public function test_create_session_records_the_student_as_data_owner(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_owner_',
            'portal.auto_relogin' => false,
        ]);

        Queue::fake();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('login')->twice()->andReturn($this->makeLoginResult(['nama' => 'Mahasiswa Owner']));

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $first = $manager->createSession('20990000040', 'secret');
        $second = $manager->createSession(' 20990000040 ', 'secret');

        $owner = app(PortalDataOwner::class);
        $this->assertSame($owner->key($first['session_token']), $owner->key($second['session_token']));
        $this->assertSame($owner->keyForUsername('20990000040'), $owner->key($first['session_token']));

        Queue::assertPushed(RefreshPortalSessionJob::class, 2);
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
            app(Encrypter::class)
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
            app(Encrypter::class)
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
            app(Encrypter::class)
        );

        $this->assertNull($manager->invalidateSession('lock-timeout-token', 'session_expired_indicator_detected'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'campus_session.mutation_lock_timeout')
            ->once();
    }

    public function test_a_resume_the_portal_did_not_visibly_refuse_is_tried_again(): void
    {
        config(['portal.session_prefix' => 'portal_session_resume_unconfirmed_']);

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('login')->once()->andThrow(PortalAuthService::unconfirmedRefusal(PortalAuthenticationException::sessionExpired()));

        $encrypter = app(Encrypter::class);
        $manager = new PortalSessionManager($authService, $encrypter);
        $cacheKey = config('portal.session_prefix').'offline-token';
        $session = $this->makeOfflineSession('offline-token', [
            'encrypted_credentials' => $encrypter->encrypt(json_encode(['username' => '20990000001', 'password' => 'login-pass'])),
        ]);
        Cache::put($cacheKey, $session, 1200);

        $this->assertSame('unreachable', $manager->resumeOfflineSession('offline-token'));
        $this->assertSame($session, Cache::get($cacheKey));
        $this->assertTrue($manager->isSessionUsable('offline-token'));
    }

    public function test_a_refused_resume_that_could_not_end_the_session_is_tried_again(): void
    {
        config(['portal.session_prefix' => 'portal_session_resume_lock_busy_']);

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('login')->once()->andThrow(PortalAuthenticationException::invalidCredentials());

        $encrypter = app(Encrypter::class);
        $manager = new PortalSessionManager($authService, $encrypter);
        $cacheKey = config('portal.session_prefix').'offline-token';
        $session = $this->makeOfflineSession('offline-token', [
            'encrypted_credentials' => $encrypter->encrypt(json_encode(['username' => '20990000001', 'password' => 'login-pass'])),
        ]);
        Cache::put($cacheKey, $session, 1200);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
        $cache = Mockery::mock(app('cache'));
        $cache->shouldReceive('lock')->andReturn($lock);
        Cache::swap($cache);

        // The session stays open with its password, so a later attempt can still end it.
        $this->assertSame('unreachable', $manager->resumeOfflineSession('offline-token'));
        $this->assertSame($session, Cache::get($cacheKey));
    }

    public function test_a_portal_back_note_a_busy_lock_kept_out_is_written_by_the_next_attempt(): void
    {
        config(['portal.session_prefix' => 'portal_session_portal_back_lock_busy_']);

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('analyzeLoginForm')->twice()->andReturn(['action' => 'https://portal.invalid/portal/mahasiswa/?mod=loginact', 'method' => 'post', 'fields' => []]);
        $authService->shouldReceive('login')->never();

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'offline-token';
        $session = $this->makeOfflineSession('offline-token');
        Cache::put($cacheKey, $session, 1200);

        $cache = app('cache');
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
        $busyCache = Mockery::mock($cache);
        $busyCache->shouldReceive('lock')->andReturn($lock);
        Cache::swap($busyCache);

        // Not noted yet, so the student is not told yet either: the chain tries again.
        $this->assertSame('unreachable', $manager->resumeOfflineSession('offline-token'));
        $this->assertSame('notify', $manager->offlineReconnectMode('offline-token'));

        Cache::swap($cache);

        $this->assertSame('unresumable', $manager->resumeOfflineSession('offline-token'));
        $this->assertSame('portal_back', $manager->offlineReconnectMode('offline-token'));
    }

    public function test_an_offline_session_the_portal_is_back_for_says_so_even_with_a_copy_left_behind(): void
    {
        config(['portal.session_prefix' => 'portal_session_portal_back_copy_']);

        $manager = new PortalSessionManager(Mockery::mock(PortalAuthService::class), app(Encrypter::class));
        Cache::put(config('portal.session_prefix').'offline-token', $this->makeOfflineSession('offline-token', [
            'encrypted_credentials' => 'sealed-with-an-old-app-key',
            'portal_back_at' => now()->toISOString(),
        ]), 1200);

        $this->assertSame('portal_back', $manager->offlineReconnectMode('offline-token'));
    }

    public function test_keep_alive_leaves_an_offline_session_to_its_resume_chain(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_keepalive_offline_',
            'portal.auto_relogin' => false,
        ]);

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldNotReceive('isSessionAlive');
        $authService->shouldNotReceive('login');
        $authService->shouldNotReceive('logout');

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'offline-token';
        $session = $this->makeOfflineSession('offline-token');

        Cache::put($cacheKey, $session, 1200);

        $this->assertSame('offline', $manager->keepAlive('offline-token'));
        $this->assertSame($session, Cache::get($cacheKey));
        $this->assertTrue($manager->isSessionUsable('offline-token'));
    }

    public function test_activity_on_an_offline_session_never_schedules_a_keep_alive(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_offline_touch_',
            'portal.session_activity_touch_interval' => 60,
        ]);

        Queue::fake();

        $manager = new PortalSessionManager(Mockery::mock(PortalAuthService::class), app(Encrypter::class));
        $cacheKey = config('portal.session_prefix').'offline-token';
        $session = $this->makeOfflineSession('offline-token', [
            'last_activity' => now()->subMinutes(2)->toISOString(),
        ]);

        Cache::put($cacheKey, $session, 1200);

        $this->assertTrue($manager->validateSession('offline-token'));

        $this->assertNotSame($session['last_activity'], Cache::get($cacheKey)['last_activity']);
        Queue::assertNotPushed(RefreshPortalSessionJob::class);
    }

    public function test_activity_on_an_online_session_restarts_a_lapsed_keep_alive(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_lapsed_touch_',
            'portal.session_activity_touch_interval' => 60,
        ]);

        Queue::fake();

        $manager = new PortalSessionManager(Mockery::mock(PortalAuthService::class), app(Encrypter::class));
        Cache::put(config('portal.session_prefix').'online-token', $this->makeStoredSession('online-token', [
            'last_activity' => now()->subMinutes(2)->toISOString(),
            'upstream_checked_at' => null,
        ]), 1200);

        $this->assertTrue($manager->validateSession('online-token'));

        Queue::assertPushed(RefreshPortalSessionJob::class, fn (RefreshPortalSessionJob $job): bool => $job->uniqueId() === 'online-token');
    }

    public function test_destroy_session_drops_the_local_session_before_the_portal_logout(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_logout_order_',
        ]);

        $cacheKey = config('portal.session_prefix').'logout-token';
        $sessionSeenByLogout = 'not called';

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('logout')
            ->once()
            ->andReturnUsing(function () use ($cacheKey, &$sessionSeenByLogout): void {
                $sessionSeenByLogout = Cache::get($cacheKey);

                throw new \RuntimeException('Portal logout timed out');
            });

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        Cache::put($cacheKey, $this->makeStoredSession('logout-token'), 1200);

        $this->assertFalse($manager->destroySession('logout-token'));
        $this->assertNull($sessionSeenByLogout);
        $this->assertNull(Cache::get($cacheKey));
        $this->assertFalse($manager->isSessionUsable('logout-token'));
    }

    public function test_portal_cookies_are_stored_encrypted(): void
    {
        config([
            'portal.session_prefix' => 'portal_session_sealed_cookies_',
            'portal.auto_relogin' => false,
        ]);

        Queue::fake();

        $authService = Mockery::mock(PortalAuthService::class);
        $authService->shouldReceive('login')->once()->andReturn([
            ...$this->makeLoginResult(['nama' => 'Mahasiswa Cookie']),
            'cookies' => [[
                'Name' => 'PHPSESSID',
                'Value' => 'live-portal-cookie',
                'Domain' => 'my.cic.ac.id',
                'Path' => '/',
                'Expires' => null,
                'Secure' => true,
                'HttpOnly' => true,
            ]],
        ]);

        $manager = new PortalSessionManager($authService, app(Encrypter::class));
        $token = $manager->createSession('20990000041', 'login-pass')['session_token'];
        $raw = Cache::get(config('portal.session_prefix').$token);

        $this->assertIsString($raw['cookies']);
        $this->assertStringNotContainsString('live-portal-cookie', serialize($raw));
        $this->assertSame('live-portal-cookie', $manager->getSession($token)['cookies'][0]['Value']);
        $this->assertSame('live-portal-cookie', $manager->getSessionCookieJar($token)?->getCookieByName('PHPSESSID')?->getValue());

        // A write (here an activity touch) seals the cookies again.
        $this->travel(2)->minutes();
        $this->assertTrue($manager->validateSession($token));
        $this->assertStringNotContainsString('live-portal-cookie', serialize(Cache::get(config('portal.session_prefix').$token)));
        $this->assertSame('live-portal-cookie', $manager->getSession($token)['cookies'][0]['Value']);
    }

    public function test_sessions_stored_before_cookies_were_sealed_are_still_read(): void
    {
        config(['portal.session_prefix' => 'portal_session_plain_cookies_']);

        $manager = new PortalSessionManager(Mockery::mock(PortalAuthService::class), app(Encrypter::class));
        Cache::put(config('portal.session_prefix').'plain-token', $this->makeStoredSession('plain-token'), 1200);

        $this->assertSame('cookie-value', $manager->getSessionCookieJar('plain-token')?->getCookieByName('PHPSESSID')?->getValue());
    }

    public function test_cookies_that_no_longer_decrypt_read_as_none(): void
    {
        config(['portal.session_prefix' => 'portal_session_unreadable_cookies_']);

        Log::spy();

        $manager = new PortalSessionManager(Mockery::mock(PortalAuthService::class), app(Encrypter::class));
        Cache::put(config('portal.session_prefix').'rotated-key-token', $this->makeStoredSession('rotated-key-token', [
            'cookies' => 'sealed-with-an-old-app-key',
        ]), 1200);

        $this->assertSame([], $manager->getSession('rotated-key-token')['cookies']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'campus_session.cookies_unreadable')
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
     * A session opened while the portal was unreachable (createOfflineSession):
     * no portal cookies and never checked upstream.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeOfflineSession(string $token, array $overrides = []): array
    {
        return $this->makeStoredSession($token, [
            'cookies' => [],
            'upstream_checked_at' => null,
            'offline' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeStoredSession(string $token, array $overrides = []): array
    {
        return array_merge([
            'token' => $token,
            'username' => '20990000001',
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
