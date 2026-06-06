<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalNotificationSent;
use App\Jobs\ScanPortalNotificationsJob;
use App\Services\AttendanceShortcutService;
use App\Services\PortalBroadcastService;
use App\Services\SipandaiSubmissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ScanPortalNotificationsJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_notification_scan_broadcasts_assignment_deadline_notice(): void
    {
        Event::fake([PortalNotificationSent::class]);

        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('getPendingAssignmentsSnapshot')->once()->andReturn([
                    'assignments' => [
                        [
                            'title' => 'Tugas Algoritma',
                            'deadline_at' => Carbon::now()->addHours(6)->toISOString(),
                        ],
                    ],
                ]);
                $mock->shouldReceive('getTodayShortcuts')->once()->andReturn([
                    'classes' => [],
                ]);
            })
        );

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('getExamList')->once()->andReturn([]);
            })
        );

        $job = new ScanPortalNotificationsJob('laravel-session', 'campus-token');
        $job->handle(
            app(AttendanceShortcutService::class),
            app(PortalBroadcastService::class),
            app(SipandaiSubmissionService::class)
        );

        Event::assertDispatched(PortalNotificationSent::class, function (PortalNotificationSent $event): bool {
            $payload = $event->broadcastWith();

            return ($payload['type'] ?? null) === 'assignment'
                && ($payload['urgency'] ?? null) === 'critical';
        });
    }
}
