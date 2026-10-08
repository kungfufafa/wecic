<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache of parsed upstream pages ("resources"), owned by the student.
 *
 * Resources are the building blocks shared between datasets: e.g. one class
 * detail page feeds the pending assignments list, the attendance shortcuts and
 * the class page. Invalidation is explicit (after portal actions) and works
 * for every parameter variant of a resource at once through a marker
 * timestamp, so callers do not need to know which entries exist.
 */
class PortalDataCache
{
    public function __construct(private readonly PortalDataOwner $owner) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  callable(): array<string, mixed>  $fetch
     * @param  (callable(array<string, mixed>): bool)|null  $cacheable
     * @return array{data: array<string, mixed>, origin: 'server_cache'|'upstream'}
     */
    public function remember(
        string $sessionToken,
        string $resource,
        array $params,
        callable $fetch,
        bool $forceFresh = false,
        ?callable $cacheable = null
    ): array {
        $ownerKey = $this->owner->key($sessionToken);
        $entryKey = $this->entryKey($ownerKey, $resource, $params);

        if (! $forceFresh) {
            $entry = Cache::get($entryKey);

            if ($this->isUsable($entry, $ownerKey, $resource)) {
                return ['data' => $entry['data'], 'origin' => 'server_cache'];
            }
        }

        // Stamp the entry with the moment the fetch began: an invalidation that
        // lands while the page is being downloaded must win over this entry.
        $startedAt = microtime(true);
        $data = $fetch();

        if ($cacheable === null || $cacheable($data)) {
            Cache::put($entryKey, ['data' => $data, 'stored_at' => $startedAt], $this->ttl($resource));
        }

        return ['data' => $data, 'origin' => 'upstream'];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function forget(string $sessionToken, string $resource, array $params = []): void
    {
        Cache::forget($this->entryKey($this->owner->key($sessionToken), $resource, $params));
    }

    /**
     * Invalidate every cached variant of the given resources for the session's owner.
     */
    public function invalidate(string $sessionToken, string ...$resources): void
    {
        $ownerKey = $this->owner->key($sessionToken);

        foreach ($resources as $resource) {
            Cache::put($this->markerKey($ownerKey, $resource), microtime(true), $this->ttl($resource));
        }
    }

    private function isUsable(mixed $entry, string $ownerKey, string $resource): bool
    {
        if (! is_array($entry) || ! is_array($entry['data'] ?? null)) {
            return false;
        }

        $invalidatedAt = Cache::get($this->markerKey($ownerKey, $resource));

        return ! is_numeric($invalidatedAt) || (float) ($entry['stored_at'] ?? 0) > (float) $invalidatedAt;
    }

    private function ttl(string $resource): int
    {
        return (int) config("portal.cache.resources.{$resource}", config('portal.cache.default_ttl', 300));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function entryKey(string $ownerKey, string $resource, array $params): string
    {
        return 'portal_resource_'.md5($ownerKey.'|'.$resource.'|'.self::paramsHash($params));
    }

    private function markerKey(string $ownerKey, string $resource): string
    {
        return 'portal_resource_invalidated_'.md5($ownerKey.'|'.$resource);
    }

    /**
     * Stable hash for a parameter set: key order and scalar types do not matter.
     *
     * @param  array<string, mixed>  $params
     */
    public static function paramsHash(array $params): string
    {
        $normalized = array_map(
            static fn (mixed $value): mixed => is_scalar($value) ? (string) $value : $value,
            array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '')
        );
        ksort($normalized);

        return md5((string) json_encode($normalized));
    }
}
