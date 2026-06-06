<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalSessionNoticeSent;
use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RefreshPortalSessionJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_refresh_job_broadcasts_auto_relogin_success_notice(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->once()->andReturn(true);
                $mock->shouldReceive('refreshSession')->once()->andReturn([
                    'success' => true,
                    'expires_at' => now()->addHour()->toISOString(),
                ]);
            })
        );

        $job = new RefreshPortalSessionJob('campus-token', 'laravel-session');
        $job->handle(app(PortalSessionManager::class));

        Event::assertDispatched(PortalSessionNoticeSent::class, function (PortalSessionNoticeSent $event): bool {
            return ($event->broadcastWith()['status'] ?? null) === 'auto_relogin_succeeded';
        });
    }
}
