<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshPortalDataJob;
use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Services\PortalDataArchive;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalDataArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-archive';

    private const USERNAME = '20990000003';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RefreshPortalDataJob::class]);
        // Long enough to outlive the clock jumps below.
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 86400 * 60);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_first_build_is_archived_as_trusted_and_an_identical_build_only_counts_as_seen_again(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A', 'B'], '2026-10-22T08:00:00+00:00'));

        $this->repository()->sync(self::TOKEN, 'grades');
        Carbon::setTestNow(now()->addHour());
        $this->repository()->sync(self::TOKEN, 'grades');

        $version = PortalArchiveVersion::query()->sole();
        $this->assertSame($this->ownerHash, $version->owner_hash);
        $this->assertSame(PortalArchiveVersion::STATUS_TRUSTED, $version->status);
        $this->assertSame(2, $version->seen_count);
        $this->assertSame('A', $version->payload['semesters'][0]['courses'][0]['letter']);
        $this->assertStringNotContainsString('Basis Data', (string) PortalArchiveVersion::query()->toBase()->value('payload'));
    }

    public function test_a_build_that_loses_grades_is_held_back_and_the_trusted_version_is_served(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A']));

        $this->repository()->sync(self::TOKEN, 'grades');
        Carbon::setTestNow(now()->addHour());
        $result = $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertCount(2, $result['semesters'][0]['courses']);
        $snapshot = app(PortalSnapshotStore::class)->get(self::TOKEN, 'grades');
        $this->assertCount(2, $snapshot['data']['semesters'][0]['courses']);
        $this->assertSame('suspect', $snapshot['integrity']['status']);
        $this->assertSame('Mata kuliah di KHS berkurang dari 2 menjadi 1.', $snapshot['integrity']['findings'][0]['message']);

        $suspect = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();
        $this->assertSame($suspect->id, $snapshot['integrity']['version_id']);

        $notification = PortalNotification::query()->sole();
        $this->assertSame('archive_suspect', $notification->type);
        $this->assertSame('/archive/grades?version='.$suspect->id, $notification->url);
        $this->assertSame('warning', $notification->urgency);

        $read = $this->repository()->read(self::TOKEN, 'grades');
        $this->assertSame('suspect', $read['meta']['integrity']['status']);
    }

    public function test_a_changed_letter_grade_is_suspect_but_a_newly_released_one_is_trusted(): void
    {
        $this->buildReturns($this->grades(['A', '']), $this->grades(['A', 'B']), $this->grades(['C', 'B']));

        $this->repository()->sync(self::TOKEN, 'grades');
        $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertSame(2, PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_TRUSTED)->count());

        $result = $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertSame('A', $result['semesters'][0]['courses'][0]['letter']);
        $suspect = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();
        $this->assertSame('Nilai huruf berubah pada 1 data: Ganjil 2025/2026|IF101: A → C.', $suspect->findings[0]['message']);
    }

    public function test_a_suspect_version_is_dismissed_when_the_portal_shows_the_trusted_data_again(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A']), $this->grades(['A', 'B']));

        $this->repository()->sync(self::TOKEN, 'grades');
        $this->repository()->sync(self::TOKEN, 'grades');
        $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertSame(PortalArchiveVersion::STATUS_DISMISSED, PortalArchiveVersion::query()->whereNotNull('findings')->sole()->status);
        $this->assertArrayNotHasKey('integrity', app(PortalSnapshotStore::class)->get(self::TOKEN, 'grades'));
    }

    public function test_attendance_that_turns_absent_is_suspect(): void
    {
        $params = ['id' => '91', 'dosen_id' => '7', 'course_id' => '501'];
        $this->buildReturns($this->classDetail(['present', 'present']), $this->classDetail(['present', 'absent']));

        $this->repository()->sync(self::TOKEN, 'class_detail', $params);
        $result = $this->repository()->sync(self::TOKEN, 'class_detail', $params);

        $this->assertSame('present', $result['attendance_info']['meetings'][1]['status']);
        $suspect = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();
        $this->assertSame('Status presensi pertemuan berubah pada 1 data: 2: Hadir → Absen.', $suspect->findings[0]['message']);
        $this->assertSame('Basis Data', $suspect->subject);
        $this->assertArrayNotHasKey('students_list', $suspect->payload);
    }

    public function test_a_missing_snapshot_is_restored_from_the_archive_and_resynced_in_the_background(): void
    {
        $this->buildReturns($this->grades(['A', 'B']));
        $this->repository()->sync(self::TOKEN, 'grades');
        app(PortalSnapshotStore::class)->forget(self::TOKEN, 'grades');

        Carbon::setTestNow(now()->addDays(10));
        $result = $this->repository()->read(self::TOKEN, 'grades');

        $this->assertSame('archive', $result['meta']['data_origin']);
        $this->assertSame('syncing', $result['meta']['status']);
        $this->assertCount(2, $result['data']['semesters'][0]['courses']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_an_unreadable_page_is_not_archived_when_nothing_is_trusted_yet(): void
    {
        $this->buildReturns(['found' => false, 'semesters' => []]);

        $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertSame(0, PortalArchiveVersion::query()->count());
    }

    public function test_an_unreadable_page_after_a_trusted_version_is_held_back(): void
    {
        $this->buildReturns($this->grades(['A']), ['found' => false, 'semesters' => []]);

        $this->repository()->sync(self::TOKEN, 'grades');
        $result = $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertTrue($result['found']);
        $this->assertSame('requires', PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole()->findings[0]['rule']);
    }

    public function test_sessions_without_a_known_student_are_not_archived(): void
    {
        $this->buildReturns($this->grades(['A']));

        $this->repository()->sync('token-of-nobody', 'grades');

        $this->assertSame(0, PortalArchiveVersion::query()->count());
    }

    public function test_accepting_a_suspect_version_makes_it_the_served_data(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A']));
        $this->repository()->sync(self::TOKEN, 'grades');
        $this->repository()->sync(self::TOKEN, 'grades');
        $suspect = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();

        app(PortalDataArchive::class)->accept(self::TOKEN, $suspect);

        $this->assertSame(PortalArchiveVersion::STATUS_TRUSTED, $suspect->fresh()->status);
        $snapshot = app(PortalSnapshotStore::class)->get(self::TOKEN, 'grades');
        $this->assertCount(1, $snapshot['data']['semesters'][0]['courses']);
        $this->assertArrayNotHasKey('integrity', $snapshot);
    }

    public function test_a_rejected_version_stays_rejected_when_the_portal_keeps_showing_it(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A']), $this->grades(['A']));
        $this->repository()->sync(self::TOKEN, 'grades');
        $this->repository()->sync(self::TOKEN, 'grades');
        $suspect = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();

        app(PortalDataArchive::class)->reject(self::TOKEN, $suspect);
        $result = $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertSame(PortalArchiveVersion::STATUS_REJECTED, $suspect->fresh()->status);
        $this->assertCount(2, $result['semesters'][0]['courses']);
        $this->assertSame(1, PortalNotification::query()->count());
    }

    public function test_old_versions_are_pruned_but_the_current_one_is_kept(): void
    {
        config(['portal.archive.max_versions' => 3]);
        $payloads = array_map(fn (int $index): array => $this->grades(array_fill(0, $index, 'A')), range(1, 5));
        $this->buildReturns(...$payloads);

        foreach (array_keys($payloads) as $_) {
            $this->repository()->sync(self::TOKEN, 'grades');
            Carbon::setTestNow(now()->addMinute());
        }

        $this->assertSame(3, PortalArchiveVersion::query()->count());
        $current = PortalArchiveVersion::query()->trusted()->latest('trusted_at')->first();
        $this->assertCount(5, $current->payload['semesters'][0]['courses']);
    }

    public function test_diff_lists_changed_leaves_with_readable_paths(): void
    {
        $diff = app(PortalDataArchive::class)->diff(
            ['summary' => ['ipk' => 3.5], 'extracted_at' => 'a', 'items' => [['name' => 'A']]],
            ['summary' => ['ipk' => 3.25], 'extracted_at' => 'b', 'items' => [['name' => 'A'], ['name' => 'B']]]
        );

        $this->assertSame([
            ['path' => 'summary › ipk', 'kind' => 'changed', 'old' => '3.5', 'new' => '3.25'],
            ['path' => 'items › #2 › name', 'kind' => 'added', 'old' => null, 'new' => 'B'],
        ], $diff);
    }

    /**
     * @param  list<string>  $letters
     * @return array<string, mixed>
     */
    private function grades(array $letters, string $extractedAt = '2026-10-21T08:00:00+00:00'): array
    {
        $names = ['Basis Data', 'Jaringan Komputer', 'Statistika', 'Kalkulus', 'Etika Profesi'];

        return [
            'found' => true,
            'semesters' => [[
                'label' => 'Ganjil 2025/2026',
                'courses' => array_map(static fn (string $letter, int $index): array => [
                    'code' => 'IF10'.($index + 1),
                    'name' => $names[$index],
                    'sks' => 3,
                    'letter' => $letter,
                ], $letters, array_keys($letters)),
            ]],
            'extracted_at' => $extractedAt,
        ];
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, mixed>
     */
    private function classDetail(array $statuses): array
    {
        return [
            'content_type' => 'students_list',
            'course_info' => ['name' => 'Basis Data'],
            'attendance_info' => [
                'meetings' => array_map(static fn (string $status, int $index): array => [
                    'meeting_number' => $index + 1,
                    'status' => $status,
                ], $statuses, array_keys($statuses)),
            ],
            'assignment_history' => ['assignments' => []],
            'students_list' => [['name' => 'Teman Sekelas']],
        ];
    }

    /**
     * @param  array<string, mixed>  ...$payloads
     */
    private function buildReturns(array ...$payloads): void
    {
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock) use ($payloads): void {
            $mock->shouldReceive('build')->andReturn(...$payloads);
        }));
    }

    private function repository(): PortalDatasetRepository
    {
        return app(PortalDatasetRepository::class);
    }
}
