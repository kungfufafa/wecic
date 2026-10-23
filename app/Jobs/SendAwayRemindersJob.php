<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PortalAwayReminders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The reminders of one student whose app is closed (PortalAwayReminders),
 * queued by `portal:remind-away`. Unique per student while queued or
 * running, so a slow worker never stacks runs for the same student.
 */
class SendAwayRemindersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Snapshot and archive reads only, no portal request.
     */
    public int $timeout = 60;

    /**
     * Seconds the uniqueness lock outlives a run that never released it.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly string $ownerHash)
    {
        $this->onQueue('campus-notifications');
    }

    public function uniqueId(): string
    {
        return $this->ownerHash;
    }

    public function handle(PortalAwayReminders $reminders): void
    {
        $reminders->remind($this->ownerHash);
    }
}
