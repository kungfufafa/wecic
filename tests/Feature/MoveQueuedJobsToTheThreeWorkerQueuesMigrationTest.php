<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jobs still waiting, or failed, under the old campus-* queue names move to
 * the queue of the worker that runs them now: sessions, default or
 * background (portal:move-old-queue-jobs, run by the migration).
 */
class MoveQueuedJobsToTheThreeWorkerQueuesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private ?Migration $migration = null;

    public function test_waiting_jobs_move_to_the_queue_of_the_worker_that_runs_them_now(): void
    {
        $keepAlive = $this->queuedJob('campus-sessions');
        $upload = $this->queuedJob('campus-uploads');
        $refresh = $this->queuedJob('campus-refresh');
        $scan = $this->queuedJob('campus-notifications');
        $reading = $this->queuedJob('default');
        $crawl = $this->queuedJob('campus-background');

        $this->migration()->up();

        $this->assertSame('sessions', $this->queueOf('jobs', $keepAlive));
        $this->assertSame('default', $this->queueOf('jobs', $upload));
        $this->assertSame('default', $this->queueOf('jobs', $refresh));
        $this->assertSame('default', $this->queueOf('jobs', $scan));
        $this->assertSame('default', $this->queueOf('jobs', $reading));
        $this->assertSame('background', $this->queueOf('jobs', $crawl));
    }

    public function test_failed_jobs_move_too_so_a_retry_reaches_a_worker(): void
    {
        $push = $this->failedJob('campus-notifications');
        $crawl = $this->failedJob('campus-background');

        $this->migration()->up();

        $this->assertSame('default', $this->queueOf('failed_jobs', $push));
        $this->assertSame('background', $this->queueOf('failed_jobs', $crawl));
    }

    public function test_a_second_run_changes_nothing_and_other_queues_are_left_alone(): void
    {
        $keepAlive = $this->queuedJob('campus-sessions');
        $unknown = $this->queuedJob('somewhere-else');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('sessions', $this->queueOf('jobs', $keepAlive));
        $this->assertSame('somewhere-else', $this->queueOf('jobs', $unknown));
    }

    public function test_rolling_back_puts_session_and_background_jobs_on_the_old_names(): void
    {
        $keepAlive = $this->queuedJob('campus-sessions');
        $scan = $this->queuedJob('campus-notifications');
        $crawl = $this->queuedJob('campus-background');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame('campus-sessions', $this->queueOf('jobs', $keepAlive));
        $this->assertSame('default', $this->queueOf('jobs', $scan));
        $this->assertSame('campus-background', $this->queueOf('jobs', $crawl));
    }

    public function test_failed_jobs_kept_outside_the_database_are_not_touched(): void
    {
        config(['queue.failed.driver' => 'file']);
        $keepAlive = $this->queuedJob('campus-sessions');
        $push = $this->failedJob('campus-notifications');

        $this->migration()->up();

        $this->assertSame('sessions', $this->queueOf('jobs', $keepAlive));
        $this->assertSame('campus-notifications', $this->queueOf('failed_jobs', $push));
    }

    public function test_running_the_command_again_moves_what_old_workers_queued_after_the_migration(): void
    {
        $this->migration()->up();
        $releasedByAnOldWorker = $this->queuedJob('campus-sessions');

        $this->artisan('portal:move-old-queue-jobs')
            ->expectsOutput('Moved 1 job(s).')
            ->assertSuccessful();

        $this->assertSame('sessions', $this->queueOf('jobs', $releasedByAnOldWorker));
    }

    private function migration(): Migration
    {
        return $this->migration ??= require database_path('migrations/2026_10_24_152050_move_queued_jobs_to_the_three_worker_queues.php');
    }

    private function queuedJob(string $queue): int
    {
        return (int) DB::table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->addMinutes(19)->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
    }

    private function failedJob(string $queue): int
    {
        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);
    }

    private function queueOf(string $table, int $id): string
    {
        return (string) DB::table($table)->where('id', $id)->value('queue');
    }
}
