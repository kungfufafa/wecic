<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\SyncPortalArchiveJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every queued job must fit the Horizon supervisor that runs it
 * (config/horizon.php): in every environment its queue has exactly one
 * supervisor, that supervisor lets it finish, and it finishes before the
 * redis queue hands it to a second worker (`retry_after`).
 */
class QueueJobConfigurationTest extends TestCase
{
    /**
     * Environments Horizon must start the supervisors in; staging stands for
     * any environment config/horizon.php does not name.
     */
    private const ENVIRONMENTS = ['production', 'local', 'staging'];

    /**
     * Seconds Horizon gives a job without its own `$timeout` when the
     * supervisor sets none.
     */
    private const HORIZON_DEFAULT_TIMEOUT = 60;

    public function test_horizon_runs_the_redis_queue_the_app_dispatches_to(): void
    {
        $this->assertSame('redis', config('queue.connections.redis.driver'));
        $this->assertStringContainsString("\nQUEUE_CONNECTION=redis\n", (string) file_get_contents(base_path('.env.example')));

        foreach (self::ENVIRONMENTS as $environment) {
            foreach ($this->supervisors($environment) as $name => $supervisor) {
                $this->assertSame('redis', $supervisor->connection, "{$environment}.{$name} must work the redis queue.");
            }
        }
    }

    public function test_every_job_finishes_before_the_redis_queue_retries_it(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach ($this->jobs() as $class => $reflection) {
            $this->assertLessThan($retryAfter, $this->longestTimeout($reflection), "{$class} can run longer than queue.connections.redis.retry_after ({$retryAfter} s).");
        }

        foreach (self::ENVIRONMENTS as $environment) {
            foreach ($this->supervisors($environment) as $name => $supervisor) {
                $this->assertLessThan($retryAfter, $supervisor->timeout, "{$environment}.{$name} lets a job run past retry_after.");
            }
        }

        $this->assertGreaterThan(SyncPortalArchiveJob::TIMEOUT, $retryAfter);
    }

    public function test_every_job_queue_has_exactly_one_supervisor_in_every_environment(): void
    {
        foreach (self::ENVIRONMENTS as $environment) {
            foreach ($this->jobs() as $class => $reflection) {
                $queue = $this->queueOf($reflection);

                $this->assertCount(1, $this->supervisorsRunning($environment, $queue), "{$class} is queued on '{$queue}', which needs exactly one supervisor in the {$environment} environment.");
            }
        }
    }

    public function test_keep_alive_pings_and_archive_crawls_each_have_a_supervisor_of_their_own(): void
    {
        foreach (self::ENVIRONMENTS as $environment) {
            $supervisors = $this->supervisors($environment);

            $this->assertSame(['sessions', 'default', 'background'], array_keys($supervisors));
            $this->assertSame(['sessions'], $this->queuesOf($supervisors['sessions']));
            $this->assertSame(['default'], $this->queuesOf($supervisors['default']));
            $this->assertSame(['background'], $this->queuesOf($supervisors['background']));
            $this->assertSame('background', (new SyncPortalArchiveJob(1))->queue);
        }
    }

    public function test_each_supervisor_lets_the_jobs_on_its_queue_finish(): void
    {
        foreach (self::ENVIRONMENTS as $environment) {
            foreach ($this->supervisors($environment) as $name => $supervisor) {
                // A job without its own $timeout runs on the supervisor's.
                $this->assertGreaterThanOrEqual(300, $supervisor->timeout, "{$environment}.{$name} would stop a job without its own \$timeout after {$supervisor->timeout} s.");
                $this->assertSame(1, $supervisor->maxTries, "{$environment}.{$name}: a job retries only when it says so (\$tries).");

                foreach ($this->jobs() as $class => $reflection) {
                    if (in_array($this->queueOf($reflection), $this->queuesOf($supervisor), true)) {
                        $this->assertGreaterThanOrEqual($this->longestTimeout($reflection), $supervisor->timeout, "{$class} may run longer than the timeout of {$environment}.{$name}.");
                    }
                }
            }
        }
    }

    public function test_a_deploy_never_holds_keep_alive_pings_behind_an_archive_crawl(): void
    {
        // horizon:terminate otherwise keeps the old master, and every queue
        // with it, waiting for the slowest worker to finish its job.
        $this->assertTrue(config('horizon.fast_termination'));
    }

    public function test_composer_dev_runs_horizon_and_no_other_worker(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $script = implode("\n", (array) ($composer['scripts']['dev'] ?? []));

        $this->assertStringContainsString('php artisan horizon:listen', $script);
        $this->assertStringNotContainsString('queue:listen', $script);
        $this->assertStringNotContainsString('queue:work', $script);
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
     * The supervisors Horizon starts in the given environment, by name: the
     * first environment key of config/horizon.php that matches, as
     * ProvisioningPlan::deploy() picks it.
     *
     * @return array<string, SupervisorOptions>
     */
    private function supervisors(string $environment): array
    {
        $plan = ProvisioningPlan::get('test')->parsed;
        $match = collect($plan)->first(fn (mixed $supervisors, string $name): bool => Str::is($name, $environment));

        $this->assertNotNull($match, "Horizon starts no supervisor in the {$environment} environment.");

        return collect($match)->filter(fn (SupervisorOptions $supervisor): bool => $supervisor->maxProcesses > 0)->all();
    }

    /**
     * @return list<SupervisorOptions>
     */
    private function supervisorsRunning(string $environment, string $queue): array
    {
        return array_values(array_filter(
            $this->supervisors($environment),
            fn (SupervisorOptions $supervisor): bool => in_array($queue, $this->queuesOf($supervisor), true)
        ));
    }

    /**
     * @return list<string>
     */
    private function queuesOf(SupervisorOptions $supervisor): array
    {
        return explode(',', (string) $supervisor->queue);
    }

    /**
     * The queue a job names with onQueue(), else the connection's default.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function queueOf(ReflectionClass $reflection): string
    {
        $source = (string) file_get_contents((string) $reflection->getFileName());
        preg_match_all("/onQueue\\('([^']+)'\\)/", $source, $matches);
        $queues = array_values(array_unique($matches[1]));

        $this->assertLessThanOrEqual(1, count($queues), "{$reflection->getName()} names more than one queue.");

        return $queues[0] ?? (string) config('queue.connections.redis.queue');
    }

    /**
     * The `$timeout` a job declares, or the larger bound it may raise it to
     * in its constructor (`MAX_TIMEOUT`); without one, the timeout of the
     * supervisors that run its queue.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function longestTimeout(ReflectionClass $reflection): int
    {
        $timeout = $reflection->hasProperty('timeout') ? $reflection->getProperty('timeout')->getDefaultValue() : null;
        $timeout = is_int($timeout) ? $timeout : $this->supervisorTimeoutFor($this->queueOf($reflection));

        if ($reflection->hasConstant('MAX_TIMEOUT')) {
            $timeout = max($timeout, (int) $reflection->getConstant('MAX_TIMEOUT'));
        }

        return $timeout;
    }

    private function supervisorTimeoutFor(string $queue): int
    {
        $timeouts = [];

        foreach (self::ENVIRONMENTS as $environment) {
            foreach ($this->supervisorsRunning($environment, $queue) as $supervisor) {
                $timeouts[] = $supervisor->timeout ?: self::HORIZON_DEFAULT_TIMEOUT;
            }
        }

        return $timeouts !== [] ? max($timeouts) : self::HORIZON_DEFAULT_TIMEOUT;
    }
}
