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
     * How the mocked portal answers a login: 'down', 'up' or 'rejects'.
     */
    private string $portal = 'down';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class, SyncPortalArchiveJob::class]);
        Event::fake([PortalSessionNoticeSent::class]);
        $this->withSession(['_token' => 'known-csrf-token'])->withHeader('X-CSRF-TOKEN', 'known-csrf-token');

        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andReturnUsing(function (): array {
                return match ($this->portal) {
                    'up' => $this->loginResult(),
                    'rejects' => throw PortalAuthenticationException::invalidCredentials(),
                    default => throw PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection timed out'),
                };
            });
            $mock->shouldReceive('logout');
        }));

        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME]);
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
            && $job->queue === 'campus-sessions');
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
        $this->assertSame('PHPSESSID', $stored['cookies'][0]['Name']);
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

    public function test_a_rejected_password_ends_the_chain_and_keeps_the_session_offline(): void
    {
        $token = $this->offlineSession();
        $this->portal = 'rejects';

        $this->runResume($token, attempt: 2);

        $this->assertTrue(app(PortalOfflineAccess::class)->isOfflineSession($token));
        $this->assertTrue(app(PortalSessionManager::class)->isSessionUsable($token));
        $this->assertArrayNotHasKey('encrypted_credentials', $this->storedSession($token));
        Bus::assertNotDispatched(ResumeOfflineSessionJob::class, fn (ResumeOfflineSessionJob $job): bool => $this->jobProperty($job, 'attempt') === 3);
        Bus::assertNotDispatched(RefreshPortalSessionJob::class);
        Event::assertDispatched(PortalSessionNoticeSent::class, fn (PortalSessionNoticeSent $event): bool => ($event->broadcastWith()['status'] ?? null) === 'offline_resume_rejected');

        // Without a password there is nothing left to try with.
        $this->portal = 'up';
        $this->runResume($token, attempt: 3);
        $this->assertTrue(app(PortalOfflineAccess::class)->isOfflineSession($token));
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

    private function offlineSession(): string
    {
        $this->portal = 'down';
        $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.offline', true);
        // Logging in regenerates the CSRF token.
        session()->put('_token', 'known-csrf-token');

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
