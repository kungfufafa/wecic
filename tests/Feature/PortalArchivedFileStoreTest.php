<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalAuthenticationException;
use App\Jobs\ArchiveCourseMaterialsJob;
use App\Jobs\ArchivePortalFilesJob;
use App\Models\PortalArchivedFile;
use App\Models\PortalNotification;
use App\Services\PortalArchivedFileStore;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PortalArchivedFileStoreTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-files';

    private const USERNAME = '20990000006';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_meeting_yields_its_material_assignment_and_rps_files(): void
    {
        $items = $this->store()->candidatesFromMeeting($this->meeting(), ['meeting_id' => '445', 'courses_id' => '8810']);

        $this->assertSame([
            ['kind' => 'modul', 'source_url' => 'https://portal.invalid/portal/files/modul/abc-3-mod.pdf', 'title' => 'Normalisasi Data', 'course_key' => '8810', 'meeting_id' => '445', 'meeting_number' => 3],
            ['kind' => 'latihan', 'source_url' => 'https://portal.invalid/portal/files/latihan/abc-3-lat.pdf', 'title' => 'Pertemuan 3', 'course_key' => '8810', 'meeting_id' => '445', 'meeting_number' => 3],
            ['kind' => 'rps', 'source_url' => 'https://portal.invalid/portal/files/sap/rps-basis-data.pdf', 'title' => 'RPS Basis Data', 'course_key' => '8810', 'meeting_id' => null, 'meeting_number' => null],
        ], $items);
    }

    public function test_links_to_portal_pages_are_never_archived(): void
    {
        $meeting = $this->meeting();
        $meeting['materials']['main_material_iframe'] = '../sipandai/?mod=sipandai&act=jobdel&id=5';
        $meeting['materials']['assignment_material_iframe'] = 'https://evil.example/files/modul/x.pdf';
        $meeting['rps_info']['file_link'] = '';

        $this->assertSame([], $this->store()->candidatesFromMeeting($meeting, ['meeting_id' => '445', 'courses_id' => '8810']));
    }

    public function test_a_downloaded_file_is_stored_once_by_content(): void
    {
        $this->portalServes('%PDF-1.4 modul normalisasi');
        [$item] = $this->store()->candidatesFromMeeting($this->meeting(), ['meeting_id' => '445', 'courses_id' => '8810']);

        $file = $this->store()->store(self::TOKEN, $item, 'Basis Data');
        $again = $this->store()->store(self::TOKEN, $item, 'Basis Data');

        $this->assertTrue($file->is($again));
        $this->assertSame($this->ownerHash, $file->owner_hash);
        $this->assertSame('Basis Data', $file->course_name);
        $this->assertSame(hash('sha256', '%PDF-1.4 modul normalisasi'), $file->sha256);
        Storage::disk('local')->assertExists($file->blobPath());
        $this->assertSame('%PDF-1.4 modul normalisasi', $this->store()->contents($file));
    }

    public function test_files_over_the_size_limit_are_refused(): void
    {
        config(['portal.archive.files.max_file_size' => 10]);
        $this->portalServes('%PDF-1.4 lebih dari sepuluh byte');
        [$item] = $this->store()->candidatesFromMeeting($this->meeting(), ['meeting_id' => '445', 'courses_id' => '8810']);

        $this->expectException(RuntimeException::class);

        $this->store()->store(self::TOKEN, $item);
    }

    public function test_building_a_meeting_queues_only_the_files_not_archived_yet(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $rps = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['source_url' => 'https://portal.invalid/portal/files/sap/rps-basis-data.pdf']);
        Storage::disk('local')->put($rps->blobPath(), '%PDF rps');
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->andReturn($this->meeting());
        }));
        // Only a course of the student's class list archives the files its meetings link to.
        config(['notebook.course_notebooks.auto_add_opened_meetings' => false]);
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'lms_classes', ['classes' => [
            ['course_info' => ['code' => 'UC01024011', 'name' => 'Basis Data'], 'join_link' => ['params' => ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810']]],
        ]]);

        app(PortalDatasetRepository::class)->sync(self::TOKEN, 'meeting_detail', ['meeting_id' => '445', 'courses_id' => '8810']);

        Queue::assertPushed(ArchivePortalFilesJob::class, 1);
        Queue::assertPushed(ArchivePortalFilesJob::class, fn (ArchivePortalFilesJob $job): bool => $job->uniqueId() === md5(self::TOKEN.'|'.implode(',', [
            'https://portal.invalid/portal/files/modul/abc-3-mod.pdf',
            'https://portal.invalid/portal/files/latihan/abc-3-lat.pdf',
        ])));
    }

    public function test_a_file_whose_copy_went_missing_counts_as_not_archived(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $rps = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['source_url' => 'https://portal.invalid/portal/files/sap/rps-basis-data.pdf']);

        $this->assertFalse($this->store()->isArchived($this->ownerHash, $rps->source_url));

        Storage::disk('local')->put($rps->blobPath(), '%PDF rps');

        $this->assertTrue($this->store()->isArchived($this->ownerHash, $rps->source_url));
        $this->assertFalse($this->store()->isArchived('student_someone_else', $rps->source_url));
    }

    public function test_the_file_job_downloads_a_file_again_when_its_copy_went_missing(): void
    {
        $this->sessionIsUsable();
        $this->portalServes('%PDF-1.4 modul normalisasi');
        [$item] = $this->store()->candidatesFromMeeting($this->meeting(), ['meeting_id' => '445', 'courses_id' => '8810']);
        $broken = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create([
            'source_url' => $item['source_url'],
            'sha256' => hash('sha256', '%PDF-1.4 modul normalisasi'),
        ]);

        app()->call([new ArchivePortalFilesJob(self::TOKEN, [$item], 'Basis Data'), 'handle']);

        $this->assertSame(1, PortalArchivedFile::query()->forOwner($this->ownerHash)->count());
        Storage::disk('local')->assertExists($broken->blobPath());
        $this->assertSame('%PDF-1.4 modul normalisasi', $this->store()->contents($broken->fresh()));
    }

    public function test_a_shared_copy_is_never_deleted_while_a_student_starts_using_it(): void
    {
        $this->portalServes('%PDF-1.4 modul normalisasi');
        [$item] = $this->store()->candidatesFromMeeting($this->meeting(), ['meeting_id' => '445', 'courses_id' => '8810']);
        $lockKey = 'portal_archive_blob_'.hash('sha256', '%PDF-1.4 modul normalisasi');
        $lockedWhileStoring = null;
        PortalArchivedFile::creating(function () use ($lockKey, &$lockedWhileStoring): void {
            $probe = Cache::lock($lockKey, 1);
            $lockedWhileStoring = ! $probe->get();
            $probe->release();
        });

        $file = $this->store()->store(self::TOKEN, $item, 'Basis Data');

        $this->assertTrue($lockedWhileStoring);
        $this->assertTrue(Cache::lock($lockKey, 1)->get());
        Storage::disk('local')->assertExists($file->blobPath());
    }

    public function test_the_course_job_leaves_a_notification_when_the_session_ended_before_it_ran(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isSessionUsable')->andReturnFalse();
        }));
        $this->instance(PortalBroadcastService::class, Mockery::mock(PortalBroadcastService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dataInvalidated')->once()->withArgs(fn (string $sessionId, string $token, array $cacheKeys, array $syncKeys, string $message): bool => str_starts_with($message, 'Materi kelas belum diarsipkan')
                && in_array('/api/notifications', $cacheKeys, true));
        }));
        $job = new ArchiveCourseMaterialsJob('laravel-session', self::TOKEN, ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810']);
        // The student logged out meanwhile: the token no longer leads to them.
        app(PortalDataOwner::class)->forget(self::TOKEN);

        app()->call([$job, 'handle']);

        $notification = PortalNotification::query()->sole();
        $this->assertSame($this->ownerHash, $notification->owner_hash);
        $this->assertSame('archive_class_not_run', $notification->type);
        $this->assertSame('/archive', $notification->url);
        $this->assertSame('warning', $notification->urgency);
        $this->assertSame('class:2265', $notification->record_key);
    }

    public function test_the_course_job_leaves_a_notification_when_the_session_expires_while_it_runs(): void
    {
        $this->sessionIsUsable();
        $classParams = ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810'];
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock) use ($classParams): void {
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'class_detail', $classParams)->andThrow(PortalAuthenticationException::sessionExpired());
        }));
        $this->instance(PortalBroadcastService::class, Mockery::mock(PortalBroadcastService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dataInvalidated')->once();
        }));

        app()->call([new ArchiveCourseMaterialsJob('laravel-session', self::TOKEN, $classParams), 'handle']);

        $this->assertSame('archive_class_not_run', PortalNotification::query()->forOwner($this->ownerHash)->sole()->type);
    }

    public function test_the_file_job_downloads_each_missing_file_and_skips_failures(): void
    {
        $this->sessionIsUsable();
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('downloadCampusFile')->andReturnUsing(function (string $token, string $url): array {
                if (str_contains($url, 'latihan')) {
                    throw new RuntimeException('Expected file response');
                }

                return ['filename' => basename($url), 'content_type' => 'application/pdf', 'contents' => '%PDF '.$url];
            });
        }));
        $items = $this->store()->candidatesFromMeeting($this->meeting(), ['meeting_id' => '445', 'courses_id' => '8810']);

        app()->call([new ArchivePortalFilesJob(self::TOKEN, $items, 'Basis Data'), 'handle']);

        $this->assertEqualsCanonicalizing(['modul', 'rps'], PortalArchivedFile::query()->pluck('kind')->all());
    }

    public function test_the_course_job_archives_every_meeting_of_a_class(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $this->sessionIsUsable();
        $this->portalServes('%PDF materi');
        $classParams = ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810'];
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock) use ($classParams): void {
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'class_detail', $classParams)->andReturn([
                'course_info' => ['name' => 'Basis Data', 'rps_link' => ''],
                'course_materials' => [
                    ['title' => 'Pertemuan 3', 'join_link' => ['href' => '?mod=sipandai&act=discussion&id=445&courses_id=8810']],
                    ['title' => 'Pertemuan 4', 'join_link' => ['href' => '?mod=sipandai&amp;act=discussion&amp;id=446&amp;courses_id=8810']],
                    ['title' => 'Tanpa link'],
                ],
            ]);
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'meeting_detail', ['meeting_id' => '445', 'courses_id' => '8810'])->andReturn($this->meeting());
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'meeting_detail', ['meeting_id' => '446', 'courses_id' => '8810'])->andReturn(['materials' => [], 'rps_info' => []]);
        }));
        $this->instance(PortalBroadcastService::class, Mockery::mock(PortalBroadcastService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dataInvalidated')->once()->withArgs(fn (string $sessionId, string $token, array $cacheKeys, array $syncKeys, string $message): bool => $message === '3 berkas materi baru tersimpan di arsip MyCIC.');
        }));

        app()->call([new ArchiveCourseMaterialsJob('laravel-session', self::TOKEN, $classParams), 'handle']);

        $this->assertSame(3, PortalArchivedFile::query()->forOwner($this->ownerHash)->count());
        $this->assertSame(['Basis Data'], PortalArchivedFile::query()->distinct()->pluck('course_name')->all());
    }

    private function sessionIsUsable(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isSessionUsable')->andReturnTrue();
        }));
    }

    private function portalServes(string $contents): void
    {
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($contents): void {
            $mock->shouldReceive('downloadCampusFile')->andReturnUsing(fn (string $token, string $url): array => [
                'filename' => basename($url),
                'content_type' => 'application/pdf',
                'contents' => $contents,
            ]);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function meeting(): array
    {
        return [
            'found' => true,
            'materials' => [
                'main_topic' => 'Normalisasi Data',
                'main_material_iframe' => '../files/modul/abc-3-mod.pdf',
                'assignment_topic' => 'Pertemuan 3',
                'assignment_material_iframe' => '../files/latihan/abc-3-lat.pdf',
            ],
            'rps_info' => ['file_link' => '../files/sap/rps-basis-data.pdf', 'file_text' => 'RPS Basis Data'],
        ];
    }

    private function store(): PortalArchivedFileStore
    {
        return app(PortalArchivedFileStore::class);
    }
}
