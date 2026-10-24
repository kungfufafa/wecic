<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Assert;

/**
 * Reads the events routes/console.php schedules.
 */
trait InspectsScheduledEvents
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
}
