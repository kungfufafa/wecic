<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Services\SipandaiSubmissionService;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalActionControllerTest extends TestCase
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

    public function test_upload_assignment_requires_session(): void
    {
        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $response = $this->post('/api/campus/assignments/upload', [
            'course_id' => 9000,
            'discussion_id' => 29833,
            'meeting_number' => 2,
            'attachment' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_upload_assignment_success(): void
    {
        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $this->mockServiceExpectingSubmitAssignment('token-123', $file);

        $response = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/campus/assignments/upload', [
                'course_id' => 9000,
                'discussion_id' => 29833,
                'meeting_number' => 2,
                'form_values' => ['keterangan' => 'Test upload'],
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'meta' => [
                    'source' => 'sipandai_assignment_upload',
                ],
            ]);
    }

    public function test_mark_attendance_requires_session(): void
    {
        $response = $this->postJson('/api/campus/attendance/mark', [
            'course_id' => 8837,
            'discussion_id' => 29808,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_mark_attendance_success(): void
    {
        $this->mockServiceExpectingSubmitAttendance('token-abc');

        $response = $this->withSession(['campus_session_token' => 'token-abc'])
            ->postJson('/api/campus/attendance/mark', [
                'course_id' => 8837,
                'discussion_id' => 29808,
                'meeting_number' => 2,
                'form_values' => ['status' => 'Hadir'],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'meta' => [
                    'source' => 'sipandai_attendance',
                ],
            ]);
    }

    private function mockServiceExpectingSubmitAssignment(string $token, UploadedFile $expectedFile): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token, $expectedFile): void {
                $mock->shouldReceive('submitAssignment')
                    ->once()
                    ->withArgs(function ($sessionToken, $courseId, $discussionId, $meetingNumber, $file, $overrides) use ($token, $expectedFile) {
                        return $sessionToken === $token
                            && $courseId === 9000
                            && $discussionId === 29833
                            && $meetingNumber === 2
                            && $file instanceof UploadedFile
                            && $file->getClientOriginalName() === $expectedFile->getClientOriginalName()
                            && isset($overrides['keterangan']);
                    })
                    ->andReturn([
                        'success' => true,
                        'message' => 'Uploaded',
                        'data' => [
                            'alerts' => ['Uploaded'],
                        ],
                    ]);
            })
        );
    }

    private function mockServiceExpectingSubmitAttendance(string $token): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('submitAttendance')
                    ->once()
                    ->withArgs(function ($sessionToken, $courseId, $discussionId, $meetingNumber, $overrides) use ($token) {
                        return $sessionToken === $token
                            && $courseId === 8837
                            && $discussionId === 29808
                            && $meetingNumber === 2
                            && ($overrides['status'] ?? null) === 'Hadir';
                    })
                    ->andReturn([
                        'success' => true,
                        'message' => 'Attendance recorded',
                        'data' => [
                            'alerts' => ['Hadir'],
                        ],
                    ]);
            })
        );
    }
}
