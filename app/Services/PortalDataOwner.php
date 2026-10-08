<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Resolves which student owns the data reached through a campus session.
 *
 * Scraped data belongs to the student rather than to one login, so caches and
 * snapshots are keyed by owner: a re-login reuses them, and storage is bounded
 * by the number of students instead of the number of logins.
 */
class PortalDataOwner
{
    public function remember(string $sessionToken, string $username, int $ttl): void
    {
        Cache::put($this->mappingKey($sessionToken), $this->keyForUsername($username), $ttl);
    }

    public function forget(string $sessionToken): void
    {
        Cache::forget($this->mappingKey($sessionToken));
    }

    /**
     * Unknown sessions fall back to a per-session key so data is never shared
     * between students by accident.
     */
    public function key(string $sessionToken): string
    {
        $ownerKey = Cache::get($this->mappingKey($sessionToken));

        return is_string($ownerKey) && $ownerKey !== ''
            ? $ownerKey
            : 'session_'.PortalLogContext::fingerprint($sessionToken);
    }

    public function keyForUsername(string $username): string
    {
        return 'student_'.PortalLogContext::fingerprint(strtolower(trim($username)));
    }

    private function mappingKey(string $sessionToken): string
    {
        return 'portal_owner_'.hash('sha256', $sessionToken);
    }
}
