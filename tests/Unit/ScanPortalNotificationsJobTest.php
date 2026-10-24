<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalNotificationSent;
use App\Exceptions\PortalScrapingException;
use App\Jobs\ScanPortalNotificationsJob;
use App\Models\PortalNotification;
use App\Services\ActionCenterBuilder;
use App\Services\AttendanceShortcutService;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ScanPortalNotificationsJobTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'campus-token';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PortalNotificationSent::class]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('laravelSessionId')->andReturn('laravel-session');
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, '20990000001', 3600);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_scan_resyncs_time_sensitive_datasets_and_reminds_of_close_deadlines(): void
    {
        $this->mockDatasets(function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->once()->with(self::TOKEN, 'assignments')->andReturn([]);
            $mock->shouldReceive('ensureFresh')->once()->with(self::TOKEN, 'exams')->andReturn([]);
        });
        $this->mockActionCenter([
            $this->actionItem('assignment-5101', 'Tugas Basis Data', '2026-10-21', 'critical', '/meeting/5101?courses_id=77', 'assignments'),
            $this->actionItem('payment-deadline-2026-10-25', 'Bayar UKT Tahap 2', '2026-10-25', 'warning', '/finance', 'payments'),
            $this->actionItem('announcement-abc', 'Batas pengajuan cuti', '2026-11-01', 'info', '/announcement/abc', 'announcements'),
            $this->actionItem('krs-pending-dpa', 'KRS menunggu validasi DPA', null, 'warning', '/krs', 'krs'),
        ]);
        $this->mockAttendance([]);

        $this->runJob();

        $reminders = PortalNotification::query()->orderBy('id')->get();
        $this->assertSame(['Tugas Basis Data', 'Bayar UKT Tahap 2'], $reminders->pluck('title')->all());
        $this->assertSame(['critical', 'warning'], $reminders->pluck('urgency')->all());
        $this->assertSame(['/meeting/5101?courses_id=77', '/finance'], $reminders->pluck('url')->all());
        $this->assertSame(['deadline_reminder', 'deadline_reminder'], $reminders->pluck('type')->all());
        $this->assertSame('assignments', $reminders->first()->dataset);
        $this->assertSame(app(PortalDataOwner::class)->keyForUsername('20990000001'), $reminders->first()->owner_hash);
        Event::assertDispatched(PortalNotificationSent::class, fn (PortalNotificationSent $event): bool => $event->sessionId === 'laravel-session'
            && $event->broadcastWith()['type'] === 'deadline_reminder'
            && $event->broadcastWith()['urgency'] === 'critical');
    }

    public function test_reminder_is_not_repeated_after_logging_in_again(): void
    {
        $item = $this->actionItem('assignment-5101', 'Tugas Basis Data', '2026-10-21', 'warning', '/meeting/5101?courses_id=77', 'assignments');
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->andReturn([]));
        $this->mockActionCenter([$item], times: 2);
        $this->mockAttendance([], times: 2);

        $this->runJob();
        app(PortalDataOwner::class)->remember('campus-token-relogin', '20990000001', 3600);
        $this->runJob('campus-token-relogin');

        $this->assertDatabaseCount('portal_notifications', 1);
        Event::assertDispatchedTimes(PortalNotificationSent::class, 1);
    }

    public function test_deadline_turning_critical_is_reminded_again(): void
    {
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->andReturn([]));
        $this->mockAttendance([], times: 2);
        $actionCenter = Mockery::mock(ActionCenterBuilder::class);
        $actionCenter->shouldReceive('build')->twice()->andReturn(
            ['items' => [$this->actionItem('assignment-5101', 'Tugas Basis Data', '2026-10-22', 'warning', '/meeting/5101', 'assignments')]],
            ['items' => [$this->actionItem('assignment-5101', 'Tugas Basis Data', '2026-10-22', 'critical', '/meeting/5101', 'assignments')]],
        );
        $this->instance(ActionCenterBuilder::class, $actionCenter);

        $this->runJob();
        $this->runJob();

        $this->assertSame(['warning', 'critical'], PortalNotification::query()->orderBy('id')->pluck('urgency')->all());
    }

    public function test_external_action_link_falls_back_to_the_dashboard(): void
    {
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->andReturn([]));
        $item = [...$this->actionItem('payment-deadline-2026-10-25', 'Bayar UKT', '2026-10-25', 'warning', 'https://my.cic.ac.id/portal', 'payments'), 'cta_external' => true];
        $this->mockActionCenter([$item]);
        $this->mockAttendance([]);

        $this->runJob();

        $this->assertSame('/dashboard', PortalNotification::query()->value('url'));
    }

    public function test_attendance_reminder_only_for_a_class_in_session_not_yet_marked(): void
    {
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->andReturn([]));
        $this->mockActionCenter([]);
        $this->mockAttendance([
            $this->attendanceClass('9001', 'Basis Data', activeNow: true, alreadyMarked: false),
            $this->attendanceClass('9002', 'Jaringan Komputer', activeNow: true, alreadyMarked: true),
            $this->attendanceClass('9003', 'Statistika', activeNow: false, alreadyMarked: false),
        ]);

        $this->runJob();

        $notification = PortalNotification::query()->sole();
        $this->assertSame('attendance_open', $notification->type);
        $this->assertSame('Absen Basis Data sekarang', $notification->title);
        $this->assertSame('/meeting/9001?courses_id=77', $notification->url);
        $this->assertSame('warning', $notification->urgency);
    }

    public function test_failed_dataset_refresh_still_sends_reminders_from_the_last_snapshot(): void
    {
        $this->mockDatasets(function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'assignments')->andThrow(new \RuntimeException('portal timeout'));
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'exams')->andReturn([]);
        });
        $this->mockActionCenter([$this->actionItem('assignment-5101', 'Tugas Basis Data', '2026-10-21', 'critical', '/meeting/5101', 'assignments')]);
        $this->mockAttendance([]);

        $this->runJob();

        $this->assertDatabaseCount('portal_notifications', 1);
    }

    public function test_expired_campus_session_stops_the_scan(): void
    {
        $this->mockDatasets(function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->once()->andThrow(new PortalScrapingException('Session expired', 403));
        });
        $this->instance(ActionCenterBuilder::class, Mockery::mock(ActionCenterBuilder::class, fn (MockInterface $mock) => $mock->shouldNotReceive('build')));
        $this->instance(AttendanceShortcutService::class, Mockery::mock(AttendanceShortcutService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('getTodayShortcuts')));

        $this->runJob();

        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_concurrent_scan_for_the_same_student_is_skipped(): void
    {
        Cache::lock('portal_notification_scan_'.app(PortalDataOwner::class)->key(self::TOKEN), 300)->get();
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldNotReceive('ensureFresh'));

        $this->runJob();

        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_reminder_links_never_open_a_meeting_without_its_course(): void
    {
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->andReturn([]));
        $this->mockActionCenter([$this->actionItem('assignment-5101', 'Tugas Basis Data', '2026-10-21', 'critical', '/meeting/5101', 'assignments')]);
        $class = $this->attendanceClass('9001', 'Basis Data', activeNow: true, alreadyMarked: false);
        $class['attendance_payload']['courseid'] = '';
        $this->mockAttendance([$class]);

        $this->runJob();

        $this->assertSame(
            ['deadline_reminder' => '/dashboard', 'attendance_open' => '/dashboard'],
            PortalNotification::query()->orderBy('id')->pluck('url', 'type')->all()
        );
    }

    public function test_scan_gives_a_slow_portal_more_than_the_default_worker_timeout(): void
    {
        $this->assertSame(300, (new ScanPortalNotificationsJob('laravel-session', self::TOKEN))->timeout);
    }

    public function test_scan_schedules_the_next_scan_while_the_session_lives(): void
    {
        Queue::fake();
        $this->freezeTime();
        $this->mockSessions(usable: true);
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->andReturn([]));
        $this->mockActionCenter([], times: 2);
        $this->mockAttendance([], times: 2);

        $this->runJob();
        $this->runJob();

        // One follow-up per session, however many scans ran meanwhile.
        Queue::assertPushed(ScanPortalNotificationsJob::class, 1);
        Queue::assertPushed(ScanPortalNotificationsJob::class, fn (ScanPortalNotificationsJob $job): bool => $this->isFollowUp($job)
            && $job->queue === null
            && now()->addSeconds(600)->equalTo($job->delay));
    }

    public function test_next_scan_runs_when_todays_next_class_starts(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::create(2026, 10, 22, 9, 55, 0, config('app.timezone')));
        $this->mockSessions(usable: true);
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->andReturn([]));
        $this->mockActionCenter([]);
        $this->mockAttendance([
            $this->attendanceClass('9001', 'Basis Data', activeNow: false, alreadyMarked: false, startTime: '10:00'),
            $this->attendanceClass('9000', 'Statistika', activeNow: false, alreadyMarked: false, startTime: '07:30'),
        ]);

        $this->runJob();

        Queue::assertPushed(ScanPortalNotificationsJob::class, fn (ScanPortalNotificationsJob $job): bool => now()->addSeconds(330)->equalTo($job->delay));
    }

    public function test_class_opening_mid_session_is_reminded_by_the_follow_up_scan(): void
    {
        Queue::fake();
        $this->mockSessions(usable: true, active: true);
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->andReturn([]));
        $this->mockActionCenter([], times: 2);
        $attendance = Mockery::mock(AttendanceShortcutService::class);
        $attendance->shouldReceive('getTodayShortcuts')->twice()->andReturn(
            ['classes' => [$this->attendanceClass('9001', 'Basis Data', activeNow: false, alreadyMarked: false)]],
            ['classes' => [$this->attendanceClass('9001', 'Basis Data', activeNow: true, alreadyMarked: false)]],
        );
        $this->instance(AttendanceShortcutService::class, $attendance);

        // Login at 07:30: the class has not started yet.
        $this->runJob();
        $this->assertDatabaseCount('portal_notifications', 0);

        $followUp = null;
        Queue::assertPushed(ScanPortalNotificationsJob::class, function (ScanPortalNotificationsJob $job) use (&$followUp): bool {
            $followUp = $job;

            return true;
        });

        app()->call([$followUp, 'handle']);

        $this->assertSame('Absen Basis Data sekarang', PortalNotification::query()->sole()->title);
        // The chain goes on: the follow-up queued its own successor.
        Queue::assertPushed(ScanPortalNotificationsJob::class, 2);
    }

    public function test_follow_up_skips_the_scan_while_the_student_is_away_but_keeps_the_chain(): void
    {
        Queue::fake();
        $this->mockSessions(usable: true, active: false);
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldNotReceive('ensureFresh'));
        $this->instance(ActionCenterBuilder::class, Mockery::mock(ActionCenterBuilder::class, fn (MockInterface $mock) => $mock->shouldNotReceive('build')));

        $this->runJob(followUp: true);

        Queue::assertPushed(ScanPortalNotificationsJob::class, fn (ScanPortalNotificationsJob $job): bool => $this->isFollowUp($job));
    }

    public function test_follow_up_resyncs_only_snapshots_past_the_resync_age(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::create(2026, 10, 22, 9, 0, 0, config('app.timezone')));
        $this->mockSessions(usable: true, active: true);
        // Both are past `fresh_for`, which a login scan re-syncs; only assignments is past an hour.
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'assignments', ['assignments' => []], now()->subMinutes(61)->toISOString());
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'exams', ['exams' => []], now()->subMinutes(40)->toISOString());
        $this->mockDatasets(function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->once()->with(self::TOKEN, 'assignments')->andReturn([]);
            $mock->shouldReceive('ensureFresh')->never()->with(self::TOKEN, 'exams');
        });
        $this->mockActionCenter([$this->actionItem('assignment-5101', 'Tugas Basis Data', '2026-10-22', 'critical', '/meeting/5101?courses_id=77', 'assignments')]);
        $this->mockAttendance([]);

        $this->runJob(followUp: true);

        $this->assertSame('Tugas Basis Data', PortalNotification::query()->sole()->title);
    }

    public function test_follow_up_builds_reminders_from_recent_snapshots_without_the_portal(): void
    {
        Queue::fake();
        $this->mockSessions(usable: true, active: true);
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'assignments', ['assignments' => []], now()->subMinutes(15)->toISOString());
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'exams', ['exams' => []], now()->subMinutes(15)->toISOString());
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldNotReceive('ensureFresh'));
        $this->mockActionCenter([$this->actionItem('assignment-5101', 'Tugas Basis Data', '2026-10-22', 'critical', '/meeting/5101?courses_id=77', 'assignments')]);
        $this->mockAttendance([]);

        $this->runJob(followUp: true);

        $this->assertSame('Tugas Basis Data', PortalNotification::query()->sole()->title);
        Queue::assertPushed(ScanPortalNotificationsJob::class, fn (ScanPortalNotificationsJob $job): bool => $this->isFollowUp($job));
    }

    public function test_scan_queued_before_follow_ups_existed_runs_as_a_first_scan(): void
    {
        $values = (new ScanPortalNotificationsJob('laravel-session', self::TOKEN))->__serialize();
        unset($values["\0".ScanPortalNotificationsJob::class."\0followUp"]);
        $job = (new \ReflectionClass(ScanPortalNotificationsJob::class))->newInstanceWithoutConstructor();
        $job->__unserialize($values);
        $this->mockDatasets(function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->once()->with(self::TOKEN, 'assignments')->andReturn([]);
            $mock->shouldReceive('ensureFresh')->once()->with(self::TOKEN, 'exams')->andReturn([]);
        });
        $this->mockActionCenter([]);
        $this->mockAttendance([]);

        app()->call([$job, 'handle']);

        $this->assertFalse($this->isFollowUp($job));
    }

    public function test_chain_ends_with_the_session(): void
    {
        Queue::fake();
        $this->mockSessions(usable: false);
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldNotReceive('ensureFresh'));

        $this->runJob(followUp: true);

        $this->assertDatabaseCount('portal_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_expired_campus_session_schedules_no_follow_up(): void
    {
        Queue::fake();
        $this->mockSessions(usable: true);
        $this->mockDatasets(fn (MockInterface $mock) => $mock->shouldReceive('ensureFresh')->once()->andThrow(new PortalScrapingException('Session expired', 403)));

        $this->runJob();

        Queue::assertNothingPushed();
    }

    public function test_failed_scan_still_schedules_the_next_one(): void
    {
        Queue::fake();
        $this->mockSessions(usable: true);

        (new ScanPortalNotificationsJob('laravel-session', self::TOKEN))->failed(new \RuntimeException('Job timed out'));

        Queue::assertPushed(ScanPortalNotificationsJob::class, fn (ScanPortalNotificationsJob $job): bool => $this->isFollowUp($job));
    }

    private function runJob(string $token = self::TOKEN, bool $followUp = false): void
    {
        app()->call([new ScanPortalNotificationsJob('laravel-session', $token, $followUp), 'handle']);
    }

    private function isFollowUp(ScanPortalNotificationsJob $job): bool
    {
        return (fn (): bool => $this->followUp)->call($job);
    }

    private function mockSessions(bool $usable, bool $active = true): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($usable, $active): void {
            $mock->shouldReceive('laravelSessionId')->andReturn('laravel-session');
            $mock->shouldReceive('isSessionUsable')->with(self::TOKEN)->andReturn($usable);
            $mock->shouldReceive('isBackgroundSession')->with(self::TOKEN)->andReturnFalse();
            $mock->shouldReceive('wasActiveWithin')->with(self::TOKEN, 1200)->andReturn($active);
        }));
    }

    private function mockDatasets(callable $expectations): void
    {
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, $expectations));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function mockActionCenter(array $items, int $times = 1): void
    {
        $this->instance(ActionCenterBuilder::class, Mockery::mock(ActionCenterBuilder::class, function (MockInterface $mock) use ($items, $times): void {
            $mock->shouldReceive('build')->times($times)->andReturn(['items' => $items]);
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     */
    private function mockAttendance(array $classes, int $times = 1): void
    {
        $this->instance(AttendanceShortcutService::class, Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock) use ($classes, $times): void {
            $mock->shouldReceive('getTodayShortcuts')->times($times)->andReturn(['classes' => $classes]);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function actionItem(string $id, string $title, ?string $dueAt, string $severity, string $ctaUrl, string $source): array
    {
        return [
            'id' => $id,
            'type' => 'assignment_due',
            'title' => $title,
            'description' => 'Deskripsi sintetis.',
            'due_at' => $dueAt,
            'days_left' => 0,
            'severity' => $severity,
            'cta_label' => 'Buka',
            'cta_url' => $ctaUrl,
            'cta_external' => false,
            'source' => $source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceClass(string $meetingId, string $courseName, bool $activeNow, bool $alreadyMarked, string $startTime = '07:30'): array
    {
        return [
            'meeting_id' => $meetingId,
            'course_name' => $courseName,
            'schedule' => ['start_time' => $startTime, 'end_time' => '09:10'],
            'active_now' => $activeNow,
            'attendance_status' => ['already_marked' => $alreadyMarked],
            'attendance_payload' => ['courseid' => '77', 'modulesid' => $meetingId, 'pertemuan' => 5],
        ];
    }
}
