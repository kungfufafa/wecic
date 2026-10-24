<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Jobs\ArchivePortalFilesJob;
use App\Jobs\IngestNotebookSourceJob;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Jobs\SyncPortalArchiveJob;
use App\Models\PortalArchivedFile;
use App\Models\PortalArchiveVersion;
use App\Models\PortalOfflineAccount;
use App\Services\PortalAuthService;
use App\Services\PortalBackgroundSync;
use App\Services\PortalCourseArchiver;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * With background sync on, MyCIC keeps the archive current while the student
 * is away: it logs in with the stored credentials, re-syncs the datasets,
 * crawls the classes when that is due, and logs out again
 * (PortalBackgroundSync).
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
            Bus::assertDispatched(SyncPortalArchiveJob::class, fn (SyncPortalArchiveJob $job): bool => $job->uniqueId() === (string) $account->id && $job->queue === 'background');
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
        config(['portal.background_sync.datasets' => ['skkm'], 'portal.background_sync.courses.enabled' => false]);
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
        $this->assertSame('', $account->password_hash);
        // A refused password is not kept.
        $this->assertNull(PortalOfflineAccount::query()->whereKey($account->id)->toBase()->value('credentials'));
        $this->assertTrue(app(PortalBackgroundSync::class)->status($this->ownerHash)['rejected']);
        $this->travel(4)->hours();
        $this->assertCount(0, app(PortalBackgroundSync::class)->dueAccounts());

        app(PortalOfflineAccess::class)->remember(self::USERNAME, 'password-baru', ['name' => 'Mahasiswa Baru']);

        $account->refresh();
        $this->assertNull($account->credentials_rejected_at);
        $this->assertSame('password-baru', $account->credentials['password']);
        $this->assertCount(1, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_a_login_the_portal_did_not_visibly_refuse_keeps_the_stored_password(): void
    {
        $account = $this->account(self::USERNAME);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andThrow(
                PortalAuthService::unconfirmedRefusal(PortalAuthenticationException::invalidCredentials())
            );
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_UNREACHABLE, $status);
        $account->refresh();
        $this->assertNull($account->credentials_rejected_at);
        $this->assertNotSame('', $account->password_hash);
        $this->assertSame(self::PASSWORD, $account->credentials['password']);
        $this->assertFalse(app(PortalBackgroundSync::class)->status($this->ownerHash)['rejected']);
    }

    public function test_an_unreachable_portal_is_recorded_and_tried_again_next_interval(): void
    {
        $account = $this->account(self::USERNAME);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andThrow(PortalConnectionException::networkError('https://portal.invalid/portal/', 'Connection timed out'));
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

    public function test_a_due_sync_also_archives_every_class_its_meetings_and_files(): void
    {
        $this->courseCrawlIsPossible();
        config(['portal.background_sync.datasets' => ['classes']]);
        $account = $this->account(self::USERNAME, ['last_course_sync_at' => null]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'classes', [])->andReturn($this->classList());
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'class_detail', $this->classParams())->andReturn($this->classDetail());
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'meeting_detail', ['meeting_id' => '445', 'courses_id' => '8810'])->andReturn($this->meeting(3));
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'meeting_detail', ['meeting_id' => '446', 'courses_id' => '8810'])->andReturn($this->meeting(4));
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $account->refresh();
        $this->assertNotNull($account->last_course_sync_at);
        $this->assertNull($account->last_background_sync_error);
        $this->assertSame(
            ['class_detail', 'classes', 'meeting_detail', 'meeting_detail'],
            PortalArchiveVersion::query()->forOwner($this->ownerHash)->orderBy('dataset')->pluck('dataset')->all()
        );
        $files = PortalArchivedFile::query()->forOwner($this->ownerHash)->orderBy('source_url')->get();
        $this->assertSame([
            'https://portal.invalid/portal/files/latihan/abc-3-lat.pdf',
            'https://portal.invalid/portal/files/modul/abc-3-mod.pdf',
            'https://portal.invalid/portal/files/modul/abc-4-mod.pdf',
            'https://portal.invalid/portal/files/sap/rps-basis-data.pdf',
        ], $files->pluck('source_url')->all());
        $this->assertSame(['Basis Data'], $files->pluck('course_name')->unique()->values()->all());
        Storage::disk('local')->assertExists($files->first()->blobPath());
        // The background session is gone once the sync ends: files are stored in it, never queued.
        Bus::assertNotDispatched(ArchivePortalFilesJob::class);
        $this->assertNull(app(PortalSessionManager::class)->activeOnlineSessionTokenForOwner($this->ownerHash));
    }

    public function test_the_class_crawl_waits_for_its_own_interval(): void
    {
        $this->freezeSecond();
        config(['portal.background_sync.datasets' => ['skkm'], 'portal.background_sync.courses.interval' => 1440]);
        $account = $this->account(self::USERNAME, ['last_course_sync_at' => now()->subHours(2)]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'skkm', [])->andReturn(['found' => true, 'entries' => []]);
        }));

        $this->assertFalse(app(PortalBackgroundSync::class)->coursesDue($account));
        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $this->assertSame(0, PortalArchivedFile::query()->count());
        $this->assertTrue($account->refresh()->last_course_sync_at->equalTo(now()->subHours(2)));

        $this->travel(23)->hours();
        $this->assertTrue(app(PortalBackgroundSync::class)->coursesDue($account));
    }

    public function test_the_class_crawl_can_be_switched_off(): void
    {
        config(['portal.background_sync.courses.enabled' => false]);
        $account = $this->account(self::USERNAME, ['last_course_sync_at' => null]);

        $this->assertFalse(app(PortalBackgroundSync::class)->coursesDue($account));
        $this->assertFalse(app(PortalBackgroundSync::class)->status($this->ownerHash)['courses_enabled']);
    }

    public function test_a_meeting_that_cannot_be_read_is_recorded_without_stopping_the_crawl(): void
    {
        $this->courseCrawlIsPossible();
        config(['portal.background_sync.datasets' => ['classes']]);
        $account = $this->account(self::USERNAME, ['last_course_sync_at' => null]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'classes', [])->andReturn($this->classList());
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'class_detail', $this->classParams())->andReturn($this->classDetail());
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'meeting_detail', ['meeting_id' => '445', 'courses_id' => '8810'])->andThrow(new \RuntimeException('Halaman pertemuan tidak terbaca'));
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'meeting_detail', ['meeting_id' => '446', 'courses_id' => '8810'])->andReturn($this->meeting(4));
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_PARTIAL, $status);
        $account->refresh();
        $this->assertSame('materi kelas: Basis Data: pertemuan 445: Halaman pertemuan tidak terbaca', $account->last_background_sync_error);
        $this->assertNotNull($account->last_course_sync_at);
        $this->assertEqualsCanonicalizing(['modul', 'rps'], PortalArchivedFile::query()->pluck('kind')->all());
    }

    public function test_a_crawl_that_archives_nothing_is_tried_again_with_the_next_sync(): void
    {
        $this->courseCrawlIsPossible();
        config(['portal.background_sync.datasets' => ['classes']]);
        $account = $this->account(self::USERNAME, ['last_course_sync_at' => null]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'classes', [])->andReturn($this->classList());
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'class_detail', $this->classParams())->andThrow(new \RuntimeException('Halaman kelas tidak terbaca'));
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_PARTIAL, $status);
        $account->refresh();
        $this->assertSame('materi kelas: kelas 2265: Halaman kelas tidak terbaca', $account->last_background_sync_error);
        $this->assertNull($account->last_course_sync_at);
        $this->assertTrue(app(PortalBackgroundSync::class)->coursesDue($account));
    }

    public function test_the_student_can_switch_background_sync_off_and_on(): void
    {
        $this->portalAcceptsLogin();
        $this->loginAgain();

        $this->getJson('/api/archive')
            ->assertOk()
            ->assertJsonPath('data.background_sync.enabled', true)
            ->assertJsonPath('data.background_sync.active', true)
            ->assertJsonPath('data.background_sync.pending_login', false)
            ->assertJsonPath('data.background_sync.courses_enabled', true)
            ->assertJsonPath('data.background_sync.courses_interval_minutes', 1440)
            ->assertJsonPath('data.background_sync.last_course_sync_at', null);

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

    public function test_credentials_that_cannot_be_decrypted_are_dropped_instead_of_blocking_the_queue(): void
    {
        config(['portal.background_sync.batch_size' => 1]);
        $broken = $this->account(self::USERNAME);
        PortalOfflineAccount::query()->whereKey($broken->id)->toBase()->update(['credentials' => 'sealed-with-an-old-app-key']);
        $waiting = $this->account('20990000021', ['last_background_sync_at' => now()->subHours(5)]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('login');
        }));

        $this->assertTrue(app(PortalBackgroundSync::class)->status($this->ownerHash)['enabled']);
        $status = app(PortalBackgroundSync::class)->syncAccount(PortalOfflineAccount::query()->findOrFail($broken->id));

        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status);
        $this->assertNull(PortalOfflineAccount::query()->whereKey($broken->id)->toBase()->value('credentials'));
        $this->assertStringStartsWith('Password tersimpan tidak bisa dibaca lagi', (string) $broken->refresh()->last_background_sync_error);
        $this->assertTrue(app(PortalBackgroundSync::class)->status($this->ownerHash)['pending_login']);
        $this->assertSame([$waiting->id], app(PortalBackgroundSync::class)->dueAccounts()->modelKeys());
    }

    public function test_a_sync_that_throws_is_recorded_and_waits_for_its_next_interval(): void
    {
        $account = $this->account(self::USERNAME);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('activeOnlineSessionTokenForOwner')->andThrow(new \RuntimeException('Cache tidak bisa dibaca'));
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status);
        $this->assertSame('Cache tidak bisa dibaca', $account->refresh()->last_background_sync_error);
        $this->assertCount(0, app(PortalBackgroundSync::class)->dueAccounts());
        $this->travel(4)->hours();
        $this->assertCount(1, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_a_running_sync_is_stamped_so_the_scheduler_does_not_queue_it_again(): void
    {
        $account = $this->account(self::USERNAME);
        $seen = [];
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock) use (&$seen): void {
            $mock->shouldReceive('login')->once()->andReturnUsing(function () use (&$seen): never {
                $seen = [
                    'stored' => PortalOfflineAccount::query()->sole()->last_background_sync_status,
                    'shown' => app(PortalBackgroundSync::class)->status($this->ownerHash)['last_status'],
                    'due' => app(PortalBackgroundSync::class)->dueAccounts()->count(),
                ];

                throw PortalConnectionException::networkError('https://portal.invalid/portal/', 'Connection timed out');
            });
        }));

        app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(['stored' => PortalBackgroundSync::SYNC_RUNNING, 'shown' => PortalBackgroundSync::SYNC_RUNNING, 'due' => 0], $seen);
        $this->assertSame(PortalOfflineAccount::SYNC_UNREACHABLE, $account->refresh()->last_background_sync_status);
    }

    public function test_a_sync_that_died_reads_as_failed_once_its_job_timeout_has_passed(): void
    {
        $this->account(self::USERNAME, [
            'last_background_sync_at' => now(),
            'last_background_sync_status' => PortalBackgroundSync::SYNC_RUNNING,
        ]);

        $this->assertSame(PortalBackgroundSync::SYNC_RUNNING, app(PortalBackgroundSync::class)->status($this->ownerHash)['last_status']);
        $this->assertCount(0, app(PortalBackgroundSync::class)->dueAccounts());

        $this->travel(SyncPortalArchiveJob::TIMEOUT + 60)->seconds();

        $status = app(PortalBackgroundSync::class)->status($this->ownerHash);
        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status['last_status']);
        $this->assertStringStartsWith('Sinkronisasi terhenti sebelum selesai', (string) $status['last_error']);
    }

    public function test_a_job_that_times_out_records_the_failure_on_the_account(): void
    {
        $account = $this->account(self::USERNAME, ['last_background_sync_status' => PortalBackgroundSync::SYNC_RUNNING]);

        (new SyncPortalArchiveJob($account->id))->failed(new \RuntimeException('Job has timed out'));
        (new SyncPortalArchiveJob(999999))->failed(null);

        $account->refresh();
        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $account->last_background_sync_status);
        $this->assertStringStartsWith('Sinkronisasi terhenti sebelum selesai', (string) $account->last_background_sync_error);
        $this->assertNotNull($account->last_background_sync_at);
    }

    public function test_a_login_with_a_new_password_during_a_rejected_background_login_is_not_flagged(): void
    {
        $account = $this->account(self::USERNAME);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->with(self::USERNAME, self::PASSWORD)->andReturnUsing(function (): never {
                // The student logs in online with their new password meanwhile.
                app(PortalOfflineAccess::class)->remember(self::USERNAME, 'password-baru', ['name' => 'Mahasiswa Baru']);

                throw PortalAuthenticationException::invalidCredentials();
            });
        }));

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status);
        $account->refresh();
        $this->assertNull($account->credentials_rejected_at);
        $this->assertNotSame('', $account->password_hash);
        $this->assertSame('password-baru', $account->credentials['password']);
        $this->assertFalse(app(PortalBackgroundSync::class)->status($this->ownerHash)['rejected']);
        $this->travel(4)->hours();
        $this->assertCount(1, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_a_class_crawl_cut_short_resumes_with_the_classes_it_had_not_archived(): void
    {
        config(['portal.background_sync.datasets' => ['classes']]);
        $classA = ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810'];
        $classB = ['id' => '2266', 'dosen_id' => '5240', 'course_id' => '8811'];
        $account = $this->account(self::USERNAME, ['last_course_sync_at' => null]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->twice()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->twice();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->twice()->with(Mockery::type('string'), 'classes', [])->andReturn($this->classList());
        }));
        $attemptsB = 0;
        $this->instance(PortalCourseArchiver::class, Mockery::mock(PortalCourseArchiver::class, function (MockInterface $mock) use ($classA, $classB, &$attemptsB): void {
            $mock->shouldReceive('classParamsFrom')->andReturn([$classA, $classB]);
            $mock->shouldReceive('archiveClass')->once()->with(Mockery::type('string'), $classA)->andReturn(['course_name' => 'Basis Data', 'meetings' => 0, 'files' => 0, 'errors' => []]);
            $mock->shouldReceive('archiveClass')->twice()->with(Mockery::type('string'), $classB)->andReturnUsing(function () use (&$attemptsB): array {
                if (++$attemptsB === 1) {
                    throw PortalAuthenticationException::sessionExpired();
                }

                return ['course_name' => 'Jaringan Komputer', 'meetings' => 0, 'files' => 0, 'errors' => []];
            });
        }));

        app(PortalBackgroundSync::class)->syncAccount($account);
        $this->assertNull($account->refresh()->last_course_sync_at);

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $this->assertNotNull($account->refresh()->last_course_sync_at);
        $this->assertNull(Cache::get('portal_background_course_crawl_'.$account->id));
    }

    public function test_switching_background_sync_off_and_on_starts_a_cut_short_class_crawl_over(): void
    {
        config(['portal.background_sync.datasets' => ['classes']]);
        $classA = ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810'];
        $classB = ['id' => '2266', 'dosen_id' => '5240', 'course_id' => '8811'];
        $account = $this->account(self::USERNAME, ['last_course_sync_at' => null]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->twice()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->twice();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->twice()->with(Mockery::type('string'), 'classes', [])->andReturn($this->classList());
        }));
        $attemptsB = 0;
        $this->instance(PortalCourseArchiver::class, Mockery::mock(PortalCourseArchiver::class, function (MockInterface $mock) use ($classA, $classB, &$attemptsB): void {
            $mock->shouldReceive('classParamsFrom')->andReturn([$classA, $classB]);
            $mock->shouldReceive('archiveClass')->twice()->with(Mockery::type('string'), $classA)->andReturn(['course_name' => 'Basis Data', 'meetings' => 0, 'files' => 0, 'errors' => []]);
            $mock->shouldReceive('archiveClass')->twice()->with(Mockery::type('string'), $classB)->andReturnUsing(function () use (&$attemptsB): array {
                if (++$attemptsB === 1) {
                    throw PortalAuthenticationException::sessionExpired();
                }

                return ['course_name' => 'Jaringan Komputer', 'meetings' => 0, 'files' => 0, 'errors' => []];
            });
        }));

        app(PortalBackgroundSync::class)->syncAccount($account);
        app(PortalBackgroundSync::class)->setEnabled($this->ownerHash, false);
        app(PortalOfflineAccess::class)->forget($this->ownerHash);
        app(PortalBackgroundSync::class)->setEnabled($this->ownerHash, true);
        $account->refresh()->forceFill(['credentials' => ['username' => self::USERNAME, 'password' => self::PASSWORD], 'last_online_login_at' => now()])->save();

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $this->assertNotNull($account->refresh()->last_course_sync_at);
    }

    public function test_switching_background_sync_off_is_remembered_before_anything_else_is_kept(): void
    {
        $this->assertNull(app(PortalOfflineAccess::class)->findForOwner($this->ownerHash));

        app(PortalBackgroundSync::class)->setEnabled($this->ownerHash, false);

        $account = PortalOfflineAccount::query()->where('owner_hash', $this->ownerHash)->sole();
        $this->assertFalse($account->isBackgroundSyncEnabled());
        $this->assertNull($account->credentials);
        $this->assertFalse(app(PortalBackgroundSync::class)->status($this->ownerHash)['enabled']);
    }

    public function test_a_login_that_moves_the_account_to_the_current_owner_key_records_the_sync_under_that_key(): void
    {
        config(['portal.background_sync.datasets' => ['classes']]);
        $account = $this->account(self::USERNAME, ['last_course_sync_at' => null]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'classes', [])->andReturn($this->classList());
        }));
        $this->instance(PortalCourseArchiver::class, Mockery::mock(PortalCourseArchiver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('classParamsFrom')->andReturn([$this->classParams()]);
            $mock->shouldReceive('archiveClass')->once()->andReturn(['course_name' => 'Basis Data', 'meetings' => 0, 'files' => 0, 'errors' => []]);
        }));
        $logged = $this->captureLogs();
        $currentOwnerHash = $this->rotateOwnerKey();

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $this->assertSame($currentOwnerHash, $account->owner_hash);
        $stored = PortalOfflineAccount::query()->sole();
        $this->assertSame($account->id, $stored->id);
        $this->assertSame($currentOwnerHash, $stored->owner_hash);
        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $stored->last_background_sync_status);
        $this->assertNotNull($stored->last_course_sync_at);
        $this->assertSame([$currentOwnerHash], $this->loggedOwnerHashes($logged, 'Background archive sync crawled the classes'));
        $this->assertSame([$currentOwnerHash], $this->loggedOwnerHashes($logged, 'Background archive sync finished'));
    }

    public function test_a_login_that_drops_the_account_for_the_current_owner_keys_row_stops_the_sync(): void
    {
        config(['portal.background_sync.datasets' => ['skkm']]);
        $earlier = $this->account(self::USERNAME, ['last_course_sync_at' => null]);
        Cache::put('portal_background_course_crawl_'.$earlier->id, ['2265'], 600);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        }));
        $this->instance(PortalCourseArchiver::class, Mockery::mock(PortalCourseArchiver::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('archiveClass');
        }));
        $logged = $this->captureLogs();
        $currentOwnerHash = $this->rotateOwnerKey();
        $current = $this->accountUnderOwnerKey($currentOwnerHash);

        $status = app(PortalBackgroundSync::class)->syncAccount($earlier);

        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status);
        $this->assertNull(PortalOfflineAccount::query()->find($earlier->id));
        $stored = PortalOfflineAccount::query()->sole();
        $this->assertSame($current->id, $stored->id);
        $this->assertNull($stored->last_background_sync_at);
        $this->assertNull($stored->last_background_sync_status);
        $this->assertNull(Cache::get('portal_background_course_crawl_'.$earlier->id));
        $this->assertSame([], $this->loggedOwnerHashes($logged, 'Background archive sync finished'));
        $stops = array_values(array_filter($logged->getArrayCopy(), static fn (MessageLogged $entry): bool => $entry->message === 'Background archive sync stopped: the account moved to the current owner key'));
        $this->assertCount(1, $stops);
        $this->assertSame($earlier->id, $stops[0]->context['account_id']);
        $this->assertArrayNotHasKey('owner_hash', $stops[0]->context);
        $this->assertSame([], array_filter(array_keys($this->cacheEntries()), fn (string $key): bool => str_starts_with($key, (string) config('portal.session_prefix'))));
    }

    public function test_a_login_that_carries_over_an_earlier_background_sync_opt_out_stops_the_sync(): void
    {
        config(['portal.background_sync.datasets' => ['skkm']]);
        PortalOfflineAccount::query()->create([
            'owner_hash' => $this->ownerHash,
            'password_hash' => '',
            'last_online_login_at' => now()->subDays(2),
            'background_sync_disabled_at' => now()->subDays(2),
        ]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        }));
        $currentOwnerHash = $this->rotateOwnerKey();
        $current = $this->accountUnderOwnerKey($currentOwnerHash);

        $status = app(PortalBackgroundSync::class)->syncAccount($current);

        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status);
        $stored = PortalOfflineAccount::query()->sole();
        $this->assertSame($current->id, $stored->id);
        $this->assertFalse($stored->isBackgroundSyncEnabled());
        $this->assertNull($stored->credentials);
        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $stored->last_background_sync_status);
        $this->assertSame('Sinkronisasi latar dimatikan sebelum sinkronisasi dimulai; tidak ada yang disinkronkan.', $stored->last_background_sync_error);
    }

    public function test_an_account_dropped_while_the_active_session_syncs_records_nothing_on_it(): void
    {
        config(['portal.background_sync.datasets' => ['skkm'], 'portal.background_sync.courses.enabled' => false]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldNotReceive('logout');
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->with(Mockery::type('string'), 'skkm', [])->andReturnUsing(function (): array {
                // Any session store resolves the student's owner key.
                app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

                return ['found' => true, 'entries' => []];
            });
        }));
        $this->login()->assertOk();
        $earlier = PortalOfflineAccount::query()->sole();
        $logged = $this->captureLogs();
        $currentOwnerHash = $this->rotateOwnerKey();
        $current = $this->accountUnderOwnerKey($currentOwnerHash);

        $status = app(PortalBackgroundSync::class)->syncAccount($earlier);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $this->assertNull(PortalOfflineAccount::query()->find($earlier->id));
        $stored = PortalOfflineAccount::query()->sole();
        $this->assertSame($current->id, $stored->id);
        $this->assertNull($stored->last_background_sync_status);
        $this->assertSame([], $this->loggedOwnerHashes($logged, 'Background archive sync finished'));
        $this->assertCount(1, array_filter($logged->getArrayCopy(), static fn (MessageLogged $entry): bool => $entry->message === 'Background archive sync stopped: the account moved to the current owner key'));
    }

    public function test_an_account_moved_during_the_class_crawl_is_stamped_and_logged_under_its_current_key(): void
    {
        config(['portal.background_sync.datasets' => []]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldNotReceive('logout');
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->with(Mockery::type('string'), 'classes', [])->andReturn($this->classList());
        }));
        $this->instance(PortalCourseArchiver::class, Mockery::mock(PortalCourseArchiver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('classParamsFrom')->andReturn([$this->classParams()]);
            $mock->shouldReceive('archiveClass')->once()->andReturnUsing(function (): array {
                // Any session store resolves the student's owner key.
                app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

                return ['course_name' => 'Basis Data', 'meetings' => 0, 'files' => 0, 'errors' => []];
            });
        }));
        $this->login()->assertOk();
        $account = PortalOfflineAccount::query()->sole();
        $this->assertNull($account->last_course_sync_at);
        $logged = $this->captureLogs();
        $currentOwnerHash = $this->rotateOwnerKey();

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_SYNCED, $status);
        $stored = PortalOfflineAccount::query()->sole();
        $this->assertSame($currentOwnerHash, $stored->owner_hash);
        $this->assertNotNull($stored->last_course_sync_at);
        $this->assertSame([$currentOwnerHash], $this->loggedOwnerHashes($logged, 'Background archive sync crawled the classes'));
        $this->assertSame([$currentOwnerHash], $this->loggedOwnerHashes($logged, 'Background archive sync finished'));
    }

    public function test_a_sync_that_throws_after_moving_the_account_logs_the_failure_under_its_current_key(): void
    {
        config(['portal.background_sync.datasets' => ['skkm']]);
        $account = $this->account(self::USERNAME);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->once()->andReturn($this->loginResult());
            $mock->shouldReceive('logout')->once();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        }));
        $offlineAccess = Mockery::mock(PortalOfflineAccess::class, [app(PortalDataOwner::class)])->makePartial();
        $offlineAccess->shouldReceive('refreshUserInfo')->once()->andThrow(new \RuntimeException('Info mahasiswa tidak tersimpan'));
        $this->instance(PortalOfflineAccess::class, $offlineAccess);
        $logged = $this->captureLogs();
        $currentOwnerHash = $this->rotateOwnerKey();

        $status = app(PortalBackgroundSync::class)->syncAccount($account);

        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status);
        $stored = PortalOfflineAccount::query()->sole();
        $this->assertSame($currentOwnerHash, $stored->owner_hash);
        $this->assertSame('Info mahasiswa tidak tersimpan', $stored->last_background_sync_error);
        $this->assertSame([$currentOwnerHash], $this->loggedOwnerHashes($logged, 'Background archive sync failed'));
        $this->assertSame([$currentOwnerHash], $this->loggedOwnerHashes($logged, 'Background archive sync finished'));
    }

    public function test_unreadable_credentials_of_an_account_moved_since_the_job_loaded_it_are_logged_under_its_current_key(): void
    {
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('login');
        }));
        $account = $this->account(self::USERNAME);
        PortalOfflineAccount::query()->whereKey($account->id)->toBase()->update(['credentials' => 'sealed-with-an-old-app-key']);
        $loaded = PortalOfflineAccount::query()->findOrFail($account->id);
        $logged = $this->captureLogs();
        $currentOwnerHash = $this->rotateOwnerKey();
        // A login elsewhere resolves the student's key after the job loaded the account.
        app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

        $status = app(PortalBackgroundSync::class)->syncAccount($loaded);

        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status);
        $stored = PortalOfflineAccount::query()->sole();
        $this->assertSame($account->id, $stored->id);
        $this->assertSame($currentOwnerHash, $stored->owner_hash);
        $this->assertNull(PortalOfflineAccount::query()->whereKey($account->id)->toBase()->value('credentials'));
        $this->assertStringStartsWith('Password tersimpan tidak bisa dibaca lagi', (string) $stored->last_background_sync_error);
        $this->assertSame([$currentOwnerHash], $this->loggedOwnerHashes($logged, 'Stored background sync credentials could not be decrypted'));
        $this->assertSame([$currentOwnerHash], $this->loggedOwnerHashes($logged, 'Background archive sync finished'));
    }

    public function test_unreadable_credentials_of_an_account_dropped_since_the_job_loaded_it_leave_the_current_row_alone(): void
    {
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('login');
        }));
        $earlier = $this->account(self::USERNAME);
        PortalOfflineAccount::query()->whereKey($earlier->id)->toBase()->update(['credentials' => 'sealed-with-an-old-app-key']);
        $loaded = PortalOfflineAccount::query()->findOrFail($earlier->id);
        $logged = $this->captureLogs();
        $currentOwnerHash = $this->rotateOwnerKey();
        $current = $this->accountUnderOwnerKey($currentOwnerHash);
        // A login elsewhere resolves the student's key after the job loaded the account.
        app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

        $status = app(PortalBackgroundSync::class)->syncAccount($loaded);

        $this->assertSame(PortalOfflineAccount::SYNC_FAILED, $status);
        $this->assertNull(PortalOfflineAccount::query()->find($earlier->id));
        $stored = PortalOfflineAccount::query()->sole();
        $this->assertSame($current->id, $stored->id);
        $this->assertSame(['username' => self::USERNAME, 'password' => self::PASSWORD], $stored->credentials);
        $this->assertNull($stored->last_background_sync_status);
        $warnings = array_values(array_filter($logged->getArrayCopy(), static fn (MessageLogged $entry): bool => $entry->message === 'Stored background sync credentials could not be decrypted'));
        $this->assertCount(1, $warnings);
        $this->assertNull($warnings[0]->context['owner_hash']);
        $this->assertSame($earlier->id, $warnings[0]->context['account_id']);
        $this->assertSame([], $this->loggedOwnerHashes($logged, 'Background archive sync finished'));
    }

    /**
     * Pin a new owner secret (PORTAL_OWNER_KEY) with the one in use listed
     * as earlier: the next time the student's key is resolved, their rows
     * move to the new key (PortalDataOwner). The PortalDataOwner singleton
     * reads the config on every call, so nothing has to be rebuilt.
     *
     * @return string The student's owner key after the rotation.
     */
    private function rotateOwnerKey(): string
    {
        $secretInUse = config('portal.archive.owner_key') ?: config('app.key');
        config([
            'portal.archive.owner_key' => 'owner-secret-after-rotation',
            'portal.archive.previous_owner_keys' => [$secretInUse],
        ]);

        return 'student_'.substr(hash_hmac('sha256', self::USERNAME, 'owner-secret-after-rotation'), 0, 16);
    }

    /**
     * An account stored directly under `$ownerHash`, without resolving the
     * student's key (which would move the earlier rows first).
     */
    private function accountUnderOwnerKey(string $ownerHash): PortalOfflineAccount
    {
        return PortalOfflineAccount::query()->create([
            'owner_hash' => $ownerHash,
            'password_hash' => 'irrelevant',
            'user_info' => ['name' => 'Mahasiswa Arsip'],
            'credentials' => ['username' => self::USERNAME, 'password' => self::PASSWORD],
            'last_online_login_at' => now()->subDay(),
            'last_course_sync_at' => now(),
        ]);
    }

    /**
     * Every log entry written from now on.
     *
     * @return \ArrayObject<int, MessageLogged>
     */
    private function captureLogs(): \ArrayObject
    {
        $logged = new \ArrayObject;
        Log::listen(static function (MessageLogged $entry) use ($logged): void {
            $logged->append($entry);
        });

        return $logged;
    }

    /**
     * @param  \ArrayObject<int, MessageLogged>  $logged
     * @return list<string|null>
     */
    private function loggedOwnerHashes(\ArrayObject $logged, string $message): array
    {
        return array_values(array_map(
            static fn (MessageLogged $entry): ?string => $entry->context['owner_hash'] ?? null,
            array_filter($logged->getArrayCopy(), static fn (MessageLogged $entry): bool => $entry->message === $message)
        ));
    }

    /**
     * A file disk, a portal that serves every course file, and no notebook
     * ingestion or queued downloads running inline.
     */
    private function courseCrawlIsPossible(): void
    {
        Storage::fake('local');
        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class, SyncPortalArchiveJob::class, ArchivePortalFilesJob::class, IngestNotebookSourceJob::class]);
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('downloadCampusFile')->andReturnUsing(fn (string $token, string $url): array => [
                'filename' => basename($url),
                'content_type' => 'application/pdf',
                'contents' => '%PDF '.$url,
            ]);
        }));
    }

    /**
     * @return array{id: string, dosen_id: string, course_id: string}
     */
    private function classParams(): array
    {
        return ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810'];
    }

    /**
     * @return array<string, mixed>
     */
    private function classList(): array
    {
        return [
            'classes' => [
                ['course_info' => ['name' => 'Basis Data'], 'join_link' => ['params' => $this->classParams()]],
                ['course_info' => ['name' => 'Tanpa link']],
            ],
            'summary' => ['total' => 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classDetail(): array
    {
        return [
            'content_type' => 'class_info',
            'course_info' => ['name' => 'Basis Data', 'rps_link' => '../files/sap/rps-basis-data.pdf'],
            'course_materials' => [
                ['title' => 'Pertemuan 3', 'join_link' => ['href' => '?mod=sipandai&act=discussion&id=445&courses_id=8810']],
                ['title' => 'Pertemuan 4', 'join_link' => ['href' => '?mod=sipandai&amp;act=discussion&amp;id=446&amp;courses_id=8810']],
                ['title' => 'Tanpa link'],
            ],
            'attendance_info' => ['meetings' => []],
            'assignment_history' => ['assignments' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meeting(int $number): array
    {
        return [
            'found' => true,
            'materials' => [
                'main_topic' => "Materi pertemuan {$number}",
                'main_material_iframe' => "../files/modul/abc-{$number}-mod.pdf",
                'assignment_topic' => $number === 3 ? 'Pertemuan 3' : '',
                'assignment_material_iframe' => $number === 3 ? '../files/latihan/abc-3-lat.pdf' : '',
            ],
            'rps_info' => $number === 3 ? ['file_link' => '../files/sap/rps-basis-data.pdf', 'file_text' => 'RPS Basis Data'] : [],
        ];
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
            // Only the crawl tests make the class crawl due.
            'last_course_sync_at' => now(),
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
                'Domain' => 'portal.invalid',
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
