<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PortalEndpointsCommandTest extends TestCase
{
    public function test_a_single_endpoint_is_described(): void
    {
        $this->artisan('portal:endpoints', ['--key' => 'login_page'])
            ->expectsOutputToContain('Endpoint: login_page')
            ->expectsOutputToContain('Category   : authentication')
            ->assertSuccessful();
    }

    public function test_an_endpoint_with_required_params_lists_them(): void
    {
        $this->artisan('portal:endpoints', ['--key' => 'course_materials'])
            ->expectsOutputToContain('Endpoint: course_materials')
            ->expectsOutputToContain('Required Params : id, dosen_id, course_id')
            ->assertSuccessful();
    }

    public function test_an_unknown_endpoint_fails(): void
    {
        $this->artisan('portal:endpoints', ['--key' => 'tidak_ada'])
            ->assertFailed();
    }
}
