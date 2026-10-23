<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalSessionNoticeSent;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Bus;
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
        Bus::fake([RefreshPortalDataJob::class]);
        $this->mockKeepAlive('relogged', expectReschedule: true);

        app()->call([new RefreshPortalSessionJob('campus-token'), 'handle']);

        Event::assertDispatched(PortalSessionNoticeSent::class, function (PortalSessionNoticeSent $event): bool {
            return ($event->broadcastWith()['status'] ?? null) === 'auto_relogin_succeeded';
        });
    }

    public function test_alive_session_schedules_next_ping_silently(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        Bus::fake([RefreshPortalDataJob::class]);
        $this->mockKeepAlive('alive', expectReschedule: true);

        app()->call([new RefreshPortalSessionJob('campus-token'), 'handle']);

        Event::assertNotDispatched(PortalSessionNoticeSent::class);
        // Decision datasets without a snapshot are built in the background.
        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
    }

    public function test_expired_or_idle_session_ends_the_chain_and_asks_for_login(): void
    {
        Bus::fake([RefreshPortalDataJob::class]);

        foreach (['expired', 'idle'] as $outcome) {
            Event::fake([PortalSessionNoticeSent::class]);
            $this->mockKeepAlive($outcome, expectReschedule: false);

            app()->call([new RefreshPortalSessionJob('campus-token'), 'handle']);

            Event::assertDispatched(PortalSessionNoticeSent::class, function (PortalSessionNoticeSent $event): bool {
                return ($event->broadcastWith()['status'] ?? null) === 'manual_relogin_required';
            });
        }

        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_offline_session_ends_the_chain_silently(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        Bus::fake([RefreshPortalDataJob::class]);
        $this->mockKeepAlive('offline', expectReschedule: false);

        app()->call([new RefreshPortalSessionJob('campus-token'), 'handle']);

        // ResumeOfflineSessionJob owns an offline session: no login prompt, no scraping.
        Event::assertNotDispatched(PortalSessionNoticeSent::class);
        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_only_one_keep_alive_chain_exists_per_session(): void
    {
        Queue::fake();

        RefreshPortalSessionJob::dispatch('campus-token');
        RefreshPortalSessionJob::dispatch('campus-token');
        RefreshPortalSessionJob::dispatch('other-token');

        Queue::assertPushed(RefreshPortalSessionJob::class, 2);
    }

    public function test_a_keep_alive_has_time_for_a_ping_and_a_full_relogin(): void
    {
        $job = new RefreshPortalSessionJob('campus-token');

        // Ping (10 s) plus up to four login requests at the 30 s request timeout.
        $this->assertSame(180, $job->timeout);
        $this->assertGreaterThanOrEqual(10 + 4 * (int) config('portal.request_timeout'), $job->timeout);
    }

    public function test_alive_session_of_an_inactive_student_skips_the_warm_up(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        Bus::fake([RefreshPortalDataJob::class]);
        $this->mockKeepAlive('alive', expectReschedule: true, recentlyActive: false);

        app()->call([new RefreshPortalSessionJob('campus-token'), 'handle']);

        // The chain goes on (the session stays alive), but nothing is scraped for nobody.
        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    private function mockKeepAlive(string $outcome, bool $expectReschedule, bool $recentlyActive = true): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($outcome, $expectReschedule, $recentlyActive): void {
                $mock->shouldReceive('keepAlive')->once()->with('campus-token')->andReturn($outcome);
                $mock->shouldReceive('laravelSessionId')->with('campus-token')->andReturn('laravel-session');
                $mock->shouldReceive('wasActiveWithin')->with('campus-token', 1200)->andReturn($recentlyActive);

                if ($expectReschedule) {
                    $mock->shouldReceive('scheduleKeepAlive')->once()->with('campus-token');
                } else {
                    $mock->shouldNotReceive('scheduleKeepAlive');
                }
            })
        );
    }
}
