<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalSessionNoticeSent;
use App\Exceptions\PortalConnectionException;
use App\Jobs\ExpirePortalSessionJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Services\PortalAuthService;
use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A session nobody reads any more (its keep-alive chain gave up while the
 * portal was down, a background job was killed, the student never came
 * back) still ends once it idles out (ExpirePortalSessionJob): its password
 * copy and portal cookies never stay behind in a cache store that keeps
 * expired entries until they are read (the database store).
 */
class PortalSessionIdleExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = '20990000027';

    private const PASSWORD = 'rahasia-portal';

    private const IDLE_TIMEOUT = 7200;

    /**
     * Whether the mocked portal answers at all.
     */
    private bool $portalUp = true;

    /**
     * Portal logouts the mocked portal received.
     */
    private int $logouts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'database',
            'portal.auto_relogin' => true,
            'portal.session_idle_timeout' => self::IDLE_TIMEOUT,
        ]);
        $this->freezeTime();
        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ExpirePortalSessionJob::class]);
        Event::fake([PortalSessionNoticeSent::class]);

        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andReturnUsing(fn (): array => [
                'success' => true,
                'user_info' => ['name' => 'Mahasiswa Idle', 'student_id' => self::USERNAME],
                'cookies' => [[
                    'Name' => 'PHPSESSID',
                    'Value' => 'cookie-value',
                    'Domain' => 'portal.invalid',
                    'Path' => '/',
                    'Expires' => null,
                    'Secure' => true,
                    'HttpOnly' => true,
                ]],
                'session_data' => ['login_time' => now()->toISOString()],
            ]);
            $mock->shouldReceive('isSessionAlive')->andReturnUsing(function (): bool {
                if (! $this->portalUp) {
                    throw PortalConnectionException::networkError('https://portal.invalid/portal/', 'Connection timed out');
                }

                return true;
            });
            $mock->shouldReceive('logout')->andReturnUsing(function (): void {
                $this->logouts++;
            });
        }));

        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Idle', 'student_id' => self::USERNAME]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_every_new_session_is_watched_from_just_after_its_idle_expiry(): void
    {
        $manager = app(PortalSessionManager::class);

        $online = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $offline = $manager->createOfflineSession(self::USERNAME, ['name' => 'Mahasiswa Idle'], self::PASSWORD)['session_token'];
        $background = $manager->createBackgroundSession(self::USERNAME, self::PASSWORD)['session_token'];

        foreach ([$online, $offline, $background] as $token) {
            Bus::assertDispatched(ExpirePortalSessionJob::class, fn (ExpirePortalSessionJob $job): bool => $job->sessionToken() === $token
                && $job->queue === 'sessions'
                && now()->addSeconds(self::IDLE_TIMEOUT + 1)->equalTo($job->delay));
            $this->assertTrue($manager->getSession($token)['idle_expiry_watched']);
        }

        Bus::assertDispatchedTimes(ExpirePortalSessionJob::class, 3);
    }

    public function test_a_session_whose_keep_alive_gave_up_loses_its_password_copy_once_it_idles_out_unobserved(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $manager->rememberLaravelSessionId($token, 'browser-session');
        $this->assertStringContainsString('encrypted_credentials', $this->cacheRow($token));

        // The portal goes down and the keep-alive chain gives up for good.
        $this->portalUp = false;
        $keepAlive = new RefreshPortalSessionJob($token);

        try {
            app()->call([$keepAlive, 'handle']);
            $this->fail('An unreachable portal must fail the keep-alive.');
        } catch (PortalConnectionException $exception) {
            $keepAlive->failed($exception);
        }

        // Nobody reads the session again: the database store still holds its copy past the expiry.
        $this->travel(self::IDLE_TIMEOUT + 1)->seconds();
        $this->assertStringContainsString('encrypted_credentials', $this->cacheRow($token));

        $check = $this->runIdleExpiry($token);

        $check->assertReleased(61);
        $row = $this->cacheRow($token);
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('encrypted_credentials', $row);
        $this->assertFalse($manager->getSession($token)['is_active']);
        $this->assertSame(1, $this->logouts);
        Event::assertDispatched(PortalSessionNoticeSent::class, fn (PortalSessionNoticeSent $event): bool => $event->sessionId === 'browser-session'
            && ($event->broadcastWith()['status'] ?? null) === 'manual_relogin_required'
            && ($event->broadcastWith()['message'] ?? null) === ExpirePortalSessionJob::IDLE_MESSAGE);

        // After its short retention the ended session is gone from the store, and the check ends.
        $this->travel(61)->seconds();
        $this->runIdleExpiry($token)->assertNotReleased();
        $this->assertNull($this->cacheRow($token));
    }

    public function test_activity_moves_the_check_to_the_new_idle_expiry(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];

        $this->travel(3600)->seconds();
        $this->assertTrue($manager->validateSession($token));
        $this->travel(self::IDLE_TIMEOUT - 3600 + 1)->seconds();

        $this->runIdleExpiry($token)->assertReleased(3600);

        $session = $manager->getSession($token);
        $this->assertTrue($session['is_active']);
        $this->assertArrayHasKey('encrypted_credentials', $session);
        $this->assertSame(0, $this->logouts);
        Event::assertNotDispatched(PortalSessionNoticeSent::class);
    }

    public function test_an_offline_session_loses_its_password_copy_once_it_idles_out_unwatched(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createOfflineSession(self::USERNAME, ['name' => 'Mahasiswa Idle'], self::PASSWORD)['session_token'];
        $manager->rememberLaravelSessionId($token, 'browser-session');
        $this->assertStringContainsString('encrypted_credentials', $this->cacheRow($token));

        $this->travel(self::IDLE_TIMEOUT + 1)->seconds();
        $this->runIdleExpiry($token)->assertReleased(61);

        $this->assertStringNotContainsString('encrypted_credentials', $this->cacheRow($token));
        $this->assertFalse($manager->getSession($token)['is_active']);
        $this->assertSame(0, $this->logouts);
        Event::assertNotDispatched(PortalSessionNoticeSent::class);
    }

    public function test_a_background_session_its_job_never_ended_is_logged_out_once_it_idles_out(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createBackgroundSession(self::USERNAME, self::PASSWORD)['session_token'];

        $this->travel(self::IDLE_TIMEOUT + 1)->seconds();
        $this->runIdleExpiry($token)->assertReleased(61);

        $this->assertFalse($manager->getSession($token)['is_active']);
        $this->assertSame(1, $this->logouts);
    }

    public function test_a_session_whose_lock_is_busy_is_looked_at_again_soon(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $manager->rememberLaravelSessionId($token, 'browser-session');
        $this->travel(self::IDLE_TIMEOUT + 1)->seconds();

        $cache = app('cache');
        $busy = Mockery::mock(Lock::class);
        $busy->shouldReceive('block')->andThrow(new LockTimeoutException);
        $busyCache = Mockery::mock($cache);
        $busyCache->shouldReceive('lock')->andReturnUsing(
            fn (string $name, int $seconds = 0, ?string $owner = null): Lock => str_starts_with($name, 'campus_session_lock_') ? $busy : $cache->lock($name, $seconds, $owner)
        );
        Cache::swap($busyCache);

        $this->runIdleExpiry($token)->assertReleased(30);
        $this->assertStringContainsString('encrypted_credentials', $this->cacheRow($token));
        Event::assertNotDispatched(PortalSessionNoticeSent::class);

        Cache::swap($cache);
        $this->runIdleExpiry($token)->assertReleased(61);
        $this->assertStringNotContainsString('encrypted_credentials', $this->cacheRow($token));
        Event::assertDispatched(PortalSessionNoticeSent::class);
    }

    public function test_an_ended_session_kept_past_its_retention_is_removed(): void
    {
        $token = 'ended-session';
        Cache::put(config('portal.session_prefix').$token, [
            'token' => $token,
            'username' => self::USERNAME,
            'cookies' => [],
            'expires_at' => now()->subMinutes(10)->toISOString(),
            'ended_at' => now()->subMinutes(2)->toISOString(),
            'is_active' => false,
        ], 3600);

        $this->runIdleExpiry($token)->assertNotReleased();

        $this->assertNull($this->cacheRow($token));
    }

    public function test_an_ended_session_is_looked_at_again_just_after_its_retention(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $this->travel(self::IDLE_TIMEOUT + 1)->seconds();
        $this->assertFalse($manager->isSessionUsable($token));
        $this->travel(20)->seconds();

        $this->runIdleExpiry($token)->assertReleased(41);
        $this->assertNotNull($this->cacheRow($token));
    }

    public function test_the_check_ends_with_a_session_that_is_gone(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $manager->destroySession($token);

        $this->runIdleExpiry($token)->assertNotReleased();
        $this->runIdleExpiry('never-existed')->assertNotReleased();
    }

    public function test_a_session_opened_before_the_check_existed_gets_one_at_its_next_keep_alive(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $key = config('portal.session_prefix').$token;
        $legacy = Cache::get($key);
        unset($legacy['idle_expiry_watched']);
        Cache::put($key, $legacy, 3600);
        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ExpirePortalSessionJob::class]);

        $this->assertSame('alive', $manager->keepAlive($token));
        $this->assertSame('alive', $manager->keepAlive($token));

        Bus::assertDispatchedTimes(ExpirePortalSessionJob::class, 1);
        Bus::assertDispatched(ExpirePortalSessionJob::class, fn (ExpirePortalSessionJob $job): bool => $job->sessionToken() === $token);
        $this->assertTrue($manager->getSession($token)['idle_expiry_watched']);
    }

    public function test_the_check_never_gives_up_and_runs_with_the_session_keep_alives(): void
    {
        $job = new ExpirePortalSessionJob('any-token');

        $this->assertSame(0, $job->tries);
        $this->assertSame('sessions', $job->queue);
    }

    private function runIdleExpiry(string $token): ExpirePortalSessionJob
    {
        $job = (new ExpirePortalSessionJob($token))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    /**
     * The raw row of the session in the database cache store, read without
     * going through the store (which removes an expired row it reads).
     */
    private function cacheRow(string $token): ?string
    {
        $value = DB::table('cache')
            ->where('key', Cache::store('database')->getPrefix().config('portal.session_prefix').$token)
            ->value('value');

        return is_string($value) ? $value : null;
    }
}
