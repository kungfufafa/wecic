<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What lets MyCIC act for a student while they are away or the portal is
 * unreachable: a hash of the password of their last successful portal login
 * (offline login) and, with background sync on, that login's credentials
 * encrypted (background sync and resuming an offline session).
 *
 * @property int $id
 * @property string $owner_hash
 * @property string $password_hash
 * @property array<string, mixed>|null $user_info
 * @property array{username: string, password: string}|null $credentials
 * @property \Illuminate\Support\Carbon|null $credentials_rejected_at
 * @property \Illuminate\Support\Carbon|null $background_sync_disabled_at
 * @property \Illuminate\Support\Carbon $last_online_login_at
 * @property \Illuminate\Support\Carbon|null $last_offline_login_at
 * @property \Illuminate\Support\Carbon|null $last_background_sync_at
 * @property string|null $last_background_sync_status
 * @property string|null $last_background_sync_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PortalOfflineAccount extends Model
{
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
        'background_sync_disabled_at',
        'last_online_login_at',
        'last_offline_login_at',
        'last_background_sync_at',
        'last_background_sync_status',
        'last_background_sync_error',
    ];

    protected $hidden = [
        'password_hash',
        'user_info',
        'credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_info' => 'encrypted:array',
            'credentials' => 'encrypted:array',
            'credentials_rejected_at' => 'datetime',
            'background_sync_disabled_at' => 'datetime',
            'last_online_login_at' => 'datetime',
            'last_offline_login_at' => 'datetime',
            'last_background_sync_at' => 'datetime',
        ];
    }

    /**
     * Accounts MyCIC may log in for: credentials stored, not rejected by the
     * portal, and an online login within `$credentialDays`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithUsableCredentials(Builder $query, int $credentialDays): Builder
    {
        return $query
            ->whereNotNull('credentials')
            ->whereNull('credentials_rejected_at')
            ->whereNull('background_sync_disabled_at')
            ->where('last_online_login_at', '>=', now()->subDays(max(1, $credentialDays)));
    }

    public function hasUsableCredentials(int $credentialDays): bool
    {
        return is_array($this->credentials)
            && $this->credentials_rejected_at === null
            && $this->background_sync_disabled_at === null
            && $this->last_online_login_at->addDays(max(1, $credentialDays))->isFuture();
    }

    public function isBackgroundSyncEnabled(): bool
    {
        return $this->background_sync_disabled_at === null;
    }
}
