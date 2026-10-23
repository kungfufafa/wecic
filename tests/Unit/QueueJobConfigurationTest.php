<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\SyncPortalArchiveJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;
use Tests\Traits\InspectsScheduledQueueWorkers;

/**
 * Every queued job must fit the workers that run it: its queue is one
 * exactly one scheduled worker (routes/console.php) and the `composer dev`
 * listener run, that worker lets it finish, and it finishes before the
 * database queue hands it to a second worker (`retry_after`).
 */
class QueueJobConfigurationTest extends TestCase
{
    use InspectsScheduledQueueWorkers;

    /**
     * Seconds `queue:work` gives a job without its own `$timeout` when the
     * worker passes no `--timeout`.
     */
    private const QUEUE_WORK_DEFAULT_TIMEOUT = 60;

    /**
     * How long a scheduled worker's overlap lock may outlive the worker's
     * longest possible run: past it, a worker killed mid-run (reboot, out of
     * memory) holds its queues back for nothing.
     */
    private const OVERLAP_LOCK_MARGIN = 300;

    public function test_every_job_finishes_before_the_database_queue_retries_it(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        foreach ($this->jobs() as $class => $reflection) {
            $this->assertLessThan($retryAfter, $this->longestTimeout($reflection), "{$class} can run longer than queue.connections.database.retry_after ({$retryAfter} s).");
        }

        foreach ($this->scheduledQueueWorkers() as $worker) {
            $this->assertLessThan($retryAfter, (int) $this->scheduledWorkerOption($worker, 'timeout'), "The scheduled worker lets a job run past retry_after: {$worker->command}");
        }

        $this->assertGreaterThan(SyncPortalArchiveJob::TIMEOUT, $retryAfter);
    }

    public function test_every_job_queue_has_exactly_one_scheduled_worker_and_the_dev_listener(): void
    {
        $development = $this->devWorkerQueues();

        foreach ($this->jobs() as $class => $reflection) {
            foreach ($this->queuesOf($reflection) as $queue) {
                $this->assertCount(1, $this->scheduledWorkersRunning($queue), "{$class} is queued on '{$queue}', which needs exactly one scheduled worker in routes/console.php.");
                $this->assertContains($queue, $development, "{$class} is queued on '{$queue}', which the composer dev worker does not run.");
            }
        }
    }

    public function test_each_scheduled_worker_lets_the_jobs_on_its_queues_finish(): void
    {
        foreach ($this->scheduledQueueWorkers() as $worker) {
            $timeout = $this->scheduledWorkerOption($worker, 'timeout');

            $this->assertNotNull($timeout, "The scheduled worker needs an explicit --timeout: {$worker->command}");

            foreach ($this->jobsRunBy($worker) as $class => $reflection) {
                $this->assertGreaterThanOrEqual($this->longestTimeout($reflection), $timeout, "{$class} may run longer than the --timeout of the worker that runs it: {$worker->command}");
            }
        }
    }

    public function test_a_scheduled_worker_overlap_lock_expires_just_after_its_longest_run(): void
    {
        foreach ($this->scheduledQueueWorkers() as $worker) {
            $maxTime = $this->scheduledWorkerOption($worker, 'max-time');

            $this->assertNotNull($maxTime, "Without --max-time a busy scheduled worker never ends, so no overlap lock can cover its run: {$worker->command}");

            $longestJob = max([0, ...array_map(
                fn (ReflectionClass $reflection): int => $this->longestTimeout($reflection),
                array_values($this->jobsRunBy($worker))
            )]);
            $longestRun = $maxTime + max((int) $this->scheduledWorkerOption($worker, 'timeout'), $longestJob);
            $lock = $worker->expiresAt * 60;

            $this->assertTrue($worker->withoutOverlapping, "The scheduled worker must not overlap itself: {$worker->command}");
            $this->assertGreaterThan($longestRun, $lock, "The overlap lock ({$lock} s) can expire while the worker still runs ({$longestRun} s): {$worker->command}");
            $this->assertLessThanOrEqual($longestRun + self::OVERLAP_LOCK_MARGIN, $lock, "A worker killed mid-run blocks the next one for {$lock} s, far past its longest run ({$longestRun} s): {$worker->command}");
        }
    }

    public function test_the_dev_listener_never_kills_a_job_before_its_own_timeout(): void
    {
        $script = $this->devScript();

        $this->assertMatchesRegularExpression('/queue:(listen|work)/', $script);

        if (preg_match('/queue:listen[^"]*--timeout=(\d+)/', $script, $matches) !== 1) {
            $this->fail('The composer dev listener needs an explicit --timeout: queue:listen kills a child after 60 s by default.');
        }

        $timeout = (int) $matches[1];
        $longest = max(array_map(fn (ReflectionClass $reflection): int => $this->longestTimeout($reflection), $this->jobs()));

        $this->assertTrue($timeout === 0 || $timeout >= $longest, "queue:listen --timeout={$timeout} stops jobs that may run {$longest} s, and the whole dev stack with them.");
    }

    /**
     * @return array<class-string, ReflectionClass<object>>
     */
    private function jobs(): array
    {
        $jobs = [];

        foreach (glob(app_path('Jobs/*.php')) ?: [] as $path) {
            $class = 'App\\Jobs\\'.basename($path, '.php');
            $reflection = new ReflectionClass($class);

            if ($reflection->implementsInterface(ShouldQueue::class) && ! $reflection->isAbstract()) {
                $jobs[$class] = $reflection;
            }
        }

        $this->assertNotEmpty($jobs);

        return $jobs;
    }

    /**
     * The jobs queued on any queue the given scheduled worker runs.
     *
     * @return array<class-string, ReflectionClass<object>>
     */
    private function jobsRunBy(Event $worker): array
    {
        $queues = $this->scheduledWorkerQueues($worker);

        return array_filter(
            $this->jobs(),
            fn (ReflectionClass $reflection): bool => array_intersect($this->queuesOf($reflection), $queues) !== []
        );
    }

    /**
     * @return list<Event>
     */
    private function scheduledWorkersRunning(string $queue): array
    {
        return array_values(array_filter(
            $this->scheduledQueueWorkers(),
            fn (Event $worker): bool => in_array($queue, $this->scheduledWorkerQueues($worker), true)
        ));
    }

    /**
     * The `$timeout` a job declares, or the larger bound it may raise it to
     * in its constructor (`MAX_TIMEOUT`); without one, the `--timeout` of the
     * scheduled worker that runs its queue.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function longestTimeout(ReflectionClass $reflection): int
    {
        $timeout = $reflection->hasProperty('timeout') ? $reflection->getProperty('timeout')->getDefaultValue() : null;
        $timeout = is_int($timeout) ? $timeout : $this->scheduledWorkerTimeoutFor($reflection);

        if ($reflection->hasConstant('MAX_TIMEOUT')) {
            $timeout = max($timeout, (int) $reflection->getConstant('MAX_TIMEOUT'));
        }

        return $timeout;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function scheduledWorkerTimeoutFor(ReflectionClass $reflection): int
    {
        $timeouts = [];

        foreach ($this->queuesOf($reflection) as $queue) {
            foreach ($this->scheduledWorkersRunning($queue) as $worker) {
                $timeouts[] = $this->scheduledWorkerOption($worker, 'timeout') ?? self::QUEUE_WORK_DEFAULT_TIMEOUT;
            }
        }

        return $timeouts !== [] ? max($timeouts) : self::QUEUE_WORK_DEFAULT_TIMEOUT;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function queuesOf(ReflectionClass $reflection): array
    {
        $source = (string) file_get_contents((string) $reflection->getFileName());
        preg_match_all("/onQueue\\('([^']+)'\\)/", $source, $matches);

        return $matches[1] !== [] ? array_values(array_unique($matches[1])) : ['default'];
    }

    /**
     * @return list<string>
     */
    private function devWorkerQueues(): array
    {
        $this->assertSame(1, preg_match('/queue:(?:work|listen)[^"\']*--queue=([a-z0-9,_-]+)/', $this->devScript(), $matches), 'No composer dev queue worker with an explicit --queue list found.');

        return explode(',', $matches[1]);
    }

    private function devScript(): string
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        return implode("\n", (array) ($composer['scripts']['dev'] ?? []));
    }
}
