<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * What lets MyCIC act for a student while they are away or the portal is
 * unreachable: a hash of the password of their last successful portal login
 * (offline login) and, only once the student switched background sync on
 * (opt-in, `background_sync_enabled_at`), that login's credentials
 * encrypted (background sync and resuming an offline session).
 *
 * Nothing is kept longer than it can be used (`model:prune`, see
 * prunable()): the credentials go `credential_days` after the last online
 * login or as soon as the portal refuses them, the password hash and user
 * info after `offline_login_days`, and the row itself once both have
 * passed, unless it carries the student's background sync opt-in.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string $password_hash
 * @property array<string, mixed>|null $user_info
 * @property array{username: string, password: string}|null $credentials
 * @property \Illuminate\Support\Carbon|null $credentials_rejected_at
 * @property \Illuminate\Support\Carbon|null $background_sync_enabled_at
 * @property \Illuminate\Support\Carbon $last_online_login_at
 * @property \Illuminate\Support\Carbon|null $last_offline_login_at
 * @property \Illuminate\Support\Carbon|null $last_background_sync_at
 * @property string|null $last_background_sync_status
 * @property string|null $last_background_sync_error
 * @property \Illuminate\Support\Carbon|null $last_course_sync_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PortalOfflineAccount extends Model
{
    use Prunable;

    public const SYNC_SYNCED = 'synced';

    public const SYNC_PARTIAL = 'partial';

    public const SYNC_FAILED = 'failed';

    public const SYNC_UNREACHABLE = 'unreachable';

    public const SYNC_REJECTED = 'rejected';

    protected $fillable = [
        'owner_hash',
        'password_hash',
        'user_info',
        'credentials',
        'credentials_rejected_at',
        'background_sync_enabled_at',
        'last_online_login_at',
        'last_offline_login_at',
        'last_background_sync_at',
        'last_background_sync_status',
        'last_background_sync_error',
        'last_course_sync_at',
    ];

    protected $hidden = [
        'password_hash',
        'user_info',
        'credentials',
    ];

    /**
     * Once the portal has refused the password (`credentials_rejected_at`),
     * its hash must never open MyCIC offline again. Whatever lifts the mark
     * without storing a new hash (switching background sync off, an offline
     * session logging in again) drops the hash with it, so only the next
     * online login (PortalOfflineAccess::remember()) restores offline login.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $account): void {
            if ($account->getOriginal('credentials_rejected_at') !== null
                && $account->credentials_rejected_at === null
                && ! $account->isDirty('password_hash')) {
                $account->password_hash = '';
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_info' => 'encrypted:array',
            'credentials' => 'encrypted:array',
            'credentials_rejected_at' => 'datetime',
            'background_sync_enabled_at' => 'datetime',
            'last_online_login_at' => 'datetime',
            'last_offline_login_at' => 'datetime',
            'last_background_sync_at' => 'datetime',
            'last_course_sync_at' => 'datetime',
        ];
    }

    /**
     * Accounts MyCIC may log in for: background sync switched on,
     * credentials stored, not rejected by the portal, and an online login
     * within `$credentialDays`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithUsableCredentials(Builder $query, int $credentialDays): Builder
    {
        return $query
            ->whereNotNull('credentials')
            ->whereNull('credentials_rejected_at')
            ->whereNotNull('background_sync_enabled_at')
            ->where('last_online_login_at', '>=', now()->subDays(max(1, $credentialDays)));
    }

    public function hasUsableCredentials(int $credentialDays): bool
    {
        return is_array($this->credentials)
            && $this->credentials_rejected_at === null
            && $this->background_sync_enabled_at !== null
            && $this->last_online_login_at->addDays(max(1, $credentialDays))->isFuture();
    }

    public function isBackgroundSyncEnabled(): bool
    {
        return $this->background_sync_enabled_at !== null;
    }

    /**
     * Days after the last online login during which the stored credentials
     * are used for background sync (`portal.background_sync.credential_days`).
     */
    public static function credentialDays(): int
    {
        return max(1, (int) config('portal.background_sync.credential_days', 30));
    }

    /**
     * Days after the last online login during which the password hash opens
     * MyCIC offline (`portal.archive.offline_login_days`).
     */
    public static function offlineLoginDays(): int
    {
        return max(1, (int) config('portal.archive.offline_login_days', 30));
    }

    /**
     * Days after the last online login past which an account keeps nothing
     * but a background sync opt-in: both windows above have passed.
     */
    public static function retentionDays(): int
    {
        return max(self::credentialDays(), self::offlineLoginDays());
    }

    /**
     * Everything an account holds to act for the student, emptied: only the
     * owner, the background sync opt-in and the last online login remain.
     *
     * @return array<string, string|null>
     */
    public static function forgottenAttributes(): array
    {
        return [
            'password_hash' => '',
            'user_info' => null,
            'credentials' => null,
            'credentials_rejected_at' => null,
            'last_offline_login_at' => null,
            'last_background_sync_at' => null,
            'last_background_sync_status' => null,
            'last_background_sync_error' => null,
            'last_course_sync_at' => null,
        ];
    }

    /**
     * Accounts holding something past its use (`model:prune`): credentials
     * the portal refused or older than credentialDays(), a password hash or
     * user info older than offlineLoginDays(), and past retentionDays() any
     * row but an emptied background sync opt-in. Ages count from the last
     * online login.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where(static function (Builder $query): void {
            $query
                ->where(static function (Builder $query): void {
                    $query->whereNotNull('credentials')->where(static function (Builder $query): void {
                        $query->whereNotNull('credentials_rejected_at')
                            ->orWhere('last_online_login_at', '<', now()->subDays(self::credentialDays()));
                    });
                })
                ->orWhere(static function (Builder $query): void {
                    $query->where('last_online_login_at', '<', now()->subDays(self::offlineLoginDays()))
                        ->where(static function (Builder $query): void {
                            $query->where('password_hash', '!=', '')->orWhereNotNull('user_info');
                        });
                })
                ->orWhere(static function (Builder $query): void {
                    $query->where('last_online_login_at', '<', now()->subDays(self::retentionDays()))
                        ->where(static function (Builder $query): void {
                            $query->whereNull('background_sync_enabled_at')
                                ->orWhereNotNull('credentials_rejected_at')
                                ->orWhereNotNull('last_offline_login_at')
                                ->orWhereNotNull('last_background_sync_at')
                                ->orWhereNotNull('last_background_sync_status')
                                ->orWhereNotNull('last_background_sync_error')
                                ->orWhereNotNull('last_course_sync_at');
                        });
                });
        });
    }

    /**
     * Past retentionDays() the row goes, unless it carries a background sync
     * opt-in: that is kept, emptied, so the next online login still honours
     * it. Before that only what has expired is emptied, which also takes the
     * account out of background sync. An online login that lands meanwhile
     * renewed the account, which is then left alone.
     */
    public function prune(): bool
    {
        $unchanged = static::query()
            ->whereKey($this->getKey())
            ->where('last_online_login_at', $this->getRawOriginal('last_online_login_at'));
        $lastOnlineLogin = $this->last_online_login_at;

        if ($lastOnlineLogin->lessThan(now()->subDays(self::retentionDays()))) {
            if ($unchanged->clone()->whereNull('background_sync_enabled_at')->delete() === 1) {
                return true;
            }

            return $unchanged->update(self::forgottenAttributes()) === 1;
        }

        $expired = [];

        if ($this->credentials_rejected_at !== null || $lastOnlineLogin->lessThan(now()->subDays(self::credentialDays()))) {
            $expired['credentials'] = null;
        }

        if ($lastOnlineLogin->lessThan(now()->subDays(self::offlineLoginDays()))) {
            $expired['password_hash'] = '';
            $expired['user_info'] = null;
        }

        return $expired !== [] && $unchanged->update($expired) === 1;
    }

    /**
     * Encrypted attributes sealed with an APP_KEY the app no longer has can
     * never be read again, and saving a new value over one fails too (the
     * dirty check decrypts the old value). They are cleared at once, in the
     * database as well (a save would not write a null over them), so the
     * next save stores fresh values. Other pending changes are kept.
     *
     * @return list<string> The attributes that were cleared.
     */
    public function forgetUnreadableEncryptedAttributes(): array
    {
        $unreadable = [];

        foreach (['user_info', 'credentials'] as $attribute) {
            try {
                $this->getAttribute($attribute);
            } catch (DecryptException) {
                $unreadable[] = $attribute;
            }
        }

        if ($unreadable === []) {
            return [];
        }

        $cleared = array_fill_keys($unreadable, null);

        if ($this->exists) {
            static::query()->whereKey($this->getKey())->update($cleared);
        }

        $this->setRawAttributes([...$this->getAttributes(), ...$cleared]);
        $this->syncOriginalAttributes($unreadable);

        return $unreadable;
    }
}
