<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PortalNotification;
use App\Services\PortalPushNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Push messages for notifications PortalNotificationCenter just stored, to
 * every app install the student registered: the app gets them even while it
 * is closed, unlike the realtime event that only an open page sees.
 *
 * A notification read (or pruned) before the job runs is not sent: the
 * student already saw it.
 */
class SendPortalPushNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after Expo was unreachable or busy.
     *
     * @var list<int>
     */
    public array $backoff = [30, 120];

    /**
     * A few requests to Expo at `services.expo.timeout` each.
     */
    public int $timeout = 60;

    /**
     * @param  list<int>  $notificationIds
     */
    public function __construct(
        public readonly string $ownerHash,
        public readonly array $notificationIds
    ) {
        $this->onQueue('campus-notifications');
    }

    public function handle(PortalPushNotifier $push): void
    {
        $notifications = PortalNotification::query()
            ->forOwner($this->ownerHash)
            ->unread()
            ->whereIn('id', $this->notificationIds)
            ->orderBy('id')
            ->get()
            ->all();

        $push->send($this->ownerHash, $notifications);
    }
}
