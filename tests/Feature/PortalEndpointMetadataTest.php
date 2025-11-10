<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PortalEndpointMetadataTest extends TestCase
{
    public function test_endpoints_index_returns_grouped_data(): void
    {
        $response = $this->getJson('/api/campus/metadata/endpoints');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'message',
                'data',
                'meta' => ['timestamp', 'grouped', 'total_endpoints'],
            ]);

        $this->assertIsArray($response->json('data'));
    }

    public function test_endpoints_show_returns_single_endpoint(): void
    {
        $response = $this->getJson('/api/campus/metadata/endpoints?key=profile');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.method', 'GET')
            ->assertJsonPath('data.description', 'Student profile data (Test 3).');
    }
}
