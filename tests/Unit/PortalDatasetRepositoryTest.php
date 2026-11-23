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
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
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

    public function test_a_failed_sync_of_a_still_fresh_snapshot_is_retried_after_the_back_off(): void
    {
        // Synced a minute ago, so a forced read is past the cooldown.
        Carbon::setTestNow(now()->subMinute());
        $this->snapshots->put('token', 'exams', ['exams' => ['UTS']]);
        Carbon::setTestNow(now()->addMinute());
        $this->mockBuilder(function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andThrow(PortalScrapingException::invalidResponse('https://my.cic.ac.id', 'Error page detected'));
        });

        $this->assertSame('failed', $this->repository()->read('token', 'exams', forceFresh: true)['meta']['status']);

        // Within the back-off the failure is reported and nothing is queued.
        $this->assertSame('failed', $this->repository()->read('token', 'exams')['meta']['status']);
        Queue::assertNothingPushed();

        // Ten minutes later the snapshot is still fresh, but the failure is
        // retried in the background instead of being reported until it goes stale.
        Carbon::setTestNow(now()->addMinutes(10));
        $result = $this->repository()->read('token', 'exams');

        $this->assertSame(['exams' => ['UTS']], $result['data']);
        $this->assertSame('syncing', $result['meta']['status']);
        $this->assertArrayNotHasKey('error', $result['meta']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);

        $reads = $this->repository()->peekMany('token', ['exams'], 'laravel-session');
        $this->assertSame('syncing', $reads['exams']['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_peek_many_retries_a_failed_sync_of_a_fresh_snapshot_after_the_back_off(): void
    {
        $this->snapshots->put('token', 'portal_context', ['semester_number' => 5]);
        $this->snapshots->markFailed('token', 'portal_context', 'Portal down');
        $this->expectBuilds(0);

        $failed = $this->repository()->peekMany('token', ['portal_context'])['portal_context']['meta'];
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(PortalDatasetRepository::SYNC_FAILED_CODE, $failed['error']);
        Queue::assertNothingPushed();

        Carbon::setTestNow(now()->addSeconds(config('portal.sync.failure_backoff') + 1));

        // Retried: it reads like any re-sync, without the error of the failed one.
        $retried = $this->repository()->peekMany('token', ['portal_context'])['portal_context']['meta'];
        $this->assertSame('syncing', $retried['status']);
        $this->assertArrayNotHasKey('error', $retried);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_a_caller_that_times_out_behind_a_concurrent_build_serves_the_snapshot_instead_of_crawling_again(): void
    {
        config(['portal.sync.lock_wait' => 0]);
        $this->snapshots->put('token', 'assignments', ['assignments' => ['old']], now()->subHour()->toISOString());
        $this->expectBuilds(0);

        $lock = Cache::lock($this->lockKey('token', 'assignments'), 300);
        $this->assertTrue($lock->get());

        $result = $this->repository()->read('token', 'assignments', forceFresh: true);
        $synced = $this->repository()->sync('token', 'assignments');

        $this->assertSame(['assignments' => ['old']], $result['data']);
        $this->assertSame('syncing', $result['meta']['status']);
        $this->assertSame(['assignments' => ['old']], $synced);

        $lock->release();
    }

    public function test_a_caller_with_no_snapshot_still_builds_after_waiting_on_a_concurrent_build(): void
    {
        config(['portal.sync.lock_wait' => 0]);
        $this->expectBuilds(1, ['assignments' => ['new']]);

        $lock = Cache::lock($this->lockKey('token', 'assignments'), 300);
        $this->assertTrue($lock->get());

        $result = $this->repository()->read('token', 'assignments');

        $this->assertSame(['assignments' => ['new']], $result['data']);
        $this->assertSame('synced', $result['meta']['status']);

        $lock->release();
    }

    public function test_force_cooldown_runs_from_when_a_slow_sync_finished(): void
    {
        $this->mockBuilder(function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andReturnUsing(function (): array {
                // The crawl takes longer than the cooldown.
                Carbon::setTestNow(now()->addSeconds(35));

                return ['assignments' => ['fresh']];
            });
        });

        $this->repository()->sync('token', 'assignments');
        Carbon::setTestNow(now()->addSeconds(2));

        $result = $this->repository()->read('token', 'assignments', forceFresh: true);

        $this->assertSame(['assignments' => ['fresh']], $result['data']);
        $this->assertSame('snapshot', $result['meta']['data_origin']);
    }

    public function test_forced_read_rebuilds_once_the_cooldown_after_a_finished_sync_is_over(): void
    {
        $this->snapshots->put('token', 'profile', ['nama' => 'Lama'], now()->subSeconds(40)->toISOString());
        $this->expectBuilds(1, ['nama' => 'Baru']);

        Carbon::setTestNow(now()->addSeconds(31));

        $result = $this->repository()->read('token', 'profile', forceFresh: true);

        $this->assertSame(['nama' => 'Baru'], $result['data']);
        $this->assertSame('upstream', $result['meta']['data_origin']);
    }

    public function test_forced_read_can_bypass_the_cooldown_when_requested(): void
    {
        $this->snapshots->put('token', 'exams', ['exams' => [['id' => 9521, 'can_upload' => false]]], now()->subSeconds(5)->toISOString());
        $this->expectBuilds(1, ['exams' => [['id' => 9521, 'can_upload' => true]]]);

        $withinCooldown = $this->repository()->read('token', 'exams', forceFresh: true);
        $this->assertSame('snapshot', $withinCooldown['meta']['data_origin']);
        $this->assertFalse($withinCooldown['data']['exams'][0]['can_upload']);

        $bypassed = $this->repository()->read('token', 'exams', forceFresh: true, bypassCooldown: true);
        $this->assertSame('upstream', $bypassed['meta']['data_origin']);
        $this->assertTrue($bypassed['data']['exams'][0]['can_upload']);
    }

    public function test_a_stale_snapshot_is_still_served_when_its_resync_cannot_be_queued(): void
    {
        $this->snapshots->put('token', 'grades', ['summary' => ['ipk' => 3.1]], now()->subHours(2)->toISOString());
        $this->expectBuilds(0);
        $this->mock(BusDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('database is locked'));
        });

        $read = $this->repository()->read('token', 'grades');
        $peek = $this->repository()->peek('token', 'grades');

        $this->assertSame(['summary' => ['ipk' => 3.1]], $read['data']);
        $this->assertSame('synced', $read['meta']['status']);
        $this->assertSame(['summary' => ['ipk' => 3.1]], $peek['data']);
        // Nothing was queued, so the snapshot is not left marked as syncing.
        $this->assertSame('synced', $this->snapshots->get('token', 'grades')['status']);
        $this->assertArrayNotHasKey('syncing_since', $this->snapshots->get('token', 'grades'));
    }

    public function test_a_stale_snapshot_is_marked_syncing_before_its_job_is_queued(): void
    {
        $this->snapshots->put('token', 'grades', ['summary' => ['ipk' => 3.1]], now()->subHours(2)->toISOString());
        $this->expectBuilds(0);
        $statusWhenQueued = null;
        $this->mock(BusDispatcher::class, function (MockInterface $mock) use (&$statusWhenQueued): void {
            $mock->shouldReceive('dispatch')->once()->andReturnUsing(function () use (&$statusWhenQueued): null {
                $statusWhenQueued = app(PortalSnapshotStore::class)->get('token', 'grades')['status'];

                return null;
            });
        });

        $this->assertSame('syncing', $this->repository()->read('token', 'grades')['meta']['status']);
        $this->assertSame('syncing', $statusWhenQueued);
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

    public function test_class_page_rebuild_that_missed_a_confirmed_attendance_keeps_it_projected(): void
    {
        $params = ['id' => '5393', 'dosen_id' => '1863', 'course_id' => '8833'];
        app(PortalActionProjector::class)->recordAttendance('token', 8833, 30728, 8);
        $this->expectBuilds(1, [
            'course_materials' => [['meeting_number' => 8, 'join_link' => ['href' => '?mod=sipandai&act=discuss&id=30728&courses_id=8833']]],
            'attendance_info' => ['meetings' => [['meeting_number' => 8, 'status' => 'absent', 'details' => []]], 'summary' => []],
        ]);

        $result = $this->repository()->read('token', 'class_detail', $params, forceFresh: true);

        $this->assertSame('present', $result['data']['attendance_info']['meetings'][0]['status']);
        $this->assertSame('present', $this->snapshots->get('token', 'class_detail', $params)['data']['attendance_info']['meetings'][0]['status']);
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

    public function test_an_early_resync_queues_a_still_fresh_snapshot_once_its_last_sync_is_not_moments_old(): void
    {
        $params = PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS;
        $this->snapshots->put('token', 'dashboard', ['date' => '2026-10-19', 'classes' => []], now()->toISOString(), $params);
        $this->expectBuilds(0);

        // Moments after a sync nothing is queued again.
        Carbon::setTestNow(now()->addSeconds(10));
        $this->assertFalse($this->repository()->queueEarlyResync('token', 'dashboard', $params, 'laravel-session'));

        // Still fresh two minutes later: queueSync() leaves it alone, an early re-sync does not.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame([], $this->repository()->queueSync('token', ['dashboard'], $params));
        $this->assertTrue($this->repository()->queueEarlyResync('token', 'dashboard', $params, 'laravel-session'));
        $this->assertSame('syncing', $this->snapshots->get('token', 'dashboard', $params)['status']);

        // While that job runs nothing more is queued.
        $this->assertFalse($this->repository()->queueEarlyResync('token', 'dashboard', $params, 'laravel-session'));
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
        Queue::assertPushed(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => (fn (): array => $this->params)->call($job) === $params
            && (fn (): array => $this->domains)->call($job) === ['dashboard']);
    }

    public function test_an_inline_early_resync_syncs_a_still_fresh_snapshot_under_the_same_guards(): void
    {
        $params = PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS;
        $this->snapshots->put('token', 'dashboard', ['date' => '2026-10-19', 'classes' => []], now()->toISOString(), $params);
        $this->mockBuilder(function (MockInterface $mock) use ($params): void {
            $mock->shouldReceive('build')->with('token', 'dashboard', $params)->once()->andReturn(['date' => '2026-10-19', 'classes' => ['IF101']]);
        });

        // Moments after a sync nothing is synced again.
        Carbon::setTestNow(now()->addSeconds(10));
        $this->assertNull($this->repository()->resyncEarlyInline('token', 'dashboard', $params));

        // Still fresh two minutes later: synced right here, no job queued.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame(['date' => '2026-10-19', 'classes' => ['IF101']], $this->repository()->resyncEarlyInline('token', 'dashboard', $params));
        $this->assertSame('synced', $this->snapshots->get('token', 'dashboard', $params)['status']);
        Queue::assertNothingPushed();

        // A sync of it queued (or running) elsewhere is left to finish.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->snapshots->markSyncing('token', 'dashboard', $params);
        $this->assertNull($this->repository()->resyncEarlyInline('token', 'dashboard', $params));
    }

    public function test_an_inline_early_resync_leaves_a_missing_snapshot_to_the_job_already_queued_for_it(): void
    {
        $params = PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS;
        $this->expectBuilds(0);

        $this->assertSame('pending', $this->repository()->peek('token', 'dashboard', $params)['meta']['status']);
        $this->assertNull($this->repository()->resyncEarlyInline('token', 'dashboard', $params));
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_an_inline_early_resync_cut_short_by_an_expired_session_lets_the_next_read_queue_it_again(): void
    {
        $params = PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS;
        $this->snapshots->put('token', 'dashboard', ['date' => '2026-10-19', 'classes' => []], now()->subMinutes(15)->toISOString(), $params);
        $this->mockBuilder(function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andThrow(PortalScrapingException::pageNotAccessible('https://my.cic.ac.id'));
        });

        try {
            $this->repository()->resyncEarlyInline('token', 'dashboard', $params);
            $this->fail('An expired campus session must propagate.');
        } catch (PortalScrapingException) {
            // Expected: the job asks the student to log in again.
        }

        $this->assertSame('synced', $this->snapshots->get('token', 'dashboard', $params)['status']);
        $this->assertSame('syncing', $this->repository()->peek('token', 'dashboard', $params)['meta']['status']);
        Queue::assertPushed(RefreshPortalDataJob::class, 1);
    }

    public function test_queue_sync_skips_fresh_in_flight_and_unknown_datasets(): void
    {
        $this->snapshots->put('token', 'payments', ['outstanding_summary' => []]);
        $this->snapshots->put('token', 'krs', ['found' => true], now()->subHour()->toISOString());
        $this->snapshots->markSyncing('token', 'krs');

        $this->assertSame([], $this->repository()->queueSync('token', ['payments', 'krs', 'not_a_dataset']));
        Queue::assertNothingPushed();
    }

    public function test_a_queued_sync_leaves_the_unique_job_lock_with_its_job(): void
    {
        $this->snapshots->put('token', 'grades', ['summary' => ['ipk' => 3.1]], now()->subHours(2)->toISOString());

        $this->assertSame(['grades'], $this->repository()->queueSync('token', ['grades']));

        Queue::assertPushed(RefreshPortalDataJob::class, 1);
        $this->assertFalse(Cache::lock($this->uniqueJobLockKey(['grades']), 10)->get(), 'The queued job holds its unique lock until it ran.');
        $this->assertSame('syncing', $this->snapshots->get('token', 'grades')['status']);
    }

    public function test_a_failed_push_gives_back_the_unique_job_lock_it_took(): void
    {
        $this->snapshots->put('token', 'grades', ['summary' => ['ipk' => 3.1]], now()->subHours(2)->toISOString());
        $this->mock(BusDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        });

        try {
            $this->repository()->queueSync('token', ['grades']);
            $this->fail('A failed push is reported to the caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }

        $this->assertTrue(Cache::lock($this->uniqueJobLockKey(['grades']), 10)->get(), 'The next identical dispatch is not dropped.');
        $this->assertSame('synced', $this->snapshots->get('token', 'grades')['status']);
    }

    public function test_a_unique_job_lock_held_by_an_identical_job_is_neither_released_nor_dispatched_again(): void
    {
        $this->snapshots->put('token', 'grades', ['summary' => ['ipk' => 3.1]], now()->subHours(2)->toISOString());
        $identicalJobLock = Cache::lock($this->uniqueJobLockKey(['grades']), 300);
        $this->assertTrue($identicalJobLock->get());
        $this->mock(BusDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        // The identical job syncs the dataset, so it reads as syncing meanwhile.
        $this->assertSame(['grades'], $this->repository()->queueSync('token', ['grades']));
        $this->assertSame('syncing', $this->snapshots->get('token', 'grades')['status']);
        $this->assertTrue($identicalJobLock->release(), 'The identical job still owns its lock.');
    }

    public function test_a_unique_job_lock_that_could_not_be_taken_is_never_released(): void
    {
        $this->snapshots->put('token', 'grades', ['summary' => ['ipk' => 3.1]], now()->subHours(2)->toISOString());
        $lockKey = $this->uniqueJobLockKey(['grades']);
        $identicalJobLock = Cache::lock($lockKey, 300);
        $this->assertTrue($identicalJobLock->get());

        $cache = Mockery::mock(app('cache'));
        $cache->shouldReceive('lock')->with($lockKey, Mockery::any())->andThrow(new \RuntimeException('cache unavailable'));
        Cache::swap($cache);
        $this->mock(BusDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        try {
            $this->repository()->queueSync('token', ['grades']);
            $this->fail('A lock that could not be taken is reported to the caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('cache unavailable', $exception->getMessage());
        }

        $this->assertSame('synced', $this->snapshots->get('token', 'grades')['status'], 'Nothing was queued, so the claim is given back.');
        $this->assertTrue($identicalJobLock->release(), 'The identical job still owns its lock.');
    }

    private function repository(): PortalDatasetRepository
    {
        return app(PortalDatasetRepository::class);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function lockKey(string $sessionToken, string $dataset, array $params = []): string
    {
        return (fn (): string => $this->lockKey($sessionToken, $dataset, $params))->call($this->repository());
    }

    /**
     * The unique-job lock key of the background sync of `$datasets` for `token`
     *
     * @param  list<string>  $datasets
     */
    private function uniqueJobLockKey(array $datasets): string
    {
        return UniqueLock::getKey(new RefreshPortalDataJob('', 'token', $datasets));
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
