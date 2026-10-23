<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
use App\Exceptions\PortalOfflineException;
use App\Jobs\RefreshPortalDataJob;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Queue\InteractsWithUniqueJobs;
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
    use InteractsWithUniqueJobs;

    /**
     * What `meta.error` says about a failed sync. The exception text (portal
     * URLs, cURL or SQL errors) stays in the log and never reaches the browser.
     */
    public const SYNC_FAILED_CODE = 'sync_failed';

    /**
     * Seconds a meeting snapshot whose submission status is `unresolved`
     * stays fresh (instead of the dataset's `fresh_for`).
     */
    public const UNRESOLVED_MEETING_FRESH_FOR = 60;

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
        $result = $this->readSnapshotOrUpstream($sessionToken, $dataset, $params, $forceFresh, $broadcastSessionId);

        if ($dataset === 'meeting_detail' && $this->meetingSkippedByFullNotebook($sessionToken, $params, $result['data'])) {
            $result['meta']['notebook_limit_reached'] = true;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function readSnapshotOrUpstream(
        string $sessionToken,
        string $dataset,
        array $params,
        bool $forceFresh,
        ?string $broadcastSessionId
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
                // A failed sync that is being retried reads like any re-sync.
                $results[$dataset]['meta']['status'] = 'syncing';
                unset($results[$dataset]['meta']['error']);
            }
        }

        return $results;
    }

    /**
     * Whether the dataset's stored snapshot is still fresh: younger than its
     * `fresh_for` and not invalidated by a portal action since. A snapshot
     * restored from the archive carries the age of when the portal last
     * showed it. Reads the snapshot store only: it never contacts the
     * portal, queues a sync or restores the archive.
     *
     * @param  array<string, mixed>  $params
     */
    public function hasFreshSnapshot(string $sessionToken, string $dataset, array $params = []): bool
    {
        $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

        return $snapshot !== null && ! $this->isStale($sessionToken, $dataset, $snapshot);
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
        return $this->queueBackgroundSync($sessionToken, $datasets, $params, $broadcastSessionId, $delaySeconds, false);
    }

    /**
     * queueSync() for a snapshot that is still fresh but must not be trusted
     * as is: a forced read of data it belongs to, or a snapshot that
     * describes another day. Nothing is queued while a sync of it runs,
     * moments after one finished (`force_cooldown`), or while backing off
     * after a failure. Never builds inline.
     *
     * @param  array<string, mixed>  $params
     */
    public function queueEarlyResync(string $sessionToken, string $dataset, array $params = [], ?string $broadcastSessionId = null): bool
    {
        return $this->queueBackgroundSync($sessionToken, [$dataset], $params, $broadcastSessionId, 0, true) !== [];
    }

    /**
     * queueEarlyResync(), but synced right here by the background job that
     * calls it instead of queued behind everything else on the queue: for a
     * dataset that must follow the one the job just re-synced. The same
     * guards apply (a sync of it queued or running, `force_cooldown`, the
     * back-off after a failure), and reads meanwhile report it `syncing`.
     * A failed sync is recorded like any background sync's and rethrown.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null The synced data, null when no sync was due.
     */
    public function resyncEarlyInline(string $sessionToken, string $dataset, array $params = []): ?array
    {
        if ($this->offlineAccess->isOfflineSession($sessionToken) || ! is_array(config("portal.datasets.{$dataset}"))) {
            return null;
        }

        $claim = $this->claimBackgroundSync($sessionToken, $dataset, $params, true);

        if ($claim === null) {
            return null;
        }

        try {
            return $this->sync($sessionToken, $dataset, $params);
        } catch (\Throwable $throwable) {
            // sync() records every other failure; after an expired session the next read may queue it again.
            if (PortalException::indicatesExpiredSession($throwable)) {
                $this->releaseBackgroundSync($sessionToken, $dataset, $params, $claim);
            }

            throw $throwable;
        }
    }

    /**
     * @param  list<string>  $datasets
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function queueBackgroundSync(
        string $sessionToken,
        array $datasets,
        array $params,
        ?string $broadcastSessionId,
        int $delaySeconds,
        bool $evenIfFresh
    ): array {
        $claims = [];

        if ($this->offlineAccess->isOfflineSession($sessionToken)) {
            return [];
        }

        foreach (array_values(array_unique($datasets)) as $dataset) {
            if (! is_array(config("portal.datasets.{$dataset}"))) {
                continue;
            }

            $claim = $this->claimBackgroundSync($sessionToken, $dataset, $params, $evenIfFresh);

            if ($claim !== null) {
                $claims[$dataset] = $claim;
            }
        }

        if ($claims === []) {
            return [];
        }

        $queued = array_keys($claims);

        try {
            $job = new RefreshPortalDataJob((string) $broadcastSessionId, $sessionToken, $queued, $params);

            if ($delaySeconds > 0) {
                $job->delay(now()->addSeconds($delaySeconds));
            }

            // An identical job already queued or running syncs these datasets, so the claims stand.
            $this->dispatchUnique($job);
        } catch (\Throwable $throwable) {
            // Nothing is queued after all: let the next read try again.
            foreach ($claims as $dataset => $claim) {
                $this->releaseBackgroundSync($sessionToken, $dataset, $params, $claim);
            }

            throw $throwable;
        }

        return $queued;
    }

    /**
     * Push the job unless an identical one (same session, datasets and
     * parameters) is already queued or running, as dispatch() does for a
     * ShouldBeUnique job, except that a push that fails gives back the
     * unique-job lock: otherwise every identical dispatch would be dropped
     * silently until it expires.
     *
     * Only the lock this call took is given back, by its owner token, so
     * the lock of an identical job is never released: not when it holds the
     * lock (nothing is pushed), and not when taking the lock fails (the
     * exception leaves before anything is released).
     *
     * @return bool Whether the job was pushed.
     */
    private function dispatchUnique(RefreshPortalDataJob $job): bool
    {
        $uniqueLock = Cache::lock(UniqueLock::getKey($job), $job->uniqueFor());

        if (! $uniqueLock->get()) {
            return false;
        }

        $this->addUniqueJobInformationToContext($job);

        try {
            app(BusDispatcher::class)->dispatch($job);
        } catch (\Throwable $throwable) {
            try {
                $uniqueLock->release();
            } catch (\Throwable) {
                // The cache that failed the push may not answer either; the lock then expires.
            }

            throw $throwable;
        } finally {
            $this->removeUniqueJobInformationFromContext($job);
        }

        return true;
    }

    /**
     * Claim the background sync of one dataset, so reads meanwhile neither
     * queue it again nor report it idle: a missing snapshot takes the queued
     * marker (at most once per sync lock window), a stored one is marked
     * `syncing`. Null when no sync is due: one is already claimed or
     * running, a failure is being backed off, or the snapshot is still fresh
     * (with `$evenIfFresh`: synced within `force_cooldown`).
     *
     * @param  array<string, mixed>  $params
     * @return array{marker: string|null, previous: array<string, mixed>|null}|null
     */
    private function claimBackgroundSync(string $sessionToken, string $dataset, array $params, bool $evenIfFresh): ?array
    {
        $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

        if ($snapshot === null) {
            if ($this->buildFailedRecently($sessionToken, $dataset, $params)) {
                return null;
            }

            $marker = $this->queuedKey($sessionToken, $dataset, $params);

            return Cache::add($marker, now()->toISOString(), (int) config('portal.sync.lock_ttl', 300))
                ? ['marker' => $marker, 'previous' => null]
                : null;
        }

        if (! $this->needsBackgroundSync($sessionToken, $dataset, $snapshot)
            && ! ($evenIfFresh && $this->mayResyncEarly($sessionToken, $dataset, $snapshot))) {
            return null;
        }

        $this->snapshots->markSyncing($sessionToken, $dataset, $params);

        return ['marker' => null, 'previous' => $snapshot];
    }

    /**
     * Give back a claim whose sync never ran, so the next read queues it again.
     *
     * @param  array<string, mixed>  $params
     * @param  array{marker: string|null, previous: array<string, mixed>|null}  $claim
     */
    private function releaseBackgroundSync(string $sessionToken, string $dataset, array $params, array $claim): void
    {
        if ($claim['marker'] !== null) {
            Cache::forget($claim['marker']);
        }

        if ($claim['previous'] !== null) {
            $this->snapshots->restoreSyncState($sessionToken, $dataset, $claim['previous'], $params);
        }
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
            try {
                if ($this->queueSync($sessionToken, [$dataset], $params, $broadcastSessionId) !== []) {
                    $status = 'syncing';
                }
            } catch (\Throwable $throwable) {
                // The snapshot is already in hand: serve it, the next read queues again.
                Log::warning('Background re-sync of a portal dataset could not be queued', [
                    'dataset' => $dataset,
                    'error' => $throwable->getMessage(),
                ]);
            }
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
     * A stale snapshot, or one whose last sync failed (a portal hiccup must
     * not keep a still-fresh snapshot reported `failed` for its whole
     * `fresh_for` window), that is not already being re-synced and is not
     * backing off after that failure.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function needsBackgroundSync(string $sessionToken, string $dataset, array $snapshot): bool
    {
        return ($this->isStale($sessionToken, $dataset, $snapshot) || ($snapshot['status'] ?? null) === 'failed')
            && ! $this->isSyncInFlight($snapshot)
            && ! $this->isBackingOffAfterFailure($snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function mayResyncEarly(string $sessionToken, string $dataset, array $snapshot): bool
    {
        return ! $this->isSyncInFlight($snapshot)
            && ! $this->isBackingOffAfterFailure($snapshot)
            && ! $this->isWithinForceCooldown($sessionToken, $dataset, $snapshot);
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
            // A slow concurrent sync of the same dataset is still running: serve
            // what is stored (it announces its result when done) instead of
            // crawling the portal a second time. Only a caller with nothing to
            // serve builds without the lock rather than wait indefinitely.
            $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

            if ($snapshot === null) {
                return $this->build($sessionToken, $dataset, $params, $forceFresh);
            }

            $finished = $this->completedSince($snapshot, $requestedAt) && ! $this->snapshots->isInvalidated($sessionToken, $dataset, $snapshot);

            return $this->snapshotResult($snapshot, [
                'requested_fresh' => $forceFresh,
                ...($finished ? [] : ['status' => 'syncing']),
            ]);
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
        // snapshot keeps serving the version MyCIC trusts. A meeting whose
        // submission could not be checked is not trusted over that version.
        $archived = $this->isUnresolvedMeeting($dataset, $data)
            ? ['data' => $data, 'integrity' => null]
            : $this->archive->record($sessionToken, $dataset, $params, $data, $startedAt);

        $this->snapshots->put($sessionToken, $dataset, $archived['data'], $startedAt, $params, integrity: $archived['integrity']);
        Cache::forget($this->buildFailedKey($sessionToken, $dataset, $params));

        // What changed since the student last synced this dataset (K9).
        if ($archived['integrity'] === null) {
            $this->notifier->process($sessionToken, $dataset, $data, $params);
        }

        // Course files a meeting of the student's own course links to are
        // copied into the archive in the background, and the meeting joins
        // its course notebook.
        if ($dataset === 'meeting_detail') {
            if ($this->takesMeetingCourse($sessionToken, $params)) {
                $this->archivedFiles->queueFromMeeting($sessionToken, $data, $params);
            }

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
        return $this->snapshots->ageInSeconds($snapshot) > $this->freshFor($dataset, $snapshot)
            || $this->snapshots->isInvalidated($sessionToken, $dataset, $snapshot);
    }

    /**
     * The dataset's `fresh_for`, cut short for a meeting whose submission
     * could not be checked: the class page cross-check is retried on the
     * next read instead of 15 minutes later.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function freshFor(string $dataset, array $snapshot): int
    {
        $freshFor = $this->snapshots->freshFor($dataset);

        return $this->isUnresolvedMeeting($dataset, $snapshot['data'] ?? null)
            ? min($freshFor, self::UNRESOLVED_MEETING_FRESH_FOR)
            : $freshFor;
    }

    /**
     * A meeting whose assignment submission could not be checked against
     * the class page (PortalMeetingDetailResolver, status `unresolved`).
     */
    private function isUnresolvedMeeting(string $dataset, mixed $data): bool
    {
        return $dataset === 'meeting_detail'
            && is_array($data)
            && ($data['assignment_info']['status'] ?? null) === 'unresolved';
    }

    /**
     * Files of a meeting are archived only for a course the student takes:
     * a meeting link of another class (shared by a classmate) archives
     * nothing. Never throws: archiving must not fail the build.
     *
     * @param  array<string, mixed>  $params
     */
    private function takesMeetingCourse(string $sessionToken, array $params): bool
    {
        try {
            return $this->courseNotebooks->takesCourse($sessionToken, (string) ($params['courses_id'] ?? ''));
        } catch (\Throwable $throwable) {
            Log::warning('Course of a meeting could not be checked for the file archive', [
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Whether an opened meeting stays out of its course notebook because
     * the notebook is full (NotebookCourseSync::addOpenedMeeting skips it),
     * so the meeting page can say so. A meeting already in the notebook, or
     * removed from it by the student, is not. Checked on every read: the
     * student may make room at any time. Never throws.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $meetingDetail
     */
    private function meetingSkippedByFullNotebook(string $sessionToken, array $params, array $meetingDetail): bool
    {
        $meetingId = (string) ($params['meeting_id'] ?? '');
        $courseKey = (string) ($params['courses_id'] ?? '');

        if (! $this->courseNotebooks->enabled() || ! (bool) config('notebook.course_notebooks.auto_add_opened_meetings', true)
            || ($meetingDetail['found'] ?? true) === false || ! ctype_digit($meetingId) || ! ctype_digit($courseKey)) {
            return false;
        }

        try {
            $notebook = $this->courseNotebooks->findCourseNotebook($this->owner->key($sessionToken), $courseKey);

            return $notebook !== null
                && ! $this->courseNotebooks->isMeetingDismissed($notebook, $meetingId)
                && ! $this->courseNotebooks->canAddMeeting($notebook, $meetingId);
        } catch (\Throwable $throwable) {
            Log::warning('Course notebook room of a meeting could not be checked', [
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Repeated forced reads (pull-to-refresh, a reload right after a
     * background sync) reuse a snapshot that was just synced, unless a portal
     * action invalidated it afterwards. The cooldown runs from when the sync
     * finished (`updated_at`), not from when it began (`synced_at`): a crawl
     * that took longer than the cooldown is otherwise repeated at once.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function isWithinForceCooldown(string $sessionToken, string $dataset, array $snapshot): bool
    {
        return ($snapshot['status'] ?? 'synced') === 'synced'
            && $this->secondsSinceSyncFinished($snapshot) < (int) config('portal.sync.force_cooldown', 30)
            && ! $this->snapshots->isInvalidated($sessionToken, $dataset, $snapshot);
    }

    /**
     * A build holds the sync lock, so it is stored within `lock_ttl` of
     * `synced_at`. A snapshot stored long after its data was read (restored
     * from the archive, for one) keeps the age of `synced_at`.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function secondsSinceSyncFinished(array $snapshot): float
    {
        $dataAge = $this->snapshots->ageInSeconds($snapshot);

        if (($snapshot['source'] ?? null) === PortalDataArchive::SOURCE || ! isset($snapshot['updated_at'])) {
            return $dataAge;
        }

        $storedAge = max(0.0, CarbonImmutable::parse($snapshot['updated_at'])->diffInSeconds(now(), true));

        return $dataAge - $storedAge <= (int) config('portal.sync.lock_ttl', 300) ? $storedAge : $dataAge;
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
