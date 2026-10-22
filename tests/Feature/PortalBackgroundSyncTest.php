<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Jobs\SyncPortalArchiveJob;
use App\Models\PortalArchiveVersion;
use App\Models\PortalOfflineAccount;
use App\Services\PortalAuthService;
use App\Services\PortalBackgroundSync;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * With background sync on, MyCIC keeps the archive current while the student
 * is away: it logs in with the stored credentials, re-syncs the datasets and
 * logs out again (PortalBackgroundSync).
 */
class PortalBackgroundSyncTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = '20990000006';

    private const PASSWORD = 'rahasia-portal';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class, SyncPortalArchiveJob::class]);
        $this->withSession(['_token' => 'known-csrf-token'])->withHeader('X-CSRF-TOKEN', 'known-csrf-token');
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_online_login_stores_the_credentials_encrypted(): void
    {
        $this->portalAcceptsLogin();

        $this->login()->assertOk();

        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame(['username' => self::USERNAME, 'password' => self::PASSWORD], $account->credentials);
        $this->assertNull($account->credentials_rejected_at);
        $this->assertStringNotContainsString(self::PASSWORD, (string) PortalOfflineAccount::query()->toBase()->value('credentials'));
    }

    public function test_credentials_are_not_stored_when_background_sync_is_switched_off_for_the_deployment(): void
    {
        config(['portal.background_sync.enabled' => false]);
        $this->portalAcceptsLogin();

        $this->login()->assertOk();

        $this->assertNull(PortalOfflineAccount::query()->sole()->credentials);
        $this->assertCount(0, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_the_scheduler_queues_one_sync_per_account_that_is_due(): void
    {
        $due = $this->account('20990000011');
        $this->account('20990000012', ['last_background_sync_at' => now()->subMinutes(10)]);
        $overdue = $this->account('20990000013', ['last_background_sync_at' => now()->subHours(4)]);
        $this->account('20990000014', ['credentials_rejected_at' => now()]);
        $this->account('20990000015', ['background_sync_disabled_at' => now(), 'credentials' => null]);
        $this->account('20990000016', ['last_online_login_at' => now()->subDays(31)]);

        $this->artisan('portal:sync-archives')->assertSuccessful();

        Bus::assertDispatchedTimes(SyncPortalArchiveJob::class, 2);

        foreach ([$due, $overdue] as $account) {
            Bus::assertDispatched(SyncPortalArchiveJob::class, fn (SyncPortalArchiveJob $job): bool => $job->uniqueId() === (string) $account->id && $job->queue === 'campus-refresh');
        }
    }

    public function test_each_scheduler_run_takes_one_batch_with_the_longest_waiting_first(): void
    {
        config(['portal.background_sync.batch_size' => 1]);
        $this->account('20990000011', ['last_background_sync_at' => now()->subHours(4)]);
        $neverSynced = $this->account('20990000012');

        $this->artisan('portal:sync-archives')->assertSuccessful();

        Bus::assertDispatchedTimes(SyncPortalArchiveJob::class, 1);
        Bus::assertDispatched(SyncPortalArchiveJob::class, fn (SyncPortalArchiveJob $job): bool => $job->uniqueId() === (string) $neverSynced->id);
    }

    public function test_a_sync_logs_in_rebuilds_the_datasets_and_logs_out_again(): void
    {
        config(['portal.background_sync.datasets' => ['skkm', 'thesis', 'not_a_dataset']]);
        $account = $this->account(self::USERNAME);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->with(self::USERNAME, self::PASSWORD)->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'skkm', [])->andReturn(['found' => true, 'entries' => [['name' => 'Seminar', 'status' => 'valid']]]);
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'thesis', [])->andReturn(['found' => true, 'status' => ['state' => 'proposal']]);
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $account->refresh();
        $this->assertNotNull($account->last_background_sync_at);
        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $account->last_background_sync_status);
        $this->assertNull($account->last_background_sync_error);
        $this->assertSame('Mahasiswa Latar', $account->user_info['name']);
        $this->assertSame(['skkm', 'thesis'], PortalArchiveVersion::query()->forOwner($this->ownerHash)->orderBy('dataset')->pluck('dataset')->all());
        $this->assertNull(app(PortalSessionManager::class)->activeOnlineSessionTokenForOwner($this->ownerHash));
        $this->assertSame([], array_filter(array_keys($this->cacheEntries()), fn (string $key): bool => str_starts_with($key, (string) config('portal.session_prefix'))));
    }

    public function test_a_dataset_that_fails_is_recorded_without_stopping_the_others(): void
    {
        config(['portal.background_sync.datasets' => ['skkm', 'thesis']]);
        $account = $this->account(self::USERNAME);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'skkm', [])->andThrow(new \RuntimeException('Halaman SKKM tidak terbaca'));
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'thesis', [])->andReturn(['found' => true, 'status' => ['state' => 'proposal']]);
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_PARTIAL, $status);
        $this->assertSame('skkm: Halaman SKKM tidak terbaca', $account->refresh()->last_background_sync_error);
    }

    public function test_a_sync_reuses_the_students_active_online_session_instead_of_logging_in_again(): void
    {
        config(['portal.background_sync.datasets' => ['skkm']]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldNotReceive('logout');
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'skkm', [])->andReturn(['found' => true, 'entries' => []]);
        }));

        $this->login()->assertOk();
        $token = (string) session('campus_session_token');
        $account = PortalOfflineAccount::query()->sole();

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $this->assertTrue(app(PortalSessionManager::class)->isSessionUsable($token));
        $this->assertSame($token, app(PortalSessionManager::class)->activeOnlineSessionTokenForOwner($this->ownerHash));
    }

    public function test_a_rejected_login_stops_the_syncs_until_the_student_logs_in_online_again(): void
    {
        $account = $this->account(self::USERNAME);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andThrow(PortalAuthenticationException::invalidCredentials());
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_REJECTED, $status);
        $account->refresh();
        $this->assertNotNull($account->credentials_rejected_at);
        $this->assertTrue(app(PortalBackgroundSync::class)->status($this->ownerHash)['rejected']);
        $this->travel(4)->hours();
        $this->assertCount(0, app(PortalBackgroundSync::class)->dueAccounts());

        app(PortalOfflineAccess::class)->remember(self::USERNAME, 'password-baru', ['name' => 'Mahasiswa Baru']);

        $account->refresh();
        $this->assertNull($account->credentials_rejected_at);
        $this->assertSame('password-baru', $account->credentials['password']);
        $this->assertCount(1, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_an_unreachable_portal_is_recorded_and_tried_again_next_interval(): void
    {
        $account = $this->account(self::USERNAME);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andThrow(PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection timed out'));
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_UNREACHABLE, $status);
        $account->refresh();
        $this->assertSame(self::PASSWORD, $account->credentials['password']);
        $this->assertNull($account->credentials_rejected_at);
        $this->assertCount(0, app(PortalBackgroundSync::class)->dueAccounts());
        $this->travel(4)->hours();
        $this->assertCount(1, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_the_student_can_switch_background_sync_off_and_on(): void
    {
        $this->portalAcceptsLogin();
        $this->loginAgain();

        $this->getJson('/api/archive')
            ->assertOk()
            ->assertJsonPath('data.background_sync.enabled', true)
            ->assertJsonPath('data.background_sync.active', true)
            ->assertJsonPath('data.background_sync.pending_login', false);

        $this->patchJson('/api/archive/background-sync', ['enabled' => 'maybe'])->assertStatus(422);
        $this->patchJson('/api/archive/background-sync', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.active', false);
        $account = PortalOfflineAccount::query()->sole();
        $this->assertNull($account->credentials);
        $this->assertNotNull($account->background_sync_disabled_at);

        // Switched off, a login stores no credentials.
        $this->loginAgain();
        $this->assertNull(PortalOfflineAccount::query()->sole()->credentials);

        $this->patchJson('/api/archive/background-sync', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.pending_login', true);

        $this->loginAgain();
        $this->assertSame(self::PASSWORD, PortalOfflineAccount::query()->sole()->credentials['password']);
        $this->getJson('/api/archive')->assertOk()->assertJsonPath('data.background_sync.active', true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(string $username, array $overrides = []): PortalOfflineAccount
    {
        return PortalOfflineAccount::query()->create([
            'owner_hash' => app(PortalDataOwner::class)->keyForUsername($username),
            'password_hash' => 'irrelevant',
            'user_info' => ['name' => 'Mahasiswa Arsip'],
            'credentials' => ['username' => $username, 'password' => self::PASSWORD],
            'last_online_login_at' => now()->subDay(),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loginResult(): array
    {
        return [
            'success' => true,
            'user_info' => ['name' => 'Mahasiswa Latar', 'student_id' => self::USERNAME],
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

    private function portalAcceptsLogin(): void
    {
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andReturn($this->loginResult());
            $mock->shouldReceive('logout');
        }));
    }

    private function login(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
    }

    private function loginAgain(): void
    {
        $this->login()->assertOk();
        // Logging in regenerates the CSRF token.
        session()->put('_token', 'known-csrf-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheEntries(): array
    {
        $store = Cache::getStore();
        $property = new \ReflectionProperty($store, 'storage');

        return $property->getValue($store);
    }
}
