<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PortalAuthControllerTest extends TestCase
{
    public function test_login_validation_returns_json_without_accept_header(): void
    {
        $response = $this->post('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'Username dan password harus diisi',
            ]);

        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('content-type')
        );
    }
}
