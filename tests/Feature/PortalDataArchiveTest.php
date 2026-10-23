<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshPortalDataJob;
use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Services\PortalDataArchive;
use App\Services\PortalDataCache;
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
        $this->assertSame('/archive/grades?params_hash='.$suspect->params_hash.'&version='.$suspect->id, $notification->url);
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
            ['path' => 'items › #2 (baru) › name', 'kind' => 'added', 'old' => null, 'new' => 'B'],
        ], $diff);
    }

    public function test_the_moment_a_build_began_is_stored_in_the_application_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');
        $observedAt = '2026-10-22T02:27:00.000000Z';

        app(PortalDataArchive::class)->record(self::TOKEN, 'grades', [], $this->grades(['A']), $observedAt);

        $version = PortalArchiveVersion::query()->sole();
        $this->assertTrue($version->first_seen_at->equalTo(Carbon::parse($observedAt)));
        $this->assertTrue($version->last_seen_at->equalTo(Carbon::parse($observedAt)));
        $this->assertTrue($version->trusted_at->equalTo(Carbon::parse($observedAt)));
        $this->assertSame('2026-10-22 09:27:00', PortalArchiveVersion::query()->toBase()->value('last_seen_at'));
        $grades = collect(app(PortalDataArchive::class)->summary($this->ownerHash))->firstWhere('dataset', 'grades');
        $this->assertSame('2026-10-22T02:27:00.000000Z', $grades['last_seen_at']);
    }

    public function test_a_removed_payment_shows_as_removed_instead_of_shifting_the_payments_after_it(): void
    {
        $old = ['payment_history' => [$this->payment('R1', 100, '2026-01-05'), $this->payment('R2', 200, '2026-02-05'), $this->payment('R3', 300, '2026-03-05')]];
        $new = ['payment_history' => [$this->payment('R1', 100, '2026-01-05'), $this->payment('R3', 300, '2026-03-05')]];
        $expected = [
            ['path' => 'payment history › #2 › paid at', 'kind' => 'removed', 'old' => '2026-02-05', 'new' => null],
            ['path' => 'payment history › #2 › reference', 'kind' => 'removed', 'old' => 'R2', 'new' => null],
            ['path' => 'payment history › #2 › amount', 'kind' => 'removed', 'old' => '200', 'new' => null],
        ];

        $this->assertSame($expected, app(PortalDataArchive::class)->diff($old, $new, dataset: 'payments'));
        $this->assertSame($expected, app(PortalDataArchive::class)->diff($old, $new));
    }

    public function test_a_removed_row_next_to_a_changed_one_keeps_both_apart(): void
    {
        $old = ['payment_history' => [$this->payment('R1', 100, '2026-01-05'), $this->payment('R2', 200, '2026-02-05'), $this->payment('R3', 300, '2026-03-05')]];
        $new = ['payment_history' => [$this->payment('R1', 100, '2026-01-05'), $this->payment('R3', 350, '2026-03-05')]];
        $expected = [
            ['path' => 'payment history › #2 › paid at', 'kind' => 'removed', 'old' => '2026-02-05', 'new' => null],
            ['path' => 'payment history › #2 › reference', 'kind' => 'removed', 'old' => 'R2', 'new' => null],
            ['path' => 'payment history › #2 › amount', 'kind' => 'removed', 'old' => '200', 'new' => null],
            ['path' => 'payment history › #3 › amount', 'kind' => 'changed', 'old' => '300', 'new' => '350'],
        ];

        $this->assertSame($expected, app(PortalDataArchive::class)->diff($old, $new, dataset: 'payments'));
        $this->assertSame($expected, app(PortalDataArchive::class)->diff($old, $new));
    }

    public function test_a_course_lost_from_the_middle_of_a_semester_is_the_only_difference(): void
    {
        $old = ['found' => true, 'semesters' => [
            $this->khsSemester('Semester 2 - 2024/2025', 2, ['IF201' => 'A', 'IF202' => 'B', 'IF203' => 'C', 'IF204' => 'A', 'IF205' => 'B', 'IF206' => 'A', 'IF207' => 'B', 'IF208' => 'A']),
            $this->khsSemester('Semester 1 - 2024/2025', 1, ['IF101' => 'A', 'IF102' => 'B', 'IF103' => 'A', 'IF104' => 'B']),
        ]];
        $new = $old;
        $new['semesters'][0] = $this->khsSemester('Semester 2 - 2024/2025', 2, ['IF201' => 'A', 'IF202' => 'B', 'IF204' => 'A', 'IF205' => 'B', 'IF206' => 'A', 'IF207' => 'B', 'IF208' => 'A']);

        $expected = [
            ['path' => 'semesters › #1 › courses › #3 › code', 'kind' => 'removed', 'old' => 'IF203', 'new' => null],
            ['path' => 'semesters › #1 › courses › #3 › name', 'kind' => 'removed', 'old' => 'Mata Kuliah IF203', 'new' => null],
            ['path' => 'semesters › #1 › courses › #3 › sks', 'kind' => 'removed', 'old' => '3', 'new' => null],
            ['path' => 'semesters › #1 › courses › #3 › components › tugas', 'kind' => 'removed', 'old' => '80', 'new' => null],
            ['path' => 'semesters › #1 › courses › #3 › score', 'kind' => 'removed', 'old' => '65', 'new' => null],
            ['path' => 'semesters › #1 › courses › #3 › letter', 'kind' => 'removed', 'old' => 'C', 'new' => null],
            ['path' => 'semesters › #1 › courses › #3 › weight', 'kind' => 'removed', 'old' => '2', 'new' => null],
            ['path' => 'semesters › #1 › courses › #3 › quality points', 'kind' => 'removed', 'old' => '6', 'new' => null],
            ['path' => 'semesters › #1 › courses › #3 › is graded', 'kind' => 'removed', 'old' => 'ya', 'new' => null],
            ['path' => 'semesters › #1 › course count', 'kind' => 'changed', 'old' => '8', 'new' => '7'],
            ['path' => 'semesters › #1 › graded count', 'kind' => 'changed', 'old' => '8', 'new' => '7'],
            ['path' => 'semesters › #1 › total sks', 'kind' => 'changed', 'old' => '24', 'new' => '21'],
            ['path' => 'semesters › #1 › total quality points', 'kind' => 'changed', 'old' => '81', 'new' => '75'],
            ['path' => 'semesters › #1 › ips', 'kind' => 'changed', 'old' => '3.38', 'new' => '3.57'],
        ];

        $this->assertSame($expected, app(PortalDataArchive::class)->diff($old, $new, dataset: 'grades'));
        $this->assertSame($expected, app(PortalDataArchive::class)->diff($old, $new));
    }

    public function test_a_list_the_portal_only_sorted_differently_shows_no_removed_or_added_rows(): void
    {
        $old = ['payment_history' => [$this->payment('R1', 100, '2026-01-05'), $this->payment('R2', 200, '2026-02-05'), $this->payment('R3', 300, '2026-03-05')]];
        $new = ['payment_history' => [$this->payment('R3', 300, '2026-03-05'), $this->payment('R1', 100, '2026-01-05'), $this->payment('R2', 250, '2026-02-05')]];

        $this->assertSame([
            ['path' => 'payment history › #2 › amount', 'kind' => 'changed', 'old' => '200', 'new' => '250'],
        ], app(PortalDataArchive::class)->diff($old, $new, dataset: 'payments'));
    }

    public function test_a_row_only_the_newer_version_has_is_marked_as_new_instead_of_reusing_an_old_number(): void
    {
        $old = ['payment_history' => [$this->payment('R1', 100, '2026-01-05'), $this->payment('R2', 200, '2026-02-05')]];
        $new = ['payment_history' => [$this->payment('R0', 50, '2025-12-05'), ...$old['payment_history']]];

        $expected = [
            ['path' => 'payment history › #1 (baru) › paid at', 'kind' => 'added', 'old' => null, 'new' => '2025-12-05'],
            ['path' => 'payment history › #1 (baru) › reference', 'kind' => 'added', 'old' => null, 'new' => 'R0'],
            ['path' => 'payment history › #1 (baru) › amount', 'kind' => 'added', 'old' => null, 'new' => '50'],
        ];

        $this->assertSame($expected, app(PortalDataArchive::class)->diff($old, $new, dataset: 'payments'));
        $this->assertSame($expected, app(PortalDataArchive::class)->diff($old, $new));
    }

    public function test_one_unkeyed_row_changed_in_place_reads_as_changed_fields(): void
    {
        $point = static fn (float $ips, int $sks, int $count): array => ['label' => 'Semester 1', 'ips' => $ips, 'sks' => $sks, 'course_count' => $count, 'graded_count' => $count, 'is_complete' => true];
        $old = ['series' => [$point(3.5, 6, 2), $point(3.25, 24, 8)]];
        $new = ['series' => [$point(3.5, 6, 2), $point(3.43, 21, 7)]];

        $this->assertSame([
            ['path' => 'series › #2 › ips', 'kind' => 'changed', 'old' => '3.25', 'new' => '3.43'],
            ['path' => 'series › #2 › sks', 'kind' => 'changed', 'old' => '24', 'new' => '21'],
            ['path' => 'series › #2 › course count', 'kind' => 'changed', 'old' => '8', 'new' => '7'],
            ['path' => 'series › #2 › graded count', 'kind' => 'changed', 'old' => '8', 'new' => '7'],
        ], app(PortalDataArchive::class)->diff($old, $new));
    }

    public function test_a_comparison_says_how_many_differences_it_did_not_list(): void
    {
        $old = ['entries' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C']]];

        $limited = app(PortalDataArchive::class)->compare($old, ['entries' => []], 2);
        $complete = app(PortalDataArchive::class)->compare($old, ['entries' => []], null);

        $this->assertCount(2, $limited['changes']);
        $this->assertSame(3, $limited['total']);
        $this->assertTrue($limited['truncated']);
        $this->assertCount(3, $complete['changes']);
        $this->assertFalse($complete['truncated']);
        $this->assertSame($limited['changes'], app(PortalDataArchive::class)->diff($old, ['entries' => []], 2));
    }

    public function test_a_held_back_version_is_compared_with_the_trusted_version_it_was_judged_against(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A']), $this->grades(['A', 'B', 'C']));
        $this->repository()->sync(self::TOKEN, 'grades');
        $judgedAgainst = PortalArchiveVersion::query()->sole();
        Carbon::setTestNow(now()->addHour());
        $this->repository()->sync(self::TOKEN, 'grades');
        $suspect = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();
        app(PortalDataArchive::class)->reject(self::TOKEN, $suspect);

        Carbon::setTestNow(now()->addHour());
        $this->repository()->sync(self::TOKEN, 'grades');

        $newer = PortalArchiveVersion::query()->trusted()->latest('trusted_at')->latest('id')->first();
        $this->assertFalse($newer->is($judgedAgainst));
        $this->assertTrue(app(PortalDataArchive::class)->baseline($suspect->fresh())->is($judgedAgainst));
        $this->assertTrue(app(PortalDataArchive::class)->baseline($newer)->is($judgedAgainst));
    }

    public function test_a_dismissed_glitch_that_shows_up_again_is_compared_with_the_trusted_version_it_was_judged_against_last(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A']), $this->grades(['A', 'B']), $this->grades(['A', 'B', 'C']), $this->grades(['A']));

        foreach (range(1, 5) as $_) {
            $this->repository()->sync(self::TOKEN, 'grades');
            Carbon::setTestNow(now()->addHour());
        }

        $glitch = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();
        $current = PortalArchiveVersion::query()->trusted()->latest('trusted_at')->latest('id')->first();
        $this->assertCount(3, $current->payload['semesters'][0]['courses']);
        $this->assertSame('Mata kuliah di KHS berkurang dari 3 menjadi 1.', $glitch->findings[0]['message']);

        $baseline = app(PortalDataArchive::class)->baseline($glitch);

        $this->assertTrue($baseline->is($current));
        $removedCodes = array_column(array_filter(
            app(PortalDataArchive::class)->diff($baseline->payload, $glitch->payload, dataset: 'grades'),
            static fn (array $change): bool => $change['kind'] === 'removed' && str_ends_with($change['path'], '› code')
        ), 'old');
        $this->assertSame(['IF102', 'IF103'], $removedCodes);
    }

    public function test_a_portal_back_on_an_earlier_trusted_version_is_announced_without_rewriting_its_history(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A', 'B', 'C']), $this->grades(['A', 'B']));
        $this->repository()->sync(self::TOKEN, 'grades');
        $earlier = PortalArchiveVersion::query()->sole();
        Carbon::setTestNow(now()->addHour());
        $this->repository()->sync(self::TOKEN, 'grades');
        $current = PortalArchiveVersion::query()->latest('id')->first();
        Carbon::setTestNow(now()->addHour());

        $result = $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertCount(3, $result['semesters'][0]['courses']);
        $this->assertSame(PortalArchiveVersion::STATUS_TRUSTED, $earlier->fresh()->status);
        $sighting = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();
        $this->assertFalse($sighting->is($earlier));
        $this->assertSame($earlier->content_hash, $sighting->content_hash);
        $this->assertSame(1, $sighting->sighting);
        $this->assertSame('Mata kuliah di KHS berkurang dari 3 menjadi 2.', $sighting->findings[0]['message']);
        $this->assertSame($sighting->id, app(PortalSnapshotStore::class)->get(self::TOKEN, 'grades')['integrity']['version_id']);
        $this->assertTrue(app(PortalDataArchive::class)->baseline($current->fresh())->is($earlier));
        $this->assertTrue(app(PortalDataArchive::class)->baseline($sighting)->is($current));

        $notification = PortalNotification::query()->where('type', 'archive_suspect')->sole();
        $this->assertSame('/archive/grades?params_hash='.$sighting->params_hash.'&version='.$sighting->id, $notification->url);

        app(PortalDataArchive::class)->reject(self::TOKEN, $sighting);
        Carbon::setTestNow(now()->addHour());
        $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertSame(PortalArchiveVersion::STATUS_REJECTED, $sighting->fresh()->status);
        $this->assertSame(PortalArchiveVersion::STATUS_TRUSTED, $earlier->fresh()->status);
        $this->assertSame(1, PortalNotification::query()->where('type', 'archive_suspect')->count());
    }

    public function test_a_dismissed_glitch_that_comes_back_is_announced_again(): void
    {
        $this->buildReturns($this->grades(['A', 'B']), $this->grades(['A']), $this->grades(['A', 'B']), $this->grades(['A']), $this->grades(['A']));

        foreach (range(1, 3) as $_) {
            $this->repository()->sync(self::TOKEN, 'grades');
            Carbon::setTestNow(now()->addHour());
        }

        $glitch = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_DISMISSED)->sole();
        $this->assertSame(1, PortalNotification::query()->count());

        $this->repository()->sync(self::TOKEN, 'grades');
        Carbon::setTestNow(now()->addHour());
        $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertSame(PortalArchiveVersion::STATUS_SUSPECT, $glitch->fresh()->status);
        $this->assertSame(2, PortalArchiveVersion::query()->count());
        $notifications = PortalNotification::query()->orderBy('id')->get();
        $this->assertCount(2, $notifications);
        $this->assertSame(['archive_suspect', 'archive_suspect'], $notifications->pluck('type')->all());
        $this->assertNotSame($notifications[0]->dedupe_key, $notifications[1]->dedupe_key);
        $this->assertSame('suspect', app(PortalSnapshotStore::class)->get(self::TOKEN, 'grades')['integrity']['status']);
    }

    public function test_a_grade_lost_while_a_new_course_appears_is_held_back(): void
    {
        $lostAndAdded = $this->grades(['A', 'B', '']);
        array_shift($lostAndAdded['semesters'][0]['courses']);
        $this->buildReturns($this->grades(['A', 'B']), $lostAndAdded);

        $this->repository()->sync(self::TOKEN, 'grades');
        $result = $this->repository()->sync(self::TOKEN, 'grades');

        $this->assertSame('IF101', $result['semesters'][0]['courses'][0]['code']);
        $suspect = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();
        $this->assertSame(['Nilai huruf hilang pada 1 data: Ganjil 2025/2026|IF101 (A).'], array_column($suspect->findings, 'message'));
    }

    public function test_without_a_chosen_class_the_history_opens_the_class_with_a_version_to_check(): void
    {
        $classA = ['id' => '91', 'dosen_id' => '7', 'course_id' => '501'];
        $classH = ['id' => '98', 'dosen_id' => '7', 'course_id' => '508'];
        $this->buildReturns(
            $this->classDetail(['present', 'present']),
            $this->classDetail(['present']),
            $this->classDetail(['present', 'absent']),
            $this->classDetail(['present'])
        );

        foreach ([$classA, $classH, $classA, $classH] as $params) {
            $this->repository()->sync(self::TOKEN, 'class_detail', $params);
            Carbon::setTestNow(now()->addMinute());
        }

        $suspect = PortalArchiveVersion::query()->where('status', PortalArchiveVersion::STATUS_SUSPECT)->sole();
        $history = app(PortalDataArchive::class)->versions($this->ownerHash, 'class_detail');

        $this->assertSame(PortalDataCache::paramsHash($classH), $history['groups'][0]['params_hash']);
        $this->assertSame(PortalDataCache::paramsHash($classA), $history['selected_params_hash']);
        $this->assertContains($suspect->id, array_column($history['versions'], 'id'));

        $chosen = app(PortalDataArchive::class)->versions($this->ownerHash, 'class_detail', PortalDataCache::paramsHash($classH));
        $this->assertSame(PortalDataCache::paramsHash($classH), $chosen['selected_params_hash']);
        $this->assertNotContains($suspect->id, array_column($chosen['versions'], 'id'));

        $notification = PortalNotification::query()->sole();
        $this->assertSame('/archive/class_detail?params_hash='.PortalDataCache::paramsHash($classA).'&version='.$suspect->id, $notification->url);
    }

    /**
     * @return array{paid_at: string, reference: string, amount: int}
     */
    private function payment(string $reference, int $amount, string $paidAt): array
    {
        return ['paid_at' => $paidAt, 'reference' => $reference, 'amount' => $amount];
    }

    /**
     * A semester the way KhsExtractor builds it: every course 3 SKS, with
     * the aggregate fields computed from its courses.
     *
     * @param  array<string, string>  $letters  Course code => letter grade.
     * @return array<string, mixed>
     */
    private function khsSemester(string $label, int $semester, array $letters): array
    {
        $weights = ['A' => 4.0, 'B' => 3.0, 'C' => 2.0];
        $scores = ['A' => 90.0, 'B' => 78.0, 'C' => 65.0];
        $courses = array_map(static fn (string $code, string $letter): array => [
            'code' => $code,
            'name' => 'Mata Kuliah '.$code,
            'sks' => 3,
            'components' => ['tugas' => 80.0],
            'score' => $scores[$letter],
            'letter' => $letter,
            'weight' => $weights[$letter],
            'quality_points' => 3 * $weights[$letter],
            'is_graded' => true,
        ], array_keys($letters), $letters);
        $qualityPoints = array_sum(array_column($courses, 'quality_points'));

        return [
            'label' => $label,
            'semester' => $semester,
            'is_regular' => true,
            'status' => 'complete',
            'columns' => ['tugas'],
            'courses' => $courses,
            'course_count' => count($courses),
            'graded_count' => count($courses),
            'total_sks' => 3 * count($courses),
            'total_quality_points' => $qualityPoints,
            'ips' => round($qualityPoints / (3 * count($courses)), 2),
        ];
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
