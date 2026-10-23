<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves which student owns the data reached through a campus session.
 *
 * Scraped data belongs to the student rather than to one login, so caches and
 * snapshots are keyed by owner: a re-login reuses them, and storage is bounded
 * by the number of students instead of the number of logins.
 *
 * A student's owner key is an HMAC of their username with the owner secret
 * (`portal.archive.owner_key`, else APP_KEY as it always was), so it can be
 * pinned before APP_KEY is rotated. Rows stored under a key made with an
 * earlier secret (`portal.archive.previous_owner_keys`, APP_PREVIOUS_KEYS)
 * are moved to the current key the first time the student's key is needed
 * (their next login, online or offline, or background sync).
 */
class PortalDataOwner
{
    /**
     * Owner keys this process already looked for earlier-secret rows of.
     *
     * @var array<string, true>
     */
    private array $adopted = [];

    public function __construct(private readonly PortalOwnerRekeyer $rekeyer) {}

    public function remember(string $sessionToken, string $username, int $ttl): void
    {
        Cache::put($this->mappingKey($sessionToken), $this->keyForUsername($username), $ttl);
    }

    /**
     * Map a token to an owner key known without the username (a stored
     * row's `owner_hash`), for read-only work on that student's snapshots
     * (PortalAwayReminders).
     */
    public function rememberKey(string $sessionToken, string $ownerKey, int $ttl): void
    {
        Cache::put($this->mappingKey($sessionToken), $ownerKey, $ttl);
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
        $username = strtolower(trim($username));
        $ownerKey = $this->studentKey($username, $this->secret());

        if (! isset($this->adopted[$ownerKey])) {
            $this->adoptEarlierKeys($username, $ownerKey);
        }

        return $ownerKey;
    }

    /**
     * Move the student's rows stored under keys made with earlier secrets to
     * `$ownerKey`. Never throws: a failed move is tried again next time.
     */
    private function adoptEarlierKeys(string $username, string $ownerKey): void
    {
        $earlierKeys = array_values(array_diff(array_unique(array_map(
            fn (string $secret): string => $this->studentKey($username, $secret),
            $this->earlierSecrets()
        )), [$ownerKey]));

        try {
            $moved = 0;

            foreach ($earlierKeys as $earlierKey) {
                $moved += $this->rekeyer->rekey($earlierKey, $ownerKey);
            }

            $this->adopted[$ownerKey] = true;

            if ($moved > 0) {
                Log::info('Student data moved to the current owner key', ['rows' => $moved]);
            }
        } catch (\Throwable $throwable) {
            Log::warning('Student data could not be moved to the current owner key', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function studentKey(string $username, string $secret): string
    {
        return 'student_'.substr(hash_hmac('sha256', $username, $secret), 0, 16);
    }

    /**
     * The same secret PortalLogContext::fingerprint() used for owner keys
     * before the owner secret existed, unless one is pinned.
     */
    private function secret(): string
    {
        $pinned = config('portal.archive.owner_key');

        return is_string($pinned) && $pinned !== ''
            ? $pinned
            : (string) (config('app.key') ?: 'campus-observability');
    }

    /**
     * Secrets owner keys may have been made with before: the listed earlier
     * owner secrets, APP_KEY when another secret is pinned now, and the
     * earlier APP_KEYs (owner keys followed APP_KEY until it was pinned).
     *
     * @return list<string>
     */
    private function earlierSecrets(): array
    {
        $current = $this->secret();
        $pinned = config('portal.archive.owner_key');
        $candidates = [
            ...(array) config('portal.archive.previous_owner_keys', []),
            ...(is_string($pinned) && $pinned !== '' ? [config('app.key')] : []),
            ...(array) config('app.previous_keys', []),
        ];

        return array_values(array_unique(array_filter(
            $candidates,
            static fn (mixed $secret): bool => is_string($secret) && $secret !== '' && $secret !== $current
        )));
    }

    private function mappingKey(string $sessionToken): string
    {
        return 'portal_owner_'.hash('sha256', $sessionToken);
    }
}
