<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\IngestNotebookSourceJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\SendAwayRemindersJob;
use App\Jobs\SyncPortalArchiveJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jobs still waiting in the database queue, the one used before Horizon,
 * move to the redis queue of the worker that runs them now (sessions,
 * default or background), still due when they were (portal:move-old-queue-jobs,
 * run once by the migration).
 */
class MoveOldQueueJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    public function test_waiting_jobs_move_to_the_redis_queue_of_the_worker_that_runs_them_now(): void
    {
        Queue::connection('database')->later(now()->addMinutes(19), new RefreshPortalSessionJob('token-a'), '', 'campus-sessions');
        Queue::connection('database')->push(new SendAwayRemindersJob('owner-a'), '', 'campus-notifications');
        Queue::connection('database')->push(new IngestNotebookSourceJob(5), '', 'default');
        Queue::connection('database')->push(new SyncPortalArchiveJob(1), '', 'campus-background');
        Bus::fake();

        $this->artisan('portal:move-old-queue-jobs')
            ->expectsOutput('Moved 4 waiting job(s) and 0 failed job(s) to redis.')
            ->assertSuccessful();

        Bus::assertDispatched(RefreshPortalSessionJob::class, fn (RefreshPortalSessionJob $job): bool => $job->connection === 'redis'
            && $job->queue === 'sessions'
            && $job->delay?->getTimestamp() === now()->addMinutes(19)->getTimestamp());
        Bus::assertDispatched(SendAwayRemindersJob::class, fn (SendAwayRemindersJob $job): bool => $job->connection === 'redis' && $job->queue === 'default' && $job->delay === null);
        Bus::assertDispatched(IngestNotebookSourceJob::class, fn (IngestNotebookSourceJob $job): bool => $job->queue === 'default');
        Bus::assertDispatched(SyncPortalArchiveJob::class, fn (SyncPortalArchiveJob $job): bool => $job->queue === 'background');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_job_a_database_worker_is_running_is_left_alone(): void
    {
        Queue::connection('database')->push(new SyncPortalArchiveJob(1), '', 'campus-background');
        DB::table('jobs')->update(['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);
        Bus::fake();

        $this->artisan('portal:move-old-queue-jobs')->assertSuccessful();

        Bus::assertNothingDispatched();
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_job_left_reserved_by_a_worker_that_died_moves_too(): void
    {
        Queue::connection('database')->push(new SyncPortalArchiveJob(1), '', 'campus-background');
        DB::table('jobs')->update(['reserved_at' => now()->subSeconds((int) config('queue.connections.redis.retry_after'))->getTimestamp(), 'attempts' => 1]);
        Bus::fake();

        $this->artisan('portal:move-old-queue-jobs')
            ->expectsOutput('Moved 1 waiting job(s) and 0 failed job(s) to redis.')
            ->assertSuccessful();

        Bus::assertDispatched(SyncPortalArchiveJob::class, fn (SyncPortalArchiveJob $job): bool => $job->queue === 'background');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_job_is_left_in_the_table_when_redis_refuses_it(): void
    {
        Queue::connection('database')->push(new SendAwayRemindersJob('owner-a'), '', 'campus-notifications');
        Bus::shouldReceive('dispatchToQueue')->once()->andThrow(new \RuntimeException('Connection refused'));

        try {
            $this->artisan('portal:move-old-queue-jobs')->run();
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_payload_that_cannot_be_read_stays_and_is_reported(): void
    {
        DB::table('jobs')->insert(['queue' => 'campus-sessions', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        Bus::fake();

        $this->artisan('portal:move-old-queue-jobs')
            ->expectsOutput('Left 1 job(s) in the jobs table whose payload could not be read.')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_failed_jobs_of_the_database_queue_point_at_redis_so_a_retry_reaches_horizon(): void
    {
        $push = $this->failedJob('database', 'campus-notifications');
        $reading = $this->failedJob('database', 'default');
        $upload = $this->failedJob('sync', 'campus-uploads');
        Bus::fake();

        $this->artisan('portal:move-old-queue-jobs')
            ->expectsOutput('Moved 0 waiting job(s) and 2 failed job(s) to redis.')
            ->assertSuccessful();

        $this->assertSame(['redis', 'default'], $this->failedJobQueue($push));
        $this->assertSame(['redis', 'default'], $this->failedJobQueue($reading));
        $this->assertSame(['sync', 'campus-uploads'], $this->failedJobQueue($upload));

        // The redis pop script counts attempts in the payload; without them a retry is dropped.
        $payload = json_decode((string) DB::table('failed_jobs')->where('id', $push)->value('payload'), true);
        $this->assertSame(0, $payload['attempts']);
        $this->assertSame('uuid-'.$push, $payload['id']);
        $this->assertSame('App\\Jobs\\SendPortalPushNotificationsJob', $payload['displayName']);
    }

    public function test_failed_jobs_kept_outside_the_database_are_not_touched(): void
    {
        config(['queue.failed.driver' => 'file']);
        $push = $this->failedJob('database', 'campus-notifications');
        Bus::fake();

        $this->artisan('portal:move-old-queue-jobs')->assertSuccessful();

        $this->assertSame(['database', 'campus-notifications'], $this->failedJobQueue($push));
    }

    public function test_a_second_run_only_moves_what_was_queued_since(): void
    {
        Queue::connection('database')->later(now()->addMinutes(2), new RefreshPortalSessionJob('token-a'), '', 'campus-sessions');
        Bus::fake();
        $this->artisan('portal:move-old-queue-jobs')->assertSuccessful();
        // Released by a worker still running the old code after the first run.
        Queue::connection('database')->later(now()->addMinutes(2), new RefreshPortalSessionJob('token-b'), '', 'campus-sessions');
        Bus::fake();

        $this->artisan('portal:move-old-queue-jobs')
            ->expectsOutput('Moved 1 waiting job(s) and 0 failed job(s) to redis.')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(RefreshPortalSessionJob::class, 1);
        Bus::assertDispatched(RefreshPortalSessionJob::class, fn (RefreshPortalSessionJob $job): bool => $job->uniqueId() === 'token-b');
    }

    public function test_the_migration_runs_the_move(): void
    {
        Queue::connection('database')->push(new SendAwayRemindersJob('owner-a'), '', 'campus-notifications');
        Bus::fake();

        (require database_path('migrations/2026_10_24_214812_move_waiting_database_jobs_to_redis.php'))->up();

        Bus::assertDispatched(SendAwayRemindersJob::class, fn (SendAwayRemindersJob $job): bool => $job->connection === 'redis' && $job->queue === 'default');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function failedJob(string $connection, string $queue): int
    {
        $id = (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => $connection,
            'queue' => $queue,
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);

        // A database-queue payload: no attempts or id of its own.
        DB::table('failed_jobs')->where('id', $id)->update(['payload' => json_encode([
            'uuid' => 'uuid-'.$id,
            'displayName' => 'App\\Jobs\\SendPortalPushNotificationsJob',
            'data' => ['commandName' => 'App\\Jobs\\SendPortalPushNotificationsJob', 'command' => 'O:8:"stdClass":0:{}'],
        ])]);

        return $id;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function failedJobQueue(int $id): array
    {
        $row = DB::table('failed_jobs')->where('id', $id)->first();

        return [(string) $row->connection, (string) $row->queue];
    }
}
