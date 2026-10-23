<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendPortalPushNotificationsJob;
use App\Models\PortalNotification;
use App\Models\PortalPushToken;
use Illuminate\Support\Facades\Log;

/**
 * Stores notifications per student and announces new ones in realtime.
 *
 * A notification is identified by its owner and dedupe key, so the same
 * change found twice (two syncs racing, a re-login, another device) is
 * stored and announced once. A deadline reminder of an announcement is also
 * dropped when it reads exactly like one the student got lately: its key
 * comes from the announcement's id, which can change while the deadline
 * stays. Only the browser session that triggered the
 * work is told right away; every other session sees it on its next load.
 * The student's app installs get every new one as a push message
 * (SendPortalPushNotificationsJob), whichever session found it.
 * Notifications that link an announcement follow it to the id it goes by
 * now (PortalAnnouncementIds), so a link stored before stable ids keeps
 * opening it.
 *
 * @phpstan-type NotificationInput array{
 *     type: string,
 *     dedupe_key: string,
 *     title: string,
 *     body?: string|null,
 *     url?: string|null,
 *     urgency?: string,
 *     dataset?: string|null,
 *     record_key?: string|null
 * }
 */
class PortalNotificationCenter
{
    public const URGENCIES = ['info', 'warning', 'critical'];

    /**
     * Device copies a new notification outdates, the unread filter's list included.
     */
    public const CACHE_KEYS = ['/api/notifications', '/api/notifications?unread=1', '/api/notifications/unread-count'];

    public const SYNC_KEYS = ['notification-index'];

    /**
     * Days a deadline reminder of an announcement is compared with earlier
     * ones (see isAnnouncementReminderRepeat()): announcement deadlines are
     * listed at most 14 days ahead.
     */
    private const ANNOUNCEMENT_REMINDER_REPEAT_DAYS = 14;

    public function __construct(
        private readonly PortalDataOwner $owner,
        private readonly PortalBroadcastService $broadcast,
        private readonly PortalSessionManager $sessionManager,
        private readonly PortalAnnouncementIds $announcementIds
    ) {}

    /**
     * @param  list<NotificationInput>  $notifications
     * @return list<PortalNotification> The notifications that were new.
     */
    public function publish(string $sessionToken, array $notifications, ?string $broadcastSessionId = null): array
    {
        if ($notifications === []) {
            return [];
        }

        $ownerHash = $this->owner->key($sessionToken);
        $created = [];
        $announcementReminders = null;

        foreach ($notifications as $notification) {
            if ($this->isAnnouncementReminder($notification)) {
                $announcementReminders ??= $this->recentAnnouncementReminders($ownerHash);

                if (isset($announcementReminders[$this->readsAs($notification)])) {
                    continue;
                }

                $announcementReminders[$this->readsAs($notification)] = true;
            }

            $model = PortalNotification::query()->createOrFirst(
                ['owner_hash' => $ownerHash, 'dedupe_key' => $notification['dedupe_key']],
                [
                    'type' => $notification['type'],
                    'dataset' => $notification['dataset'] ?? null,
                    'record_key' => isset($notification['record_key']) ? mb_substr($notification['record_key'], 0, 255) : null,
                    'title' => mb_substr($notification['title'], 0, 255),
                    'body' => $notification['body'] ?? null,
                    'url' => $this->internalUrl($notification['url'] ?? null),
                    'urgency' => in_array($notification['urgency'] ?? null, self::URGENCIES, true) ? $notification['urgency'] : 'info',
                ]
            );

            if ($model->wasRecentlyCreated) {
                $created[] = $model;
            }
        }

        if ($created !== []) {
            $this->announce($sessionToken, $ownerHash, $created, $broadcastSessionId);
            $this->push($ownerHash, $created);
        }

        return $created;
    }

    /**
     * A record of a dataset changed key (PortalSnapshotDiffNotifier
     * `aliases`): the student's notifications of it follow, link included.
     *
     * @return int Notifications re-keyed.
     */
    public function rekey(string $ownerHash, string $dataset, string $type, string $oldKey, string $newKey, ?string $url = null): int
    {
        $changes = ['record_key' => mb_substr($newKey, 0, 255)];
        $url = $this->internalUrl($url);

        if ($url !== null) {
            $changes['url'] = $url;
        }

        return PortalNotification::query()
            ->forOwner($ownerHash)
            ->where('dataset', $dataset)
            ->where('type', $type)
            ->where('record_key', mb_substr($oldKey, 0, 255))
            ->update($changes);
    }

    /**
     * Announcements changed id (old id => current id): every notification of
     * the student that links one follows, whatever its type (a new
     * announcement, a deadline reminder), link and record key included.
     *
     * @param  array<string, string>  $currentIds
     * @return int Notifications re-keyed.
     */
    public function rekeyAnnouncements(string $ownerHash, array $currentIds): int
    {
        return $this->announcementIds->rekeyNotifications($ownerHash, $currentIds);
    }

    public function unreadCount(string $ownerHash): int
    {
        return PortalNotification::query()->forOwner($ownerHash)->unread()->count();
    }

    /**
     * Newest first; `beforeId` continues a previous page. A notification
     * that links an announcement by an id it no longer goes by (stored
     * before stable ids) follows it as it is read.
     *
     * @return array{items: list<PortalNotification>, has_more: bool}
     */
    public function latest(string $ownerHash, int $limit, bool $unreadOnly = false, ?int $beforeId = null): array
    {
        $items = PortalNotification::query()
            ->forOwner($ownerHash)
            ->when($unreadOnly, fn ($query) => $query->unread())
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->all();

        try {
            $this->announcementIds->followIn($ownerHash, $items);
        } catch (\Throwable $throwable) {
            Log::warning('Notifications of announcements could not follow their ids', [
                'error' => $throwable->getMessage(),
            ]);
        }

        return [
            'items' => array_slice($items, 0, $limit),
            'has_more' => count($items) > $limit,
        ];
    }

    /**
     * @param  list<int>|null  $ids  Null marks every unread notification.
     * @param  int|null  $upToId  Leaves newer notifications alone: one stored
     *                            after the page loaded was never shown there.
     * @return int Notifications that were unread and are now read.
     */
    public function markRead(string $ownerHash, ?array $ids = null, ?int $upToId = null): int
    {
        return PortalNotification::query()
            ->forOwner($ownerHash)
            ->unread()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->when($upToId !== null, fn ($query) => $query->where('id', '<=', $upToId))
            ->update(['read_at' => now()]);
    }

    /**
     * @param  list<PortalNotification>  $created
     */
    private function announce(string $sessionToken, string $ownerHash, array $created, ?string $broadcastSessionId): void
    {
        $sessionId = $broadcastSessionId;

        try {
            $sessionId ??= $this->sessionManager->laravelSessionId($sessionToken);
            $unreadCount = $this->unreadCount($ownerHash);
        } catch (\Throwable $throwable) {
            Log::warning('Portal notification could not be announced', [
                'error' => $throwable->getMessage(),
            ]);

            return;
        }

        if ($sessionId === null) {
            return;
        }

        foreach ($created as $notification) {
            $this->broadcast->notification($sessionId, $sessionToken, [
                ...$notification->toApiArray(),
                'message' => $notification->title,
                'unread_count' => $unreadCount,
                'cache_keys' => self::CACHE_KEYS,
                'sync_keys' => self::SYNC_KEYS,
            ]);
        }
    }

    /**
     * Queues push messages for the student's app installs, if they
     * registered any. Like the realtime announcement, a failure is only
     * logged: the notifications are stored either way.
     *
     * @param  list<PortalNotification>  $created
     */
    private function push(string $ownerHash, array $created): void
    {
        try {
            if (! PortalPushToken::query()->forOwner($ownerHash)->exists()) {
                return;
            }

            SendPortalPushNotificationsJob::dispatch(
                $ownerHash,
                array_map(static fn (PortalNotification $notification): int => $notification->id, $created)
            );
        } catch (\Throwable $throwable) {
            Log::warning('Portal notification could not be queued as push', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * A deadline reminder of an announcement is keyed by the announcement's
     * id, and that id changes without the deadline changing: ids stopped
     * being built from the wording, and an announcement falls back to its
     * wording-based id while another one shares its title, day and unit.
     *
     * @param  NotificationInput  $notification
     */
    private function isAnnouncementReminder(array $notification): bool
    {
        return $notification['type'] === 'deadline_reminder' && ($notification['dataset'] ?? null) === 'announcements';
    }

    /**
     * Announcement deadline reminders the student got lately, by how they
     * read: one that reads exactly the same (title, deadline line and
     * urgency) is the same reminder under a changed id, so it is not sent
     * again. A deadline that moved, or turned critical, reads differently.
     *
     * @return array<string, true>
     */
    private function recentAnnouncementReminders(string $ownerHash): array
    {
        return PortalNotification::query()
            ->forOwner($ownerHash)
            ->where('type', 'deadline_reminder')
            ->where('dataset', 'announcements')
            ->where('created_at', '>=', now()->subDays(self::ANNOUNCEMENT_REMINDER_REPEAT_DAYS))
            ->get(['title', 'body', 'urgency'])
            ->mapWithKeys(fn (PortalNotification $notification): array => [$this->readsAs([
                'title' => $notification->title,
                'body' => $notification->body,
                'urgency' => $notification->urgency,
            ]) => true])
            ->all();
    }

    /**
     * Stored titles are cut at 255 characters.
     *
     * @param  array{title: string, body?: string|null, urgency?: string}  $notification
     */
    private function readsAs(array $notification): string
    {
        $urgency = in_array($notification['urgency'] ?? null, self::URGENCIES, true) ? $notification['urgency'] : 'info';

        return implode("\0", [mb_substr($notification['title'], 0, 255), $notification['body'] ?? '', $urgency]);
    }

    /**
     * Notifications only ever link inside MyCIC; anything else is dropped so
     * a scraped value can never turn into an off-site link.
     */
    private function internalUrl(?string $url): ?string
    {
        if ($url === null || $url === '' || ! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return null;
        }

        return mb_substr($url, 0, 512);
    }
}
