<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Jobs\RefreshPortalDataJob;
use App\Services\PortalActionProjector;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * GET /api/dashboard decides which of today's classes is in session when it
 * is read, not when the dashboard snapshot was built.
 */
class DashboardTodayClassesApiTest extends TestCase
{
    private const TOKEN = 'token-today-classes';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalDataJob::class]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
        }));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_class_in_session_when_the_snapshot_was_built_no_longer_leads_once_it_ended(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 21, 0, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-19', [
            $this->todayClass('IF202', '20:00:00', '21:30:00', true),
            $this->todayClass('IF101', '08:00:00', '09:40:00', false),
        ]);

        // The next morning the stale snapshot is still served while it re-syncs.
        $this->travelTo(CarbonImmutable::create(2026, 10, 20, 8, 30, 0, 'Asia/Jakarta'));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.date', '2026-10-19')
            ->assertJsonPath('data.classes_summary.today_classes.0.course_code', 'IF101')
            ->assertJsonPath('data.classes_summary.today_classes.0.active_now', false)
            ->assertJsonPath('data.classes_summary.today_classes.1.course_code', 'IF202')
            ->assertJsonPath('data.classes_summary.today_classes.1.active_now', false);
    }

    public function test_the_class_in_session_now_leads_a_snapshot_built_earlier_today(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 20, 7, 0, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-20', [
            $this->todayClass('IF101', '08:00:00', '09:40:00', false),
            $this->todayClass('IF202', '10:00:00', '11:40:00', false),
        ]);

        $this->travelTo(CarbonImmutable::create(2026, 10, 20, 10, 15, 0, 'Asia/Jakarta'));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.course_code', 'IF202')
            ->assertJsonPath('data.classes_summary.today_classes.0.active_now', true)
            ->assertJsonPath('data.classes_summary.today_classes.1.course_code', 'IF101')
            ->assertJsonPath('data.classes_summary.today_classes.1.active_now', false);
    }

    public function test_todays_attendance_is_joined_from_its_own_snapshot(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 9, 45, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-19', [$this->scheduledClass()]);
        $this->storeAttendance('2026-10-19', alreadyMarked: false);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.state', 'ready')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.actionable', true)
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.meeting_id', '445')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.meeting_url', '/meeting/445?courses_id=8810')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.payload.modulesid', '445')
            ->assertJsonPath('data.classes_summary.actionable_attendance_count', 1)
            ->assertJsonPath('data.classes_summary.attendance_sync.status', 'synced');

        Bus::assertNotDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobParams($job) !== []);
    }

    /**
     * The schedule's re-sync is queued first, then the crawl of today's
     * class and meeting pages in a job of its own: the worker never makes
     * the schedule wait for that crawl.
     */
    public function test_a_missing_attendance_snapshot_is_queued_after_the_schedule_and_reported_as_checking(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 9, 45, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-19', [$this->scheduledClass()], now()->subMinutes(15)->toISOString());

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('meta.status', 'syncing')
            ->assertJsonPath('data.classes_summary.today_classes.0.course_code', 'IF101')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.state', 'checking')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.actionable', false)
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.class_url', '/class/2265?dosen_id=5240&course_id=8810')
            ->assertJsonPath('data.classes_summary.actionable_attendance_count', 0)
            ->assertJsonPath('data.classes_summary.attendance_sync.status', 'pending');

        $jobs = Bus::dispatched(RefreshPortalDataJob::class)
            ->map(fn (RefreshPortalDataJob $job): array => [$this->jobDomains($job), $this->jobParams($job)])
            ->values()
            ->all();

        $this->assertSame([['dashboard'], []], $jobs[0]);
        $this->assertSame([['dashboard'], PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS], $jobs[1]);
    }

    public function test_attendance_marked_through_mycic_stays_marked_while_its_snapshot_resyncs(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 9, 45, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-19', [$this->scheduledClass()]);
        $this->storeAttendance('2026-10-19', alreadyMarked: false);
        app(PortalActionProjector::class)->recordAttendance(self::TOKEN, 8810, 445, 6);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.status.already_marked', true)
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.actionable', false)
            ->assertJsonPath('data.classes_summary.actionable_attendance_count', 0);
    }

    public function test_a_forced_read_resyncs_a_still_fresh_attendance_snapshot_in_the_background(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 9, 43, 0, 'Asia/Jakarta'));
        $this->storeAttendance('2026-10-19', alreadyMarked: false);
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 9, 45, 0, 'Asia/Jakarta'));
        // Synced moments ago: the forced read serves it instead of rebuilding.
        $this->storeDashboard('2026-10-19', [$this->scheduledClass()]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard?fresh=1')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.state', 'ready')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.actionable', true)
            ->assertJsonPath('data.classes_summary.attendance_sync.status', 'syncing');

        $this->assertCount(1, Bus::dispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobParams($job) === PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS));
        // The schedule synced moments ago is not crawled again.
        Bus::assertNotDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ['dashboard'] && $this->jobParams($job) === []);
    }

    /**
     * The lecturer opened attendance after its snapshot was built (still
     * fresh) and the student presses Sinkronkan, which can only name
     * `dashboard`: the re-sync it queues brings today's attendance along.
     */
    public function test_the_sync_button_refreshes_todays_attendance_along_with_the_schedule(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 9, 58, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-19', [$this->scheduledClass()]);
        $this->storeAttendance('2026-10-19', alreadyMarked: false, opened: false);
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 10, 3, 0, 'Asia/Jakarta'));

        $this->withoutMiddleware(VerifyCsrfTokenMiddleware::class);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
            $mock->shouldReceive('isSessionUsable')->with(self::TOKEN)->andReturnTrue();
        }));
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->with(self::TOKEN, 'dashboard', [])->once()->andReturnUsing(
                fn (): array => app(PortalSnapshotStore::class)->get(self::TOKEN, 'dashboard')['data']
            );
            $mock->shouldReceive('build')->with(self::TOKEN, 'dashboard', PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS)->once()->andReturnUsing(
                fn (): array => $this->attendanceData('2026-10-19', alreadyMarked: false, opened: true)
            );
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.actionable', false);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->postJson('/api/refresh', ['domains' => ['dashboard']])
            ->assertStatus(202);

        // The worker runs the re-sync the button queued.
        $refresh = Bus::dispatched(RefreshPortalDataJob::class)
            ->first(fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ['dashboard'] && $this->jobParams($job) === []);
        $this->assertInstanceOf(RefreshPortalDataJob::class, $refresh);
        app()->call([$refresh, 'handle']);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.state', 'ready')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.actionable', true)
            ->assertJsonPath('data.classes_summary.actionable_attendance_count', 1)
            ->assertJsonPath('data.classes_summary.attendance_sync.status', 'synced');

        // Synced straight after the schedule, not left to a job queued behind the rest.
        Bus::assertNotDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobParams($job) !== []);
    }

    public function test_attendance_of_another_day_is_not_joined_and_is_resynced(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 18, 23, 58, 0, 'Asia/Jakarta'));
        $this->storeAttendance('2026-10-18', alreadyMarked: true);
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 0, 2, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-19', [$this->scheduledClass()]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.state', 'checking')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.status.already_marked', false)
            ->assertJsonPath('data.classes_summary.attendance_sync.status', 'syncing');

        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobParams($job) === PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS);
    }

    public function test_the_schedule_is_still_served_when_its_attendance_cannot_be_queued(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 19, 9, 45, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-19', [$this->scheduledClass()]);
        $this->mock(BusDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('database is locked'));
        });

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes.0.course_code', 'IF101')
            ->assertJsonPath('data.classes_summary.today_classes.0.attendance.state', 'unavailable')
            ->assertJsonPath('data.classes_summary.attendance_sync.status', 'failed');
    }

    public function test_a_day_without_classes_never_reads_or_queues_the_attendance(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 18, 10, 0, 0, 'Asia/Jakarta'));
        $this->storeDashboard('2026-10-18', []);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classes_summary.today_classes', [])
            ->assertJsonMissingPath('data.classes_summary.attendance_sync');

        Bus::assertNotDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobParams($job) !== []);
    }

    /**
     * @param  list<array<string, mixed>>  $todayClasses
     */
    private function storeDashboard(string $date, array $todayClasses, ?string $syncedAt = null): void
    {
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'dashboard', [
            'user_info' => [],
            'dashboard_overview' => ['total_courses' => '2', 'gpa' => null, 'total_credits' => '6', 'outstanding_payments' => null],
            'recent_activity' => [],
            'classes_summary' => [
                'total_classes' => 2,
                'day' => 'Senin',
                'date' => $date,
                'today_classes_count' => count($todayClasses),
                'actionable_attendance_count' => 0,
                'today_classes' => $todayClasses,
            ],
            'status' => 'success',
            'timestamp' => now()->toISOString(),
        ], $syncedAt);
    }

    private function storeAttendance(string $date, bool $alreadyMarked, bool $opened = true): void
    {
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'dashboard', $this->attendanceData($date, $alreadyMarked, $opened), params: PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS);
    }

    /**
     * Data of the attendance snapshot. Before the lecturer opens today's
     * meeting its attendance target is not reliable yet.
     *
     * @return array<string, mixed>
     */
    private function attendanceData(string $date, bool $alreadyMarked, bool $opened = true): array
    {
        return [
            'date' => $date,
            'day' => 'Senin',
            'current_time' => now()->format('H:i'),
            'classes' => [[
                'class_id' => '2265',
                'meeting_id' => '445',
                'meeting_number' => 6,
                'course_code' => 'IF101',
                'today_material' => ['title' => 'Pertemuan 6', 'meeting_number' => 6, 'is_today' => true],
                'attendance_target_reliable' => $opened,
                'schedule' => ['day' => 'Senin', 'start_time' => '09:30:00', 'time' => '09:30 - 11:00'],
                'attendance_status' => ['already_marked' => $alreadyMarked, 'status' => $alreadyMarked ? 'present' : null, 'label' => $alreadyMarked ? 'Hadir' : null, 'time' => null],
                'attendance_payload' => ['courseid' => '8810', 'modulesid' => '445', 'pertemuan' => 6],
                'assignment_payload' => ['courses_id' => '8810', 'id' => '445', 'pertemuan' => 6],
            ]],
        ];
    }

    /**
     * A class of the schedule snapshot: its attendance is joined at read time.
     *
     * @return array<string, mixed>
     */
    private function scheduledClass(): array
    {
        return [
            'class_id' => '2265',
            'dosen_id' => '5240',
            'course_id' => '8810',
            'course_code' => 'IF101',
            'course_name' => 'Algoritma',
            'schedule' => ['day' => 'Senin', 'time' => '09:30 - 11:00', 'start_time' => '09:30:00', 'end_time' => '11:00:00'],
            'active_now' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function jobDomains(RefreshPortalDataJob $job): array
    {
        return (fn (): array => $this->domains)->call($job);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobParams(RefreshPortalDataJob $job): array
    {
        return (fn (): array => $this->params)->call($job);
    }

    /**
     * @return array<string, mixed>
     */
    private function todayClass(string $code, string $start, string $end, bool $activeNow): array
    {
        return [
            'course_code' => $code,
            'course_name' => "Mata Kuliah {$code}",
            'schedule' => ['day' => 'Senin', 'time' => substr($start, 0, 5).' - '.substr($end, 0, 5), 'start_time' => $start, 'end_time' => $end],
            'active_now' => $activeNow,
            'attendance' => ['available' => false, 'actionable' => false],
        ];
    }
}
