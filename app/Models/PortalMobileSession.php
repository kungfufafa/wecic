<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A login of the mobile app. Its bearer token (a Sanctum personal access
 * token) names the server-side Laravel session the app's requests run in
 * (StartPortalSession), so the token lives exactly as long as that session
 * holds a campus session.
 *
 * @property int $id
 * @property string $session_id
 * @property string|null $device_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PortalMobileSession extends Model
{
    /** @use HasFactory<\Database\Factories\PortalMobileSessionFactory> */
    use HasApiTokens, HasFactory, Prunable;

    protected $fillable = [
        'session_id',
        'device_name',
    ];

    protected $hidden = [
        'session_id',
    ];

    /**
     * @return HasMany<PortalPushToken, $this>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PortalPushToken::class);
    }

    /**
     * Logins whose token went unused for `portal.mobile.token_retention_days`
     * (their session idled out long ago, so the token could only ever be
     * refused), and any left without a token.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays((int) config('portal.mobile.token_retention_days', 30));

        return static::query()->whereDoesntHave('tokens', fn (Builder $tokens): Builder => $tokens->where('last_used_at', '>=', $cutoff));
    }

    /**
     * The token goes with its login; the app installs it registered keep
     * getting pushes (PortalPushToken).
     */
    protected function pruning(): void
    {
        $this->tokens()->delete();
        $this->pushTokens()->update(['portal_mobile_session_id' => null]);
    }
}
