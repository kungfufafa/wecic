<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Services\PortalActionProjector;
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
        // The exception text stays in the log; the browser only gets a code.
        $this->assertSame(PortalDatasetRepository::SYNC_FAILED_CODE, $result['meta']['error']);
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
        $owner->remember('old-login', '20990000001', 600);
        $owner->remember('new-login', '20990000001', 600);

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

    public function test_rebuild_that_missed_a_confirmed_action_keeps_it_projected(): void
    {
        app(PortalActionProjector::class)->recordAssignmentUploaded('token', 9000, 29833, 2);
        $this->expectBuilds(1, ['assignments' => [
            ['meeting_id' => '29833', 'assignment_payload' => ['courses_id' => '9000', 'id' => '29833', 'pertemuan' => 2]],
            ['meeting_id' => '40000', 'assignment_payload' => ['courses_id' => '9001', 'id' => '40000', 'pertemuan' => 3]],
        ]]);

        $result = $this->repository()->read('token', 'assignments', forceFresh: true);

        $this->assertSame(['40000'], array_column($result['data']['assignments'], 'meeting_id'));
        $this->assertSame(['40000'], array_column($this->snapshots->get('token', 'assignments')['data']['assignments'], 'meeting_id'));
    }

    public function test_peek_never_builds_inline_and_queues_a_missing_snapshot_once(): void
    {
        $this->expectBuilds(0);

        $first = $this->repository()->peek('token', 'krs', [], 'laravel-session');
        $second = $this->repository()->peek('token', 'krs', [], 'laravel-session');

        $this->assertNull($first['data']);
        $this->assertSame('pending', $first['meta']['status']);
        $this->assertSame('pending', $second['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_peek_serves_a_stale_snapshot_while_it_resyncs(): void
    {
        $this->snapshots->put('token', 'grades', ['summary' => ['ipk' => 3.1]], now()->subHours(2)->toISOString());
        $this->expectBuilds(0);

        $result = $this->repository()->peek('token', 'grades');

        $this->assertSame(['summary' => ['ipk' => 3.1]], $result['data']);
        $this->assertSame('syncing', $result['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_peek_can_leave_a_missing_snapshot_alone(): void
    {
        $this->expectBuilds(0);

        $result = $this->repository()->peek('token', 'assignments', [], null, false);

        $this->assertSame('missing', $result['meta']['status']);
        Queue::assertNothingPushed();
    }

    public function test_peek_many_queues_every_missing_dataset_in_one_job(): void
    {
        $this->snapshots->put('token', 'krs', ['found' => true]);
        $this->expectBuilds(0);

        $reads = $this->repository()->peekMany('token', ['krs', 'grades', 'skkm', 'exams'], 'laravel-session', ['grades', 'skkm']);

        $this->assertSame(['found' => true], $reads['krs']['data']);
        $this->assertSame('pending', $reads['grades']['meta']['status']);
        $this->assertSame('pending', $reads['skkm']['meta']['status']);
        $this->assertSame('missing', $reads['exams']['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
        Queue::assertPushed(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => (fn (): array => $this->domains)->call($job) === ['grades', 'skkm']);
    }

    public function test_peek_many_leaves_stale_reuse_only_datasets_alone(): void
    {
        $this->snapshots->put('token', 'assignments', ['assignments' => []], now()->subHour()->toISOString());
        $this->expectBuilds(0);

        $reads = $this->repository()->peekMany('token', ['assignments'], 'laravel-session', ['krs']);

        // Served as is: the heavy crawl is never started by a secondary read.
        $this->assertSame(['assignments' => []], $reads['assignments']['data']);
        $this->assertSame('synced', $reads['assignments']['meta']['status']);
        Queue::assertNothingPushed();
    }

    public function test_peek_many_resyncs_every_stale_and_missing_dataset_in_one_job(): void
    {
        $this->snapshots->put('token', 'krs', ['found' => true], now()->subHours(2)->toISOString());
        $this->snapshots->put('token', 'skkm', ['found' => true], now()->subHours(2)->toISOString());
        $this->expectBuilds(0);

        $reads = $this->repository()->peekMany('token', ['krs', 'skkm', 'grades'], 'laravel-session');

        $this->assertSame('syncing', $reads['krs']['meta']['status']);
        $this->assertSame('syncing', $reads['skkm']['meta']['status']);
        $this->assertSame('pending', $reads['grades']['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
        Queue::assertPushed(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => (fn (): array => $this->domains)->call($job) === ['krs', 'skkm', 'grades']);

        // While that job runs, repeated reads queue nothing more.
        $this->repository()->peekMany('token', ['krs', 'skkm', 'grades'], 'laravel-session');
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_a_background_build_that_fails_without_a_snapshot_backs_off_and_reports_failed(): void
    {
        config(['portal.sync.build_failure_backoff' => 900]);
        $this->mockBuilder(function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andThrow(PortalScrapingException::dataExtractionFailed('grades', 'Kartu Hasil Studi tidak ditemukan di portal.'));
        });

        try {
            $this->repository()->sync('token', 'grades');
            $this->fail('The failed build should be rethrown to the job.');
        } catch (PortalScrapingException) {
            // expected
        }

        $reads = $this->repository()->peekMany('token', ['grades'], 'laravel-session');

        $this->assertNull($reads['grades']['data']);
        $this->assertSame('failed', $reads['grades']['meta']['status']);
        $this->assertSame('failed', $this->repository()->peek('token', 'grades')['meta']['status']);
        $this->assertSame([], $this->repository()->queueSync('token', ['grades']));
        Queue::assertNothingPushed();

        // After the back-off the build is queued again.
        Carbon::setTestNow(now()->addSeconds(901));
        $this->assertSame('pending', $this->repository()->peekMany('token', ['grades'], 'laravel-session')['grades']['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_a_successful_build_clears_an_earlier_build_failure(): void
    {
        $this->mockBuilder(function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andThrow(PortalScrapingException::dataExtractionFailed('skkm', 'down'));
            $mock->shouldReceive('build')->once()->andReturn(['found' => true]);
        });

        try {
            $this->repository()->read('token', 'skkm');
        } catch (PortalScrapingException) {
            // The first inline build fails: no snapshot, the page shows its error.
        }

        $this->assertSame('failed', $this->repository()->peek('token', 'skkm')['meta']['status']);

        // Opening the page again still builds inline (the student asked for it).
        $this->assertSame(['found' => true], $this->repository()->read('token', 'skkm')['data']);
        $this->assertSame('synced', $this->repository()->peek('token', 'skkm')['meta']['status']);
    }

    public function test_queue_sync_skips_fresh_in_flight_and_unknown_datasets(): void
    {
        $this->snapshots->put('token', 'payments', ['outstanding_summary' => []]);
        $this->snapshots->put('token', 'krs', ['found' => true], now()->subHour()->toISOString());
        $this->snapshots->markSyncing('token', 'krs');

        $this->assertSame([], $this->repository()->queueSync('token', ['payments', 'krs', 'not_a_dataset']));
        Queue::assertNothingPushed();
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
