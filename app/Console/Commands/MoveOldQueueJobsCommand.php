<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move jobs waiting, or failed, under the queue names used before one queue
 * per worker (sessions, default, background) to the queue that runs them
 * now: a worker only takes rows on a queue it listens to. The migration of
 * 2026-10-24 runs it once; run it again after deploying, once PHP-FPM has
 * reloaded and every worker still running the old code has stopped, for
 * the jobs those queued in the meantime. Running it again changes nothing.
 *
 * `--back` (the migration's rollback) has the same window the other way
 * round, and the old code has no command to repeat it: jobs the new code
 * queues on sessions or background after it runs wait until someone moves
 * them by hand.
 */
class MoveOldQueueJobsCommand extends Command
{
    protected $signature = 'portal:move-old-queue-jobs
        {--back : Put session and background jobs back on their old names (rolling the code back)}';

    protected $description = 'Move jobs on the old campus-* queue names to the sessions, default and background queues';

    /**
     * Old queue name => the queue its jobs run on now.
     */
    private const MOVES = [
        'campus-sessions' => 'sessions',
        'campus-uploads' => 'default',
        'campus-refresh' => 'default',
        'campus-notifications' => 'default',
        'campus-background' => 'background',
    ];

    /**
     * The way back keeps default jobs where they are: the old layout ran
     * default on the same worker as campus-refresh and campus-notifications.
     */
    private const BACK = [
        'sessions' => 'campus-sessions',
        'background' => 'campus-background',
    ];

    public function handle(): int
    {
        $moves = $this->option('back') ? self::BACK : self::MOVES;
        $moved = 0;

        foreach ($this->tables() as [$connection, $table]) {
            if (! Schema::connection($connection)->hasTable($table)) {
                continue;
            }

            foreach ($moves as $from => $to) {
                $moved += DB::connection($connection)->table($table)->where('queue', $from)->update(['queue' => $to]);
            }
        }

        $this->info(sprintf('Moved %d job(s).', $moved));

        return self::SUCCESS;
    }

    /**
     * The database queue's table and, when failed jobs are kept in the
     * database, theirs: queue:retry puts a job back on the queue it failed on.
     *
     * @return list<array{0: string|null, 1: string}>
     */
    private function tables(): array
    {
        $tables = [[config('queue.connections.database.connection'), (string) config('queue.connections.database.table', 'jobs')]];

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $tables[] = [config('queue.failed.database'), (string) config('queue.failed.table', 'failed_jobs')];
        }

        return $tables;
    }
}
