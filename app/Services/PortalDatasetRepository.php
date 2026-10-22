<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
use App\Exceptions\PortalOfflineException;
use App\Jobs\RefreshPortalDataJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Snapshot-first access to portal datasets (stale-while-revalidate).
 *
 * - fresh snapshot      → served as is
 * - stale snapshot      → served immediately, one background re-sync is queued
 * - missing snapshot    → built inline once; concurrent callers wait for it
 * - forced (`fresh=1`)  → rebuilt inline from upstream, unless a sync already
 *                         finished moments ago and nothing invalidated it since
 *
 * A failed rebuild never hides data the student already has: the previous
 * snapshot is served with a `failed` status instead. Only an expired campus
 * session propagates, because that requires a new login.
 *
 * Archived datasets (PortalDataArchive) outlive their snapshot: a missing
 * snapshot is restored from the archive and treated as stale, and a build
 * that looks like the portal lost data keeps serving the trusted version.
 * An offline session (PortalOfflineAccess) only ever reads snapshots and the
 * archive, with status `offline`.
 */
class PortalDatasetRepository
{
    /**
     * What `meta.error` says about a failed sync. The exception text (portal
     * URLs, cURL or SQL errors) stays in the log and never reaches the browser.
     */
    public const SYNC_FAILED_CODE = 'sync_failed';

    public function __construct(
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalDatasetBuilder $builder,
        private readonly PortalFetchPolicy $fetchPolicy,
        private readonly PortalDataCache $dataCache,
        private readonly PortalDataOwner $owner,
        private readonly PortalActionProjector $projector,
        private readonly PortalSnapshotDiffNotifier $notifier,
        private readonly PortalDataArchive $archive,
        private readonly PortalOfflineAccess $offlineAccess,
        private readonly PortalArchivedFileStore $archivedFiles,
        private readonly NotebookCourseSync $courseNotebooks
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function read(
        string $sessionToken,
        string $dataset,
        array $params = [],
        bool $forceFresh = false,
        ?string $broadcastSessionId = null
    ): array {
        $snapshot = $this->snapshotOrArchive($sessionToken, $dataset, $params);

        if ($this->offlineAccess->isOfflineSession($sessionToken)) {
            return $snapshot !== null
                ? $this->snapshotResult($snapshot, ['status' => 'offline', 'requested_fresh' => $forceFresh])
                : throw PortalOfflineException::offlineSession();
        }

        if ($snapshot !== null && (! $forceFresh || $this->isWithinForceCooldown($sessionToken, $dataset, $snapshot))) {
            return $this->serveSnapshot($sessionToken, $dataset, $params, $snapshot, $forceFresh, $broadcastSessionId);
        }

        try {
            return $this->syncOnce($sessionToken, $dataset, $params, $forceFresh);
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            if ($snapshot === null) {
                $this->recordBuildFailure($sessionToken, $dataset, $params);

                throw $throwable;
            }

            Log::warning('Portal dataset sync failed, serving previous snapshot', [
                'dataset' => $dataset,
                'error' => $throwable->getMessage(),
            ]);
            $this->snapshots->markFailed($sessionToken, $dataset, $throwable->getMessage(), $params);

            return $this->snapshotResult($snapshot, [
                'status' => 'failed',
                'requested_fresh' => $forceFresh,
                'error' => self::SYNC_FAILED_CODE,
            ]);
        }
    }

    /**
     * Snapshot-only read for secondary data (dashboard metrics, action center,
     * insights on another page). It never contacts the portal: a stale
     * snapshot is served while one background re-sync runs, exactly like
     * read(); a missing snapshot is queued for a background build and comes
     * back as `data: null` with status `pending`.
     *
     * @param  array<string, mixed>  $params
     * @return array{data: array<string, mixed>|null, meta: array<string, mixed>}
     */
    public function peek(
        string $sessionToken,
        string $dataset,
        array $params = [],
        ?string $broadcastSessionId = null,
        bool $queueWhenMissing = true
    ): array {
        $snapshot = $this->snapshotOrArchive($sessionToken, $dataset, $params);

        if ($this->offlineAccess->isOfflineSession($sessionToken)) {
            return $snapshot !== null
                ? $this->snapshotResult($snapshot, ['status' => 'offline'])
                : $this->missingResult('offline');
        }

        if ($snapshot !== null) {
            return $this->serveSnapshot($sessionToken, $dataset, $params, $snapshot, false, $broadcastSessionId);
        }

        if ($this->buildFailedRecently($sessionToken, $dataset, $params)) {
            return $this->missingResult('failed');
        }

        if ($queueWhenMissing) {
            $this->queueSync($sessionToken, [$dataset], $params, $broadcastSessionId);
        }

        return $this->missingResult($queueWhenMissing ? 'pending' : 'missing');
    }

    /**
     * peek() for several datasets at once, queueing at most ONE background
     * job for all of them: missing snapshots come back `pending`, stale ones
     * `syncing`, and the job's scope shares the pages they have in common.
     *
     * Only the datasets in `$refreshable` (all of them when null) are built or
     * re-synced; the others are reuse-only: their snapshot is served as is,
     * stale or not, and a missing one comes back `missing`. A heavy crawl
     * (pending assignments) is therefore never started by a secondary read.
     * A dataset that cannot be read, or whose background build just failed
     * without leaving a snapshot, comes back `failed`.
     *
     * @param  list<string>  $datasets
     * @param  list<string>|null  $refreshable
     * @return array<string, array{data: array<string, mixed>|null, meta: array<string, mixed>}>
     */
    public function peekMany(string $sessionToken, array $datasets, ?string $broadcastSessionId = null, ?array $refreshable = null): array
    {
        $results = [];
        $toSync = [];
        $offline = $this->offlineAccess->isOfflineSession($sessionToken);

        foreach (array_values(array_unique($datasets)) as $dataset) {
            $canRefresh = ! $offline && ($refreshable === null || in_array($dataset, $refreshable, true));

            try {
                $snapshot = $this->snapshotOrArchive($sessionToken, $dataset, []);

                if ($snapshot === null) {
                    $failed = $this->buildFailedRecently($sessionToken, $dataset, []);
                    $results[$dataset] = $this->missingResult($offline ? 'offline' : ($failed ? 'failed' : 'missing'));

                    if ($canRefresh && ! $failed) {
                        $toSync[] = $dataset;
                    }

                    continue;
                }

                $results[$dataset] = $this->snapshotResult($snapshot, $offline ? ['status' => 'offline'] : $this->failureMeta($snapshot));

                if ($canRefresh && $this->needsBackgroundSync($sessionToken, $dataset, $snapshot)) {
                    $toSync[] = $dataset;
                }
            } catch (\Throwable $throwable) {
                Log::warning('Portal dataset snapshot could not be read', [
                    'dataset' => $dataset,
                    'error' => $throwable->getMessage(),
                ]);
                $results[$dataset] = $this->missingResult('failed');
            }
        }

        if ($toSync === []) {
            return $results;
        }

        try {
            $queued = $this->queueSync($sessionToken, $toSync, [], $broadcastSessionId);
        } catch (\Throwable $throwable) {
            Log::warning('Background sync of portal datasets could not be queued', [
                'datasets' => $toSync,
                'error' => $throwable->getMessage(),
            ]);

            return $results;
        }

        foreach ($toSync as $dataset) {
            if ($results[$dataset]['data'] === null) {
                // Queued now or by an earlier call whose build is still running.
                $results[$dataset]['meta']['status'] = 'pending';
            } elseif (in_array($dataset, $queued, true)) {
                $results[$dataset]['meta']['status'] = 'syncing';
            }
        }

        return $results;
    }

    /**
     * Queue one background sync for the datasets whose snapshot is missing or
     * stale, without waiting for it. A dataset already being synced (or
     * backing off after a failure) is left alone, and a missing one is queued
     * at most once per sync lock window, so repeated calls never pile up jobs.
     *
     * @param  list<string>  $datasets
     * @param  array<string, mixed>  $params
     * @return list<string> The datasets this call queued.
     */
    public function queueSync(
        string $sessionToken,
        array $datasets,
        array $params = [],
        ?string $broadcastSessionId = null,
        int $delaySeconds = 0
    ): array {
        $queued = [];
        $markers = [];

        if ($this->offlineAccess->isOfflineSession($sessionToken)) {
            return [];
        }

        foreach (array_values(array_unique($datasets)) as $dataset) {
            if (! is_array(config("portal.datasets.{$dataset}"))) {
                continue;
            }

            $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

            if ($snapshot === null) {
                if ($this->buildFailedRecently($sessionToken, $dataset, $params)) {
                    continue;
                }

                $marker = $this->queuedKey($sessionToken, $dataset, $params);

                if (Cache::add($marker, now()->toISOString(), (int) config('portal.sync.lock_ttl', 300))) {
                    $queued[] = $dataset;
                    $markers[] = $marker;
                }

                continue;
            }

            if ($this->needsBackgroundSync($sessionToken, $dataset, $snapshot)) {
                $this->snapshots->markSyncing($sessionToken, $dataset, $params);
                $queued[] = $dataset;
            }
        }

        if ($queued === []) {
            return [];
        }

        try {
            $job = new RefreshPortalDataJob((string) $broadcastSessionId, $sessionToken, $queued, $params);

            if ($delaySeconds > 0) {
                $job->delay(now()->addSeconds($delaySeconds));
            }

            dispatch($job);
        } catch (\Throwable $throwable) {
            // Nothing is queued after all: let the next read try again.
            foreach ($markers as $marker) {
                Cache::forget($marker);
            }

            throw $throwable;
        }

        return $queued;
    }

    /**
     * Queue the decision datasets (`portal.sync.warmup_datasets`) that are
     * missing or stale in one background job, so their pages and the action
     * center open from a snapshot later. Never throws: a warm-up must not
     * fail the login or keep-alive that triggers it.
     *
     * @return list<string> The datasets this call queued.
     */
    public function warmUp(string $sessionToken, ?string $broadcastSessionId = null, int $delaySeconds = 0): array
    {
        try {
            return $this->queueSync(
                $sessionToken,
                array_values(array_filter((array) config('portal.sync.warmup_datasets', []), 'is_string')),
                [],
                $broadcastSessionId,
                $delaySeconds
            );
        } catch (\Throwable $throwable) {
            Log::warning('Portal dataset warm-up could not be queued', [
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Rebuild a dataset from upstream, bypassing resource caches, and store it.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function sync(string $sessionToken, string $dataset, array $params = []): array
    {
        try {
            return $this->syncOnce($sessionToken, $dataset, $params, true)['data'];
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            if ($this->snapshots->get($sessionToken, $dataset, $params) === null) {
                $this->recordBuildFailure($sessionToken, $dataset, $params);
            } else {
                $this->snapshots->markFailed($sessionToken, $dataset, $throwable->getMessage(), $params);
            }

            throw $throwable;
        }
    }

    /**
     * The snapshot when it is still fresh, otherwise a forced re-sync. Meant
     * for background work that needs current data but must not queue more jobs.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function ensureFresh(string $sessionToken, string $dataset, array $params = []): array
    {
        $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

        if ($snapshot !== null && ! $this->isStale($sessionToken, $dataset, $snapshot)) {
            return $snapshot['data'];
        }

        return $this->sync($sessionToken, $dataset, $params);
    }

    /**
     * Record that a portal action changed upstream state: snapshots of the
     * datasets become stale (still served, re-synced on next read) and the
     * listed resources are dropped from the resource cache.
     *
     * @param  list<string>  $datasets
     * @param  list<string>  $resources
     */
    public function invalidate(string $sessionToken, array $datasets, array $resources = []): void
    {
        $this->snapshots->markStale($sessionToken, ...$datasets);
        $this->dataCache->invalidate($sessionToken, ...$resources);
    }

    /**
     * @return array{cache_keys: list<string>, sync_keys: list<string>}
     */
    public static function realtimeKeys(string ...$datasets): array
    {
        $cacheKeys = [];
        $syncKeys = [];

        foreach ($datasets as $dataset) {
            array_push($cacheKeys, ...config("portal.datasets.{$dataset}.cache_keys", []));
            array_push($syncKeys, ...config("portal.datasets.{$dataset}.sync_keys", []));
        }

        return [
            'cache_keys' => array_values(array_unique($cacheKeys)),
            'sync_keys' => array_values(array_unique($syncKeys)),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $snapshot
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function serveSnapshot(
        string $sessionToken,
        string $dataset,
        array $params,
        array $snapshot,
        bool $forceFresh,
        ?string $broadcastSessionId
    ): array {
        $status = $snapshot['status'] ?? 'synced';

        if ($this->needsBackgroundSync($sessionToken, $dataset, $snapshot)) {
            RefreshPortalDataJob::dispatch((string) $broadcastSessionId, $sessionToken, [$dataset], $params);
            $this->snapshots->markSyncing($sessionToken, $dataset, $params);
            $status = 'syncing';
        }

        return $this->snapshotResult($snapshot, [
            'status' => $status,
            'requested_fresh' => $forceFresh,
            ...($status === 'failed' ? ['error' => self::SYNC_FAILED_CODE] : []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{error?: string}
     */
    private function failureMeta(array $snapshot): array
    {
        return ($snapshot['status'] ?? null) === 'failed' ? ['error' => self::SYNC_FAILED_CODE] : [];
    }

    /**
     * @return array{data: null, meta: array{data_origin: 'snapshot', synced_at: null, status: string}}
     */
    private function missingResult(string $status): array
    {
        return [
            'data' => null,
            'meta' => [
                'data_origin' => 'snapshot',
                'synced_at' => null,
                'status' => $status,
            ],
        ];
    }

    /**
     * A stale snapshot that is not already being re-synced and is not backing
     * off after a failed sync.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function needsBackgroundSync(string $sessionToken, string $dataset, array $snapshot): bool
    {
        return $this->isStale($sessionToken, $dataset, $snapshot)
            && ! $this->isSyncInFlight($snapshot)
            && ! $this->isBackingOffAfterFailure($snapshot);
    }

    /**
     * A build that failed before any snapshot existed leaves nothing to carry
     * a `failed` state, so a marker does: reads report the dataset `failed`
     * and background builds back off for `portal.sync.build_failure_backoff`
     * seconds instead of re-queueing the same failing crawl every lock window.
     *
     * @param  array<string, mixed>  $params
     */
    private function recordBuildFailure(string $sessionToken, string $dataset, array $params): void
    {
        Cache::forget($this->queuedKey($sessionToken, $dataset, $params));
        Cache::put(
            $this->buildFailedKey($sessionToken, $dataset, $params),
            now()->toISOString(),
            max(1, (int) config('portal.sync.build_failure_backoff', 900))
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function buildFailedRecently(string $sessionToken, string $dataset, array $params): bool
    {
        return Cache::has($this->buildFailedKey($sessionToken, $dataset, $params));
    }

    /**
     * Build the dataset once: a caller that had to wait for a concurrent
     * build of the same dataset reuses that result instead of scraping again.
     *
     * @param  array<string, mixed>  $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function syncOnce(string $sessionToken, string $dataset, array $params, bool $forceFresh): array
    {
        $requestedAt = CarbonImmutable::now();
        $lock = Cache::lock($this->lockKey($sessionToken, $dataset, $params), (int) config('portal.sync.lock_ttl', 300));

        try {
            return $lock->block((int) config('portal.sync.lock_wait', 20), function () use ($sessionToken, $dataset, $params, $forceFresh, $requestedAt): array {
                $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

                if ($snapshot !== null && $this->completedSince($snapshot, $requestedAt) && ! $this->snapshots->isInvalidated($sessionToken, $dataset, $snapshot)) {
                    return $this->snapshotResult($snapshot, ['requested_fresh' => $forceFresh]);
                }

                return $this->build($sessionToken, $dataset, $params, $forceFresh);
            });
        } catch (LockTimeoutException) {
            // A slow concurrent sync must not block this caller indefinitely.
            return $this->build($sessionToken, $dataset, $params, $forceFresh);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function build(string $sessionToken, string $dataset, array $params, bool $forceFresh): array
    {
        // The snapshot is stamped with the moment the build began, so an
        // invalidation that lands mid-build still marks the result stale.
        $startedAt = now()->toISOString();
        $data = $this->fetchPolicy->runFresh(
            fn (): array => $this->builder->build($sessionToken, $dataset, $params),
            $forceFresh
        );

        $syncMeta = is_array($data['_sync'] ?? null) ? $data['_sync'] : [];
        unset($data['_sync']);

        // Upstream pages can lag behind an action the portal just confirmed,
        // and a build that began before the action never saw it.
        $data = $this->projector->apply($sessionToken, $dataset, $params, $data);

        // A payload that looks like the portal lost data is held back; the
        // snapshot keeps serving the version MyCIC trusts.
        $archived = $this->archive->record($sessionToken, $dataset, $params, $data, $startedAt);

        $this->snapshots->put($sessionToken, $dataset, $archived['data'], $startedAt, $params, integrity: $archived['integrity']);
        Cache::forget($this->buildFailedKey($sessionToken, $dataset, $params));

        // What changed since the student last synced this dataset (K9).
        if ($archived['integrity'] === null) {
            $this->notifier->process($sessionToken, $dataset, $data, $params);
        }

        // Course files a meeting links to are copied into the archive in the
        // background, and the meeting joins its course notebook.
        if ($dataset === 'meeting_detail') {
            $this->archivedFiles->queueFromMeeting($sessionToken, $data, $params);
            $this->courseNotebooks->addOpenedMeeting($sessionToken, $archived['data'], $params);
        }

        // Every class in the class list gets its course notebook.
        if ($dataset === 'lms_classes' || $dataset === 'classes') {
            $this->courseNotebooks->provisionFromClasses($sessionToken, $archived['data']);
        }

        return [
            'data' => $archived['data'],
            'meta' => [
                'data_origin' => $syncMeta['data_origin'] ?? 'upstream',
                'synced_at' => $startedAt,
                'status' => 'synced',
                'requested_fresh' => $forceFresh,
                ...($archived['integrity'] !== null ? ['integrity' => $archived['integrity']] : []),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $meta
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function snapshotResult(array $snapshot, array $meta): array
    {
        return [
            'data' => $snapshot['data'],
            'meta' => [
                'data_origin' => ($snapshot['source'] ?? null) === PortalDataArchive::SOURCE ? 'archive' : 'snapshot',
                'synced_at' => $snapshot['synced_at'] ?? null,
                'status' => $snapshot['status'] ?? 'synced',
                ...(is_array($snapshot['integrity'] ?? null) ? ['integrity' => $snapshot['integrity']] : []),
                ...$meta,
            ],
        ];
    }

    /**
     * The snapshot, or else the archived trusted version stored as a stale
     * snapshot (stamped with when the portal last showed it), so it is served
     * at once and re-synced in the background like any stale snapshot.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private function snapshotOrArchive(string $sessionToken, string $dataset, array $params): ?array
    {
        $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

        if ($snapshot !== null) {
            return $snapshot;
        }

        $restored = $this->archive->restore($sessionToken, $dataset, $params);

        if ($restored === null) {
            return null;
        }

        $this->snapshots->put($sessionToken, $dataset, $restored['data'], $restored['seen_at'], $params, PortalDataArchive::SOURCE, $restored['integrity']);

        return $this->snapshots->get($sessionToken, $dataset, $params);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isStale(string $sessionToken, string $dataset, array $snapshot): bool
    {
        return $this->snapshots->ageInSeconds($snapshot) > $this->snapshots->freshFor($dataset)
            || $this->snapshots->isInvalidated($sessionToken, $dataset, $snapshot);
    }

    /**
     * Repeated forced reads (pull-to-refresh, a reload right after a
     * background sync) reuse a snapshot that was just synced, unless a portal
     * action invalidated it afterwards.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function isWithinForceCooldown(string $sessionToken, string $dataset, array $snapshot): bool
    {
        return ($snapshot['status'] ?? 'synced') === 'synced'
            && $this->snapshots->ageInSeconds($snapshot) < (int) config('portal.sync.force_cooldown', 30)
            && ! $this->snapshots->isInvalidated($sessionToken, $dataset, $snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isSyncInFlight(array $snapshot): bool
    {
        if (($snapshot['status'] ?? null) !== 'syncing' || ! isset($snapshot['syncing_since'])) {
            return false;
        }

        return CarbonImmutable::parse($snapshot['syncing_since'])
            ->addSeconds((int) config('portal.sync.lock_ttl', 300))
            ->isFuture();
    }

    /**
     * A sync that just failed (e.g. the portal is down) is not retried on
     * every read; the student keeps the previous data meanwhile.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function isBackingOffAfterFailure(array $snapshot): bool
    {
        if (($snapshot['status'] ?? null) !== 'failed' || ! isset($snapshot['failed_at'])) {
            return false;
        }

        return CarbonImmutable::parse($snapshot['failed_at'])
            ->addSeconds((int) config('portal.sync.failure_backoff', 120))
            ->isFuture();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function completedSince(array $snapshot, CarbonImmutable $moment): bool
    {
        return ($snapshot['status'] ?? null) === 'synced'
            && isset($snapshot['updated_at'])
            && CarbonImmutable::parse($snapshot['updated_at'])->greaterThanOrEqualTo($moment);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function lockKey(string $sessionToken, string $dataset, array $params): string
    {
        return 'portal_sync_'.md5($this->owner->key($sessionToken).'|'.$dataset.'|'.PortalDataCache::paramsHash($params));
    }

    /**
     * Marker of a background build queued for a dataset that has no snapshot
     * yet; there is no snapshot to carry a `syncing` state.
     *
     * @param  array<string, mixed>  $params
     */
    private function queuedKey(string $sessionToken, string $dataset, array $params): string
    {
        return 'portal_sync_queued_'.md5($this->owner->key($sessionToken).'|'.$dataset.'|'.PortalDataCache::paramsHash($params));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function buildFailedKey(string $sessionToken, string $dataset, array $params): string
    {
        return 'portal_sync_build_failed_'.md5($this->owner->key($sessionToken).'|'.$dataset.'|'.PortalDataCache::paramsHash($params));
    }
}
