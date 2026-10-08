<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalNotificationSent;
use App\Jobs\ScanPortalNotificationsJob;
use App\Services\AttendanceShortcutService;
use App\Services\PortalDatasetRepository;
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
            PortalDatasetRepository::class,
            Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
                $mock->shouldReceive('ensureFresh')->once()->with('campus-token', 'assignments')->andReturn([
                    'assignments' => [
                        [
                            'title' => 'Tugas Algoritma',
                            'deadline_at' => Carbon::now()->addHours(6)->toISOString(),
                        ],
                    ],
                ]);
                $mock->shouldReceive('ensureFresh')->once()->with('campus-token', 'exams')->andReturn([
                    'exams' => [],
                ]);
            })
        );

        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('getTodayShortcuts')->once()->andReturn([
                    'classes' => [],
                ]);
            })
        );

        app()->call([new ScanPortalNotificationsJob('laravel-session', 'campus-token'), 'handle']);

        Event::assertDispatched(PortalNotificationSent::class, function (PortalNotificationSent $event): bool {
            $payload = $event->broadcastWith();

            return ($payload['type'] ?? null) === 'assignment'
                && ($payload['urgency'] ?? null) === 'critical';
        });
    }
}
