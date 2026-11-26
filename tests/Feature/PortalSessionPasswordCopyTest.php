<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalAuthenticationException;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Services\PortalAuthService;
use App\Services\PortalBackgroundSync;
use App\Services\PortalDataOwner;
use App\Services\PortalOfflineAccess;
use App\Services\PortalPasswordService;
use App\Services\PortalSessionManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A session keeps a copy of the student's password only while MyCIC may
 * keep one at all: never for a student who switched background sync off,
 * and never past the end of the session.
 */
class PortalSessionPasswordCopyTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = '20990000017';

    private const PASSWORD = 'rahasia-portal';

    private const OTHER_USERNAME = '20990000018';

    /**
     * Whether the mocked portal still serves the session's dashboard.
     */
    private bool $portalSessionAlive = true;

    /**
     * Whether the mocked portal takes the password.
     */
    private bool $portalTakesPassword = true;

    private int $logins = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.auto_relogin' => true]);
        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class]);

        // Bound before the session manager is built: nothing here reaches the portal.
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andReturnUsing(function (): array {
                $this->logins++;

                if (! $this->portalTakesPassword) {
                    throw PortalAuthenticationException::invalidCredentials();
                }

                return [
                    'success' => true,
                    'user_info' => ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME],
                    'cookies' => [[
                        'Name' => 'PHPSESSID',
                        'Value' => 'cookie-'.$this->logins,
                        'Domain' => 'portal.invalid',
                        'Path' => '/',
                        'Expires' => null,
                        'Secure' => true,
                        'HttpOnly' => true,
                    ]],
                    'session_data' => ['login_time' => now()->toISOString()],
                ];
            });
            $mock->shouldReceive('isSessionAlive')->andReturnUsing(fn (): bool => $this->portalSessionAlive);
            $mock->shouldReceive('logout');
        }));

        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME], true);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_auto_relogin_keeps_a_copy_only_for_a_student_who_switched_background_sync_on(): void
    {
        $manager = app(PortalSessionManager::class);

        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $this->assertArrayHasKey('encrypted_credentials', $this->storedSession($token));

        $this->switchBackgroundSyncOff();
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];

        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));

        // Off is the default: a student who never opted in gets no copy either.
        $token = $manager->createSession(self::OTHER_USERNAME, self::PASSWORD)['session_token'];

        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
    }

    public function test_the_keep_alive_drops_the_copy_once_the_student_switches_background_sync_off(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];

        $this->switchBackgroundSyncOff();

        $this->assertSame('alive', $manager->keepAlive($token));
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
        $this->assertTrue($manager->isSessionUsable($token));
    }

    public function test_switching_background_sync_off_drops_every_password_copy_at_once(): void
    {
        $manager = app(PortalSessionManager::class);
        $phone = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $laptop = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];

        $this->switchBackgroundSyncOff();

        foreach ([$phone, $laptop] as $token) {
            $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
            $this->assertTrue($manager->isSessionUsable($token));
        }
    }

    public function test_a_copy_kept_from_before_the_opt_out_never_logs_in_again(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $this->switchBackgroundSyncOff();
        $this->portalSessionAlive = false;

        $this->assertSame('expired', $manager->keepAlive($token));

        $this->assertSame(1, $this->logins);
        $stored = $this->storedSession($token);
        $this->assertFalse($stored['is_active']);
        $this->assertArrayNotHasKey('encrypted_credentials', $stored);
    }

    public function test_a_session_that_ends_drops_its_copy(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $this->portalSessionAlive = false;
        $this->portalTakesPassword = false;

        $this->assertSame('expired', $manager->keepAlive($token));

        $stored = $this->storedSession($token);
        $this->assertFalse($stored['is_active']);
        $this->assertArrayNotHasKey('encrypted_credentials', $stored);
    }

    public function test_an_idle_session_drops_its_copy(): void
    {
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $this->travel((int) config('portal.session_idle_timeout') + 60)->seconds();

        $this->assertSame('idle', $manager->keepAlive($token));

        $stored = $this->storedSession($token);
        $this->assertFalse($stored['is_active']);
        $this->assertArrayNotHasKey('encrypted_credentials', $stored);
    }

    public function test_every_password_copy_of_the_student_can_be_dropped_at_once(): void
    {
        $manager = app(PortalSessionManager::class);
        $phone = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $laptop = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $offline = $manager->createOfflineSession(self::USERNAME, ['name' => 'Mahasiswa Arsip'], self::PASSWORD)['session_token'];
        app(PortalOfflineAccess::class)->remember(self::OTHER_USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Lain', 'student_id' => self::OTHER_USERNAME], true);
        $otherStudent = $manager->createSession(self::OTHER_USERNAME, self::PASSWORD)['session_token'];

        $this->switchBackgroundSyncOff();
        $loginsBefore = $this->logins;

        $this->assertTrue($manager->forgetStoredCredentialsForOwner(app(PortalDataOwner::class)->keyForUsername(self::USERNAME)));

        foreach ([$phone, $laptop, $offline] as $token) {
            $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
            $this->assertTrue($manager->isSessionUsable($token));
        }

        $this->assertSame('notify', $manager->offlineReconnectMode($offline));
        $this->assertArrayHasKey('encrypted_credentials', $this->storedSession($otherStudent));
        $this->assertSame($loginsBefore, $this->logins);
    }

    public function test_a_student_without_password_copies_has_nothing_to_drop(): void
    {
        $this->switchBackgroundSyncOff();
        $manager = app(PortalSessionManager::class);
        $token = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];

        $this->assertTrue($manager->forgetStoredCredentialsForOwner(app(PortalDataOwner::class)->keyForUsername(self::USERNAME)));
        $this->assertTrue($manager->isSessionUsable($token));
        $this->assertSame([], $this->passwordCopyLists());
    }

    public function test_sessions_leave_the_list_of_password_copies_when_they_end(): void
    {
        $manager = app(PortalSessionManager::class);
        $phone = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $laptop = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $this->assertCount(1, $this->passwordCopyLists());

        $manager->destroySession($phone);
        $this->travel((int) config('portal.session_idle_timeout') + 60)->seconds();
        $this->assertSame('idle', $manager->keepAlive($laptop));

        $this->assertSame([], $this->passwordCopyLists());
    }

    public function test_a_session_with_a_busy_lock_keeps_its_place_in_the_list(): void
    {
        $manager = app(PortalSessionManager::class);
        $phone = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $laptop = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $ownerKey = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

        $cache = app('cache');
        $busy = Mockery::mock(Lock::class);
        $busy->shouldReceive('block')->andThrow(new LockTimeoutException);
        $busyCache = Mockery::mock($cache);
        $busyCache->shouldReceive('lock')->andReturnUsing(
            fn (string $name, int $seconds = 0, ?string $owner = null): Lock => $name === 'campus_session_lock_'.$phone ? $busy : $cache->lock($name, $seconds, $owner)
        );
        Cache::swap($busyCache);

        $this->assertFalse($manager->forgetStoredCredentialsForOwner($ownerKey));
        $this->assertArrayHasKey('encrypted_credentials', $this->storedSession($phone));
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($laptop));

        Cache::swap($cache);
        $this->assertTrue($manager->forgetStoredCredentialsForOwner($ownerKey));
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($phone));
        $this->assertSame([], $this->passwordCopyLists());
    }

    public function test_a_password_change_drops_the_old_password_from_every_session_of_the_student(): void
    {
        $manager = app(PortalSessionManager::class);
        $laptop = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $phone = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        app(PortalOfflineAccess::class)->remember(self::OTHER_USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Lain', 'student_id' => self::OTHER_USERNAME], true);
        $otherStudent = $manager->createSession(self::OTHER_USERNAME, self::PASSWORD)['session_token'];
        $this->portalAcceptsThePasswordChange();

        $this->changePasswordFrom($laptop)->assertOk()->assertJsonPath('success', true);

        foreach ([$laptop, $phone] as $token) {
            $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
            $this->assertTrue($manager->isSessionUsable($token));
        }

        $this->assertArrayHasKey('encrypted_credentials', $this->storedSession($otherStudent));
    }

    public function test_a_busy_session_lock_never_turns_a_changed_password_into_an_error(): void
    {
        $manager = app(PortalSessionManager::class);
        $laptop = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $phone = $manager->createSession(self::USERNAME, self::PASSWORD)['session_token'];
        $this->portalAcceptsThePasswordChange();

        $cache = app('cache');
        $busy = Mockery::mock(Lock::class);
        $busy->shouldReceive('block')->andThrow(new LockTimeoutException);
        $busyCache = Mockery::mock($cache);
        $busyCache->shouldReceive('lock')->andReturnUsing(
            fn (string $name, int $seconds = 0, ?string $owner = null): Lock => $name === 'campus_session_lock_'.$laptop ? $busy : $cache->lock($name, $seconds, $owner)
        );
        Cache::swap($busyCache);

        $this->changePasswordFrom($laptop)->assertOk()->assertJsonPath('success', true);

        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($phone));
        $this->assertNotNull(app(PortalOfflineAccess::class)->verify(self::USERNAME, 'password-baru')['user_info']);
    }

    public function test_the_reconnect_mode_follows_what_the_offline_session_keeps(): void
    {
        $manager = app(PortalSessionManager::class);

        $this->assertNull($manager->offlineReconnectMode($manager->createSession(self::USERNAME, self::PASSWORD)['session_token']));
        $this->assertNull($manager->offlineReconnectMode('missing-token'));

        $automatic = $manager->createOfflineSession(self::USERNAME, ['name' => 'Mahasiswa Arsip'], self::PASSWORD);
        $this->assertSame('automatic', $automatic['offline_reconnect']);
        $this->assertSame('automatic', $manager->offlineReconnectMode($automatic['session_token']));

        $withoutPassword = $manager->createOfflineSession(self::USERNAME, ['name' => 'Mahasiswa Arsip']);
        $this->assertSame('notify', $withoutPassword['offline_reconnect']);

        config(['portal.archive.offline_resume' => false]);
        $manual = $manager->createOfflineSession(self::USERNAME, ['name' => 'Mahasiswa Arsip'], self::PASSWORD);
        $this->assertSame('manual', $manual['offline_reconnect']);
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($manual['session_token']));
    }

    /**
     * The lists of sessions that keep a password copy, as the cache holds them.
     *
     * @return array<string, mixed>
     */
    private function passwordCopyLists(): array
    {
        return array_filter(
            Cache::getStore()->all(),
            static fn (string $key): bool => str_contains($key, 'password_copies') && ! str_ends_with($key, '_lock'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Bound before the first request: the route keeps the controller it resolved first.
     */
    private function portalAcceptsThePasswordChange(): void
    {
        $this->instance(PortalPasswordService::class, Mockery::mock(PortalPasswordService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changePassword')->once()->andReturn(['success' => true, 'message' => 'Password berhasil diubah.', 'data' => []]);
        }));
    }

    private function changePasswordFrom(string $sessionToken): TestResponse
    {
        return $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => $sessionToken])
            ->postJson('/api/auth/password', [
                'current_password' => self::PASSWORD,
                'new_password' => 'password-baru',
                'new_password_confirmation' => 'password-baru',
            ], ['X-CSRF-TOKEN' => 'known-csrf-token']);
    }

    private function switchBackgroundSyncOff(): void
    {
        app(PortalBackgroundSync::class)->disable(app(PortalDataOwner::class)->keyForUsername(self::USERNAME));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedSession(string $token): array
    {
        $session = Cache::get(config('portal.session_prefix').$token);
        $this->assertIsArray($session);

        return $session;
    }
}
