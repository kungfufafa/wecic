<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalSessionChannelAuthorizer;
use App\Support\PortalBroadcastActor;
use Tests\TestCase;

class PortalSessionChannelAuthorizerTest extends TestCase
{
    public function test_authorizer_rejects_missing_campus_session(): void
    {
        $authorizer = new PortalSessionChannelAuthorizer;

        $this->assertFalse($authorizer->authorize(null, 'session-id'));
    }

    public function test_authorizer_allows_matching_portal_broadcast_actor(): void
    {
        $this->withSession([
            'campus_session_token' => 'token-123',
        ]);

        $authorizer = new PortalSessionChannelAuthorizer;

        $this->assertTrue($authorizer->authorize(new PortalBroadcastActor('session-id'), 'session-id'));
    }

    public function test_authorizer_rejects_mismatched_session_id(): void
    {
        $this->withSession([
            'campus_session_token' => 'token-123',
        ]);

        $authorizer = new PortalSessionChannelAuthorizer;

        $this->assertFalse($authorizer->authorize(new PortalBroadcastActor('session-a'), 'session-b'));
    }
}
