<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalNotification;
use Illuminate\Support\Facades\Log;

/**
 * Stores notifications per student and announces new ones in realtime.
 *
 * A notification is identified by its owner and dedupe key, so the same
 * change found twice (two syncs racing, a re-login, another device) is
 * stored and announced once. Only the browser session that triggered the
 * work is told right away; every other session sees it on its next load.
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

    public const CACHE_KEYS = ['/api/notifications', '/api/notifications/unread-count'];

    public const SYNC_KEYS = ['notification-index'];

    public function __construct(
        private readonly PortalDataOwner $owner,
        private readonly PortalBroadcastService $broadcast,
        private readonly PortalSessionManager $sessionManager
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

        foreach ($notifications as $notification) {
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
        }

        return $created;
    }

    public function unreadCount(string $ownerHash): int
    {
        return PortalNotification::query()->forOwner($ownerHash)->unread()->count();
    }

    /**
     * Newest first; `beforeId` continues a previous page.
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

        return [
            'items' => array_slice($items, 0, $limit),
            'has_more' => count($items) > $limit,
        ];
    }

    /**
     * @param  list<int>|null  $ids  Null marks every unread notification.
     * @return int Notifications that were unread and are now read.
     */
    public function markRead(string $ownerHash, ?array $ids = null): int
    {
        return PortalNotification::query()
            ->forOwner($ownerHash)
            ->unread()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
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
