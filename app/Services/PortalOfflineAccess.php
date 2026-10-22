<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalOfflineAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Offline login: while the portal is unreachable, a student who logged in
 * online within `portal.archive.offline_login_days` can still open MyCIC
 * with the same password and read their archive (read-only).
 *
 * Only a hash of that password is kept, never the password. A session opened
 * this way is marked offline, so the scraping layer and the dataset
 * repository never contact the portal with it.
 */
class PortalOfflineAccess
{
    public function __construct(private readonly PortalDataOwner $owner) {}

    public function enabled(): bool
    {
        return (bool) config('portal.archive.enabled', true)
            && (bool) config('portal.archive.offline_login', true);
    }

    /**
     * Called after every successful portal login. Never throws: offline
     * access must not fail the login it comes from.
     *
     * @param  array<string, mixed>|null  $userInfo
     */
    public function remember(string $username, string $password, ?array $userInfo): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            PortalOfflineAccount::query()->updateOrCreate(
                ['owner_hash' => $this->owner->keyForUsername($username)],
                [
                    'password_hash' => Hash::make($password),
                    'user_info' => $userInfo,
                    'last_online_login_at' => now(),
                ]
            );
        } catch (\Throwable $throwable) {
            Log::warning('Offline access could not be remembered', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null The user info of the last online login,
     *                                   or null when offline login is not possible.
     */
    public function attempt(string $username, string $password): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $account = PortalOfflineAccount::query()
            ->where('owner_hash', $this->owner->keyForUsername($username))
            ->first();

        if ($account === null || $account->last_online_login_at->addDays($this->validDays())->isPast()) {
            return null;
        }

        if (! Hash::check($password, $account->password_hash)) {
            return null;
        }

        $account->forceFill(['last_offline_login_at' => now()])->save();

        return $account->user_info ?? [];
    }

    public function markOfflineSession(string $sessionToken, int $ttl): void
    {
        Cache::put($this->sessionKey($sessionToken), true, max(1, $ttl));
    }

    public function isOfflineSession(?string $sessionToken): bool
    {
        if ($sessionToken === null || $sessionToken === '') {
            return false;
        }

        try {
            return Cache::get($this->sessionKey($sessionToken)) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function endOfflineSession(string $sessionToken): void
    {
        Cache::forget($this->sessionKey($sessionToken));
    }

    public function forget(string $ownerHash): void
    {
        PortalOfflineAccount::query()->where('owner_hash', $ownerHash)->delete();
    }

    private function validDays(): int
    {
        return max(1, (int) config('portal.archive.offline_login_days', 30));
    }

    private function sessionKey(string $sessionToken): string
    {
        return 'portal_offline_session_'.hash('sha256', $sessionToken);
    }
}
