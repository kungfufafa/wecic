<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PortalOfflineAccount;
use App\Services\PortalBackgroundSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends a student's outbox with the stored password while they are not
 * online in MyCIC (PortalBackgroundSync::sendOutbox): queued by
 * portal:send-outbox for items an ended offline session left behind, which
 * no resume or login would send before their deadline otherwise.
 */
class SendPortalOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Uploads are not idempotent; the next portal:send-outbox run tries again.
     */
    public int $tries = 1;

    /**
     * A login, then a flush that runs inline (FlushPortalOutboxJob's budget).
     */
    public int $timeout = 600;

    public function __construct(private readonly int $accountId) {}

    /**
     * Set by portal:send-outbox for each send it queues: at most one login
     * per student and quarter hour while the portal stays down.
     */
    public static function attemptKey(int $accountId): string
    {
        return 'portal_outbox_send_attempt_'.$accountId;
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(PortalBackgroundSync $backgroundSync): void
    {
        $account = PortalOfflineAccount::query()->find($this->accountId);

        if ($account === null) {
            return;
        }

        $outcome = $backgroundSync->sendOutbox($account);

        Log::info('campus_session.outbox_send_'.$outcome, [
            'domain' => 'campus_session',
            'event' => 'outbox_send_'.$outcome,
            'component' => self::class,
            'account_id' => $this->accountId,
        ]);
    }
}
