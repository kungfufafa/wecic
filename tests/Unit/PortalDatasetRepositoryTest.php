<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSnapshotStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalDatasetRepositoryTest extends TestCase
{
    private PortalSnapshotStore $snapshots;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RefreshPortalDataJob::class]);
        $this->snapshots = app(PortalSnapshotStore::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_missing_snapshot_is_built_once_and_stored_without_a_background_job(): void
    {
        $this->expectBuilds(1, ['assignments' => [['title' => 'Tugas 1']], '_sync' => ['data_origin' => 'upstream']]);

        $result = $this->repository()->read('token', 'assignments');

        $this->assertSame(['assignments' => [['title' => 'Tugas 1']]], $result['data']);
        $this->assertSame('upstream', $result['meta']['data_origin']);
        $this->assertSame('synced', $result['meta']['status']);
        $this->assertSame(['assignments' => [['title' => 'Tugas 1']]], $this->snapshots->get('token', 'assignments')['data']);
        Queue::assertNothingPushed();
    }

    public function test_fresh_snapshot_is_served_without_touching_the_portal(): void
    {
        $this->snapshots->put('token', 'dashboard', ['overview' => 'cached']);
        $this->expectBuilds(0);

        $result = $this->repository()->read('token', 'dashboard');

        $this->assertSame(['overview' => 'cached'], $result['data']);
        $this->assertSame('snapshot', $result['meta']['data_origin']);
        $this->assertSame('synced', $result['meta']['status']);
        Queue::assertNothingPushed();
    }

    public function test_stale_snapshot_is_served_and_queues_exactly_one_refresh(): void
    {
        $this->snapshots->put('token', 'dashboard', ['overview' => 'old'], now()->subMinutes(15)->toISOString());
        $this->expectBuilds(0);

        $first = $this->repository()->read('token', 'dashboard', broadcastSessionId: 'laravel-session');
        $second = $this->repository()->read('token', 'dashboard', broadcastSessionId: 'laravel-session');

        $this->assertSame(['overview' => 'old'], $first['data']);
        $this->assertSame('syncing', $first['meta']['status']);
        $this->assertSame('syncing', $second['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_forced_read_reuses_a_snapshot_synced_moments_ago(): void
    {
        $this->snapshots->put('token', 'profile', ['nama' => 'Mahasiswa'], now()->subSeconds(5)->toISOString());
        $this->expectBuilds(0);

        $result = $this->repository()->read('token', 'profile', forceFresh: true);

        $this->assertSame('snapshot', $result['meta']['data_origin']);
        $this->assertTrue($result['meta']['requested_fresh']);
    }

    public function test_forced_read_rebuilds_once_a_portal_action_invalidated_the_dataset(): void
    {
        $this->snapshots->put('token', 'meeting_detail', ['status' => 'not_uploaded'], now()->subSeconds(5)->toISOString(), ['meeting_id' => '445']);
        $this->expectBuilds(1, ['status' => 'uploaded']);

        Carbon::setTestNow(now()->addSecond());
        $this->repository()->invalidate('token', ['meeting_detail']);
        Carbon::setTestNow(now()->addSecond());

        $result = $this->repository()->read('token', 'meeting_detail', ['meeting_id' => '445'], forceFresh: true);

        $this->assertSame(['status' => 'uploaded'], $result['data']);
        $this->assertSame('upstream', $result['meta']['data_origin']);
    }

    public function test_invalidated_snapshot_is_still_served_while_it_resyncs(): void
    {
        $this->snapshots->put('token', 'assignments', ['assignments' => ['pending']]);
        $this->expectBuilds(0);

        Carbon::setTestNow(now()->addSecond());
        $this->repository()->invalidate('token', ['assignments']);

        $result = $this->repository()->read('token', 'assignments');

        $this->assertSame(['assignments' => ['pending']], $result['data']);
        $this->assertSame('syncing', $result['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_failed_rebuild_serves_the_previous_snapshot_instead_of_an_error(): void
    {
        $this->snapshots->put('token', 'exams', ['exams' => ['UTS']], now()->subHour()->toISOString());
        $this->mockBuilder(function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andThrow(PortalScrapingException::invalidResponse('https://my.cic.ac.id', 'Error page detected'));
        });

        $result = $this->repository()->read('token', 'exams', forceFresh: true);

        $this->assertSame(['exams' => ['UTS']], $result['data']);
        $this->assertSame('failed', $result['meta']['status']);
        $this->assertSame('failed', $this->snapshots->get('token', 'exams')['status']);
    }

    public function test_recently_failed_sync_is_not_requeued_on_every_read(): void
    {
        $this->snapshots->put('token', 'exams', ['exams' => ['UTS']], now()->subHour()->toISOString());
        $this->snapshots->markFailed('token', 'exams', 'Portal down');
        $this->expectBuilds(0);

        $result = $this->repository()->read('token', 'exams');

        $this->assertSame('failed', $result['meta']['status']);
        $this->assertSame('Portal down', $result['meta']['error']);
        Queue::assertNothingPushed();

        Carbon::setTestNow(now()->addSeconds(config('portal.sync.failure_backoff') + 1));
        $this->repository()->read('token', 'exams');

        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_expired_campus_session_is_never_hidden_behind_a_snapshot(): void
    {
        $this->snapshots->put('token', 'exams', ['exams' => ['UTS']], now()->subHour()->toISOString());
        $this->mockBuilder(function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andThrow(PortalScrapingException::pageNotAccessible('https://my.cic.ac.id'));
        });

        $this->expectException(PortalScrapingException::class);

        $this->repository()->read('token', 'exams', forceFresh: true);
    }

    public function test_snapshots_from_a_previous_login_are_available_after_relogin(): void
    {
        $owner = app(PortalDataOwner::class);
        $owner->remember('old-login', '20251030030', 600);
        $owner->remember('new-login', '20251030030', 600);

        $this->snapshots->put('old-login', 'dashboard', ['overview' => 'from previous login']);
        $this->expectBuilds(0);

        $result = $this->repository()->read('new-login', 'dashboard');

        $this->assertSame(['overview' => 'from previous login'], $result['data']);
    }

    public function test_snapshot_is_stamped_with_the_moment_its_build_started(): void
    {
        $this->mockBuilder(function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andReturnUsing(function (): array {
                // An upload invalidates the dataset while this sync is still scraping.
                Carbon::setTestNow(now()->addSeconds(10));
                app(PortalSnapshotStore::class)->markStale('token', 'assignments');
                Carbon::setTestNow(now()->addSeconds(10));

                return ['assignments' => ['scraped before the upload']];
            });
        });

        $this->repository()->sync('token', 'assignments');

        $this->assertTrue($this->snapshots->isStale('token', 'assignments'));
    }

    private function repository(): PortalDatasetRepository
    {
        return app(PortalDatasetRepository::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function expectBuilds(int $times, array $payload = []): void
    {
        $this->mockBuilder(function (MockInterface $mock) use ($times, $payload): void {
            $times === 0
                ? $mock->shouldNotReceive('build')
                : $mock->shouldReceive('build')->times($times)->andReturn($payload);
        });
    }

    private function mockBuilder(callable $expectations): void
    {
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, $expectations));
    }
}
