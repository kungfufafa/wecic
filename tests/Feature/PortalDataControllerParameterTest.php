<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalDataControllerParameterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_meeting_detail_endpoint_uses_upstream_courses_id_parameter(): void
    {
        $this->mockValidCampusSession('token-123');

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-123', [
                        'meeting_id' => '445',
                        'courses_id' => '8861',
                    ])
                    ->andReturn([
                        'meeting_info' => ['title' => 'Pertemuan 1'],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-123'])
            ->getJson('/api/meeting/445?courses_id=8861');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.courses_id', '8861');
    }

    public function test_meeting_detail_endpoint_requires_courses_id_parameter(): void
    {
        $this->mockValidCampusSession('token-direct');

        $response = $this->withSession(['campus_session_token' => 'token-direct'])
            ->getJson('/api/meeting/445');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['courses_id']);
    }

    public function test_class_detail_endpoint_resolves_missing_params_from_upstream_id(): void
    {
        $this->mockValidCampusSession('token-456');

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassesData')
                    ->once()
                    ->with('token-456')
                    ->andReturn([
                        'classes' => [
                            [
                                'join_link' => [
                                    'params' => [
                                        'id' => '2265',
                                        'dosen_id' => '5240',
                                        'course_id' => '8810',
                                    ],
                                ],
                            ],
                        ],
                    ]);

                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-456', [
                        'id' => '2265',
                        'dosen_id' => '5240',
                        'course_id' => '8810',
                    ])
                    ->andReturn([
                        'course_info' => ['name' => 'Algoritma'],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-456'])
            ->getJson('/api/class/2265');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.id', '2265');
    }

    public function test_meeting_detail_endpoint_uses_upstream_courses_id_for_api_requests(): void
    {
        $this->mockValidCampusSession('token-789');

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-789', [
                        'meeting_id' => '445',
                        'courses_id' => '8861',
                    ])
                    ->andReturn([
                        'meeting_info' => ['title' => 'Pertemuan 1'],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-789'])
            ->getJson('/api/meeting/445?courses_id=8861');

        $response->assertOk()
            ->assertJsonPath('meta.courses_id', '8861');
    }

    public function test_meeting_detail_endpoint_accepts_legacy_course_id_alias_for_backward_compatibility(): void
    {
        $this->mockValidCampusSession('token-790');

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-790', [
                        'meeting_id' => '445',
                        'courses_id' => '8861',
                    ])
                    ->andReturn([
                        'meeting_info' => ['title' => 'Pertemuan 1'],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-790'])
            ->getJson('/api/meeting/445?course_id=8861');

        $response->assertOk()
            ->assertJsonPath('meta.courses_id', '8861');
    }

    public function test_class_detail_endpoint_prefers_upstream_parameters_for_api_requests(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('token-class-detail')
                    ->andReturnTrue();
            })
        );

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-class-detail', [
                        'id' => 2265,
                        'dosen_id' => 5240,
                        'course_id' => 8810,
                    ])
                    ->andReturn([
                        'course_info' => ['name' => 'Algoritma'],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-class-detail'])
            ->getJson('/api/class/2265?dosen_id=5240&course_id=8810');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.parameters.id', '2265')
            ->assertJsonPath('meta.parameters.dosen_id', '5240')
            ->assertJsonPath('meta.parameters.course_id', '8810');
    }

    public function test_validate_parameters_returns_json_errors_without_accept_header(): void
    {
        $response = $this->get('/api/support/validate-parameters?id=1');

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'Parameter validation failed',
                'validation_result' => [
                    'valid' => false,
                ],
            ]);

        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('content-type')
        );
    }

    public function test_sipandai_flow_allows_missing_dosen_id(): void
    {
        $this->mockValidCampusSession('token-flow');

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-flow', [
                        'id' => 2265,
                        'course_id' => 8810,
                    ])
                    ->andReturn([
                        'course_info' => ['name' => 'Algoritma'],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-flow'])
            ->getJson('/api/lms/flow?id=2265&course_id=8810&pertemuan=6');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.course_detail.course_info.name', 'Algoritma');
    }

    public function test_dashboard_route_returns_unauthorized_for_expired_campus_session(): void
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
            ->getJson('/api/dashboard');

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_dashboard_route_returns_today_schedule_summary(): void
    {
        $this->mockValidCampusSession('token-dashboard');

        $this->instance(
            DashboardExtractor::class,
            Mockery::mock(DashboardExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractDashboardData')
                    ->once()
                    ->with('token-dashboard')
                    ->andReturn([
                        'user_info' => [],
                        'dashboard_overview' => [
                            'total_courses' => '7',
                            'gpa' => '3.75',
                            'total_credits' => '21',
                            'outstanding_payments' => 'Rp 0',
                        ],
                        'recent_activity' => [],
                        'classes_summary' => [
                            'total_classes' => 7,
                            'active_semester' => '6',
                            'day' => 'Sabtu',
                            'date' => '2026-04-11',
                            'today_classes_count' => 1,
                            'today_classes' => [
                                [
                                    'course_name' => 'Algoritma',
                                    'room' => 'Ruang 301',
                                    'schedule' => [
                                        'time' => '09:30 - 11:00',
                                    ],
                                    'active_now' => true,
                                ],
                            ],
                        ],
                        'status' => 'success',
                        'timestamp' => '2026-04-11T09:30:00+07:00',
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-dashboard'])
            ->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.classes_summary.day', 'Sabtu')
            ->assertJsonPath('data.classes_summary.today_classes_count', 1)
            ->assertJsonPath('data.classes_summary.today_classes.0.course_name', 'Algoritma')
            ->assertJsonPath('data.classes_summary.today_classes.0.room', 'Ruang 301');
    }

    private function mockValidCampusSession(string $token): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }
}
