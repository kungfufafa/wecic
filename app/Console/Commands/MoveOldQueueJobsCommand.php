<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the jobs still waiting in the database queue, the one MyCIC used
 * before Horizon, to the redis queues Horizon runs, keeping when each is
 * due. Jobs on the old campus-* names go to the queue of the worker that
 * runs them now (sessions, default, background). Failed jobs follow, so
 * queue:retry sends them to redis.
 *
 * Each row is claimed before its job is pushed, in one transaction: a row a
 * database worker reserved meanwhile is left to that worker, and a push that
 * fails leaves the row where it was. A row reserved for longer than any job
 * may run belongs to a worker that died, and moves too. SQLite does not lock
 * a row a worker is about to reserve, so stop the database workers first
 * (docs/deployment.md, "Pindah dari queue database").
 *
 * The migration of 2026-10-24 runs it once; it can run again at any time and
 * only moves what is still there.
 */
class MoveOldQueueJobsCommand extends Command
{
    protected $signature = 'portal:move-old-queue-jobs';

    protected $description = 'Move jobs waiting in the database queue to the redis queues Horizon runs';

    /**
     * Old queue name => the queue its jobs run on now.
     */
    private const QUEUES = [
        'campus-sessions' => 'sessions',
        'campus-uploads' => 'default',
        'campus-refresh' => 'default',
        'campus-notifications' => 'default',
        'campus-background' => 'background',
    ];

    public function handle(): int
    {
        $connection = config('queue.connections.database.connection');
        $table = (string) config('queue.connections.database.table', 'jobs');
        $orphanedBefore = now()->getTimestamp() - (int) config('queue.connections.redis.retry_after');
        $waiting = fn (Builder $query): Builder => $query->whereNull('reserved_at')->orWhere('reserved_at', '<=', $orphanedBefore);
        $moved = 0;
        $unreadable = 0;

        if (Schema::connection($connection)->hasTable($table)) {
            DB::connection($connection)->table($table)->where($waiting)->orderBy('id')
                ->chunkById(100, function ($rows) use ($connection, $table, $waiting, &$moved, &$unreadable): void {
                    foreach ($rows as $row) {
                        $job = $this->job((string) $row->payload);

                        if ($job === null) {
                            $unreadable++;

                            continue;
                        }

                        $availableAt = (int) $row->available_at;
                        $job->onConnection('redis')
                            ->onQueue(self::QUEUES[$row->queue] ?? (string) $row->queue)
                            ->delay($availableAt > now()->getTimestamp() ? Carbon::createFromTimestamp($availableAt) : null);

                        $moved += DB::connection($connection)->transaction(function () use ($connection, $table, $waiting, $row, $job): int {
                            if (DB::connection($connection)->table($table)->where('id', $row->id)->where($waiting)->delete() !== 1) {
                                return 0;
                            }

                            Bus::dispatchToQueue($job);

                            return 1;
                        });
                    }
                });
        }

        $this->info(sprintf('Moved %d waiting job(s) and %d failed job(s) to redis.', $moved, $this->moveFailedJobs()));

        if ($unreadable > 0) {
            $this->warn(sprintf('Left %d job(s) in the %s table whose payload could not be read.', $unreadable, $table));
        }

        return self::SUCCESS;
    }

    /**
     * The queued object a payload carries, read the way CallQueuedHandler
     * reads it, or null when it cannot be queued again.
     */
    private function job(string $payload): ?object
    {
        $command = json_decode($payload, true)['data']['command'] ?? null;

        if (! is_string($command)) {
            return null;
        }

        try {
            $job = str_starts_with($command, 'O:')
                ? unserialize($command)
                : unserialize($this->laravel->make(Encrypter::class)->decrypt($command));
        } catch (\Throwable) {
            return null;
        }

        return is_object($job) && method_exists($job, 'onConnection') && method_exists($job, 'onQueue') && method_exists($job, 'delay')
            ? $job
            : null;
    }

    /**
     * Failed jobs kept in the database that came from the database queue,
     * pointed at redis and the queue their job runs on now. A database
     * payload keeps its attempts in a column; the redis queue reads them
     * (and the job id) from the payload, so both are added, or a retry
     * would be dropped by the first worker that pops it.
     */
    private function moveFailedJobs(): int
    {
        $connection = config('queue.failed.database');
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $moved = 0;

        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true) || ! Schema::connection($connection)->hasTable($table)) {
            return 0;
        }

        DB::connection($connection)->table($table)->where('connection', 'database')->orderBy('id')
            ->chunkById(100, function ($rows) use ($connection, $table, &$moved): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);

                    if (! is_array($payload)) {
                        continue;
                    }

                    $payload += ['id' => $payload['uuid'] ?? null, 'attempts' => 0];

                    $moved += DB::connection($connection)->table($table)->where('id', $row->id)->update([
                        'connection' => 'redis',
                        'queue' => self::QUEUES[$row->queue] ?? (string) $row->queue,
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });

        return $moved;
    }
}
