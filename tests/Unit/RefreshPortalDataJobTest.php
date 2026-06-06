<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PortalOperationStage;
use App\Events\PortalDataInvalidated;
use App\Events\PortalSyncStatusUpdated;
use App\Jobs\RefreshPortalDataJob;
use App\Services\AttendanceShortcutService;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\PortalBroadcastService;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RefreshPortalDataJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_refresh_job_broadcasts_completed_status_and_invalidation(): void
    {
        Event::fake([PortalSyncStatusUpdated::class, PortalDataInvalidated::class]);

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->once()->andReturn(true);
            })
        );

        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('getPendingAssignmentsSnapshot')->once()->andReturn(['assignments' => []]);
            })
        );

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class)
        );

        $this->instance(
            ProfileExtractor::class,
            Mockery::mock(ProfileExtractor::class)
        );

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class)
        );

        $job = new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard']);
        $job->handle(
            app(PortalSessionManager::class),
            app(PortalBroadcastService::class),
            app(AttendanceShortcutService::class),
            app(ClassesExtractor::class),
            app(ProfileExtractor::class),
            app(SipandaiSubmissionService::class)
        );

        Event::assertDispatched(PortalSyncStatusUpdated::class, function (PortalSyncStatusUpdated $event): bool {
            return ($event->broadcastWith()['status'] ?? null) === PortalOperationStage::Completed->value;
        });

        Event::assertDispatched(PortalDataInvalidated::class);
    }
}
