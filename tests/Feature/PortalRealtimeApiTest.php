<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Jobs\ProcessPortalExamUploadJob;
use App\Jobs\RefreshPortalDataJob;
use App\Services\AttendanceShortcutService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalRealtimeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfTokenMiddleware::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_upload_assignment_returns_operation_id_and_dispatches_job(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');
        $this->mockValidCampusSession('token-upload');

        $response = $this->withSession(['campus_session_token' => 'token-upload'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonStructure(['data' => ['operation_id']]);

        Queue::assertPushed(ProcessPortalAssignmentUploadJob::class);
    }

    public function test_upload_exam_returns_operation_id_and_dispatches_job(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->create('exam.zip', 256, 'application/zip');
        $this->mockValidCampusSession('token-exam-upload');
        // The upload is checked against the student's exam list.
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')
                ->withArgs(fn (string $sessionToken, string $dataset): bool => $dataset === 'exams')
                ->andReturn(['data' => ['exams' => [['id' => 8833, 'can_upload' => true]]], 'meta' => []]);
        }));

        $response = $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', [
                'id' => 8833,
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonStructure(['data' => ['operation_id']]);

        Queue::assertPushed(ProcessPortalExamUploadJob::class);
    }

    public function test_refresh_endpoint_dispatches_background_refresh_job(): void
    {
        Queue::fake();
        $this->mockValidCampusSession('token-refresh');

        $response = $this->withSession(['campus_session_token' => 'token-refresh'])
            ->postJson('/api/refresh', [
                'domains' => ['dashboard', 'assignments'],
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.domains', ['dashboard', 'assignments']);

        Queue::assertPushed(RefreshPortalDataJob::class);
    }

    private function mockValidCampusSession(string $token): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('validateSession')->andReturn(true);
                $mock->shouldReceive('getSession')->andReturn([
                    'token' => $token,
                    'is_active' => true,
                ]);
            })
        );

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class)
        );

        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class)
        );
    }
}
