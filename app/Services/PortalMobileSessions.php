<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalMobileSession;
use App\Models\PortalPushToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Bearer tokens of the mobile app: Sanctum personal access tokens of a
 * PortalMobileSession. Sanctum stores only a SHA-256 of a token; the token
 * itself (`<id>|mycic_…`) is shown once, at login. Tokens are looked up
 * here rather than through the `sanctum` guard: the request's session is
 * not started yet when the token is read, since the token decides which
 * session it is.
 */
class PortalMobileSessions
{
    /**
     * Name of the tokens a login creates.
     */
    private const TOKEN_NAME = 'mobile';

    /**
     * Seconds between writes of a token's `last_used_at`: a request reads
     * it, it does not need to write it every time.
     */
    private const TOUCH_INTERVAL = 60;

    /**
     * @return array{token: string, mobile_session: PortalMobileSession}
     */
    public function issue(string $sessionId, ?string $deviceName = null): array
    {
        $mobileSession = PortalMobileSession::query()->create([
            'session_id' => $sessionId,
            'device_name' => $deviceName !== null && $deviceName !== '' ? mb_substr($deviceName, 0, 100) : null,
        ]);

        $newToken = $mobileSession->createToken(self::TOKEN_NAME);
        $newToken->accessToken->forceFill(['last_used_at' => now()])->save();

        return ['token' => $newToken->plainTextToken, 'mobile_session' => $mobileSession];
    }

    /**
     * The login the token belongs to, or null for an unknown, expired or
     * foreign token.
     */
    public function resolve(?string $token): ?PortalMobileSession
    {
        if ($token === null || $token === '' || strlen($token) > 255) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if ($accessToken === null || ! $this->isValid($accessToken)) {
            return null;
        }

        $mobileSession = $accessToken->tokenable;

        if (! $mobileSession instanceof PortalMobileSession) {
            return null;
        }

        if ($accessToken->last_used_at === null || $accessToken->last_used_at->lt(now()->subSeconds(self::TOUCH_INTERVAL))) {
            $accessToken->forceFill(['last_used_at' => now()])->save();
        }

        return $mobileSession->withAccessToken($accessToken);
    }

    /**
     * Ends the login and its token. On a logout (`$withPushTokens`) the app
     * installs it registered stop getting pushes; a login that only went
     * stale leaves them registered to the student.
     */
    public function revoke(PortalMobileSession $mobileSession, bool $withPushTokens = false): void
    {
        $pushTokens = PortalPushToken::query()->where('portal_mobile_session_id', $mobileSession->id);

        if ($withPushTokens) {
            $pushTokens->delete();
        } else {
            $pushTokens->update(['portal_mobile_session_id' => null]);
        }

        $mobileSession->tokens()->delete();
        $mobileSession->delete();
    }

    /**
     * A login with a token that was still valid: the new login takes over
     * the app installs the old one registered, so the next logout still
     * stops their pushes.
     */
    public function replace(PortalMobileSession $previous, PortalMobileSession $current): void
    {
        PortalPushToken::query()
            ->where('portal_mobile_session_id', $previous->id)
            ->update(['portal_mobile_session_id' => $current->id]);

        $previous->tokens()->delete();
        $previous->delete();
    }

    /**
     * Sanctum's own expiry rules (`sanctum.expiration`, a token's
     * `expires_at`), which its guard would apply.
     */
    private function isValid(PersonalAccessToken $accessToken): bool
    {
        $expiration = config('sanctum.expiration');

        if (is_numeric($expiration) && $accessToken->created_at?->lte(now()->subMinutes((int) $expiration))) {
            return false;
        }

        return $accessToken->expires_at === null || $accessToken->expires_at->isFuture();
    }
}
