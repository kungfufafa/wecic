<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalOfflineAccount;
use Carbon\CarbonInterface;
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
 * well, so MyCIC can keep the archive current while the student is away;
 * the account row is kept for that even when offline login is switched off
 * (it then holds no password hash). A password the portal has since refused
 * no longer opens MyCIC offline, and its stored credentials are dropped.
 * Whatever is kept expires with the last online login
 * (PortalOfflineAccount::prunable()). A session opened offline is marked as
 * such, so the scraping layer and the dataset repository never contact the
 * portal with it, until ResumeOfflineSessionJob logs it in again.
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
     * Called after every successful portal login, and after the student
     * changed their password through MyCIC. Never throws: offline access
     * must not fail the login it comes from.
     *
     * @param  array<string, mixed>|null  $userInfo
     */
    public function remember(string $username, string $password, ?array $userInfo): void
    {
        $offlineLogin = $this->enabled();
        $backgroundSync = PortalBackgroundSync::configured();

        if (! $offlineLogin && ! $backgroundSync) {
            return;
        }

        try {
            $account = PortalOfflineAccount::query()->firstOrNew(['owner_hash' => $this->owner->keyForUsername($username)]);
            // A row sealed with an earlier APP_KEY is overwritten, not left unusable.
            $account->forgetUnreadableEncryptedAttributes();
            $storeCredentials = $backgroundSync && $account->isBackgroundSyncEnabled();

            $account->forceFill([
                'password_hash' => $offlineLogin ? Hash::make($password) : '',
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
     * The portal refused the password an offline session was opened with
     * (`$usedSince`, the session's login): its hash and the stored
     * credentials (the same password, from the same login) are dropped, so
     * it neither opens MyCIC offline nor is tried by background sync until
     * the next online login stores a new one, whatever later happens to the
     * rejection mark. An account an online login has updated since then
     * already holds the new password and is left alone. Never throws.
     */
    public function markPasswordRejected(string $username, CarbonInterface $usedSince): void
    {
        try {
            $account = PortalOfflineAccount::query()
                ->where('owner_hash', $this->owner->keyForUsername($username))
                ->first();

            if ($account !== null && $account->last_online_login_at->lessThanOrEqualTo($usedSince)) {
                $account->forceFill(['password_hash' => '', 'credentials' => null, 'credentials_rejected_at' => now()])->save();
            }
        } catch (\Throwable $throwable) {
            Log::warning('Offline account could not be marked as rejected', [
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
        return $this->verify($username, $password)['user_info'];
    }

    /**
     * attempt(), also telling whether a stored offline password was checked
     * and did not match: a failed login, counted like one the portal refused
     * (only an account that can log in offline is checked at all).
     *
     * @return array{user_info: array<string, mixed>|null, wrong_password: bool}
     */
    public function verify(string $username, string $password): array
    {
        $refused = ['user_info' => null, 'wrong_password' => false];

        if (! $this->enabled()) {
            return $refused;
        }

        $account = PortalOfflineAccount::query()
            ->where('owner_hash', $this->owner->keyForUsername($username))
            ->first();

        if ($account === null
            || $account->password_hash === ''
            || $account->credentials_rejected_at !== null
            || $account->last_online_login_at->addDays($this->validDays())->isPast()) {
            return $refused;
        }

        if (! Hash::check($password, $account->password_hash)) {
            return ['user_info' => null, 'wrong_password' => true];
        }

        $account->forceFill(['last_offline_login_at' => now()])->save();

        return ['user_info' => $account->user_info ?? [], 'wrong_password' => false];
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

    /**
     * Remove everything MyCIC keeps to act for the student (password hash,
     * stored credentials, user info, sync history). A student who switched
     * background sync off keeps only that choice, so the next online login
     * does not start storing their password again.
     */
    public function forget(string $ownerHash): void
    {
        $account = $this->findForOwner($ownerHash);

        if ($account === null) {
            return;
        }

        if ($account->isBackgroundSyncEnabled()) {
            $account->delete();

            return;
        }

        $account->forceFill(PortalOfflineAccount::forgottenAttributes())->save();
    }

    /**
     * Record that the student switched background sync off, even when MyCIC
     * keeps nothing else for them yet (their archive was deleted, or offline
     * login is switched off for the deployment), so the next online login
     * honours it instead of storing the password.
     */
    public function rememberBackgroundSyncOptOut(string $ownerHash): PortalOfflineAccount
    {
        $account = PortalOfflineAccount::query()->firstOrNew(['owner_hash' => $ownerHash]);

        if (! $account->exists) {
            $account->forceFill(['password_hash' => '', 'last_online_login_at' => now()]);
        }

        $account->forceFill([
            'background_sync_disabled_at' => $account->background_sync_disabled_at ?? now(),
            'credentials' => null,
            'credentials_rejected_at' => null,
        ])->save();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateAccount(string $username, array $attributes): void
    {
        try {
            $account = PortalOfflineAccount::query()
                ->where('owner_hash', $this->owner->keyForUsername($username))
                ->first();

            if ($account === null) {
                return;
            }

            $account->forgetUnreadableEncryptedAttributes();
            $account->forceFill($attributes)->save();
        } catch (\Throwable $throwable) {
            Log::warning('Offline account could not be updated', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function validDays(): int
    {
        return PortalOfflineAccount::offlineLoginDays();
    }

    private function sessionKey(string $sessionToken): string
    {
        return 'portal_offline_session_'.hash('sha256', $sessionToken);
    }
}
