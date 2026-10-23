<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalArchiveVersion;
use App\Support\PortalPayloadRecords;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * Durable, versioned copy of what the portal showed each student, so MyCIC
 * can serve it as a backup when the portal is down, has forgotten data, or
 * shows data that looks wrong.
 *
 * Every build of an archived dataset (`portal.datasets.<name>.archive`) is
 * recorded once per distinct content. A build that breaks a guard (see
 * PortalArchiveGuard) is kept as a `suspect` version: the snapshot keeps the
 * trusted data, the student is notified, and the suspect version waits for
 * the student to accept or reject it. If the portal goes back to the trusted
 * data, the suspect version is dismissed on its own; when it shows the
 * dismissed data again, that version is suspect again and announced again.
 * Content trusted earlier that comes back and breaks a guard (the portal
 * rolled back, e.g. a released grade disappeared) is held back as a new
 * suspect row (`sighting`): the earlier trusted version keeps its status.
 *
 * Only students are archived (a session whose owner is unknown is not), and
 * the archive never fails the sync that feeds it.
 *
 * @phpstan-type Integrity array{status: string, version_id: int, findings: list<array<string, mixed>>, detected_at: string|null, trusted_seen_at: string|null}
 * @phpstan-type Change array{path: string, kind: 'added'|'removed'|'changed', old: string|null, new: string|null}
 */
class PortalDataArchive
{
    public const SOURCE = 'mycic_archive';

    /**
     * Above this many item pairs a list is not aligned item by item (its
     * changed middle is paired in order instead), so a huge list cannot make
     * a comparison slow.
     */
    private const MAX_ALIGNMENT_CELLS = 40000;

    /**
     * Path segment prefix of a list item only the newer payload has: it is
     * numbered by its place there, which readablePath() marks as new so it
     * never reads as the older item with the same number.
     */
    private const ADDED_ITEM = '+';

    public function __construct(
        private readonly PortalDataOwner $owner,
        private readonly PortalArchiveGuard $guard,
        private readonly PortalNotificationCenter $notifications,
        private readonly PortalSnapshotStore $snapshots
    ) {}

    public function archives(string $dataset): bool
    {
        return (bool) config('portal.archive.enabled', true)
            && is_array(config("portal.datasets.{$dataset}.archive"));
    }

    /**
     * @return list<string>
     */
    public function datasets(): array
    {
        return array_values(array_filter(
            array_keys((array) config('portal.datasets', [])),
            fn (string $dataset): bool => $this->archives($dataset)
        ));
    }

    public function label(string $dataset): string
    {
        return (string) config("portal.datasets.{$dataset}.archive.label", $dataset);
    }

    /**
     * Record a freshly built payload and decide what the snapshot serves.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, integrity: Integrity|null}
     */
    public function record(string $sessionToken, string $dataset, array $params, array $data, ?string $observedAt = null): array
    {
        $passThrough = ['data' => $data, 'integrity' => null];
        $ownerHash = $this->owner->key($sessionToken);

        if (! $this->archives($dataset) || ! $this->isStudent($ownerHash)) {
            return $passThrough;
        }

        try {
            $content = $this->content($dataset, $data);
            $contentHash = $this->contentHash($content);
            $paramsHash = PortalDataCache::paramsHash($params);
            $observed = $this->observedMoment($observedAt);
            $trusted = $this->currentTrusted($ownerHash, $dataset, $paramsHash);

            if ($trusted === null && ! $this->guard->isReadable($dataset, $content)) {
                return $passThrough;
            }

            if ($trusted !== null && $trusted->content_hash === $contentHash) {
                $this->touch($trusted, $observed);
                $this->dismissOpenSuspects($ownerHash, $dataset, $paramsHash);

                return $passThrough;
            }

            $findings = $trusted !== null ? $this->guard->inspect($dataset, $trusted->payload, $content) : [];
            $version = PortalArchiveVersion::query()
                ->forOwner($ownerHash)
                ->forDataset($dataset, $paramsHash)
                ->where('content_hash', $contentHash)
                ->latest('sighting')
                ->first();

            if ($findings === []) {
                $this->trust($version, $ownerHash, $dataset, $params, $paramsHash, $contentHash, $content, $observed);
                $this->dismissOpenSuspects($ownerHash, $dataset, $paramsHash);

                return $passThrough;
            }

            $reopened = $version?->status === PortalArchiveVersion::STATUS_DISMISSED;
            $version = $this->holdBack($version, $ownerHash, $dataset, $params, $paramsHash, $contentHash, $content, $findings, $observed);

            if ($version->wasRecentlyCreated || $reopened) {
                $this->announceSuspect($sessionToken, $version, $reopened ? $observed : null);
            }

            /** @var PortalArchiveVersion $trusted */
            return [
                'data' => $trusted->payload,
                'integrity' => $this->integrity($version, $trusted),
            ];
        } catch (\Throwable $throwable) {
            Log::warning('Portal dataset could not be archived', [
                'dataset' => $dataset,
                'error' => $throwable->getMessage(),
            ]);

            return $passThrough;
        }
    }

    /**
     * The trusted version to serve when no snapshot exists (expired, cache
     * cleared, offline login), with the pending integrity state if any.
     *
     * @param  array<string, mixed>  $params
     * @return array{data: array<string, mixed>, seen_at: string, integrity: Integrity|null}|null
     */
    public function restore(string $sessionToken, string $dataset, array $params = []): ?array
    {
        $ownerHash = $this->owner->key($sessionToken);

        if (! $this->archives($dataset) || ! $this->isStudent($ownerHash)) {
            return null;
        }

        try {
            $paramsHash = PortalDataCache::paramsHash($params);
            $trusted = $this->currentTrusted($ownerHash, $dataset, $paramsHash);

            if ($trusted === null) {
                return null;
            }

            $pending = PortalArchiveVersion::query()
                ->forOwner($ownerHash)
                ->forDataset($dataset, $paramsHash)
                ->whereIn('status', [PortalArchiveVersion::STATUS_SUSPECT, PortalArchiveVersion::STATUS_REJECTED])
                ->where('last_seen_at', '>', $trusted->last_seen_at)
                ->latest('last_seen_at')
                ->first();

            return [
                'data' => $trusted->payload,
                'seen_at' => ($trusted->last_seen_at ?? $trusted->updated_at ?? now())->toISOString(),
                'integrity' => $pending !== null ? $this->integrity($pending, $trusted) : null,
            ];
        } catch (DecryptException) {
            Log::warning('Archived portal dataset could not be decrypted', ['dataset' => $dataset]);

            return null;
        } catch (\Throwable $throwable) {
            Log::warning('Archived portal dataset could not be restored', [
                'dataset' => $dataset,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The student confirms the portal is right: the version becomes the
     * trusted data and replaces the snapshot.
     */
    public function accept(string $sessionToken, PortalArchiveVersion $version): PortalArchiveVersion
    {
        $now = CarbonImmutable::now();

        $version->forceFill([
            'status' => PortalArchiveVersion::STATUS_TRUSTED,
            'trusted_at' => $now,
            'resolved_at' => $now,
        ])->save();

        $params = $version->params ?? [];
        $this->snapshots->put(
            $sessionToken,
            $version->dataset,
            $version->payload,
            ($version->last_seen_at ?? $now)->toISOString(),
            $params
        );

        return $version;
    }

    /**
     * The student says the portal is wrong: MyCIC keeps serving the trusted
     * data and stops asking about this version.
     */
    public function reject(string $sessionToken, PortalArchiveVersion $version): PortalArchiveVersion
    {
        $version->forceFill([
            'status' => PortalArchiveVersion::STATUS_REJECTED,
            'resolved_at' => CarbonImmutable::now(),
        ])->save();

        $trusted = $this->currentTrusted($version->owner_hash, $version->dataset, $version->params_hash);
        $this->snapshots->setIntegrity(
            $sessionToken,
            $version->dataset,
            $trusted !== null ? $this->integrity($version, $trusted) : null,
            $version->params ?? []
        );

        return $version;
    }

    /**
     * The version this one is compared with: the trusted version current
     * before a trusted one; for a held-back version, the trusted version
     * that was current when it was last seen, which is what its findings
     * were last judged against (holdBack() refreshes both together, also
     * when a dismissed or rejected version shows up again), else the
     * current trusted one.
     */
    public function baseline(PortalArchiveVersion $version): ?PortalArchiveVersion
    {
        $query = PortalArchiveVersion::query()
            ->forOwner($version->owner_hash)
            ->forDataset($version->dataset, $version->params_hash)
            ->trusted()
            ->whereKeyNot($version->id);

        if ($version->status === PortalArchiveVersion::STATUS_TRUSTED && $version->trusted_at !== null) {
            return $query->where('trusted_at', '<', $version->trusted_at)->latest('trusted_at')->latest('id')->first();
        }

        if ($version->last_seen_at !== null) {
            $judgedAgainst = (clone $query)
                ->where('trusted_at', '<=', $version->last_seen_at)
                ->latest('trusted_at')
                ->latest('id')
                ->first();

            if ($judgedAgainst !== null) {
                return $judgedAgainst;
            }
        }

        return $query->latest('trusted_at')->latest('id')->first();
    }

    /**
     * Leaf-level differences between two payloads, as readable paths: the
     * first `$limit` of compare().
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<Change>
     */
    public function diff(array $old, array $new, int $limit = 200, ?string $dataset = null): array
    {
        return $this->compare($old, $new, $limit, $dataset)['changes'];
    }

    /**
     * Leaf-level differences between two payloads, as readable paths.
     *
     * List items are paired by the dataset's record key (the `key` of its
     * archive guards and notify rules) wherever they sit, otherwise by
     * content, so a row that disappears shows as removed instead of shifting
     * every row after it, and a list the portal only sorted differently
     * shows no removed or added rows. An item is numbered by its place in
     * `$old`; an item only `$new` has is numbered by its place there and
     * marked "(baru)", after the others. `total` counts every difference;
     * only the first `$limit` are listed (null lists them all) and
     * `truncated` says whether some are cut.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{changes: list<Change>, total: int, truncated: bool}
     */
    public function compare(array $old, array $new, ?int $limit = 200, ?string $dataset = null): array
    {
        $changes = [];
        $this->compareNode($old, $new, '', '', $this->recordKeys($dataset), $this->volatileKeys(), $changes);
        $total = count($changes);
        $listed = $limit !== null ? array_slice($changes, 0, max(0, $limit)) : $changes;

        return [
            'changes' => $listed,
            'total' => $total,
            'truncated' => count($listed) < $total,
        ];
    }

    /**
     * @return Integrity
     */
    public function integrity(PortalArchiveVersion $version, PortalArchiveVersion $trusted): array
    {
        return [
            'status' => $version->status,
            'version_id' => $version->id,
            'findings' => $version->findings ?? [],
            'detected_at' => $version->first_seen_at?->toISOString(),
            'trusted_seen_at' => $trusted->last_seen_at?->toISOString(),
        ];
    }

    /**
     * One entry per archived dataset, including those with nothing archived yet.
     *
     * @return list<array{dataset: string, label: string, versions: int, groups: int, open_suspects: int, last_seen_at: string|null}>
     */
    public function summary(string $ownerHash): array
    {
        $rows = PortalArchiveVersion::query()
            ->forOwner($ownerHash)
            ->selectRaw('dataset, count(*) as versions, count(distinct params_hash) as groups_count, max(last_seen_at) as last_seen')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as open_suspects', [PortalArchiveVersion::STATUS_SUSPECT])
            ->groupBy('dataset')
            ->get()
            ->keyBy('dataset');

        return array_map(function (string $dataset) use ($rows): array {
            $row = $rows->get($dataset);
            $lastSeen = $row?->getAttribute('last_seen');

            return [
                'dataset' => $dataset,
                'label' => $this->label($dataset),
                'versions' => (int) ($row?->getAttribute('versions') ?? 0),
                'groups' => (int) ($row?->getAttribute('groups_count') ?? 0),
                'open_suspects' => (int) ($row?->getAttribute('open_suspects') ?? 0),
                'last_seen_at' => is_string($lastSeen) ? CarbonImmutable::parse($lastSeen)->toISOString() : null,
            ];
        }, $this->datasets());
    }

    /**
     * Versions of one dataset, newest first, and the parameter sets (one per
     * class, meeting or KRS) they belong to.
     *
     * Without `$paramsHash` the group shown is the most recently seen one
     * that has a version waiting for the student, else the most recently
     * seen one; `selected_params_hash` names it.
     *
     * @return array{groups: list<array{params_hash: string, subject: string|null, params: array<string, mixed>, versions: int, open_suspects: int, last_seen_at: string|null}>, selected_params_hash: string|null, versions: list<array<string, mixed>>}
     */
    public function versions(string $ownerHash, string $dataset, ?string $paramsHash = null): array
    {
        $versions = PortalArchiveVersion::query()
            ->forOwner($ownerHash)
            ->where('dataset', $dataset)
            ->latest('last_seen_at')
            ->latest('id')
            ->get();

        $groups = $versions->groupBy('params_hash')->map(static function ($group, string $hash): array {
            /** @var \Illuminate\Support\Collection<int, PortalArchiveVersion> $group */
            $latest = $group->first();

            return [
                'params_hash' => $hash,
                'subject' => $group->pluck('subject')->filter()->first(),
                'params' => $latest?->params ?? [],
                'versions' => $group->count(),
                'open_suspects' => $group->where('status', PortalArchiveVersion::STATUS_SUSPECT)->count(),
                'last_seen_at' => $latest?->last_seen_at?->toISOString(),
            ];
        })->values()->all();

        $waiting = array_values(array_filter($groups, static fn (array $group): bool => $group['open_suspects'] > 0));
        $selected = $paramsHash ?? ($waiting[0]['params_hash'] ?? $groups[0]['params_hash'] ?? null);
        $current = $selected !== null ? $this->currentTrusted($ownerHash, $dataset, $selected) : null;

        return [
            'groups' => $groups,
            'selected_params_hash' => $selected,
            'versions' => $versions
                ->where('params_hash', $selected)
                ->map(static fn (PortalArchiveVersion $version): array => [
                    ...$version->toApiArray(),
                    'is_current' => $current !== null && $current->is($version),
                ])
                ->values()
                ->all(),
        ];
    }

    public function findForOwner(string $ownerHash, int $versionId): ?PortalArchiveVersion
    {
        return PortalArchiveVersion::query()->forOwner($ownerHash)->whereKey($versionId)->first();
    }

    public function isCurrent(PortalArchiveVersion $version): bool
    {
        return $this->currentTrusted($version->owner_hash, $version->dataset, $version->params_hash)?->is($version) ?? false;
    }

    public function deleteOwner(string $ownerHash): int
    {
        return PortalArchiveVersion::query()->forOwner($ownerHash)->delete();
    }

    /**
     * What is archived: the payload without the fields a dataset excludes
     * (e.g. the classmates list of a class).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function content(string $dataset, array $data): array
    {
        foreach ((array) config("portal.datasets.{$dataset}.archive.exclude", []) as $path) {
            if (is_string($path)) {
                data_forget($data, $path);
            }
        }

        return $data;
    }

    /**
     * Timestamps of the scrape itself change on every sync; they are not
     * part of what the portal showed.
     *
     * @param  array<string, mixed>  $content
     */
    private function contentHash(array $content): string
    {
        return hash('sha256', (string) json_encode($this->canonical($content, $this->volatileKeys())));
    }

    /**
     * @param  array<string, true>  $volatile
     */
    private function canonical(mixed $value, array $volatile): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            $value = array_diff_key($value, $volatile);
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item, $volatile), $value);
    }

    /**
     * @return array<string, true>
     */
    private function volatileKeys(): array
    {
        return array_fill_keys(array_filter((array) config('portal.archive.volatile_keys', []), 'is_string'), true);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, true>  $volatile
     * @return array<string, string>
     */
    private function leaves(array $data, array $volatile, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && isset($volatile[$key])) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $leaves += $value === [] ? [] : $this->leaves($value, $volatile, $path);

                continue;
            }

            $normalized = match (true) {
                $value === null => null,
                is_bool($value) => $value ? 'ya' : 'tidak',
                is_scalar($value) => trim((string) $value),
                default => null,
            };

            if ($normalized !== null && $normalized !== '') {
                $leaves[$path] = $normalized;
            }
        }

        return $leaves;
    }

    /**
     * One node of compare(): leaves are compared by value, maps key by key,
     * and lists item by item after pairing the items (align()).
     *
     * @param  array<string, list<string>>  $recordKeys
     * @param  array<string, true>  $volatile
     * @param  list<Change>  $changes
     * @param  array<array-key, mixed>|null  $oldParent  What a record of this list sees as `parent`.
     * @param  array<array-key, mixed>|null  $newParent
     */
    private function compareNode(
        mixed $old,
        mixed $new,
        string $path,
        string $pattern,
        array $recordKeys,
        array $volatile,
        array &$changes,
        ?array $oldParent = null,
        ?array $newParent = null
    ): void {
        $oldIsArray = is_array($old) && $old !== [];
        $newIsArray = is_array($new) && $new !== [];

        if (! $oldIsArray || ! $newIsArray) {
            $oldLeaves = $oldIsArray ? $this->leaves($old, $volatile, $path) : $this->leafAt($path, $old);
            $newLeaves = $newIsArray ? $this->leaves($new, $volatile, $path) : $this->leafAt($path, $new);

            foreach (array_keys($oldLeaves + $newLeaves) as $leafPath) {
                $this->addChange($changes, (string) $leafPath, $oldLeaves[$leafPath] ?? null, $newLeaves[$leafPath] ?? null);
            }

            return;
        }

        if (array_is_list($old) && array_is_list($new)) {
            $keys = $recordKeys[$pattern] ?? null;
            $identity = $keys === null ? null : static function (mixed $item, ?array $parent) use ($keys, $pattern): ?string {
                if (! is_array($item)) {
                    return null;
                }

                return PortalPayloadRecords::recordKey($keys, str_contains($pattern, '.*.') ? [...$item, 'parent' => $parent] : $item);
            };

            foreach ($this->align($old, $new, $volatile, $identity, $oldParent, $newParent) as [$oldIndex, $newIndex]) {
                $this->compareNode(
                    $oldIndex !== null ? $old[$oldIndex] : null,
                    $newIndex !== null ? $new[$newIndex] : null,
                    $this->childPath($path, $oldIndex !== null ? (string) $oldIndex : self::ADDED_ITEM.$newIndex),
                    $pattern === '' ? '*' : $pattern.'.*',
                    $recordKeys,
                    $volatile,
                    $changes
                );
            }

            return;
        }

        foreach (array_keys($old + $new) as $key) {
            if (is_string($key) && isset($volatile[$key])) {
                continue;
            }

            $childPattern = $pattern === '' ? (string) $key : $pattern.'.'.$key;
            $isRecordList = isset($recordKeys[$childPattern]);

            $this->compareNode(
                $old[$key] ?? null,
                $new[$key] ?? null,
                $this->childPath($path, (string) $key),
                $childPattern,
                $recordKeys,
                $volatile,
                $changes,
                $isRecordList ? array_diff_key($old, [$key => true]) : null,
                $isRecordList ? array_diff_key($new, [$key => true]) : null
            );
        }
    }

    /**
     * Pairs of list items to compare, as [old index, new index] with null for
     * an item only one side has, in the order of `$old` with the new items
     * last. Items with the same record key are paired wherever they sit;
     * the rest are paired by content, keeping their order, and what is left
     * between two such pairs is paired when the items look like the same
     * record, otherwise reported as removed and added.
     *
     * @param  list<mixed>  $old
     * @param  list<mixed>  $new
     * @param  array<string, true>  $volatile
     * @param  (\Closure(mixed, array<array-key, mixed>|null): (string|null))|null  $identity
     * @param  array<array-key, mixed>|null  $oldParent
     * @param  array<array-key, mixed>|null  $newParent
     * @return list<array{0: int|null, 1: int|null}>
     */
    private function align(array $old, array $new, array $volatile, ?\Closure $identity, ?array $oldParent, ?array $newParent): array
    {
        $token = function (mixed $item, ?array $parent) use ($volatile, $identity): string {
            $recordKey = $identity !== null ? $identity($item, $parent) : null;

            return $recordKey !== null
                ? 'key:'.$recordKey
                : 'content:'.md5((string) json_encode($this->canonical($item, $volatile)));
        };
        $oldTokens = array_map(static fn (mixed $item): string => $token($item, $oldParent), $old);
        $newTokens = array_map(static fn (mixed $item): string => $token($item, $newParent), $new);
        $byKey = $this->pairByKey($oldTokens, $newTokens);
        $oldRest = array_values(array_diff_key(array_keys($old), $byKey));
        $newRest = array_values(array_diff_key(array_keys($new), array_flip($byKey)));
        $pairs = array_map(static fn (int $oldIndex, int $newIndex): array => [$oldIndex, $newIndex], array_keys($byKey), $byKey);
        $restMatches = $this->commonSubsequence(
            array_map(static fn (int $index): string => $oldTokens[$index], $oldRest),
            array_map(static fn (int $index): string => $newTokens[$index], $newRest)
        );
        $oldNext = 0;
        $newNext = 0;

        foreach ([...$restMatches, [count($oldRest), count($newRest)]] as [$oldMatch, $newMatch]) {
            array_push($pairs, ...$this->pairGap(
                $old,
                $new,
                $oldTokens,
                $newTokens,
                $volatile,
                array_slice($oldRest, $oldNext, $oldMatch - $oldNext),
                array_slice($newRest, $newNext, $newMatch - $newNext)
            ));

            if ($oldMatch < count($oldRest)) {
                $pairs[] = [$oldRest[$oldMatch], $newRest[$newMatch]];
            }

            $oldNext = $oldMatch + 1;
            $newNext = $newMatch + 1;
        }

        usort($pairs, static fn (array $left, array $right): int => [$left[0] ?? PHP_INT_MAX, $left[1] ?? -1] <=> [$right[0] ?? PHP_INT_MAX, $right[1] ?? -1]);

        return $pairs;
    }

    /**
     * Items whose record key both lists hold, paired wherever they sit (the
     * n-th item with a key in `$old` pairs with the n-th one in `$new`), as
     * old index => new index.
     *
     * @param  list<string>  $oldTokens
     * @param  list<string>  $newTokens
     * @return array<int, int>
     */
    private function pairByKey(array $oldTokens, array $newTokens): array
    {
        $waiting = [];

        foreach ($newTokens as $index => $token) {
            if (str_starts_with($token, 'key:')) {
                $waiting[$token][] = $index;
            }
        }

        $pairs = [];

        foreach ($oldTokens as $index => $token) {
            if (($waiting[$token] ?? []) !== []) {
                $pairs[$index] = array_shift($waiting[$token]);
            }
        }

        return $pairs;
    }

    /**
     * Index pairs of the longest common subsequence of two token lists.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<array{0: int, 1: int}>
     */
    private function commonSubsequence(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);
        $start = 0;

        while ($start < $oldCount && $start < $newCount && $old[$start] === $new[$start]) {
            $start++;
        }

        $oldEnd = $oldCount;
        $newEnd = $newCount;

        while ($oldEnd > $start && $newEnd > $start && $old[$oldEnd - 1] === $new[$newEnd - 1]) {
            $oldEnd--;
            $newEnd--;
        }

        $matches = [];

        for ($index = 0; $index < $start; $index++) {
            $matches[] = [$index, $index];
        }

        $rows = $oldEnd - $start;
        $columns = $newEnd - $start;

        if ($rows > 0 && $columns > 0 && $rows * $columns <= self::MAX_ALIGNMENT_CELLS) {
            $lengths = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

            for ($row = $rows - 1; $row >= 0; $row--) {
                for ($column = $columns - 1; $column >= 0; $column--) {
                    $lengths[$row][$column] = $old[$start + $row] === $new[$start + $column]
                        ? $lengths[$row + 1][$column + 1] + 1
                        : max($lengths[$row + 1][$column], $lengths[$row][$column + 1]);
                }
            }

            $row = 0;
            $column = 0;

            while ($row < $rows && $column < $columns) {
                if ($old[$start + $row] === $new[$start + $column]) {
                    $matches[] = [$start + $row, $start + $column];
                    $row++;
                    $column++;
                } elseif ($lengths[$row + 1][$column] >= $lengths[$row][$column + 1]) {
                    $row++;
                } else {
                    $column++;
                }
            }
        }

        for ($offset = 0; $offset < $oldCount - $oldEnd; $offset++) {
            $matches[] = [$oldEnd + $offset, $newEnd + $offset];
        }

        return $matches;
    }

    /**
     * Pair the items left between two matched items, keeping their order.
     * One item on each side is one item that changed, unless both carry a
     * record key (then they are two different records).
     *
     * @param  list<mixed>  $old
     * @param  list<mixed>  $new
     * @param  list<string>  $oldTokens
     * @param  list<string>  $newTokens
     * @param  array<string, true>  $volatile
     * @param  list<int>  $oldGap
     * @param  list<int>  $newGap
     * @return list<array{0: int|null, 1: int|null}>
     */
    private function pairGap(array $old, array $new, array $oldTokens, array $newTokens, array $volatile, array $oldGap, array $newGap): array
    {
        if ($oldGap === [] || $newGap === []) {
            return [
                ...array_map(static fn (int $index): array => [$index, null], $oldGap),
                ...array_map(static fn (int $index): array => [null, $index], $newGap),
            ];
        }

        $bothKeyed = static fn (int $oldIndex, int $newIndex): bool => str_starts_with($oldTokens[$oldIndex], 'key:')
            && str_starts_with($newTokens[$newIndex], 'key:');

        if (count($oldGap) === 1 && count($newGap) === 1 && ! $bothKeyed($oldGap[0], $newGap[0])) {
            return [[$oldGap[0], $newGap[0]]];
        }

        $scalars = array_filter([...array_intersect_key($old, array_flip($oldGap)), ...array_intersect_key($new, array_flip($newGap))], 'is_array') === [];
        $tooLarge = count($oldGap) * count($newGap) > self::MAX_ALIGNMENT_CELLS;

        if (($scalars && count($oldGap) === count($newGap)) || $tooLarge) {
            $pairs = [];

            for ($offset = 0; $offset < max(count($oldGap), count($newGap)); $offset++) {
                $pairs[] = [$oldGap[$offset] ?? null, $newGap[$offset] ?? null];
            }

            return $pairs;
        }

        $pairs = [];
        $next = 0;

        foreach ($oldGap as $oldIndex) {
            $found = null;

            for ($offset = $next; $offset < count($newGap); $offset++) {
                if ($this->sameRecord($old[$oldIndex], $new[$newGap[$offset]], $oldTokens[$oldIndex], $newTokens[$newGap[$offset]], $volatile)) {
                    $found = $offset;

                    break;
                }
            }

            if ($found === null) {
                $pairs[] = [$oldIndex, null];

                continue;
            }

            for ($offset = $next; $offset < $found; $offset++) {
                $pairs[] = [null, $newGap[$offset]];
            }

            $pairs[] = [$oldIndex, $newGap[$found]];
            $next = $found + 1;
        }

        for ($offset = $next; $offset < count($newGap); $offset++) {
            $pairs[] = [null, $newGap[$offset]];
        }

        return $pairs;
    }

    /**
     * Whether two unmatched items are one record that changed: they share
     * their record key, or at least half of their filled scalar fields.
     *
     * @param  array<string, true>  $volatile
     */
    private function sameRecord(mixed $old, mixed $new, string $oldToken, string $newToken, array $volatile): bool
    {
        if ($oldToken === $newToken) {
            return true;
        }

        if (str_starts_with($oldToken, 'key:') && str_starts_with($newToken, 'key:')) {
            return false;
        }

        if (! is_array($old) || ! is_array($new)) {
            return false;
        }

        $oldFields = $this->scalarFields($old, $volatile);
        $newFields = $this->scalarFields($new, $volatile);
        $fields = array_keys($oldFields + $newFields);

        if ($fields === []) {
            return true;
        }

        $shared = count(array_filter($fields, static fn (int|string $field): bool => isset($oldFields[$field], $newFields[$field]) && $oldFields[$field] === $newFields[$field]));

        return $shared * 2 >= count($fields);
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  array<string, true>  $volatile
     * @return array<array-key, string>
     */
    private function scalarFields(array $item, array $volatile): array
    {
        $fields = [];

        foreach ($item as $key => $value) {
            $normalized = is_array($value) || (is_string($key) && isset($volatile[$key])) ? null : $this->leafValue($value);

            if ($normalized !== null) {
                $fields[$key] = $normalized;
            }
        }

        return $fields;
    }

    /**
     * @param  list<Change>  $changes
     */
    private function addChange(array &$changes, string $path, ?string $before, ?string $after): void
    {
        if ($before === $after) {
            return;
        }

        $changes[] = [
            'path' => $this->readablePath($path),
            'kind' => match (true) {
                $before === null => 'added',
                $after === null => 'removed',
                default => 'changed',
            },
            'old' => $before,
            'new' => $after,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function leafAt(string $path, mixed $value): array
    {
        $normalized = $this->leafValue($value);

        return $normalized !== null ? [$path => $normalized] : [];
    }

    private function leafValue(mixed $value): ?string
    {
        $normalized = match (true) {
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_scalar($value) => trim((string) $value),
            default => null,
        };

        return $normalized !== '' ? $normalized : null;
    }

    private function childPath(string $path, string $key): string
    {
        return $path === '' ? $key : $path.'.'.$key;
    }

    /**
     * The record key of each list a dataset's archive guards (then its
     * notify rules) pair records by, keyed by its path with `*` for list
     * indexes. A nested list keyed by `parent.*` fields also keys its parent
     * list by those fields: `semesters.*.courses` keyed by `parent.label`
     * and `code` keys `semesters` by `label`.
     *
     * @return array<string, list<string>>
     */
    private function recordKeys(?string $dataset): array
    {
        if ($dataset === null) {
            return [];
        }

        $rules = [...$this->guard->guards($dataset), ...array_filter((array) config("portal.datasets.{$dataset}.notify", []), 'is_array')];
        $keys = [];

        foreach ($rules as $rule) {
            $records = $rule['records'] ?? null;
            $key = array_values(array_filter((array) ($rule['key'] ?? []), 'is_string'));

            if (! is_string($records) || $records === '' || $key === []) {
                continue;
            }

            $keys[$records] ??= $key;

            if (str_contains($records, '.*.')) {
                $parentKey = array_values(array_filter(array_map(
                    static fn (string $field): ?string => str_starts_with($field, 'parent.') ? substr($field, 7) : null,
                    $key
                )));

                if ($parentKey !== []) {
                    $keys[explode('.*.', $records, 2)[0]] ??= $parentKey;
                }
            }
        }

        return $keys;
    }

    /**
     * The moment a build began, in the application timezone: datetime
     * columns store wall-clock time without a zone, so a UTC moment stored
     * as is would read back hours off.
     */
    private function observedMoment(?string $observedAt): CarbonImmutable
    {
        $timezone = (string) config('app.timezone', 'UTC');

        return $observedAt !== null
            ? CarbonImmutable::parse($observedAt)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);
    }

    private function readablePath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => match (true) {
                ctype_digit($segment) => '#'.((int) $segment + 1),
                str_starts_with($segment, self::ADDED_ITEM) && ctype_digit(substr($segment, 1)) => '#'.((int) substr($segment, 1) + 1).' (baru)',
                default => str_replace('_', ' ', $segment),
            },
            explode('.', $path)
        );

        return implode(' › ', $segments);
    }

    private function currentTrusted(string $ownerHash, string $dataset, string $paramsHash): ?PortalArchiveVersion
    {
        return PortalArchiveVersion::query()
            ->forOwner($ownerHash)
            ->forDataset($dataset, $paramsHash)
            ->trusted()
            ->latest('trusted_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $content
     */
    private function trust(
        ?PortalArchiveVersion $version,
        string $ownerHash,
        string $dataset,
        array $params,
        string $paramsHash,
        string $contentHash,
        array $content,
        CarbonImmutable $observed
    ): PortalArchiveVersion {
        if ($version !== null) {
            $version->forceFill([
                'status' => PortalArchiveVersion::STATUS_TRUSTED,
                'findings' => null,
                'trusted_at' => $observed,
                'last_seen_at' => $observed,
                'seen_count' => $version->seen_count + 1,
            ])->save();

            return $version;
        }

        $version = PortalArchiveVersion::query()->create([
            'owner_hash' => $ownerHash,
            'dataset' => $dataset,
            'params_hash' => $paramsHash,
            'params' => $params,
            'subject' => $this->subject($dataset, $content),
            'content_hash' => $contentHash,
            'sighting' => 0,
            'payload' => $content,
            'status' => PortalArchiveVersion::STATUS_TRUSTED,
            'first_seen_at' => $observed,
            'last_seen_at' => $observed,
            'seen_count' => 1,
            'trusted_at' => $observed,
        ]);

        $this->prune($ownerHash, $dataset, $paramsHash);

        return $version;
    }

    /**
     * Keep a build that broke a guard as a suspect version. A version the
     * student already rejected stays rejected when the portal shows it again;
     * a dismissed one is suspect again. Content MyCIC trusted before is never
     * turned into a suspect: the sighting is a new row (the next `sighting`),
     * so the trusted version keeps its status, stays the baseline of the
     * versions after it, and rejecting the sighting does not mark a state the
     * portal really had as rejected.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $content
     * @param  list<array{rule: string, label: string, message: string}>  $findings
     */
    private function holdBack(
        ?PortalArchiveVersion $version,
        string $ownerHash,
        string $dataset,
        array $params,
        string $paramsHash,
        string $contentHash,
        array $content,
        array $findings,
        CarbonImmutable $observed
    ): PortalArchiveVersion {
        if ($version !== null && $version->status !== PortalArchiveVersion::STATUS_TRUSTED) {
            $version->forceFill([
                'status' => $version->status === PortalArchiveVersion::STATUS_REJECTED
                    ? PortalArchiveVersion::STATUS_REJECTED
                    : PortalArchiveVersion::STATUS_SUSPECT,
                'findings' => $findings,
                'last_seen_at' => $observed,
                'seen_count' => $version->seen_count + 1,
                'resolved_at' => $version->status === PortalArchiveVersion::STATUS_REJECTED ? $version->resolved_at : null,
            ])->save();

            return $version;
        }

        $version = PortalArchiveVersion::query()->create([
            'owner_hash' => $ownerHash,
            'dataset' => $dataset,
            'params_hash' => $paramsHash,
            'params' => $params,
            'subject' => $this->subject($dataset, $content),
            'content_hash' => $contentHash,
            'sighting' => $version !== null ? $version->sighting + 1 : 0,
            'payload' => $content,
            'status' => PortalArchiveVersion::STATUS_SUSPECT,
            'findings' => $findings,
            'first_seen_at' => $observed,
            'last_seen_at' => $observed,
            'seen_count' => 1,
        ]);

        $this->prune($ownerHash, $dataset, $paramsHash);

        return $version;
    }

    private function touch(PortalArchiveVersion $version, CarbonImmutable $observed): void
    {
        $version->forceFill([
            'last_seen_at' => $observed,
            'seen_count' => $version->seen_count + 1,
        ])->save();
    }

    /**
     * The portal shows trusted data again: what it showed in between was a glitch.
     */
    private function dismissOpenSuspects(string $ownerHash, string $dataset, string $paramsHash): void
    {
        PortalArchiveVersion::query()
            ->forOwner($ownerHash)
            ->forDataset($dataset, $paramsHash)
            ->where('status', PortalArchiveVersion::STATUS_SUSPECT)
            ->update([
                'status' => PortalArchiveVersion::STATUS_DISMISSED,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Keep the newest `portal.archive.max_versions` versions of one dataset;
     * the current trusted version and open suspects are never pruned.
     */
    private function prune(string $ownerHash, string $dataset, string $paramsHash): void
    {
        $keep = max(2, (int) config('portal.archive.max_versions', 30));
        $current = $this->currentTrusted($ownerHash, $dataset, $paramsHash);

        $stale = PortalArchiveVersion::query()
            ->forOwner($ownerHash)
            ->forDataset($dataset, $paramsHash)
            ->where('status', '!=', PortalArchiveVersion::STATUS_SUSPECT)
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->id))
            ->latest('last_seen_at')
            ->latest('id')
            ->skip($keep - 1)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            PortalArchiveVersion::query()->whereKey($stale->all())->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function subject(string $dataset, array $content): ?string
    {
        foreach ((array) config("portal.datasets.{$dataset}.archive.subject", []) as $path) {
            $value = is_string($path) ? data_get($content, $path) : null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 191);
            }
        }

        return null;
    }

    /**
     * Tell the student a build was held back. The link names the parameter
     * group as well, since a class, meeting or KRS has its own history. A
     * dismissed version the portal shows again is announced again: its
     * notification is told apart by the moment it was `$reopenedAt`.
     */
    private function announceSuspect(string $sessionToken, PortalArchiveVersion $version, ?CarbonImmutable $reopenedAt = null): void
    {
        $label = $this->label($version->dataset);
        $firstFinding = $version->findings[0]['message'] ?? null;
        $transition = 'archive_suspect|'.$version->id.($reopenedAt !== null ? '|'.$reopenedAt->toISOString() : '');

        $this->notifications->publish($sessionToken, [[
            'type' => 'archive_suspect',
            'dataset' => $version->dataset,
            'record_key' => 'version:'.$version->id,
            'dedupe_key' => hash('sha256', $transition),
            'title' => "Data {$label} di portal berubah tidak wajar",
            'body' => trim(($firstFinding ?? '').' MyCIC tetap menampilkan data tersimpan sampai kamu memeriksanya.'),
            'url' => '/archive/'.$version->dataset.'?'.http_build_query(['params_hash' => $version->params_hash, 'version' => $version->id]),
            'urgency' => 'warning',
        ]]);
    }

    private function isStudent(string $ownerHash): bool
    {
        return str_starts_with($ownerHash, 'student_');
    }
}
