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

/**
 * Background sync of one student's archive (see PortalBackgroundSync).
 *
 * Unique per account, so a scheduler tick that overlaps a slow sync never
 * runs two logins for the same student. The account is re-checked when the
 * job runs: credentials removed or rejected in the meantime end it quietly.
 * A job that dies (worker timeout) records the failure on the account.
 *
 * The database queue's `retry_after` must stay above TIMEOUT, or a second
 * worker reserves the job again while the first is still crawling.
 */
class SyncPortalArchiveJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A dozen datasets, each a few portal pages with the politeness delay,
     * plus, when the course crawl is due, every class with every meeting
     * and its files (PortalCourseArchiver).
     */
    public const TIMEOUT = 1800;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    public function __construct(private readonly int $accountId)
    {
        $this->onQueue('background');
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

        $backgroundSync->syncAccount($account);
    }

    public function failed(?\Throwable $exception): void
    {
        app(PortalBackgroundSync::class)->recordInterrupted($this->accountId, $exception);
    }
}
