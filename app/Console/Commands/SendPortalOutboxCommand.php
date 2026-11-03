<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FlushPortalOutboxJob;
use App\Jobs\SendPortalOutboxJob;
use App\Models\PortalOutboxItem;
use App\Services\PortalBackgroundSync;
use App\Services\PortalOfflineAccess;
use App\Services\PortalOutbox;
use App\Services\PortalSessionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the outbox (PortalOutbox) moving for students who are not online in
 * MyCIC: an offline session that idled out before the portal came back
 * leaves its items to nobody. Each run settles what can no longer be sent,
 * or what a dead flush left mid-way (and tells the student), flushes with
 * the student's online session when there is one, and queues a send with the
 * stored password (SendPortalOutboxJob) for items no session can send or no
 * password confirmed yet, at most once per ATTEMPT_SECONDS per student so a
 * portal that stays down is not asked for a login every run.
 */
class SendPortalOutboxCommand extends Command
{
    private const ATTEMPT_SECONDS = 900;

    protected $signature = 'portal:send-outbox';

    protected $description = 'Expire, flush or send the waiting outbox items of students who are not online in WeCIC';

    public function handle(PortalOutbox $outbox, PortalSessionManager $sessions, PortalOfflineAccess $offlineAccess, PortalBackgroundSync $backgroundSync): int
    {
        if (! $outbox->enabled()) {
            $this->info('The outbox is switched off (portal.outbox.enabled).');

            return self::SUCCESS;
        }

        $counts = ['expired' => 0, 'flushed' => 0, 'sent' => 0];
        $owners = PortalOutboxItem::query()->pending()->distinct()->orderBy('owner_hash')->pluck('owner_hash');

        foreach ($owners as $ownerHash) {
            try {
                $counts['expired'] += $this->settleStranded($outbox, $ownerHash);

                if (! $outbox->hasQueued($ownerHash)) {
                    continue;
                }

                $sessionToken = $sessions->activeOnlineSessionTokenForOwner($ownerHash);

                if ($sessionToken !== null && $outbox->hasSendable($ownerHash) && $outbox->flushWhenQueued($sessionToken)) {
                    $counts['flushed']++;
                }

                // Items no password has confirmed (queued after the online
                // login, or by a session that never came back) wait for the
                // stored one, online session or not.
                if ($sessionToken !== null && ! $outbox->hasUnconfirmed($ownerHash)) {
                    continue;
                }

                $account = $offlineAccess->findForOwner($ownerHash);

                if ($account !== null
                    && $backgroundSync->enabled()
                    && $account->hasUsableCredentials($backgroundSync->credentialDays())
                    && Cache::add(SendPortalOutboxJob::attemptKey((int) $account->getKey()), true, self::ATTEMPT_SECONDS)) {
                    SendPortalOutboxJob::dispatch((int) $account->getKey());
                    $counts['sent']++;
                }
            } catch (\Throwable $throwable) {
                Log::warning('Outbox sweep failed for a student', ['error' => $throwable->getMessage()]);
            }
        }

        $this->info(sprintf('Settled %d stranded item(s), queued %d flush(es) and %d send(s) with the stored password.', $counts['expired'], $counts['flushed'], $counts['sent']));

        return self::SUCCESS;
    }

    /**
     * Under the student's flush lock, so no flush is sending meanwhile: what
     * a dead flush left in `sending` (FlushPortalOutboxJob), and what can no
     * longer be sent. The student is told about each.
     *
     * @return int Items settled.
     */
    private function settleStranded(PortalOutbox $outbox, string $ownerHash): int
    {
        $lock = Cache::lock(FlushPortalOutboxJob::lockKey($ownerHash), 60);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $settled = [...$outbox->recoverAbandoned($ownerHash), ...$outbox->expireOverdue($ownerHash)];
        } finally {
            $lock->release();
        }

        $outbox->publishForOwner($ownerHash, $settled);

        return count($settled);
    }
}
