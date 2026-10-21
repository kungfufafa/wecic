<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalNotificationSent;
use App\Events\PortalSessionNoticeSent;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Decision datasets are synced in the background right after login and
 * topped up by the keep-alive ping, so their pages open from a snapshot.
 */
class PortalDataWarmupTest extends TestCase
{
    private const DECISION_DATASETS = ['portal_context', 'krs', 'advising', 'payments', 'grades', 'skkm', 'announcements', 'exams'];

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_login_queues_one_delayed_background_sync_of_the_decision_datasets(): void
    {
        Event::fake([PortalSessionNoticeSent::class, PortalNotificationSent::class]);
        Queue::fake();
        $this->mockLogin('campus-token-warm');

        $this->login()->assertOk()->assertJsonPath('status', 'success');

        Queue::assertPushed(RefreshPortalDataJob::class, 1);
        Queue::assertPushed(RefreshPortalDataJob::class, function (RefreshPortalDataJob $job): bool {
            return $this->jobProperty($job, 'domains') === self::DECISION_DATASETS
                && $this->jobProperty($job, 'campusSessionToken') === 'campus-token-warm'
                && $this->jobProperty($job, 'sessionId') !== ''
                && $job->delay !== null
                && $job->queue === 'campus-refresh';
        });
        Queue::assertPushed(ScanPortalNotificationsJob::class, 1);
    }

    public function test_login_skips_datasets_whose_snapshot_is_still_fresh(): void
    {
        Event::fake([PortalSessionNoticeSent::class, PortalNotificationSent::class]);
        Bus::fake([RefreshPortalDataJob::class, ScanPortalNotificationsJob::class]);
        $this->mockLogin('campus-token-fresh');
        app(PortalSnapshotStore::class)->put('campus-token-fresh', 'krs', ['found' => true]);
        app(PortalSnapshotStore::class)->put('campus-token-fresh', 'grades', ['summary' => []]);

        $this->login()->assertOk();

        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobProperty($job, 'domains') === ['portal_context', 'advising', 'payments', 'skkm', 'announcements', 'exams']);
    }

    public function test_login_still_succeeds_when_the_warm_up_cannot_be_queued(): void
    {
        Event::fake([PortalSessionNoticeSent::class, PortalNotificationSent::class]);
        $this->mockLogin('campus-token-broken');
        Bus::shouldReceive('dispatch')->andReturnUsing(static function (object $job): mixed {
            if ($job instanceof RefreshPortalDataJob) {
                throw new \RuntimeException('queue down');
            }

            return null;
        });

        $this->login()
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertSessionHas('campus_session_token', 'campus-token-broken');

        // The failed attempt leaves no marker behind, so the next read can queue again.
        Bus::fake([RefreshPortalDataJob::class]);
        $this->assertSame(self::DECISION_DATASETS, app(PortalDatasetRepository::class)->warmUp('campus-token-broken'));
    }

    public function test_repeated_warm_ups_do_not_pile_up_jobs(): void
    {
        Bus::fake([RefreshPortalDataJob::class]);
        $datasets = app(PortalDatasetRepository::class);

        $this->assertSame(self::DECISION_DATASETS, $datasets->warmUp('campus-token-repeat', 'laravel-session'));
        $this->assertSame([], $datasets->warmUp('campus-token-repeat', 'laravel-session'));

        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
    }

    public function test_keep_alive_tops_up_only_stale_decision_datasets(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        Bus::fake([RefreshPortalDataJob::class]);
        $snapshots = app(PortalSnapshotStore::class);

        foreach (self::DECISION_DATASETS as $dataset) {
            $snapshots->put('campus-token-alive', $dataset, ['dataset' => $dataset]);
        }

        // 20 minutes later only the 15-minute KRS snapshot is stale.
        $this->travel(20)->minutes();
        $this->mockKeepAlive('campus-token-alive', 'alive');

        app()->call([new RefreshPortalSessionJob('campus-token-alive'), 'handle']);

        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobProperty($job, 'domains') === ['krs']
            && $this->jobProperty($job, 'sessionId') === 'laravel-session');
        $this->assertSame('syncing', $snapshots->get('campus-token-alive', 'krs')['status']);
    }

    public function test_keep_alive_skips_the_warm_up_when_the_student_is_not_using_the_app(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        Bus::fake([RefreshPortalDataJob::class]);

        $this->mockKeepAlive('campus-token-idle-tab', 'alive', recentlyActive: false);
        app()->call([new RefreshPortalSessionJob('campus-token-idle-tab'), 'handle']);

        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_keep_alive_warm_up_can_be_disabled_and_never_runs_for_an_expired_session(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        Bus::fake([RefreshPortalDataJob::class]);

        config(['portal.sync.keepalive_warmup' => false]);
        $this->mockKeepAlive('campus-token-off', 'alive');
        app()->call([new RefreshPortalSessionJob('campus-token-off'), 'handle']);

        config(['portal.sync.keepalive_warmup' => true]);
        $this->mockKeepAlive('campus-token-expired', 'expired', reschedule: false);
        app()->call([new RefreshPortalSessionJob('campus-token-expired'), 'handle']);

        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_new_decision_datasets_can_be_refreshed_on_request(): void
    {
        Bus::fake([RefreshPortalDataJob::class]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with('campus-token-refresh')->andReturnTrue();
        }));

        $response = $this->withSession([
            '_token' => 'known-csrf-token',
            'campus_session_token' => 'campus-token-refresh',
        ])->postJson('/api/refresh', [
            'domains' => ['krs', 'grades', 'skkm', 'announcements', 'portal_context', 'advising'],
        ], ['X-CSRF-TOKEN' => 'known-csrf-token']);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobProperty($job, 'domains') === ['krs', 'grades', 'skkm', 'announcements', 'portal_context', 'advising']);

        // Parameterized datasets need their parameters, so they are not refreshable this way.
        $this->withSession([
            '_token' => 'known-csrf-token',
            'campus_session_token' => 'campus-token-refresh',
        ])->postJson('/api/refresh', ['domains' => ['krs_detail']], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertUnprocessable();
    }

    private function login(): \Illuminate\Testing\TestResponse
    {
        return $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->withSession(['_token' => 'known-csrf-token'])
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => '20990000001',
                'password' => 'secret-password',
            ], ['Accept' => 'application/json']);
    }

    private function mockLogin(string $token): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
            $mock->shouldReceive('createSession')->once()->andReturn([
                'success' => true,
                'session_token' => $token,
                'user_info' => ['name' => 'Mahasiswa Uji'],
                'expires_at' => '2026-10-20T12:00:00+07:00',
            ]);
            $mock->shouldReceive('rememberLaravelSessionId')->once();
        }));
    }

    private function mockKeepAlive(string $token, string $outcome, bool $reschedule = true, bool $recentlyActive = true): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token, $outcome, $reschedule, $recentlyActive): void {
            $mock->shouldReceive('keepAlive')->once()->with($token)->andReturn($outcome);
            $mock->shouldReceive('laravelSessionId')->with($token)->andReturn('laravel-session');
            $mock->shouldReceive('wasActiveWithin')->with($token, (int) config('portal.session_refresh_interval', 1200))->andReturn($recentlyActive);

            if ($reschedule) {
                $mock->shouldReceive('scheduleKeepAlive')->once()->with($token);
            }
        }));
    }

    private function jobProperty(object $job, string $property): mixed
    {
        return (new \ReflectionClass($job))->getProperty($property)->getValue($job);
    }
}
