<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalPushToken;

/**
 * The app installs (Expo push tokens) a student gets push messages on.
 */
class PortalPushDevices
{
    /**
     * Installs kept per student; registering one more drops the one
     * registered longest ago, so a client registering tokens in a loop
     * cannot grow the push fan-out.
     */
    public const MAX_PER_STUDENT = 10;

    /**
     * Registers the install for the student, moving it away from whoever
     * registered it before (one install, one student). Registering it again
     * refreshes it, which keeps it from being pruned.
     */
    public function register(string $ownerHash, string $token, ?string $platform, ?int $mobileSessionId): PortalPushToken
    {
        $now = now();

        PortalPushToken::query()->upsert([[
            'owner_hash' => $ownerHash,
            'token' => $token,
            'platform' => $platform,
            'portal_mobile_session_id' => $mobileSessionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['token'], ['owner_hash', 'platform', 'portal_mobile_session_id', 'updated_at']);

        $this->dropOldest($ownerHash);

        return PortalPushToken::query()->where('token', $token)->firstOrFail();
    }

    /**
     * @return int Installs removed (0 when it was not the student's).
     */
    public function forget(string $ownerHash, string $token): int
    {
        return PortalPushToken::query()->forOwner($ownerHash)->where('token', $token)->delete();
    }

    private function dropOldest(string $ownerHash): void
    {
        $keep = PortalPushToken::query()
            ->forOwner($ownerHash)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_PER_STUDENT)
            ->pluck('id');

        PortalPushToken::query()->forOwner($ownerHash)->whereNotIn('id', $keep)->delete();
    }
}
