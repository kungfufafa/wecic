<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What lets a student read their archive while the portal is unreachable:
 * a hash of the password of their last successful portal login.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string $password_hash
 * @property array<string, mixed>|null $user_info
 * @property \Illuminate\Support\Carbon $last_online_login_at
 * @property \Illuminate\Support\Carbon|null $last_offline_login_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PortalOfflineAccount extends Model
{
    protected $fillable = [
        'owner_hash',
        'password_hash',
        'user_info',
        'last_online_login_at',
        'last_offline_login_at',
    ];

    protected $hidden = [
        'password_hash',
        'user_info',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_info' => 'encrypted:array',
            'last_online_login_at' => 'datetime',
            'last_offline_login_at' => 'datetime',
        ];
    }
}
