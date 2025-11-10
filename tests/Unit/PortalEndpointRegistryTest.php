<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalEndpointRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class PortalEndpointRegistryTest extends TestCase
{
    public function test_url_for_simple_endpoint_matches_config_value(): void
    {
        $expected = data_get(config('portal.endpoints.profile'), 'url');

        $this->assertSame($expected, PortalEndpointRegistry::url('profile'));
    }

    public function test_url_merges_default_and_override_query_values(): void
    {
        $url = PortalEndpointRegistry::url('course_materials', [
            'id' => '1451',
            'dosen_id' => '2744',
            'course_id' => '8833',
        ]);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame([
            'mod' => 'sipandai',
            'act' => 'download',
            'id' => '1451',
            'dosen_id' => '2744',
            'course_id' => '8833',
        ], $query);
    }

    public function test_missing_required_params_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Portal endpoint [course_materials] is missing required parameters');

        PortalEndpointRegistry::url('course_materials', [
            'id' => '1451',
        ]);
    }

    public function test_grouped_by_category_contains_expected_keys(): void
    {
        $grouped = PortalEndpointRegistry::groupedByCategory();

        $this->assertArrayHasKey('data', $grouped);
        $this->assertArrayHasKey('action', $grouped);
        $this->assertArrayHasKey('profile', $grouped['data']);
        $this->assertArrayHasKey('assignment_submit', $grouped['action']);
    }

    public function test_keys_match_configured_endpoints(): void
    {
        $this->assertSame(
            array_keys(config('portal.endpoints')),
            PortalEndpointRegistry::keys()
        );
    }
}
