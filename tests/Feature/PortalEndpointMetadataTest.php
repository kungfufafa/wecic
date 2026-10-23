<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalEndpointRegistry;
use Tests\TestCase;

class PortalEndpointMetadataTest extends TestCase
{
    public function test_endpoints_index_returns_grouped_data(): void
    {
        $response = $this->getJson('/api/metadata/endpoints');

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
        $response = $this->getJson('/api/metadata/endpoints?key=profile');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.method', 'GET')
            ->assertJsonPath('data.description', 'Student profile data (Test 3).');
    }

    public function test_an_endpoint_with_required_params_is_described_with_a_url_template(): void
    {
        $response = $this->getJson('/api/metadata/endpoints?key=course_materials');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.required_params', ['id', 'dosen_id', 'course_id']);

        $url = (string) $response->json('data.url');
        $this->assertStringContainsString('act=download', $url);
        $this->assertStringContainsString('id={id}', $url);
        $this->assertStringContainsString('dosen_id={dosen_id}', $url);
        $this->assertStringContainsString('course_id={course_id}', $url);
    }

    public function test_every_registered_endpoint_can_be_described(): void
    {
        foreach (PortalEndpointRegistry::keys() as $key) {
            $this->getJson('/api/metadata/endpoints?key='.$key)
                ->assertOk()
                ->assertJsonPath('status', 'success');
        }
    }

    public function test_an_unknown_key_is_not_found_and_lists_the_available_keys(): void
    {
        $response = $this->getJson('/api/metadata/endpoints?key=does_not_exist');

        $response->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Endpoint key tidak dikenal.');

        $this->assertContains('course_materials', $response->json('meta.available_keys'));
    }
}
