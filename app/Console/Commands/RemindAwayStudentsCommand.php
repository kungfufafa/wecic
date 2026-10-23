<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendAwayRemindersJob;
use App\Services\PortalAwayReminders;
use Illuminate\Console\Command;

/**
 * Queue the reminders of every student with a registered app install whose
 * app is closed (see PortalAwayReminders). Scheduled every ten minutes, the
 * interval of the session's own reminder scan.
 */
class RemindAwayStudentsCommand extends Command
{
    protected $signature = 'portal:remind-away';

    protected $description = 'Queue deadline and class reminders for students whose mobile app is closed';

    public function handle(PortalAwayReminders $reminders): int
    {
        if (! $reminders->enabled()) {
            $this->info('Reminders for closed apps are switched off (portal.mobile.away_reminders).');

            return self::SUCCESS;
        }

        $owners = $reminders->owners();

        foreach ($owners as $ownerHash) {
            SendAwayRemindersJob::dispatch($ownerHash);
        }

        $this->info(sprintf('Queued reminders for %d student(s).', count($owners)));

        return self::SUCCESS;
    }
}
