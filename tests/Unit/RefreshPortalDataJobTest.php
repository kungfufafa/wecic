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
            return ($event->broadcastWith()['status'] ?? null) === PortalOperationStage::Completed->value;
        });
        Event::assertDispatched(PortalDataInvalidated::class, function (PortalDataInvalidated $event): bool {
            $payload = $event->broadcastWith();

            return in_array('/api/dashboard', $payload['cache_keys'], true)
                && in_array('/api/assignment/pending', $payload['cache_keys'], true)
                && in_array('dashboard-pending-assignments', $payload['sync_keys'], true);
        });
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
