<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PortalBroadcastActor;

class PortalSessionChannelAuthorizer
{
    public function authorize(mixed $user, string $sessionId): bool
    {
        if (! session()->has('campus_session_token')) {
            return false;
        }

        if ($user instanceof PortalBroadcastActor) {
            return hash_equals($user->getAuthIdentifier(), $sessionId);
        }

        return hash_equals((string) session()->getId(), $sessionId);
    }
}
