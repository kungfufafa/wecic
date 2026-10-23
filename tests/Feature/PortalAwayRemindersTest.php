<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshPortalDataJob;
use App\Jobs\SendAwayRemindersJob;
use App\Jobs\SendPortalPushNotificationsJob;
use App\Models\PortalNotification;
use App\Models\PortalPushToken;
use App\Services\PortalAwayReminders;
use App\Services\PortalDataOwner;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;
use Tests\Traits\LogsInWithMobileToken;

/**
 * A student whose app is closed still gets deadline reminders and "class
 * started" pushes, built from the stored snapshots only: the portal is
 * never contacted (TestCase fails any portal request) and no dataset build
 * is queued. Every payload here is synthetic.
 */
class PortalAwayRemindersTest extends TestCase
{
    use LogsInWithMobileToken, RefreshDatabase;

    private const USERNAME = '20990000601';

    private const SEED_TOKEN = 'campus-token-seed';

    protected function setUp(): void
    {
        parent::setUp();

        // Bound before anything builds the session manager: nothing here may reach the portal.
        $this->portalAcceptsLogin(self::USERNAME);
        Bus::fake();
        $this->travelTo(CarbonImmutable::create(2026, 10, 22, 9, 0, 0, 'Asia/Jakarta'));
        app(PortalDataOwner::class)->remember(self::SEED_TOKEN, self::USERNAME, 86400);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_deadline_turning_critical_is_pushed_while_the_app_is_closed(): void
    {
        $this->registerApp();
        $this->storeAssignmentDueTomorrowMorning();

        $created = $this->remind();

        $this->assertSame(1, $created);
        $reminder = PortalNotification::query()->sole();
        $this->assertSame('deadline_reminder', $reminder->type);
        $this->assertSame('critical', $reminder->urgency);
        $this->assertSame('Tugas Basis Data', $reminder->title);
        $this->assertSame('/meeting/5101?courses_id=77', $reminder->url);
        $this->assertSame($this->ownerHash(), $reminder->owner_hash);
        Bus::assertDispatched(SendPortalPushNotificationsJob::class, fn (SendPortalPushNotificationsJob $job): bool => $job->notificationIds === [$reminder->id]);
        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_a_reminder_is_sent_once(): void
    {
        $this->registerApp();
        $this->storeAssignmentDueTomorrowMorning();

        $this->remind();
        $this->travel(10)->minutes();
        $this->remind();

        $this->assertSame(1, PortalNotification::query()->count());
        Bus::assertDispatchedTimes(SendPortalPushNotificationsJob::class, 1);
    }

    public function test_a_student_without_an_app_install_is_not_reminded(): void
    {
        $this->storeAssignmentDueTomorrowMorning();
        PortalPushToken::factory()->forOwner('student_someone_else')->create();

        $this->assertSame(0, $this->remind());
        $this->assertSame(0, PortalNotification::query()->count());
    }

    public function test_a_student_using_the_app_is_left_to_the_scan_of_their_session(): void
    {
        $this->registerApp();
        $this->storeAssignmentDueTomorrowMorning();
        $this->loginForToken(self::USERNAME);

        $this->assertSame(0, $this->remind(), 'The open session reminds the student itself.');

        // Away longer than the session scan waits for: the session no longer reminds.
        $this->travel((int) config('portal.session_refresh_interval', 1200) + 60)->seconds();

        $this->assertSame(1, $this->remind());
    }

    public function test_snapshots_older_than_the_limit_remind_nobody(): void
    {
        $this->registerApp();
        $this->storeAssignmentDueTomorrowMorning(syncedAt: now()->subDays(2)->toISOString());

        $this->assertSame(0, $this->remind());

        config(['portal.mobile.away_reminder_max_data_age' => 3 * 86400]);

        $this->assertSame(1, $this->remind());
    }

    public function test_a_class_in_session_is_announced_once_as_started(): void
    {
        $this->registerApp();
        $this->storeTodaysSchedule('2026-10-22');

        $this->assertSame(1, $this->remind());
        $this->travel(10)->minutes();
        $this->assertSame(0, $this->remind());

        $reminder = PortalNotification::query()->sole();
        $this->assertSame('class_started', $reminder->type);
        $this->assertSame('Kelas Basis Data sudah dimulai', $reminder->title);
        $this->assertSame('Ruang B201. Buka MyCIC untuk cek dan kirim absensimu.', $reminder->body);
        $this->assertSame('/dashboard', $reminder->url);
        $this->assertSame('info', $reminder->urgency);
    }

    public function test_a_class_not_in_session_or_of_another_day_is_not_announced(): void
    {
        $this->registerApp();
        $this->storeTodaysSchedule('2026-10-22', startTime: '13:00', endTime: '14:40');

        $this->assertSame(0, $this->remind(), 'The class starts this afternoon.');

        $this->storeTodaysSchedule('2026-10-21');

        $this->assertSame(0, $this->remind(), 'A schedule built yesterday has no class in session today.');
    }

    public function test_switched_off_the_command_queues_nothing(): void
    {
        $this->registerApp();
        config(['portal.mobile.away_reminders' => false]);

        $this->artisan('portal:remind-away')->assertSuccessful();

        Bus::assertNotDispatched(SendAwayRemindersJob::class);
    }

    public function test_the_command_queues_one_job_per_student_with_an_app_install(): void
    {
        $this->registerApp();
        $this->registerApp();
        PortalPushToken::factory()->forOwner('student_second')->create();

        $this->artisan('portal:remind-away')
            ->expectsOutput('Queued reminders for 2 student(s).')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(SendAwayRemindersJob::class, 2);
        Bus::assertDispatched(SendAwayRemindersJob::class, fn (SendAwayRemindersJob $job): bool => $job->ownerHash === $this->ownerHash()
            && $job->uniqueId() === $this->ownerHash()
            && $job->queue === 'campus-notifications');
    }

    public function test_the_job_reminds_its_student(): void
    {
        $this->registerApp();
        $this->storeAssignmentDueTomorrowMorning();

        app()->call([new SendAwayRemindersJob($this->ownerHash()), 'handle']);

        $this->assertSame(1, PortalNotification::query()->count());
    }

    private function remind(): int
    {
        return app(PortalAwayReminders::class)->remind($this->ownerHash());
    }

    private function ownerHash(): string
    {
        return app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    private function registerApp(): void
    {
        PortalPushToken::factory()->forOwner($this->ownerHash())->create();
    }

    /**
     * Due 08:00 tomorrow: within 24 hours, so critical.
     */
    private function storeAssignmentDueTomorrowMorning(?string $syncedAt = null): void
    {
        app(PortalSnapshotStore::class)->put(self::SEED_TOKEN, 'assignments', [
            'assignments' => [[
                'course_name' => 'Basis Data',
                'material_title' => 'Normalisasi',
                'deadline_date' => '2026-10-23',
                'deadline' => '23 Oktober 2026 08:00',
                'meeting_id' => '5101',
                'meeting_url' => '/meeting/5101?courses_id=77',
            ]],
        ], $syncedAt ?? now()->subHour()->toISOString());
    }

    private function storeTodaysSchedule(string $date, string $startTime = '08:30', string $endTime = '10:10'): void
    {
        app(PortalSnapshotStore::class)->put(self::SEED_TOKEN, 'dashboard', [
            'classes_summary' => [
                'date' => $date,
                'today_classes' => [[
                    'class_id' => '91',
                    'course_id' => '77',
                    'course_name' => 'Basis Data',
                    'room' => 'B201',
                    'schedule' => ['day' => 'Kamis', 'start_time' => $startTime, 'end_time' => $endTime],
                    'active_now' => false,
                ]],
            ],
        ], now()->subHours(2)->toISOString());
    }
}
