<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
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
 */
class PortalDatasetRepository
{
    public function __construct(
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalDatasetBuilder $builder,
        private readonly PortalFetchPolicy $fetchPolicy,
        private readonly PortalDataCache $dataCache,
        private readonly PortalDataOwner $owner
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
        $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

        if ($snapshot !== null && (! $forceFresh || $this->isWithinForceCooldown($sessionToken, $dataset, $snapshot))) {
            return $this->serveSnapshot($sessionToken, $dataset, $params, $snapshot, $forceFresh, $broadcastSessionId);
        }

        try {
            return $this->syncOnce($sessionToken, $dataset, $params, $forceFresh);
        } catch (\Throwable $throwable) {
            if ($snapshot === null || PortalException::indicatesExpiredSession($throwable)) {
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
                'error' => $throwable->getMessage(),
            ]);
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
            if (! PortalException::indicatesExpiredSession($throwable)) {
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

        if ($this->isStale($sessionToken, $dataset, $snapshot) && ! $this->isSyncInFlight($snapshot) && ! $this->isBackingOffAfterFailure($snapshot)) {
            RefreshPortalDataJob::dispatch((string) $broadcastSessionId, $sessionToken, [$dataset], $params);
            $this->snapshots->markSyncing($sessionToken, $dataset, $params);
            $status = 'syncing';
        }

        return $this->snapshotResult($snapshot, array_filter([
            'status' => $status,
            'requested_fresh' => $forceFresh,
            'error' => $status === 'failed' ? ($snapshot['error'] ?? null) : null,
        ], static fn (mixed $value): bool => $value !== null));
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

        $this->snapshots->put($sessionToken, $dataset, $data, $startedAt, $params);

        return [
            'data' => $data,
            'meta' => [
                'data_origin' => $syncMeta['data_origin'] ?? 'upstream',
                'synced_at' => $startedAt,
                'status' => 'synced',
                'requested_fresh' => $forceFresh,
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
                'data_origin' => 'snapshot',
                'synced_at' => $snapshot['synced_at'] ?? null,
                'status' => $snapshot['status'] ?? 'synced',
                ...$meta,
            ],
        ];
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
}
