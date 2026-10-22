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
 * A hash of that password is kept for the check. With background sync on
 * (PortalBackgroundSync), the credentials themselves are stored encrypted as
 * well, so MyCIC can keep the archive current while the student is away. A
 * session opened offline is marked as such, so the scraping layer and the
 * dataset repository never contact the portal with it, until
 * ResumeOfflineSessionJob logs it in again.
 */
class PortalOfflineAccess
{
    /**
     * Resolved on first use, never injected: the session manager is a
     * singleton built around the portal client, and nothing should build it
     * just to check whether a session is offline.
     */
    private ?PortalSessionManager $sessionManager = null;

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
            $account = PortalOfflineAccount::query()->firstOrNew(['owner_hash' => $this->owner->keyForUsername($username)]);
            $storeCredentials = PortalBackgroundSync::configured() && $account->isBackgroundSyncEnabled();

            $account->forceFill([
                'password_hash' => Hash::make($password),
                'user_info' => $userInfo,
                'credentials' => $storeCredentials ? ['username' => $username, 'password' => $password] : null,
                'credentials_rejected_at' => null,
                'last_online_login_at' => now(),
            ])->save();
        } catch (\Throwable $throwable) {
            Log::warning('Offline access could not be remembered', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * An offline session logged in again on its own: the stored password is
     * confirmed, so the offline-login window starts over. Never throws.
     *
     * @param  array<string, mixed>|null  $userInfo
     */
    public function markOnlineLogin(string $username, ?array $userInfo): void
    {
        $this->updateAccount($username, [
            'last_online_login_at' => now(),
            'credentials_rejected_at' => null,
            ...($userInfo !== null ? ['user_info' => $userInfo] : []),
        ]);
    }

    /**
     * Keep what is shown while offline current after a background login.
     * Never throws.
     *
     * @param  array<string, mixed>|null  $userInfo
     */
    public function refreshUserInfo(string $username, ?array $userInfo): void
    {
        if ($userInfo !== null) {
            $this->updateAccount($username, ['user_info' => $userInfo]);
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

    /**
     * The stored session is the source of truth (its `offline` flag goes
     * when the session is resumed); the marker only spares a session read.
     */
    public function isOfflineSession(?string $sessionToken): bool
    {
        if ($sessionToken === null || $sessionToken === '') {
            return false;
        }

        try {
            $this->sessionManager ??= app(PortalSessionManager::class);

            if (Cache::get($this->sessionKey($sessionToken)) === true) {
                return ($this->sessionManager->getSession($sessionToken)['offline'] ?? true) === true;
            }

            return ($this->sessionManager->getSession($sessionToken)['offline'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function endOfflineSession(string $sessionToken): void
    {
        Cache::forget($this->sessionKey($sessionToken));
    }

    public function findForOwner(string $ownerHash): ?PortalOfflineAccount
    {
        return PortalOfflineAccount::query()->where('owner_hash', $ownerHash)->first();
    }

    public function forget(string $ownerHash): void
    {
        PortalOfflineAccount::query()->where('owner_hash', $ownerHash)->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateAccount(string $username, array $attributes): void
    {
        try {
            PortalOfflineAccount::query()
                ->where('owner_hash', $this->owner->keyForUsername($username))
                ->first()
                ?->forceFill($attributes)
                ->save();
        } catch (\Throwable $throwable) {
            Log::warning('Offline account could not be updated', [
                'error' => $throwable->getMessage(),
            ]);
        }
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
