<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalDataControllerParameterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The dashboard queues background builds for its secondary widgets.
        Bus::fake([RefreshPortalDataJob::class]);
    }

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

    public function test_meeting_detail_endpoint_marks_assignment_as_uploaded_when_history_exists_for_same_meeting(): void
    {
        $this->mockValidCampusSession('token-uploaded-meeting');

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-uploaded-meeting', [
                        'meeting_id' => '33317',
                        'courses_id' => '9198',
                    ])
                    ->andReturn([
                        'request_url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=discuss&id=33317&courses_id=9198',
                        'meeting_info' => ['breadcrumb' => 'Beranda > Kelas > Pertemuan 5'],
                        'assignment_info' => [
                            'deadline' => ':',
                            'upload_link' => '',
                            'upload_text' => '',
                        ],
                        'materials' => [
                            'assignment_topic' => 'Pertemuan 5',
                            'assignment_instructions' => 'Senin, 20 April 2026',
                            'assignment_material_iframe' => '../files/latihan/task.pdf',
                        ],
                    ]);

                $mock->shouldReceive('extractClassesData')
                    ->once()
                    ->with('token-uploaded-meeting')
                    ->andReturn([
                        'classes' => [
                            [
                                'join_link' => [
                                    'params' => [
                                        'id' => '1688',
                                        'dosen_id' => '2744',
                                        'course_id' => '9198',
                                    ],
                                ],
                            ],
                        ],
                    ]);

                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-uploaded-meeting', [
                        'id' => '1688',
                        'dosen_id' => '2744',
                        'course_id' => '9198',
                    ])
                    ->andReturn([
                        'assignment_history' => [
                            'assignments' => [
                                [
                                    'meeting' => '5',
                                    'date' => 'Selasa, 21 April 2026',
                                    'score' => '0',
                                    'file_info' => [
                                        'href' => '../files/tugasmhs/9198-5-20990000001-a14.pdf',
                                        'text' => 'Open File',
                                    ],
                                ],
                            ],
                        ],
                    ]);
            })
        );
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('inspectAssignmentSubmissionState')
                    ->once()
                    ->with('token-uploaded-meeting', 9198, 33317, 5)
                    ->andReturn([
                        'status' => 'uploaded',
                        'delete_payload' => [
                            'id' => '126663',
                            'idx' => '33317',
                            'pertemuan' => '5',
                        ],
                        'file_info' => [
                            'href' => '../files/tugasmhs/9198-5-20990000001-a14.pdf',
                            'text' => 'Open File',
                        ],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-uploaded-meeting'])
            ->getJson('/api/meeting/33317?courses_id=9198');

        $response->assertOk()
            ->assertJsonPath('class_detail.assignment_info.status', 'uploaded')
            ->assertJsonPath('class_detail.assignment_info.submission.file_info.href', '../files/tugasmhs/9198-5-20990000001-a14.pdf')
            ->assertJsonPath('class_detail.assignment_info.submission.date', 'Selasa, 21 April 2026')
            ->assertJsonPath('class_detail.assignment_info.delete_payload.id', '126663');
    }

    public function test_dashboard_endpoint_returns_sync_meta_for_fresh_request(): void
    {
        $this->mockValidCampusSession('token-dashboard');

        $this->instance(
            DashboardExtractor::class,
            Mockery::mock(DashboardExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractDashboardData')
                    ->once()
                    ->with('token-dashboard')
                    ->andReturn([
                        'dashboard_overview' => [],
                        'recent_activity' => [],
                        'classes_summary' => [],
                        'status' => 'success',
                        'timestamp' => '2026-04-21T10:00:00+07:00',
                        '_sync' => [
                            'data_origin' => 'upstream',
                            'synced_at' => '2026-04-21T10:00:00+07:00',
                        ],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-dashboard'])
            ->getJson('/api/dashboard?fresh=1');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.requested_fresh', true)
            ->assertJsonPath('meta.data_origin', 'upstream')
            ->assertJsonPath('meta.status', 'synced');

        $this->assertArrayNotHasKey('_sync', $response->json('data'));

        $snapshot = app(PortalSnapshotStore::class)->get('token-dashboard', 'dashboard');
        $this->assertNotNull($snapshot);
        $this->assertSame($snapshot['synced_at'], $response->json('meta.synced_at'));
        $this->assertArrayNotHasKey('_sync', $snapshot['data']);
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

    public function test_class_detail_endpoint_accepts_single_digit_dosen_id_from_upstream_url(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('token-single-digit-dosen')
                    ->andReturnTrue();
            })
        );

        $this->instance(
            ClassesExtractor::class,
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-single-digit-dosen', [
                        'id' => 1455,
                        'dosen_id' => 8,
                        'course_id' => 8965,
                    ])
                    ->andReturn([
                        'course_info' => ['name' => 'Bahasa Indonesia'],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-single-digit-dosen'])
            ->getJson('/api/class/1455?dosen_id=8&course_id=8965');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.parameters.id', '1455')
            ->assertJsonPath('meta.parameters.dosen_id', '8')
            ->assertJsonPath('meta.parameters.course_id', '8965');
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

    public function test_validate_parameters_accepts_single_digit_dosen_id(): void
    {
        $response = $this->getJson('/api/support/validate-parameters?id=1455&dosen_id=8&course_id=8965');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('validation_result.valid', true)
            ->assertJsonPath('validation_result.validated_params.dosen_id', '8')
            ->assertJsonPath('validation_result.suggested_api_url', '/api/class/1455?dosen_id=8&course_id=8965');
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
                            'gpa' => null,
                            'total_credits' => '21',
                            'outstanding_payments' => null,
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
            ->assertJsonPath('data.classes_summary.today_classes.0.room', 'Ruang 301')
            // IPK and bills come from their own snapshots, never a made-up 0 (Fase 0.7).
            ->assertJsonPath('data.dashboard_overview.gpa', null)
            ->assertJsonPath('data.dashboard_overview.outstanding_payments', null)
            ->assertJsonPath('data.metrics.ipk.value', null)
            ->assertJsonPath('data.metrics.ipk.status', 'pending')
            ->assertJsonPath('data.metrics.bills.status', 'pending')
            ->assertJsonPath('data.portal_context', null)
            ->assertJsonPath('data.pending_sources', ['portal_context', 'grades', 'krs', 'payments', 'skkm']);
    }

    public function test_dashboard_route_returns_unauthorized_when_upstream_session_has_expired_during_scraping(): void
    {
        $this->mockValidCampusSession('token-upstream-expired');

        $this->instance(
            DashboardExtractor::class,
            Mockery::mock(DashboardExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractDashboardData')
                    ->once()
                    ->with('token-upstream-expired')
                    ->andThrow(PortalScrapingException::pageNotAccessible('dashboard'));
            })
        );

        $response = $this->withSession([
            'campus_session_token' => 'token-upstream-expired',
            'campus_user_info' => ['nama' => 'Mahasiswa Uji'],
        ])->getJson('/api/dashboard');

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.')
            ->assertJsonPath('meta.campus_session_valid', false);

        $response->assertSessionMissing('campus_session_token');
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
