<?php

declare(strict_types=1);

use App\Services\PortalSessionChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('portal.session.{sessionId}', function ($user, string $sessionId): bool {
    return app(PortalSessionChannelAuthorizer::class)->authorize($user, $sessionId);
});
