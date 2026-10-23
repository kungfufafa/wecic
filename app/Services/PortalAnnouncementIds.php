<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Services\Extractors\AnnouncementExtractor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Announcement ids across the change from wording-based ids (`legacy_id`,
 * what every link stored before the change carries) to stable ids (title,
 * publish day and unit, see AnnouncementExtractor::stableId()).
 *
 * The legacy id of an announcement is worked out from what it showed, so
 * every one the student was ever shown maps to the id it goes by now: from
 * the feed snapshot, and from the archived versions of the feed
 * (PortalDataArchive), which keep each wording long after the announcement
 * left the feed or was edited. The newest sighting decides: an announcement
 * that falls back to its legacy id (another one shares its title, day and
 * unit) keeps it. Both are read by owner key as well, so the bulk and
 * list-read re-keys need no campus session and still work for a student
 * without an archive (archive switched off).
 *
 * Stored notifications that link an announcement, whatever their type (new
 * announcement, deadline reminder), follow it to its current id: in bulk
 * (portal:rekey-announcement-notifications), when the student's list is
 * read, and when an old link is opened. Only the link and record key
 * change, never the dedupe key, so no notification is stored twice.
 */
class PortalAnnouncementIds
{
    public const DATASET = 'announcements';

    private const ID_PATTERN = '/^[0-9a-f]{40}$/';

    /**
     * A MyCIC link to one announcement, with its query or fragment.
     */
    private const LINK_PATTERN = '#^/announcement/([0-9a-f]{40})(?=$|[?\#])#';

    private const LINK_PREFIX = '/announcement/';

    /**
     * Record key of a deadline reminder of an announcement (the action
     * center item id, see ActionCenterBuilder).
     */
    private const REMINDER_KEY_PATTERN = '/^announcement-([0-9a-f]{40})$/';

    private const REMINDER_KEY_PREFIX = 'announcement-';

    /**
     * Seconds the ids worked out from one state of a student's archive are kept.
     */
    private const ARCHIVED_IDS_TTL = 86400;

    /**
     * Snapshots read by owner key instead of by campus session (see ownerFeedItems()).
     */
    private ?PortalSnapshotStore $ownerSnapshots = null;

    public function __construct(
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * Notifications that may link an announcement: of the announcements
     * dataset, or with a link to one.
     *
     * @return Builder<PortalNotification>
     */
    public static function linkingNotifications(): Builder
    {
        return PortalNotification::query()->where(fn (Builder $query): Builder => $query
            ->where('dataset', self::DATASET)
            ->orWhere('url', 'like', self::LINK_PREFIX.'%'));
    }

    /**
     * By id; else by an id it went by before (its legacy id, also worked out
     * from its wording for an item kept before stable ids); else the oldest
     * announcement this stable id names (a link from before a second one
     * with the same title, day and unit gave both their legacy ids).
     *
     * @return array<string, mixed>|null
     */
    public static function find(mixed $items, string $id): ?array
    {
        $byLegacyId = null;
        $byStableId = null;

        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['id'] ?? null) === $id) {
                return $item;
            }

            if (($item['legacy_id'] ?? null) === $id || AnnouncementExtractor::legacyIdOf($item) === $id) {
                $byLegacyId ??= $item;
            } elseif (AnnouncementExtractor::stableIdOf($item) === $id) {
                $byStableId = $item;
            }
        }

        return $byLegacyId ?? $byStableId;
    }

    /**
     * The id an announcement linked by `$id` goes by now, when `$id` is an id
     * it no longer goes by; null when `$id` is current or unknown.
     *
     * @param  mixed  $feedItems  The feed's items; the student's feed snapshot when null.
     */
    public function currentId(string $sessionToken, string $id, mixed $feedItems = null): ?string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $feedItems ??= $this->snapshots->get($sessionToken, self::DATASET)['data']['items'] ?? null;

        foreach (is_array($feedItems) ? $feedItems : [] as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                return null;
            }
        }

        try {
            return $this->currentIds($this->owner->key($sessionToken), $feedItems ?? [])[$id] ?? null;
        } catch (\Throwable $throwable) {
            Log::warning('Announcement id could not be looked up in the archive', ['error' => $throwable->getMessage()]);

            return null;
        }
    }

    /**
     * Every id the student's announcements no longer go by => the id each
     * goes by now: from the archived feed versions, oldest first, then the
     * feed (newest), so the newest sighting decides.
     *
     * @param  mixed  $feedItems  The feed's items; the student's feed snapshot (by owner key) when null.
     * @return array<string, string>
     */
    public function currentIds(string $ownerHash, mixed $feedItems = null): array
    {
        $feedItems ??= $this->ownerFeedItems($ownerHash);

        return $this->changed(array_replace($this->archivedIds($ownerHash), $this->idsIn($feedItems)));
    }

    /**
     * The newest copy of an announcement in the student's trusted archive,
     * by the first of `$ids` it went by in that version.
     *
     * @param  list<string|null>  $ids  Preferred first.
     * @return array{item: array<string, mixed>, seen_at: string|null}|null
     */
    public function archivedCopy(string $sessionToken, array $ids): ?array
    {
        $ownerHash = $this->owner->key($sessionToken);
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && preg_match(self::ID_PATTERN, $id) === 1)));

        if ($ids === [] || ! $this->isStudent($ownerHash)) {
            return null;
        }

        try {
            foreach ($this->versions($ownerHash, trustedOnly: true) as $version) {
                $items = $this->itemsOf($version);

                foreach ($ids as $id) {
                    $item = self::find($items, $id);

                    if ($item !== null) {
                        return ['item' => $item, 'seen_at' => $version->last_seen_at?->toISOString()];
                    }
                }
            }
        } catch (\Throwable $throwable) {
            Log::warning('Archived announcement could not be read', ['error' => $throwable->getMessage()]);
        }

        return null;
    }

    /**
     * An old link was opened and resolved: the student's notifications that
     * link `$oldId` follow it to `$currentId`. Never throws; a notification
     * left behind still resolves and follows on the next read.
     */
    public function follow(string $sessionToken, string $oldId, string $currentId): void
    {
        try {
            $this->rekeyNotifications($this->owner->key($sessionToken), [$oldId => $currentId]);
        } catch (\Throwable $throwable) {
            Log::warning('Notifications of an announcement could not follow its id', ['error' => $throwable->getMessage()]);
        }
    }

    /**
     * Lazy re-key of notifications being read: those that link an
     * announcement by an id it no longer goes by follow it. The models are
     * updated in place, so the list read shows the current links.
     *
     * @param  list<PortalNotification>  $notifications  The student's own.
     * @return int Notifications re-keyed.
     */
    public function followIn(string $ownerHash, array $notifications): int
    {
        $linking = array_values(array_filter($notifications, fn (PortalNotification $notification): bool => $this->linkedIds($notification) !== []));

        if ($linking === []) {
            return 0;
        }

        return $this->rekeyNotifications($ownerHash, $this->currentIds($ownerHash), $linking);
    }

    /**
     * Rewrite the student's notifications that link an announcement by an id
     * in `$currentIds` (old id => current id): the link, and the record key
     * (the id itself for a new announcement, `announcement-<id>` for a
     * deadline reminder). Running it again changes nothing.
     *
     * @param  array<string, string>  $currentIds
     * @param  list<PortalNotification>|null  $notifications  Only these; every one of the student's when null.
     * @return int Notifications re-keyed.
     */
    public function rekeyNotifications(string $ownerHash, array $currentIds, ?array $notifications = null): int
    {
        $currentIds = $this->changed($currentIds);

        if ($currentIds === []) {
            return 0;
        }

        $notifications ??= self::linkingNotifications()->forOwner($ownerHash)->orderBy('id')->get()->all();
        $rekeyed = 0;

        foreach ($notifications as $notification) {
            if ($notification->owner_hash !== $ownerHash) {
                continue;
            }

            $changes = $this->rekeyed($notification, $currentIds);

            if ($changes !== []) {
                $notification->forceFill($changes)->save();
                $rekeyed++;
            }
        }

        return $rekeyed;
    }

    /**
     * Old id => id now, of every announcement in the student's archived feed
     * versions, oldest sighting first so the newest decides.
     *
     * Worked out once per order of the versions: a version's payload never
     * changes, so the ids depend only on which versions there are and in
     * which order they were last seen. A feed synced again unchanged only
     * moves its version's `last_seen_at` (PortalDataArchive::touch()); while
     * that keeps the order, nothing is decrypted again.
     *
     * @return array<string, string> Ids that did not change included.
     */
    private function archivedIds(string $ownerHash): array
    {
        if (! $this->isStudent($ownerHash)) {
            return [];
        }

        $order = $this->versionsQuery($ownerHash)->pluck('id')->all();

        if ($order === []) {
            return [];
        }

        $cached = Cache::get($this->archivedIdsCacheKey($ownerHash, $order));

        if (is_array($cached)) {
            return $cached;
        }

        $versions = $this->versions($ownerHash);
        $ids = [];

        foreach ($versions->reverse() as $version) {
            $ids = array_replace($ids, $this->idsIn($this->itemsOf($version)));
        }

        Cache::put($this->archivedIdsCacheKey($ownerHash, $versions->pluck('id')->all()), $ids, self::ARCHIVED_IDS_TTL);

        return $ids;
    }

    /**
     * @param  list<int|string>  $order  Version ids, newest sighting first.
     */
    private function archivedIdsCacheKey(string $ownerHash, array $order): string
    {
        return 'portal_announcement_ids_'.md5($ownerHash.'|'.implode(',', $order));
    }

    /**
     * The items of the student's feed snapshot, read by owner key: snapshots
     * belong to the student, and PortalSnapshotStore keys them by what its
     * owner resolver gives for a session, so a store whose resolver gives
     * the owner key it is handed reads the very snapshot a campus session
     * of that student reads.
     */
    private function ownerFeedItems(string $ownerHash): mixed
    {
        $this->ownerSnapshots ??= new PortalSnapshotStore(new class(app(PortalOwnerRekeyer::class)) extends PortalDataOwner
        {
            public function key(string $sessionToken): string
            {
                return $sessionToken;
            }
        });

        return $this->ownerSnapshots->get($ownerHash, self::DATASET)['data']['items'] ?? null;
    }

    /**
     * Newest sighting first. Every version counts for ids (the ids of what
     * the portal showed do not depend on whether it was trusted); a copy is
     * only served from a trusted one.
     *
     * @return Collection<int, PortalArchiveVersion>
     */
    private function versions(string $ownerHash, bool $trustedOnly = false): Collection
    {
        return $this->versionsQuery($ownerHash)
            ->when($trustedOnly, fn (Builder $query): Builder => $query->trusted())
            ->get();
    }

    /**
     * The student's archived feed versions, newest sighting first.
     *
     * @return Builder<PortalArchiveVersion>
     */
    private function versionsQuery(string $ownerHash): Builder
    {
        return PortalArchiveVersion::query()
            ->forOwner($ownerHash)
            ->where('dataset', self::DATASET)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemsOf(PortalArchiveVersion $version): array
    {
        try {
            $payload = $version->payload;
        } catch (DecryptException) {
            Log::warning('Archived announcements could not be decrypted', ['version_id' => $version->id]);

            return [];
        }

        return $this->normalized(is_array($payload) ? ($payload['items'] ?? null) : null);
    }

    /**
     * Every id an item of the list went by => the id it goes by in the list.
     *
     * @return array<string, string>
     */
    private function idsIn(mixed $items): array
    {
        $ids = [];

        foreach ($this->normalized($items) as $item) {
            foreach (array_unique(array_filter([$item['legacy_id'], AnnouncementExtractor::legacyIdOf($item)])) as $legacyId) {
                $ids[$legacyId] = $item['id'];
            }
        }

        return $ids;
    }

    /**
     * Items with an id, given ids the way the feed gives them: an item kept
     * before stable ids (its `id` was its legacy id) gets the id it goes by
     * now.
     *
     * @return list<array<string, mixed>> Each with a string `id` and `legacy_id`.
     */
    private function normalized(mixed $items): array
    {
        $normalized = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || preg_match(self::ID_PATTERN, $item['id']) !== 1) {
                continue;
            }

            $item['legacy_id'] = is_string($item['legacy_id'] ?? null) ? $item['legacy_id'] : $item['id'];
            $normalized[] = $item;
        }

        return AnnouncementExtractor::withStableIds($normalized);
    }

    /**
     * Only the ids that changed, both sides well-formed.
     *
     * @param  array<array-key, mixed>  $currentIds
     * @return array<string, string>
     */
    private function changed(array $currentIds): array
    {
        $changed = [];

        foreach ($currentIds as $oldId => $currentId) {
            $oldId = (string) $oldId;

            if (is_string($currentId) && $oldId !== $currentId
                && preg_match(self::ID_PATTERN, $oldId) === 1 && preg_match(self::ID_PATTERN, $currentId) === 1) {
                $changed[$oldId] = $currentId;
            }
        }

        return $changed;
    }

    /**
     * The announcement ids a notification links, by its link or its record key.
     *
     * @return list<string>
     */
    private function linkedIds(PortalNotification $notification): array
    {
        $ids = [];

        if (is_string($notification->url) && preg_match(self::LINK_PATTERN, $notification->url, $match) === 1) {
            $ids[] = $match[1];
        }

        $recordKey = (string) $notification->record_key;

        if (preg_match(self::REMINDER_KEY_PATTERN, $recordKey, $match) === 1) {
            $ids[] = $match[1];
        } elseif ($notification->dataset === self::DATASET && preg_match(self::ID_PATTERN, $recordKey) === 1) {
            $ids[] = $recordKey;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, string>  $currentIds
     * @return array<string, string> The columns to rewrite.
     */
    private function rekeyed(PortalNotification $notification, array $currentIds): array
    {
        $changes = [];
        $recordKey = (string) $notification->record_key;

        if (preg_match(self::REMINDER_KEY_PATTERN, $recordKey, $match) === 1) {
            if (isset($currentIds[$match[1]])) {
                $changes['record_key'] = self::REMINDER_KEY_PREFIX.$currentIds[$match[1]];
            }
        } elseif ($notification->dataset === self::DATASET && isset($currentIds[$recordKey])) {
            $changes['record_key'] = $currentIds[$recordKey];
        }

        if (is_string($notification->url) && preg_match(self::LINK_PATTERN, $notification->url, $match) === 1 && isset($currentIds[$match[1]])) {
            $changes['url'] = mb_substr(self::LINK_PREFIX.$currentIds[$match[1]].substr($notification->url, strlen(self::LINK_PREFIX) + 40), 0, 512);
        }

        return $changes;
    }

    /**
     * Only students are archived (PortalDataArchive).
     */
    private function isStudent(string $ownerHash): bool
    {
        return str_starts_with($ownerHash, 'student_');
    }
}
