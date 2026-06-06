<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PortalBroadcastingTest extends TestCase
{
    public function test_portal_session_channel_requires_active_campus_session(): void
    {
        $response = $this->withSession([])->postJson('/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.session()->getId(),
            'socket_id' => '123.456',
        ]);

        $response->assertForbidden();
    }
}
