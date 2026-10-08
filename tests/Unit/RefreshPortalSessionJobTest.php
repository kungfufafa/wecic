<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalSessionNoticeSent;
use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

    public function test_relogin_schedules_next_ping_and_notifies_the_browser(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        $this->mockKeepAlive('relogged', expectReschedule: true);

        app()->call([new RefreshPortalSessionJob('campus-token'), 'handle']);

        Event::assertDispatched(PortalSessionNoticeSent::class, function (PortalSessionNoticeSent $event): bool {
            return ($event->broadcastWith()['status'] ?? null) === 'auto_relogin_succeeded';
        });
    }

    public function test_alive_session_schedules_next_ping_silently(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        $this->mockKeepAlive('alive', expectReschedule: true);

        app()->call([new RefreshPortalSessionJob('campus-token'), 'handle']);

        Event::assertNotDispatched(PortalSessionNoticeSent::class);
    }

    public function test_expired_or_idle_session_ends_the_chain_and_asks_for_login(): void
    {
        foreach (['expired', 'idle'] as $outcome) {
            Event::fake([PortalSessionNoticeSent::class]);
            $this->mockKeepAlive($outcome, expectReschedule: false);

            app()->call([new RefreshPortalSessionJob('campus-token'), 'handle']);

            Event::assertDispatched(PortalSessionNoticeSent::class, function (PortalSessionNoticeSent $event): bool {
                return ($event->broadcastWith()['status'] ?? null) === 'manual_relogin_required';
            });
        }
    }

    public function test_only_one_keep_alive_chain_exists_per_session(): void
    {
        Queue::fake();

        RefreshPortalSessionJob::dispatch('campus-token');
        RefreshPortalSessionJob::dispatch('campus-token');
        RefreshPortalSessionJob::dispatch('other-token');

        Queue::assertPushed(RefreshPortalSessionJob::class, 2);
    }

    private function mockKeepAlive(string $outcome, bool $expectReschedule): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($outcome, $expectReschedule): void {
                $mock->shouldReceive('keepAlive')->once()->with('campus-token')->andReturn($outcome);
                $mock->shouldReceive('laravelSessionId')->with('campus-token')->andReturn('laravel-session');

                if ($expectReschedule) {
                    $mock->shouldReceive('scheduleKeepAlive')->once()->with('campus-token');
                } else {
                    $mock->shouldNotReceive('scheduleKeepAlive');
                }
            })
        );
    }
}
