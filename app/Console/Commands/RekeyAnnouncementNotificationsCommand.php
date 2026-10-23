<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PortalAnnouncementIds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Point every stored notification that links an announcement by an id it
 * no longer goes by (a wording-based id from before stable ids) to the id
 * it goes by now, as far as each student's archive and feed snapshot know
 * it: new announcements and deadline reminders alike, link and record key.
 * Run once by a migration; running it again changes nothing. Notifications
 * whose announcement neither knows follow lazily, when they are read or
 * opened (PortalAnnouncementIds).
 *
 * A student whose notifications could not be re-keyed is logged and listed
 * by owner key (an HMAC, not the username), and the command fails, so the
 * run can be repeated for them with `--owner`.
 */
class RekeyAnnouncementNotificationsCommand extends Command
{
    protected $signature = 'portal:rekey-announcement-notifications
        {--owner=* : Only these students (owner keys)}';

    protected $description = 'Point stored notifications that link an announcement by an old wording-based id to its current id';

    public function handle(PortalAnnouncementIds $announcementIds): int
    {
        $owners = PortalAnnouncementIds::linkingNotifications()
            ->when($this->option('owner') !== [], fn ($query) => $query->whereIn('owner_hash', $this->option('owner')))
            ->distinct()
            ->orderBy('owner_hash')
            ->pluck('owner_hash');
        $rekeyed = 0;
        $failed = [];

        foreach ($owners as $ownerHash) {
            try {
                $rekeyed += $announcementIds->rekeyNotifications($ownerHash, $announcementIds->currentIds($ownerHash));
            } catch (\Throwable $throwable) {
                $failed[] = $ownerHash;
                Log::warning('Announcement notifications of a student could not be re-keyed', [
                    'owner' => $ownerHash,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        $this->info(sprintf('Re-keyed %d notification(s) of %d student(s).', $rekeyed, $owners->count()));

        if ($failed !== []) {
            $this->warn(sprintf('%d student(s) could not be re-keyed; their notifications follow when they are read or opened. Owner keys:', count($failed)));

            foreach ($failed as $ownerHash) {
                $this->warn($ownerHash);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
