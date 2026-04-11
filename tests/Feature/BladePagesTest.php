<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class BladePagesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_login_page_clears_expired_session_without_redirect_loop(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('expired-token')
                    ->andReturnFalse();
            })
        );

        $response = $this->withSession(['campus_session_token' => 'expired-token'])
            ->get('/login');

        $response->assertOk()
            ->assertSessionMissing('campus_session_token');
    }

    public function test_dashboard_page_renders(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Aktivitas terbaru');
    }

    public function test_class_pages_render(): void
    {
        $this->get('/class')->assertOk();
        $this->get('/class/2265?dosen_id=5240&course_id=8810')->assertOk();
    }

    public function test_meeting_and_exam_pages_render(): void
    {
        $this->get('/meeting/445?courses_id=8861')->assertOk();
        $this->get('/exam')->assertOk();
        $this->get('/exam/8833')->assertOk();
        $this->get('/profile')->assertOk();
    }
}
