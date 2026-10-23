<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An app install (Expo push token) that gets the student's notifications as
 * push messages (SendPortalPushNotificationsJob). The app registers it again
 * at every login and start, which moves it to whoever is logged in there.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string $token
 * @property string|null $platform
 * @property int|null $portal_mobile_session_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PortalPushToken extends Model
{
    /** @use HasFactory<\Database\Factories\PortalPushTokenFactory> */
    use HasFactory, MassPrunable;

    public const PLATFORMS = ['ios', 'android', 'web'];

    /**
     * `ExponentPushToken[...]` (and the newer `ExpoPushToken[...]`), as
     * expo-notifications hands it to the app.
     */
    public const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]{8,200}\]$/';

    protected $fillable = [
        'owner_hash',
        'token',
        'platform',
        'portal_mobile_session_id',
    ];

    /**
     * @return BelongsTo<PortalMobileSession, $this>
     */
    public function mobileSession(): BelongsTo
    {
        return $this->belongsTo(PortalMobileSession::class, 'portal_mobile_session_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOwner(Builder $query, string $ownerHash): Builder
    {
        return $query->where('owner_hash', $ownerHash);
    }

    /**
     * Installs the app has not registered again for
     * `portal.mobile.push_token_retention_days`: uninstalled, or not opened
     * in months.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('updated_at', '<', now()->subDays((int) config('portal.mobile.push_token_retention_days', 60)));
    }
}
