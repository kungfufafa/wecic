<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ArchivePortalFilesJob;
use App\Jobs\RefreshPortalDataJob;
use App\Models\Notebook;
use App\Models\NotebookDismissal;
use App\Models\NotebookSource;
use App\Models\PortalArchiveVersion;
use App\Services\PortalDataArchive;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * What a meeting snapshot does beyond any other dataset: a submission status
 * that could not be checked is retried soon and never trusted over the
 * archive, its files are archived only for the student's own courses, and
 * the meeting page learns when the course notebook is too full to take it.
 */
class PortalMeetingDetailSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-meeting-snapshot';

    private const USERNAME = '20990000011';

    private const MEETING_PARAMS = ['meeting_id' => '445', 'courses_id' => '8810'];

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake([RefreshPortalDataJob::class, ArchivePortalFilesJob::class]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldReceive('isSessionUsable')->andReturnTrue();
            $mock->shouldReceive('isBackgroundSession')->andReturnFalse();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    public function test_an_unresolved_submission_status_is_checked_again_after_a_minute(): void
    {
        $this->mockBuilds(0);
        $twoMinutesAgo = now()->subMinutes(2)->toISOString();
        $this->snapshots()->put(self::TOKEN, 'meeting_detail', $this->meeting('uploaded'), $twoMinutesAgo, self::MEETING_PARAMS);

        $this->assertSame('synced', $this->repository()->read(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS)['meta']['status']);
        Queue::assertNotPushed(RefreshPortalDataJob::class);

        $this->snapshots()->put(self::TOKEN, 'meeting_detail', $this->meeting('unresolved'), $twoMinutesAgo, self::MEETING_PARAMS);
        $result = $this->repository()->read(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS);

        $this->assertSame('unresolved', $result['data']['assignment_info']['status']);
        $this->assertSame('syncing', $result['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);

        // Within the minute it is still served as is.
        $this->snapshots()->put(self::TOKEN, 'meeting_detail', $this->meeting('unresolved'), now()->subSeconds(30)->toISOString(), self::MEETING_PARAMS);

        $this->assertSame('synced', $this->repository()->read(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS)['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_an_unresolved_submission_status_never_replaces_the_trusted_archive(): void
    {
        $builds = [$this->meeting('uploaded'), $this->meeting('unresolved')];
        $this->mockBuilds(2, $builds);

        $this->repository()->sync(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS);
        $served = $this->repository()->sync(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS);

        $this->assertSame('unresolved', $served['assignment_info']['status']);
        $this->assertSame(1, PortalArchiveVersion::query()->count());
        $this->assertSame('uploaded', app(PortalDataArchive::class)->restore(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS)['data']['assignment_info']['status']);
    }

    public function test_files_of_a_meeting_are_archived_only_for_a_course_the_student_takes(): void
    {
        config(['notebook.course_notebooks.auto_add_opened_meetings' => false]);
        $this->mockBuilds(2, [$this->meeting('uploaded'), $this->meeting('uploaded')]);

        // A meeting link of another class, shared by a classmate.
        $this->repository()->sync(self::TOKEN, 'meeting_detail', ['meeting_id' => '41002', 'courses_id' => '9310']);
        Queue::assertNotPushed(ArchivePortalFilesJob::class);

        $this->snapshots()->put(self::TOKEN, 'lms_classes', ['classes' => [
            ['course_info' => ['code' => 'UC01024011', 'name' => 'Basis Data'], 'lecturer_info' => ['name' => 'Dosen A'], 'join_link' => ['params' => ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810']]],
        ]]);
        $this->repository()->sync(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS);

        Queue::assertPushed(ArchivePortalFilesJob::class, 1);
    }

    public function test_the_meeting_page_learns_when_the_course_notebook_is_too_full_to_take_the_meeting(): void
    {
        config(['notebook.limits.max_course_sources' => 1]);
        $this->mockBuilds(0);
        $this->snapshots()->put(self::TOKEN, 'meeting_detail', $this->meeting('uploaded'), params: self::MEETING_PARAMS);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course('8810')->create();
        $other = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'kind' => NotebookSource::KIND_MEETING, 'meeting_id' => '444']);

        $this->getMeeting()->assertOk()->assertJsonPath('meta.notebook_limit_reached', true);

        // Removed from the notebook by the student: left out by choice, not for lack of room.
        $dismissal = NotebookDismissal::factory()->meeting('445')->create(['notebook_id' => $notebook->id]);
        $this->getMeeting()->assertOk()->assertJsonMissingPath('meta.notebook_limit_reached');
        $dismissal->delete();

        // Room again.
        $other->delete();
        $this->getMeeting()->assertOk()->assertJsonMissingPath('meta.notebook_limit_reached');

        // Already in the notebook, which is full now.
        NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'kind' => NotebookSource::KIND_MEETING, 'meeting_id' => '445']);
        $this->getMeeting()->assertOk()->assertJsonMissingPath('meta.notebook_limit_reached');
    }

    public function test_a_meeting_of_a_course_without_a_notebook_is_never_reported_full(): void
    {
        config(['notebook.limits.max_course_sources' => 1]);
        $this->mockBuilds(0);
        $this->snapshots()->put(self::TOKEN, 'meeting_detail', $this->meeting('uploaded'), params: self::MEETING_PARAMS);

        $this->getMeeting()->assertOk()->assertJsonMissingPath('meta.notebook_limit_reached');
    }

    private function getMeeting(): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/meeting/445?courses_id=8810');
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function mockBuilds(int $times, array $payloads = []): void
    {
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock) use ($times, $payloads): void {
            $times === 0
                ? $mock->shouldNotReceive('build')
                : $mock->shouldReceive('build')->times($times)->andReturn(...$payloads);
        }));
    }

    private function repository(): PortalDatasetRepository
    {
        return app(PortalDatasetRepository::class);
    }

    private function snapshots(): PortalSnapshotStore
    {
        return app(PortalSnapshotStore::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function meeting(string $assignmentStatus): array
    {
        return [
            'found' => true,
            'meeting_info' => ['breadcrumb' => 'Kelas Basis Data > Pertemuan 3'],
            'lecturer_info' => ['name' => 'Dosen A'],
            'materials' => [
                'main_topic' => 'Pokok Bahasan : Normalisasi Data',
                'main_material_iframe' => '../files/modul/abc-3-mod.pdf',
                'assignment_topic' => 'Pertemuan 3',
                'assignment_material_iframe' => '../files/latihan/abc-3-lat.pdf',
            ],
            'assignment_info' => ['deadline' => 'Kamis, 16 April 2026', 'meeting_number' => '3', 'status' => $assignmentStatus],
            'rps_info' => ['file_link' => '', 'file_text' => ''],
        ];
    }
}
