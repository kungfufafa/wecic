<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalArchiveVersion;
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
 * data, the suspect version is dismissed on its own.
 *
 * Only students are archived (a session whose owner is unknown is not), and
 * the archive never fails the sync that feeds it.
 *
 * @phpstan-type Integrity array{status: string, version_id: int, findings: list<array<string, mixed>>, detected_at: string|null, trusted_seen_at: string|null}
 */
class PortalDataArchive
{
    public const SOURCE = 'mycic_archive';

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
            $observed = $observedAt !== null ? CarbonImmutable::parse($observedAt) : CarbonImmutable::now();
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
                ->first();

            if ($findings === []) {
                $this->trust($version, $ownerHash, $dataset, $params, $paramsHash, $contentHash, $content, $observed);
                $this->dismissOpenSuspects($ownerHash, $dataset, $paramsHash);

                return $passThrough;
            }

            $version = $this->holdBack($version, $ownerHash, $dataset, $params, $paramsHash, $contentHash, $content, $findings, $observed);

            if ($version->wasRecentlyCreated) {
                $this->announceSuspect($sessionToken, $version);
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
     * before a trusted one, the current trusted one for anything else.
     */
    public function baseline(PortalArchiveVersion $version): ?PortalArchiveVersion
    {
        $query = PortalArchiveVersion::query()
            ->forOwner($version->owner_hash)
            ->forDataset($version->dataset, $version->params_hash)
            ->trusted()
            ->whereKeyNot($version->id);

        if ($version->status === PortalArchiveVersion::STATUS_TRUSTED && $version->trusted_at !== null) {
            $query->where('trusted_at', '<', $version->trusted_at);
        }

        return $query->latest('trusted_at')->first();
    }

    /**
     * Leaf-level differences between two payloads, as readable paths.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{path: string, kind: 'added'|'removed'|'changed', old: string|null, new: string|null}>
     */
    public function diff(array $old, array $new, int $limit = 200): array
    {
        $volatile = $this->volatileKeys();
        $oldLeaves = $this->leaves($old, $volatile);
        $newLeaves = $this->leaves($new, $volatile);
        $changes = [];

        foreach (array_keys($oldLeaves + $newLeaves) as $path) {
            $before = $oldLeaves[$path] ?? null;
            $after = $newLeaves[$path] ?? null;

            if ($before === $after) {
                continue;
            }

            $changes[] = [
                'path' => $this->readablePath((string) $path),
                'kind' => match (true) {
                    $before === null => 'added',
                    $after === null => 'removed',
                    default => 'changed',
                },
                'old' => $before,
                'new' => $after,
            ];

            if (count($changes) >= $limit) {
                break;
            }
        }

        return $changes;
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
     * @return array{groups: list<array{params_hash: string, subject: string|null, params: array<string, mixed>, versions: int, open_suspects: int, last_seen_at: string|null}>, versions: list<array<string, mixed>>}
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

        $selected = $paramsHash ?? ($groups[0]['params_hash'] ?? null);
        $current = $selected !== null ? $this->currentTrusted($ownerHash, $dataset, $selected) : null;

        return [
            'groups' => $groups,
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

    private function readablePath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => ctype_digit($segment) ? '#'.((int) $segment + 1) : str_replace('_', ' ', $segment),
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
        if ($version !== null) {
            // A version the student already rejected stays rejected.
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

    private function announceSuspect(string $sessionToken, PortalArchiveVersion $version): void
    {
        $label = $this->label($version->dataset);
        $firstFinding = $version->findings[0]['message'] ?? null;

        $this->notifications->publish($sessionToken, [[
            'type' => 'archive_suspect',
            'dataset' => $version->dataset,
            'record_key' => 'version:'.$version->id,
            'dedupe_key' => hash('sha256', 'archive_suspect|'.$version->id),
            'title' => "Data {$label} di portal berubah tidak wajar",
            'body' => trim(($firstFinding ?? '').' MyCIC tetap menampilkan data tersimpan sampai kamu memeriksanya.'),
            'url' => '/archive/'.$version->dataset.'?version='.$version->id,
            'urgency' => 'warning',
        ]]);
    }

    private function isStudent(string $ownerHash): bool
    {
        return str_starts_with($ownerHash, 'student_');
    }
}
