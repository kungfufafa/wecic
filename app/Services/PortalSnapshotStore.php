<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class PortalSnapshotStore
{
    private const DEFAULT_SNAPSHOT_TTL_SECONDS = 604800; // 7 days

    /**
     * @return array{
     *     data: array<string, mixed>,
     *     synced_at: string,
     *     status: string,
     *     source: string
     * }|null
     */
    public function get(string $sessionToken, string $domain): ?array
    {
        $payload = Cache::get($this->cacheKey($sessionToken, $domain));

        if (! is_array($payload) || ! isset($payload['data'])) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $sessionToken, string $domain, array $data, ?string $syncedAt = null): void
    {
        $now = now()->toISOString();
        $payload = [
            'data' => $data,
            'synced_at' => $syncedAt ?? $now,
            'status' => 'synced',
            'source' => 'campus_portal',
            'updated_at' => $now,
        ];

        Cache::put($this->cacheKey($sessionToken, $domain), $payload, self::DEFAULT_SNAPSHOT_TTL_SECONDS);
    }

    public function has(string $sessionToken, string $domain): bool
    {
        return $this->get($sessionToken, $domain) !== null;
    }

    public function markSyncing(string $sessionToken, string $domain): void
    {
        $snapshot = $this->get($sessionToken, $domain);

        if ($snapshot !== null) {
            $snapshot['status'] = 'syncing';
            Cache::put($this->cacheKey($sessionToken, $domain), $snapshot, self::DEFAULT_SNAPSHOT_TTL_SECONDS);
        }
    }

    public function isStale(string $sessionToken, string $domain, int $maxAgeSeconds = 600): bool
    {
        $snapshot = $this->get($sessionToken, $domain);

        if ($snapshot === null) {
            return true;
        }

        $syncedAt = isset($snapshot['synced_at']) ? CarbonImmutable::parse($snapshot['synced_at']) : null;

        if ($syncedAt === null) {
            return true;
        }

        return $syncedAt->addSeconds($maxAgeSeconds)->isPast();
    }

    public function forget(string $sessionToken, string $domain): void
    {
        Cache::forget($this->cacheKey($sessionToken, $domain));
    }

    private function cacheKey(string $sessionToken, string $domain): string
    {
        return 'portal_snapshot_'.md5($sessionToken.'_'.$domain);
    }
}
