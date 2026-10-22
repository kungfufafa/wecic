<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Read models served by the API, one per dataset (and parameter set).
 *
 * Snapshots are owned by the student, retained for a week, and carry their own
 * sync state. Staleness has two sources: age beyond the dataset's `fresh_for`
 * window, or an explicit invalidation (e.g. after uploading an assignment)
 * recorded as a marker that covers every parameter variant of the dataset.
 */
class PortalSnapshotStore
{
    private const DEFAULT_FRESH_FOR_SECONDS = 600;

    public function __construct(private ?PortalDataOwner $owner = null) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array{
     *     data: array<string, mixed>,
     *     synced_at: string,
     *     status: string,
     *     source: string,
     *     updated_at?: string,
     *     syncing_since?: string,
     *     failed_at?: string,
     *     error?: string|null,
     *     integrity?: array<string, mixed>|null
     * }|null
     */
    public function get(string $sessionToken, string $domain, array $params = []): ?array
    {
        $payload = Cache::get($this->cacheKey($sessionToken, $domain, $params));

        if (! is_array($payload) || ! isset($payload['data'])) {
            return null;
        }

        return $payload;
    }

    /**
     * `source` is `mycic_archive` for a snapshot restored from the archive;
     * `integrity` describes a portal version MyCIC holds back (see
     * PortalDataArchive) while serving the trusted data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $integrity
     */
    public function put(
        string $sessionToken,
        string $domain,
        array $data,
        ?string $syncedAt = null,
        array $params = [],
        string $source = 'campus_portal',
        ?array $integrity = null
    ): void {
        $now = now()->toISOString();

        Cache::put($this->cacheKey($sessionToken, $domain, $params), [
            'data' => $data,
            'synced_at' => $syncedAt ?? $now,
            'status' => 'synced',
            'source' => $source,
            'updated_at' => $now,
            'error' => null,
            ...($integrity !== null ? ['integrity' => $integrity] : []),
        ], $this->retention());
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $integrity
     */
    public function setIntegrity(string $sessionToken, string $domain, ?array $integrity, array $params = []): void
    {
        $this->updateState($sessionToken, $domain, $params, ['integrity' => $integrity]);
    }

    /**
     * Replace the data of an existing snapshot while keeping its sync state,
     * so a pending invalidation still triggers the background re-sync.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $params
     */
    public function replaceData(string $sessionToken, string $domain, array $data, array $params = []): void
    {
        $this->updateState($sessionToken, $domain, $params, ['data' => $data]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function has(string $sessionToken, string $domain, array $params = []): bool
    {
        return $this->get($sessionToken, $domain, $params) !== null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function markSyncing(string $sessionToken, string $domain, array $params = []): void
    {
        $this->updateState($sessionToken, $domain, $params, ['status' => 'syncing', 'syncing_since' => now()->toISOString()]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function markFailed(string $sessionToken, string $domain, string $error, array $params = []): void
    {
        $this->updateState($sessionToken, $domain, $params, ['status' => 'failed', 'error' => $error, 'failed_at' => now()->toISOString()]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function isStale(string $sessionToken, string $domain, ?int $maxAgeSeconds = null, array $params = []): bool
    {
        $snapshot = $this->get($sessionToken, $domain, $params);

        if ($snapshot === null) {
            return true;
        }

        $maxAgeSeconds ??= $this->freshFor($domain);

        return $this->ageInSeconds($snapshot) > $maxAgeSeconds
            || $this->isInvalidated($sessionToken, $domain, $snapshot);
    }

    /**
     * Mark every snapshot of the given datasets (all parameter variants) as
     * outdated without discarding it, so it can still be served while a
     * re-sync runs.
     */
    public function markStale(string $sessionToken, string ...$domains): void
    {
        $ownerKey = $this->ownerKey($sessionToken);

        foreach ($domains as $domain) {
            Cache::put($this->staleMarkerKey($ownerKey, $domain), now()->toISOString(), $this->retention());
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function isInvalidated(string $sessionToken, string $domain, array $snapshot): bool
    {
        $invalidatedAt = Cache::get($this->staleMarkerKey($this->ownerKey($sessionToken), $domain));

        if (! is_string($invalidatedAt) || ! isset($snapshot['synced_at'])) {
            return false;
        }

        return CarbonImmutable::parse($snapshot['synced_at'])->lessThanOrEqualTo(CarbonImmutable::parse($invalidatedAt));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function ageInSeconds(array $snapshot): float
    {
        if (! isset($snapshot['synced_at'])) {
            return PHP_FLOAT_MAX;
        }

        return max(0.0, CarbonImmutable::parse($snapshot['synced_at'])->diffInSeconds(now(), true));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function forget(string $sessionToken, string $domain, array $params = []): void
    {
        Cache::forget($this->cacheKey($sessionToken, $domain, $params));
    }

    public function freshFor(string $domain): int
    {
        return (int) config("portal.datasets.{$domain}.fresh_for", self::DEFAULT_FRESH_FOR_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $state
     */
    private function updateState(string $sessionToken, string $domain, array $params, array $state): void
    {
        $snapshot = $this->get($sessionToken, $domain, $params);

        if ($snapshot === null) {
            return;
        }

        Cache::put($this->cacheKey($sessionToken, $domain, $params), [...$snapshot, ...$state], $this->retention());
    }

    private function retention(): int
    {
        return (int) config('portal.sync.snapshot_retention', 604800);
    }

    private function ownerKey(string $sessionToken): string
    {
        $this->owner ??= app(PortalDataOwner::class);

        return $this->owner->key($sessionToken);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function cacheKey(string $sessionToken, string $domain, array $params): string
    {
        return 'portal_snapshot_'.md5($this->ownerKey($sessionToken).'|'.$domain.'|'.PortalDataCache::paramsHash($params));
    }

    private function staleMarkerKey(string $ownerKey, string $domain): string
    {
        return 'portal_snapshot_stale_'.md5($ownerKey.'|'.$domain);
    }
}
