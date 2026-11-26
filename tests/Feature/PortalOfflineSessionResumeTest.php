<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalSessionNoticeSent;
use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Jobs\SyncPortalArchiveJob;
use App\Models\PortalOfflineAccount;
use App\Services\PortalAuthService;
use App\Services\PortalBackgroundSync;
use App\Services\PortalDataOwner;
use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * An offline session keeps the password it was opened with and tries to log
 * in again in the background, so it turns into an online session on its own
 * once the portal is back (ResumeOfflineSessionJob).
 */
class PortalOfflineSessionResumeTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = '20990000007';

    private const PASSWORD = 'rahasia-portal';

    /**
     * How the mocked portal answers a login: 'down', 'up', 'rejects' or
     * 'garbled'. Its login page loads unless it is 'down'.
     */
    private string $portal = 'down';

    /**
     * Logins the mocked portal received.
     */
    private int $logins = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class, SyncPortalArchiveJob::class]);
        Event::fake([PortalSessionNoticeSent::class]);
        $this->withSession(['_token' => 'known-csrf-token'])->withHeader('X-CSRF-TOKEN', 'known-csrf-token');

        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andReturnUsing(function (): array {
                $this->logins++;

                return match ($this->portal) {
                    'up' => $this->loginResult(),
                    'rejects' => throw PortalAuthenticationException::invalidCredentials(),
                    // Recovering: the login goes through, the dashboard comes back blank.
                    'garbled' => throw PortalAuthService::unconfirmedRefusal(PortalAuthenticationException::sessionExpired()),
                    default => throw PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection timed out'),
                };
            });
            $mock->shouldReceive('analyzeLoginForm')->andReturnUsing(function (): array {
                if ($this->portal === 'down') {
                    throw PortalConnectionException::networkError('https://my.cic.ac.id/portal/mahasiswa/', 'Connection timed out');
                }

                return ['action' => 'https://portal.invalid/portal/mahasiswa/?mod=loginact', 'method' => 'post', 'fields' => []];
            });
            $mock->shouldReceive('logout');
        }));

        // These students opted into background sync, so their offline sessions keep a password copy.
        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME], true);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_offline_login_keeps_the_password_encrypted_and_schedules_a_resume(): void
    {
        $token = $this->offlineSession();

        Bus::assertDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $job->uniqueId() === $token
            && $this->jobProperty($job, 'attempt') === 1
            && $job->delay !== null
            && $job->queue === 'sessions');
        $stored = $this->storedSession($token);
        $this->assertTrue($stored['offline']);
        $this->assertStringNotContainsString(self::PASSWORD, $stored['encrypted_credentials']);
        $this->assertSame(self::PASSWORD, json_decode(app(Encrypter::class)->decrypt($stored['encrypted_credentials']), true)['password']);
    }

    public function test_the_resume_job_tries_again_later_while_the_portal_stays_down(): void
    {
        $token = $this->offlineSession();

        $this->runResume($token, attempt: 1);

        Bus::assertDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $job->uniqueId() === $token && $this->jobProperty($job, 'attempt') === 2);
        $this->assertTrue(app(PortalOfflineAccess::class)->isOfflineSession($token));
        $this->assertArrayHasKey('encrypted_credentials', $this->storedSession($token));
        Event::assertNotDispatched(PortalSessionNoticeSent::class);

        $manager = app(PortalSessionManager::class);
        $this->assertSame(120, $manager->offlineResumeDelay(1));
        $this->assertSame(240, $manager->offlineResumeDelay(2));
        $this->assertSame(900, $manager->offlineResumeDelay(10));
    }

    public function test_the_session_goes_online_in_place_once_the_portal_answers(): void
    {
        $token = $this->offlineSession();
        $this->travel(10)->minutes();
        $this->portal = 'up';

        $this->runResume($token, attempt: 3);

        $manager = app(PortalSessionManager::class);
        $this->assertFalse(app(PortalOfflineAccess::class)->isOfflineSession($token));
        $this->assertTrue($manager->isSessionUsable($token));
        $stored = $this->storedSession($token);
        $this->assertArrayNotHasKey('offline', $stored);
        $this->assertArrayNotHasKey('encrypted_credentials', $stored);
        $this->assertSame('PHPSESSID', $manager->getSession($token)['cookies'][0]['Name']);
        $this->assertStringNotContainsString('cookie-value', serialize($stored));
        $this->assertSame($token, $manager->activeOnlineSessionTokenForOwner(app(PortalDataOwner::class)->keyForUsername(self::USERNAME)));
        $this->assertTrue(PortalOfflineAccount::query()->sole()->last_online_login_at->greaterThan(now()->subMinute()));

        Bus::assertDispatched(RefreshPortalSessionJob::class);
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobProperty($job, 'campusSessionToken') === $token);
        Bus::assertDispatched(ScanPortalNotificationsJob::class);
        Bus::assertNotDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $this->jobProperty($job, 'attempt') === 4);
        Event::assertDispatched(PortalSessionNoticeSent::class, fn (PortalSessionNoticeSent $event): bool => ($event->broadcastWith()['status'] ?? null) === 'offline_session_resumed');

        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('data.offline', false);
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-portal-offline="0"', false)
            ->assertDontSee('data-portal-offline-banner', false);
    }

    public function test_a_rejected_password_ends_the_chain_and_the_session(): void
    {
        $token = $this->offlineSession();
        $this->portal = 'rejects';

        $this->runResume($token, attempt: 2);

        // The portal no longer takes the password this session was opened with.
        $this->assertFalse(app(PortalSessionManager::class)->isSessionUsable($token));
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
        $this->assertNotNull(PortalOfflineAccount::query()->sole()->credentials_rejected_at);
        Bus::assertNotDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $this->jobProperty($job, 'attempt') === 3);
        Bus::assertNotDispatched(RefreshPortalSessionJob::class);
        Event::assertDispatched(PortalSessionNoticeSent::class, fn (PortalSessionNoticeSent $event): bool => ($event->broadcastWith()['status'] ?? null) === 'manual_relogin_required');
        $this->getJson('/api/auth/session')->assertUnauthorized();

        // Nor does that password open MyCIC offline any more.
        $this->portal = 'down';
        $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertStatus(503)
            ->assertJsonMissingPath('data.offline');

        // Nothing is left to resume.
        $this->portal = 'up';
        $this->runResume($token, attempt: 3);
        Event::assertNotDispatched(PortalSessionNoticeSent::class, fn (PortalSessionNoticeSent $event): bool => ($event->broadcastWith()['status'] ?? null) === 'offline_session_resumed');
    }

    public function test_a_portal_answer_that_is_not_a_refusal_keeps_the_session_and_its_offline_password(): void
    {
        $token = $this->offlineSession();
        $this->portal = 'garbled';

        $this->runResume($token, attempt: 2);

        // Tried again later, like an unreachable portal.
        Bus::assertDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $job->uniqueId() === $token && $this->jobProperty($job, 'attempt') === 3);
        $this->assertTrue(app(PortalSessionManager::class)->isSessionUsable($token));
        $this->assertTrue(app(PortalOfflineAccess::class)->isOfflineSession($token));
        $this->assertArrayHasKey('encrypted_credentials', $this->storedSession($token));
        $account = PortalOfflineAccount::query()->sole();
        $this->assertNull($account->credentials_rejected_at);
        $this->assertNotSame('', $account->password_hash);
        Event::assertNotDispatched(PortalSessionNoticeSent::class);

        // The student can still open MyCIC offline with their password.
        $this->portal = 'down';
        $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.offline', true);
    }

    public function test_a_resume_attempt_that_crashed_or_timed_out_keeps_the_chain_going(): void
    {
        $token = $this->offlineSession();
        $job = new ResumeOfflineSessionJob($token, 4);
        (new UniqueLock(Cache::store()))->release($job);

        $this->assertSame(180, $job->timeout);

        $job->failed(new \RuntimeException('ResumeOfflineSessionJob has timed out.'));

        Bus::assertDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $next): bool => $next->uniqueId() === $token && $this->jobProperty($next, 'attempt') === 5);
        $this->assertTrue(app(PortalSessionManager::class)->isSessionUsable($token));
    }

    public function test_a_rejected_password_stays_out_after_background_sync_is_switched_off(): void
    {
        $token = $this->offlineSession();
        $this->portal = 'rejects';

        $this->runResume($token, attempt: 2);

        $this->assertSame('', PortalOfflineAccount::query()->sole()->password_hash);

        app(PortalBackgroundSync::class)->disable(app(PortalDataOwner::class)->keyForUsername(self::USERNAME));

        $this->portal = 'down';
        $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertStatus(503)
            ->assertJsonMissingPath('data.offline');
    }

    public function test_a_rejection_leaves_an_offline_account_a_newer_online_login_updated_alone(): void
    {
        $token = $this->offlineSession();
        $this->travel(5)->minutes();
        // Meanwhile the student logged in online elsewhere with a new password.
        app(PortalOfflineAccess::class)->remember(self::USERNAME, 'password-baru', ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME]);
        $this->portal = 'rejects';

        $this->runResume($token, attempt: 2);

        $this->assertFalse(app(PortalSessionManager::class)->isSessionUsable($token));
        $this->assertNull(PortalOfflineAccount::query()->sole()->credentials_rejected_at);
        $this->assertNotNull(app(PortalOfflineAccess::class)->verify(self::USERNAME, 'password-baru')['user_info']);
    }

    public function test_a_session_without_a_stored_password_stays_offline_and_says_so(): void
    {
        config(['portal.archive.offline_resume' => false]);
        $token = $this->offlineSession();
        config(['portal.archive.offline_resume' => true]);
        $this->portal = 'up';

        $this->runResume($token, attempt: 1);

        $this->assertSame(0, $this->logins);
        $this->assertTrue(app(PortalOfflineAccess::class)->isOfflineSession($token));
        $this->assertTrue(app(PortalSessionManager::class)->isSessionUsable($token));
        $this->assertNull(PortalOfflineAccount::query()->sole()->credentials_rejected_at);
        Bus::assertNotDispatched(ResumeOfflineSessionJob::class);
        $this->assertPortalBackNoticeSent();
    }

    public function test_a_student_who_switched_background_sync_off_gets_an_offline_session_without_a_password_copy(): void
    {
        $this->switchBackgroundSyncOff();

        $response = $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.offline', true)
            ->assertJsonPath('data.offline_reconnect', 'notify')
            ->assertSessionHas('campus_offline_reconnect', 'notify');
        $token = (string) session('campus_session_token');

        $this->assertStringContainsString('tidak menyimpan password', $response->json('message'));
        $this->assertStringNotContainsString('otomatis begitu', $response->json('message'));
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
        $this->assertStringNotContainsString('encrypted_credentials', serialize(Cache::getStore()->all()));
        // The chain still runs, to tell the student once the portal is back.
        Bus::assertDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $job->uniqueId() === $token && $this->jobProperty($job, 'attempt') === 1);
    }

    public function test_without_a_password_copy_the_resume_never_logs_in_and_tells_the_student_once_the_portal_is_back(): void
    {
        $this->switchBackgroundSyncOff();
        $token = $this->offlineSession();

        $this->runResume($token, attempt: 1);

        Bus::assertDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $job->uniqueId() === $token && $this->jobProperty($job, 'attempt') === 2);
        Event::assertNotDispatched(PortalSessionNoticeSent::class);
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('data.offline_reconnect', 'notify');

        $this->portal = 'up';
        $this->runResume($token, attempt: 2);

        $this->assertSame(0, $this->logins);
        $this->assertPortalBackNoticeSent();
        Bus::assertNotDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $this->jobProperty($job, 'attempt') === 3);
        Bus::assertNotDispatched(RefreshPortalSessionJob::class);
        Bus::assertNotDispatched(RefreshPortalDataJob::class);

        // The session stays offline and readable, and says the portal is back.
        $this->assertTrue(app(PortalOfflineAccess::class)->isOfflineSession($token));
        $this->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('data.offline', true)
            ->assertJsonPath('data.offline_reconnect', 'portal_back');
        $this->get('/dashboard')->assertOk()->assertSessionHas('campus_offline_reconnect', 'portal_back');
        $this->postJson('/api/auth/session/refresh')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Kamu sedang di mode offline. Portal kampus sudah bisa diakses lagi. Keluar lalu masuk lagi untuk menyambungkan sesi.');

        // ...until it idles out.
        $this->travel((int) config('portal.session_idle_timeout') + 60)->seconds();
        $this->assertFalse(app(PortalSessionManager::class)->isSessionUsable($token));
        $this->getJson('/api/auth/session')->assertUnauthorized();
    }

    public function test_a_password_copy_kept_from_before_the_opt_out_is_dropped_unused(): void
    {
        $token = $this->offlineSession();
        $this->assertArrayHasKey('encrypted_credentials', $this->storedSession($token));

        // Switched off from the offline session, then the portal comes back.
        $this->switchBackgroundSyncOff();
        $this->portal = 'up';
        $this->runResume($token, attempt: 2);

        $this->assertSame(0, $this->logins);
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
        $this->assertTrue(app(PortalSessionManager::class)->isSessionUsable($token));
        $this->assertTrue(app(PortalOfflineAccess::class)->isOfflineSession($token));
        $this->assertPortalBackNoticeSent();
    }

    public function test_a_password_copy_that_no_longer_decrypts_is_dropped_and_the_session_says_the_portal_is_back(): void
    {
        $token = $this->offlineSession();
        $key = config('portal.session_prefix').$token;
        $stored = Cache::get($key);
        $stored['encrypted_credentials'] = 'sealed-with-an-old-app-key';
        Cache::put($key, $stored, 3600);
        $this->portal = 'up';

        $this->runResume($token, attempt: 2);

        $this->assertSame(0, $this->logins);
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
        $this->assertPortalBackNoticeSent();
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('data.offline_reconnect', 'portal_back');
        $this->postJson('/api/auth/session/refresh')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Kamu sedang di mode offline. Portal kampus sudah bisa diakses lagi. Keluar lalu masuk lagi untuk menyambungkan sesi.');
    }

    public function test_a_password_copy_kept_from_before_the_opt_out_is_dropped_while_the_portal_stays_down(): void
    {
        $token = $this->offlineSession();
        $this->switchBackgroundSyncOff();

        $this->runResume($token, attempt: 2);

        $this->assertSame(0, $this->logins);
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
        Bus::assertDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $job->uniqueId() === $token && $this->jobProperty($job, 'attempt') === 3);
        Event::assertNotDispatched(PortalSessionNoticeSent::class);
    }

    public function test_the_password_copy_goes_when_the_offline_session_idles_out(): void
    {
        $token = $this->offlineSession();
        $this->travel((int) config('portal.session_idle_timeout') + 60)->seconds();
        $this->portal = 'up';

        $this->runResume($token, attempt: 5);

        $this->assertSame(0, $this->logins);
        $stored = $this->storedSession($token);
        $this->assertFalse($stored['is_active']);
        $this->assertArrayNotHasKey('encrypted_credentials', $stored);
        Bus::assertNotDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $this->jobProperty($job, 'attempt') === 6);
    }

    public function test_the_password_copy_goes_when_a_request_finds_the_offline_session_idle(): void
    {
        $token = $this->offlineSession();
        $this->travel((int) config('portal.session_idle_timeout') + 60)->seconds();

        $this->getJson('/api/auth/session')->assertUnauthorized();

        $stored = $this->storedSession($token);
        $this->assertFalse($stored['is_active']);
        $this->assertArrayNotHasKey('encrypted_credentials', $stored);
    }

    public function test_the_password_copy_goes_with_the_session_on_logout(): void
    {
        $token = $this->offlineSession();
        $this->assertArrayHasKey('encrypted_credentials', $this->storedSession($token));

        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertNull(Cache::get(config('portal.session_prefix').$token));
        $this->assertStringNotContainsString('encrypted_credentials', serialize(Cache::getStore()->all()));
    }

    public function test_a_new_login_in_the_same_browser_ends_its_offline_session_and_password_copy(): void
    {
        $offlineToken = $this->offlineSession();
        $this->portal = 'up';

        $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.offline', false)
            ->assertJsonPath('data.offline_reconnect', null);

        $onlineToken = (string) session('campus_session_token');
        $this->assertNotSame($offlineToken, $onlineToken);
        $this->assertNull(Cache::get(config('portal.session_prefix').$offlineToken));
        $this->assertFalse(app(PortalOfflineAccess::class)->isOfflineSession($offlineToken));
        $this->assertTrue(app(PortalSessionManager::class)->isSessionUsable($onlineToken));
        $otherEntries = array_diff_key(Cache::getStore()->all(), [config('portal.session_prefix').$onlineToken => true]);
        $this->assertStringNotContainsString('encrypted_credentials', serialize($otherEntries));

        // Its chain has nothing left to log in.
        $this->runResume($offlineToken, attempt: 2);
        $this->assertSame(1, $this->logins);
        Event::assertNotDispatched(PortalSessionNoticeSent::class, fn (PortalSessionNoticeSent $event): bool => ($event->broadcastWith()['status'] ?? null) === 'offline_session_resumed');
    }

    public function test_the_chain_ends_when_the_student_logs_out(): void
    {
        $token = $this->offlineSession();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->portal = 'up';

        $this->runResume($token, attempt: 2);

        Bus::assertNotDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $this->jobProperty($job, 'attempt') === 3);
        Bus::assertNotDispatched(RefreshPortalSessionJob::class);
        Event::assertNotDispatched(PortalSessionNoticeSent::class, fn (PortalSessionNoticeSent $event): bool => ($event->broadcastWith()['status'] ?? null) === 'offline_session_resumed');
    }

    public function test_resuming_can_be_switched_off(): void
    {
        config(['portal.archive.offline_resume' => false]);

        $token = $this->offlineSession();

        Bus::assertNotDispatched(ResumeOfflineSessionJob::class);
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
    }

    private function switchBackgroundSyncOff(): void
    {
        app(PortalBackgroundSync::class)->disable(app(PortalDataOwner::class)->keyForUsername(self::USERNAME));
    }

    private function assertPortalBackNoticeSent(): void
    {
        Event::assertDispatched(PortalSessionNoticeSent::class, fn (PortalSessionNoticeSent $event): bool => ($event->broadcastWith()['status'] ?? null) === 'offline_resume_unavailable'
            && ($event->broadcastWith()['message'] ?? null) === ResumeOfflineSessionJob::PORTAL_BACK_MESSAGE
            && ($event->broadcastWith()['urgency'] ?? null) === 'warning');
    }

    private function offlineSession(): string
    {
        $this->portal = 'down';
        $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.offline', true);
        // Logging in regenerates the CSRF token.
        session()->put('_token', 'known-csrf-token');
        // Only the logins that follow count.
        $this->logins = 0;

        return (string) session('campus_session_token');
    }

    /**
     * Runs the job as the queue would: its unique lock (held since it was
     * dispatched) is released first, so the job can schedule its successor.
     */
    private function runResume(string $token, int $attempt): void
    {
        $job = new ResumeOfflineSessionJob($token, $attempt);
        (new UniqueLock(Cache::store()))->release($job);

        app()->call([$job, 'handle']);
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

    /**
     * @return array<string, mixed>
     */
    private function loginResult(): array
    {
        return [
            'success' => true,
            'user_info' => ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME],
            'cookies' => [[
                'Name' => 'PHPSESSID',
                'Value' => 'cookie-value',
                'Domain' => 'my.cic.ac.id',
                'Path' => '/',
                'Expires' => time() + 3600,
                'Secure' => true,
                'HttpOnly' => true,
                'Discard' => false,
                'Max-Age' => null,
            ]],
            'session_data' => ['login_time' => now()->toISOString()],
        ];
    }

    private function jobProperty(object $job, string $property): mixed
    {
        return (new \ReflectionClass($job))->getProperty($property)->getValue($job);
    }
}
