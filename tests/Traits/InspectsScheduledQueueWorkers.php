<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Assert;

/**
 * Reads the events routes/console.php schedules, and the options of the
 * `queue:work` events among them.
 */
trait InspectsScheduledQueueWorkers
{
    /**
     * @return list<Event>
     */
    protected function scheduledEvents(): array
    {
        $this->app->make(Kernel::class)->bootstrap();

        return array_values($this->app->make(Schedule::class)->events());
    }

    /**
     * The one scheduled event running the given artisan command.
     */
    protected function scheduledEvent(string $command): Event
    {
        $events = array_values(array_filter(
            $this->scheduledEvents(),
            static fn (Event $event): bool => str_contains((string) $event->command, " {$command}")
        ));

        Assert::assertCount(1, $events, "Expected exactly one scheduled '{$command}'.");

        return $events[0];
    }

    /**
     * @return list<Event>
     */
    protected function scheduledQueueWorkers(): array
    {
        $workers = array_values(array_filter(
            $this->scheduledEvents(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'queue:work')
        ));

        Assert::assertNotEmpty($workers, 'routes/console.php schedules no queue worker.');

        return $workers;
    }

    /**
     * The queues a scheduled worker runs, in priority order.
     *
     * @return list<string>
     */
    protected function scheduledWorkerQueues(Event $worker): array
    {
        Assert::assertSame(1, preg_match('/--queue=([a-z0-9,_-]+)/', (string) $worker->command, $matches), "No explicit --queue list in: {$worker->command}");

        return explode(',', $matches[1]);
    }

    /**
     * An integer option of a scheduled worker (`timeout`, `max-time`,
     * `tries`), or null when the command does not pass it.
     */
    protected function scheduledWorkerOption(Event $worker, string $option): ?int
    {
        return preg_match('/--'.preg_quote($option, '/').'=(\d+)/', (string) $worker->command, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
