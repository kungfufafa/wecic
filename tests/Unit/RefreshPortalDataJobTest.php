<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PortalOperationStage;
use App\Events\PortalDataInvalidated;
use App\Events\PortalSyncStatusUpdated;
use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalFetchPolicy;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

class RefreshPortalDataJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_queue_serialization_restores_constructor_properties(): void
    {
        $job = new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard', 'assignments'], ['id' => '2265']);

        /** @var RefreshPortalDataJob $restored */
        $restored = unserialize(serialize($job));

        $this->assertSame('laravel-session', $this->jobProperty($restored, 'sessionId'));
        $this->assertSame('campus-token', $this->jobProperty($restored, 'campusSessionToken'));
        $this->assertSame(['dashboard', 'assignments'], $this->jobProperty($restored, 'domains'));
        $this->assertSame(['id' => '2265'], $this->jobProperty($restored, 'params'));
    }

    public function test_the_job_stays_unique_for_as_long_as_it_may_run(): void
    {
        config(['portal.sync.lock_ttl' => 300]);

        $warmUp = new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard', 'assignments', 'krs', 'advising', 'payments', 'skkm', 'grades', 'exams']);
        $single = new RefreshPortalDataJob('laravel-session', 'campus-token', ['krs']);

        $this->assertSame(600, $warmUp->timeout);
        $this->assertSame(600, $warmUp->uniqueFor());
        $this->assertSame(120, $single->timeout);
        $this->assertSame(300, $single->uniqueFor());
    }

    public function test_refresh_job_rebuilds_datasets_from_upstream_and_broadcasts_their_keys(): void
    {
        Event::fake([PortalSyncStatusUpdated::class, PortalDataInvalidated::class]);
        $this->mockUsableSession(true);

        $forcedFreshDuringBuild = [];
        $this->instance(
            PortalDatasetBuilder::class,
            Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock) use (&$forcedFreshDuringBuild): void {
                $mock->shouldReceive('build')
                    ->twice()
                    ->andReturnUsing(function (string $token, string $dataset) use (&$forcedFreshDuringBuild): array {
                        $forcedFreshDuringBuild[$dataset] = app(PortalFetchPolicy::class)->forcesFresh();

                        return ['dataset' => $dataset, '_sync' => ['data_origin' => 'upstream']];
                    });
            })
        );

        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard', 'assignments']), 'handle']);

        // The background sync must bypass resource caches: otherwise it only
        // re-stamps cached data as freshly synced.
        $this->assertSame(['dashboard' => true, 'assignments' => true], $forcedFreshDuringBuild);

        $snapshot = app(PortalSnapshotStore::class)->get('campus-token', 'assignments');
        $this->assertSame(['dataset' => 'assignments'], $snapshot['data']);

        Event::assertDispatched(PortalSyncStatusUpdated::class, function (PortalSyncStatusUpdated $event): bool {
            $payload = $event->broadcastWith();

            return ($payload['status'] ?? null) === PortalOperationStage::Completed->value
                && in_array('/api/dashboard', $payload['cache_keys'] ?? [], true)
                && in_array('/api/assignment/pending', $payload['cache_keys'] ?? [], true);
        });

        // Each dataset is announced as soon as it is stored, not after the whole job.
        Event::assertDispatchedTimes(PortalDataInvalidated::class, 2);
        Event::assertDispatched(PortalDataInvalidated::class, function (PortalDataInvalidated $event): bool {
            $payload = $event->broadcastWith();

            return in_array('/api/dashboard', $payload['cache_keys'], true)
                && ! in_array('/api/assignment/pending', $payload['cache_keys'], true);
        });
        Event::assertDispatched(PortalDataInvalidated::class, function (PortalDataInvalidated $event): bool {
            $payload = $event->broadcastWith();

            return in_array('/api/assignment/pending', $payload['cache_keys'], true)
                && in_array('dashboard-pending-assignments', $payload['sync_keys'], true);
        });
    }

    public function test_the_crawl_of_every_class_and_meeting_runs_after_the_datasets_read_from_a_few_pages(): void
    {
        Event::fake([PortalSyncStatusUpdated::class, PortalDataInvalidated::class]);
        $this->mockUsableSession(true);

        $built = [];
        $this->instance(
            PortalDatasetBuilder::class,
            Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock) use (&$built): void {
                $mock->shouldReceive('build')
                    ->times(3)
                    ->andReturnUsing(function (string $token, string $dataset) use (&$built): array {
                        $built[] = $dataset;

                        return ['dataset' => $dataset];
                    });
            })
        );

        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['assignments', 'krs', 'dashboard']), 'handle']);

        $this->assertSame(['krs', 'dashboard', 'assignments'], $built);
    }

    /**
     * POST /api/refresh (the Sinkronkan button) can only name `dashboard`:
     * its re-sync brings today's attendance along, straight after the
     * schedule is stored and announced and ahead of the other datasets.
     */
    public function test_a_schedule_resync_brings_todays_attendance_along_right_after_the_schedule(): void
    {
        Event::fake([PortalSyncStatusUpdated::class, PortalDataInvalidated::class]);
        $this->mockUsableSession(true);
        $attendanceParams = PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS;
        // Built five minutes ago: still fresh, so no read would re-sync it.
        app(PortalSnapshotStore::class)->put('campus-token', 'dashboard', ['date' => '2026-10-19', 'classes' => []], now()->subMinutes(5)->toISOString(), $attendanceParams);

        $built = $this->mockBuilderRecording([
            'dashboard' => ['classes_summary' => ['date' => '2026-10-19', 'today_classes' => [['course_code' => 'IF101']]]],
        ]);

        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard', 'assignments', 'krs']), 'handle']);

        $this->assertSame([
            ['dashboard', []],
            ['dashboard', $attendanceParams],
            ['krs', []],
            ['assignments', []],
        ], $built->getArrayCopy());

        $attendance = app(PortalSnapshotStore::class)->get('campus-token', 'dashboard', $attendanceParams);
        $this->assertSame(['dataset' => 'dashboard', 'params' => $attendanceParams], $attendance['data']);
        $this->assertSame('synced', $attendance['status']);

        // The schedule is announced before its attendance is crawled, the attendance once it is stored.
        $announced = Event::dispatched(PortalDataInvalidated::class)
            ->map(fn (array $arguments): array => $arguments[0]->broadcastWith()['sync_keys'])
            ->values()
            ->all();
        $this->assertCount(4, $announced);
        $this->assertSame([['dashboard-overview'], ['dashboard-overview']], array_slice($announced, 0, 2));
        Event::assertNotDispatched(PortalSyncStatusUpdated::class, fn (PortalSyncStatusUpdated $event): bool => ($event->broadcastWith()['status'] ?? null) === PortalOperationStage::Failed->value);
    }

    public function test_a_schedule_resync_leaves_the_attendance_alone_when_no_resync_of_it_is_due(): void
    {
        Event::fake([PortalSyncStatusUpdated::class, PortalDataInvalidated::class]);
        $attendanceParams = PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS;
        $store = app(PortalSnapshotStore::class);
        $withClasses = ['classes_summary' => ['date' => '2026-10-19', 'today_classes' => [['course_code' => 'IF101']]]];

        // A day without classes has no attendance to check.
        $this->mockUsableSession(true);
        $store->put('campus-token', 'dashboard', ['date' => '2026-10-19', 'classes' => []], now()->subMinutes(5)->toISOString(), $attendanceParams);
        $built = $this->mockBuilderRecording(['dashboard' => ['classes_summary' => ['date' => '2026-10-19', 'today_classes' => []]]]);
        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard']), 'handle']);
        $this->assertSame([['dashboard', []]], $built->getArrayCopy());

        // Synced moments ago (`force_cooldown`).
        $this->mockUsableSession(true);
        $store->put('campus-token', 'dashboard', ['date' => '2026-10-19', 'classes' => []], now()->subSeconds(10)->toISOString(), $attendanceParams);
        $built = $this->mockBuilderRecording(['dashboard' => $withClasses]);
        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard']), 'handle']);
        $this->assertSame([['dashboard', []]], $built->getArrayCopy());

        // A job of its own is already queued for it.
        $this->mockUsableSession(true);
        $store->put('campus-token', 'dashboard', ['date' => '2026-10-19', 'classes' => []], now()->subMinutes(15)->toISOString(), $attendanceParams);
        $store->markSyncing('campus-token', 'dashboard', $attendanceParams);
        $built = $this->mockBuilderRecording(['dashboard' => $withClasses]);
        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard']), 'handle']);
        $this->assertSame([['dashboard', []]], $built->getArrayCopy());

        // That attendance job itself never follows anything up.
        $this->mockUsableSession(true);
        $built = $this->mockBuilderRecording(['dashboard' => $withClasses]);
        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard'], $attendanceParams), 'handle']);
        $this->assertSame([['dashboard', $attendanceParams]], $built->getArrayCopy());
    }

    public function test_a_failed_attendance_resync_keeps_the_schedule_and_reports_a_partial_failure(): void
    {
        Event::fake([PortalSyncStatusUpdated::class, PortalDataInvalidated::class]);
        $this->mockUsableSession(true);
        $attendanceParams = PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS;
        app(PortalSnapshotStore::class)->put('campus-token', 'dashboard', ['date' => '2026-10-19', 'classes' => ['old']], now()->subMinutes(5)->toISOString(), $attendanceParams);

        $this->instance(
            PortalDatasetBuilder::class,
            Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock) use ($attendanceParams): void {
                $mock->shouldReceive('build')
                    ->with('campus-token', 'dashboard', [])
                    ->once()
                    ->andReturn(['classes_summary' => ['date' => '2026-10-19', 'today_classes' => [['course_code' => 'IF101']]]]);
                $mock->shouldReceive('build')
                    ->with('campus-token', 'dashboard', $attendanceParams)
                    ->once()
                    ->andThrow(PortalScrapingException::invalidResponse('https://my.cic.ac.id', 'Error page detected'));
            })
        );

        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard']), 'handle']);

        $this->assertSame('IF101', app(PortalSnapshotStore::class)->get('campus-token', 'dashboard')['data']['classes_summary']['today_classes'][0]['course_code']);
        $attendance = app(PortalSnapshotStore::class)->get('campus-token', 'dashboard', $attendanceParams);
        $this->assertSame('failed', $attendance['status']);
        $this->assertSame(['date' => '2026-10-19', 'classes' => ['old']], $attendance['data']);

        Event::assertDispatchedTimes(PortalDataInvalidated::class, 1);
        Event::assertDispatched(PortalSyncStatusUpdated::class, fn (PortalSyncStatusUpdated $event): bool => ($event->broadcastWith()['status'] ?? null) === PortalOperationStage::Completed->value);
        Event::assertDispatched(PortalSyncStatusUpdated::class, fn (PortalSyncStatusUpdated $event): bool => ($event->broadcastWith()['status'] ?? null) === PortalOperationStage::Failed->value);
    }

    public function test_refresh_job_timeout_grows_with_its_datasets(): void
    {
        $this->assertSame(120, (new RefreshPortalDataJob('laravel-session', 'campus-token', ['krs']))->timeout);
        // A schedule re-sync also budgets for today's attendance that follows it; that re-sync itself does not.
        $this->assertSame(150, (new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard']))->timeout);
        $this->assertSame(120, (new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard'], PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS))->timeout);
        $this->assertSame(600, (new RefreshPortalDataJob('laravel-session', 'campus-token', [
            'portal_context', 'krs', 'advising', 'payments', 'grades', 'skkm', 'announcements', 'exams',
        ]))->timeout);
        $this->assertSame(150, (new RefreshPortalDataJob('laravel-session', 'campus-token', ['krs', 'grades', 'krs']))->timeout);
    }

    public function test_refresh_job_keeps_syncing_other_datasets_and_marks_the_failed_one(): void
    {
        Event::fake([PortalSyncStatusUpdated::class, PortalDataInvalidated::class]);
        $this->mockUsableSession(true);

        app(PortalSnapshotStore::class)->put('campus-token', 'exams', ['exams' => ['old']], now()->subHour()->toISOString());

        $this->instance(
            PortalDatasetBuilder::class,
            Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
                $mock->shouldReceive('build')
                    ->with('campus-token', 'exams', [])
                    ->andThrow(PortalScrapingException::invalidResponse('https://my.cic.ac.id', 'Error page detected'));
                $mock->shouldReceive('build')
                    ->with('campus-token', 'profile', [])
                    ->once()
                    ->andReturn(['personal_data' => ['nama' => 'Mahasiswa Uji']]);
            })
        );

        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['exams', 'profile']), 'handle']);

        $examsSnapshot = app(PortalSnapshotStore::class)->get('campus-token', 'exams');
        $this->assertSame('failed', $examsSnapshot['status']);
        $this->assertSame(['exams' => ['old']], $examsSnapshot['data']);

        Event::assertDispatched(PortalDataInvalidated::class, function (PortalDataInvalidated $event): bool {
            return $event->broadcastWith()['sync_keys'] === ['profile'];
        });
        Event::assertDispatched(PortalSyncStatusUpdated::class, function (PortalSyncStatusUpdated $event): bool {
            return ($event->broadcastWith()['status'] ?? null) === PortalOperationStage::Failed->value;
        });
    }

    public function test_refresh_job_asks_for_relogin_without_scraping_when_session_is_unusable(): void
    {
        Event::fake([PortalSyncStatusUpdated::class, PortalDataInvalidated::class]);
        $this->mockUsableSession(false);

        $this->instance(
            PortalDatasetBuilder::class,
            Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
                $mock->shouldNotReceive('build');
            })
        );

        app()->call([new RefreshPortalDataJob('laravel-session', 'campus-token', ['dashboard']), 'handle']);

        Event::assertDispatched(PortalSyncStatusUpdated::class, function (PortalSyncStatusUpdated $event): bool {
            return ($event->broadcastWith()['status'] ?? null) === PortalOperationStage::Relogin->value;
        });
        Event::assertNotDispatched(PortalDataInvalidated::class);
    }

    public function test_duplicate_refresh_requests_queue_a_single_job(): void
    {
        Queue::fake();

        RefreshPortalDataJob::dispatch('laravel-session', 'campus-token', ['dashboard', 'assignments']);
        RefreshPortalDataJob::dispatch('other-tab', 'campus-token', ['assignments', 'dashboard']);
        RefreshPortalDataJob::dispatch('laravel-session', 'campus-token', ['profile']);

        Queue::assertPushed(RefreshPortalDataJob::class, 2);
    }

    private function mockUsableSession(bool $usable): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($usable): void {
                $mock->shouldReceive('isSessionUsable')->once()->with('campus-token')->andReturn($usable);
                $mock->shouldNotReceive('validateSession');
            })
        );
    }

    /**
     * A builder that records every build as [dataset, params] and returns
     * `$payloads[$dataset]` for the schedule, a stub naming both otherwise.
     *
     * @param  array<string, array<string, mixed>>  $payloads
     * @return \ArrayObject<int, array{0: string, 1: array<string, mixed>}>
     */
    private function mockBuilderRecording(array $payloads): \ArrayObject
    {
        $built = new \ArrayObject;

        $this->instance(
            PortalDatasetBuilder::class,
            Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock) use ($built, $payloads): void {
                $mock->shouldReceive('build')
                    ->andReturnUsing(function (string $token, string $dataset, array $params = []) use ($built, $payloads): array {
                        $built[] = [$dataset, $params];

                        return $params === [] && isset($payloads[$dataset])
                            ? $payloads[$dataset]
                            : ['dataset' => $dataset, 'params' => $params];
                    });
            })
        );

        return $built;
    }

    private function jobProperty(RefreshPortalDataJob $job, string $property): mixed
    {
        $reflection = new ReflectionClass($job);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        $this->assertTrue(
            $prop->isInitialized($job),
            "Expected RefreshPortalDataJob::\${$property} to be initialized after queue unserialize."
        );

        return $prop->getValue($job);
    }
}
