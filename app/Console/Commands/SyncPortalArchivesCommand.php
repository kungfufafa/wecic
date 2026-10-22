<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PortalBackgroundSync;
use Illuminate\Console\Command;

/**
 * Queue the background archive syncs that are due (see PortalBackgroundSync).
 * Scheduled every few minutes; each run handles one batch of students.
 */
class SyncPortalArchivesCommand extends Command
{
    protected $signature = 'portal:sync-archives
        {--limit= : Students to queue in this run (defaults to portal.background_sync.batch_size)}';

    protected $description = 'Queue background syncs of the portal archive for students whose last sync is due';

    public function handle(PortalBackgroundSync $backgroundSync): int
    {
        if (! $backgroundSync->enabled()) {
            $this->info('Background sync is switched off (portal.background_sync.enabled).');

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        $queued = $backgroundSync->dispatchDue(is_numeric($limit) ? max(1, (int) $limit) : null);

        $this->info(sprintf('Queued %d background sync(s).', count($queued)));

        return self::SUCCESS;
    }
}
