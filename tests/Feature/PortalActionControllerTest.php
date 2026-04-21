<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalScrapingException;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Services\AttendanceShortcutService;
use App\Services\PortalSessionManager;
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

        $response = $this->post('/api/assignment', [
            'courses_id' => 9000,
            'id' => 29833,
            'pertemuan' => 2,
            'attachment' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_upload_assignment_success(): void
    {
        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $this->mockValidCampusSession('token-123');
        $this->mockServiceExpectingSubmitAssignment('token-123', $file);

        $response = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'form_values' => ['keterangan' => 'Test upload'],
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_upload_assignment_can_resolve_target_from_meeting_context(): void
    {
        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $this->mockValidCampusSession('token-meeting-upload');
        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('resolveAssignmentUploadPayload')
                    ->once()
                    ->with('token-meeting-upload', 33244, 9202)
                    ->andReturn([
                        'courses_id' => '9202',
                        'id' => '33244',
                        'pertemuan' => 5,
                    ]);
            })
        );
        $this->mockServiceExpectingSubmitAssignmentWithPayload('token-meeting-upload', $file, 9202, 33244, 5);

        $response = $this->withSession(['campus_session_token' => 'token-meeting-upload'])
            ->post('/api/assignment', [
                'courses_id' => 9202,
                'meeting_id' => 33244,
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_upload_assignment_returns_unprocessable_when_meeting_context_cannot_be_resolved(): void
    {
        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $this->mockValidCampusSession('token-meeting-missing');
        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('resolveAssignmentUploadPayload')
                    ->once()
                    ->with('token-meeting-missing', 33244, 9202)
                    ->andReturnNull();
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-meeting-missing'])
            ->post('/api/assignment', [
                'courses_id' => 9202,
                'meeting_id' => 33244,
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Target upload tugas belum bisa ditentukan untuk meeting ini.',
            ]);
    }

    public function test_upload_assignment_returns_unauthorized_when_upstream_session_has_expired(): void
    {
        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $this->mockValidCampusSession('token-expired-upload');
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('submitAssignment')
                    ->once()
                    ->andThrow(PortalScrapingException::pageNotAccessible('assignment form'));
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-expired-upload'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Campus session has expired. Please login again.',
            ]);
    }

    public function test_mark_attendance_requires_session(): void
    {
        $response = $this->postJson('/api/attendance', [
            'courseid' => 8837,
            'modulesid' => 29808,
            'kode' => '12345678',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_mark_attendance_success(): void
    {
        $this->mockValidCampusSession('token-abc');
        $this->mockServiceExpectingSubmitAttendance('token-abc');

        $response = $this->withSession(['campus_session_token' => 'token-abc'])
            ->postJson('/api/attendance', [
                'courseid' => 8837,
                'modulesid' => 29808,
                'kode' => '35533429',
                'pertemuan' => 2,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'marked',
                ],
            ]);
    }

    public function test_mark_attendance_accepts_portal_id_aliases(): void
    {
        $this->mockValidCampusSession('token-alias');
        $this->mockServiceExpectingSubmitAttendance('token-alias');

        $response = $this->withSession(['campus_session_token' => 'token-alias'])
            ->postJson('/api/attendance', [
                'course_id' => 8837,
                'id' => 29808,
                'kode' => '35533429',
                'meeting_number' => 2,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'marked',
                ],
            ]);
    }

    public function test_mark_attendance_accepts_legacy_form_values_code(): void
    {
        $this->mockValidCampusSession('token-legacy');
        $this->mockServiceExpectingSubmitAttendance('token-legacy');

        $response = $this->withSession(['campus_session_token' => 'token-legacy'])
            ->postJson('/api/attendance', [
                'course_id' => 8837,
                'discussion_id' => 29808,
                'meeting_number' => 2,
                'form_values' => [
                    'kode' => '35533429',
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'marked',
                ],
            ]);
    }

    public function test_mark_attendance_can_resolve_target_from_shortcut_context(): void
    {
        $this->mockValidCampusSession('token-context');

        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('resolveAttendanceShortcutForClass')
                    ->once()
                    ->with('token-context', [
                        'class_id' => 2265,
                        'dosen_id' => 5240,
                        'course_id' => 8810,
                    ])
                    ->andReturn([
                        'attendance_payload' => [
                            'courseid' => '8837',
                            'modulesid' => '29808',
                            'pertemuan' => 2,
                        ],
                    ]);
            })
        );

        $this->mockServiceExpectingSubmitAttendance('token-context');

        $response = $this->withSession(['campus_session_token' => 'token-context'])
            ->postJson('/api/attendance', [
                'kode' => '35533429',
                'shortcut_context' => [
                    'class_id' => 2265,
                    'dosen_id' => 5240,
                    'course_id' => 8810,
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'marked',
                ],
            ]);
    }

    public function test_attendance_today_shortcuts_requires_session(): void
    {
        $response = $this->getJson('/api/attendance/today');

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_pending_assignments_requires_session(): void
    {
        $response = $this->getJson('/api/assignment/pending');

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_attendance_today_shortcuts_success(): void
    {
        $this->mockValidCampusSession('token-shortcut');

        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('getTodayShortcuts')
                    ->once()
                    ->with('token-shortcut')
                    ->andReturn([
                        'date' => '2026-04-10',
                        'day' => 'Jumat',
                        'current_time' => '09:30',
                        'classes' => [
                            [
                                'course_name' => 'Algoritma',
                                'meeting_number' => 6,
                                'attendance_payload' => [
                                    'courseid' => '8837',
                                    'modulesid' => '29808',
                                    'pertemuan' => 6,
                                ],
                                'assignment_payload' => [
                                    'courses_id' => '8837',
                                    'id' => '29808',
                                    'pertemuan' => 6,
                                ],
                            ],
                        ],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-shortcut'])
            ->getJson('/api/attendance/today');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'day' => 'Jumat',
                    'classes' => [
                        [
                            'course_name' => 'Algoritma',
                            'meeting_number' => 6,
                        ],
                    ],
                ],
            ]);
    }

    public function test_pending_assignments_success(): void
    {
        $this->mockValidCampusSession('token-pending');
        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('getPendingAssignmentsSnapshot')
                    ->once()
                    ->with('token-pending')
                    ->andReturn([
                        'assignments' => [
                            [
                                'course_name' => 'Algoritma',
                                'meeting_number' => 6,
                                'meeting_url' => '/meeting/29808?courses_id=8837',
                                'can_submit_directly' => false,
                            ],
                        ],
                        '_sync' => [
                            'data_origin' => 'server_cache',
                            'synced_at' => '2026-04-21T07:00:00+07:00',
                        ],
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-pending'])
            ->getJson('/api/assignment/pending');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'assignments' => [
                        [
                            'course_name' => 'Algoritma',
                            'meeting_number' => 6,
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('meta.data_origin', 'server_cache');
    }

    public function test_delete_assignment_requires_session(): void
    {
        $response = $this->deleteJson('/api/assignment', [
            'id' => 126663,
            'idx' => 30095,
            'pertemuan' => 6,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_delete_assignment_success(): void
    {
        $this->mockValidCampusSession('token-del');
        $this->mockServiceExpectingDeleteAssignment('token-del');

        $response = $this->withSession(['campus_session_token' => 'token-del'])
            ->deleteJson('/api/assignment', [
                'id' => 126663,
                'idx' => 30095,
                'pertemuan' => 6,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_list_exams_requires_session(): void
    {
        $response = $this->getJson('/api/exam');

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_list_exams_success(): void
    {
        $this->mockValidCampusSession('token-exam-list');
        $this->mockServiceExpectingGetExamList('token-exam-list');

        $response = $this->withSession(['campus_session_token' => 'token-exam-list'])
            ->getJson('/api/exam');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'exams' => [
                        [
                            'id' => 8833,
                            'course_code' => 'IF101',
                        ],
                    ],
                ],
            ]);
    }

    public function test_exam_detail_requires_session(): void
    {
        $response = $this->getJson('/api/exam/8810');

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_exam_detail_success(): void
    {
        $this->mockValidCampusSession('token-exam-detail');
        $this->mockServiceExpectingGetExamDetail('token-exam-detail');

        $response = $this->withSession(['campus_session_token' => 'token-exam-detail'])
            ->getJson('/api/exam/8810');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'exam_detail' => [
                        'exam_id' => 8810,
                        'status' => 'not_uploaded',
                        'form' => [
                            'file_field' => 'file',
                        ],
                    ],
                ],
            ]);
    }

    public function test_upload_exam_requires_session(): void
    {
        $file = UploadedFile::fake()->create('uts.zip', 256, 'application/zip');

        $response = $this->post('/api/exam', [
            'id' => 8833,
            'attachment' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_upload_exam_success(): void
    {
        $file = UploadedFile::fake()->create('uts.zip', 256, 'application/zip');

        $this->mockValidCampusSession('token-exam-upload');
        $this->mockServiceExpectingSubmitExam('token-exam-upload', $file);

        $response = $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', [
                'id' => 8833,
                'form_values' => ['catatan' => 'Upload via test'],
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'uploaded',
                    'exam_id' => 8833,
                ],
            ]);
    }

    private function mockServiceExpectingSubmitAssignment(string $token, UploadedFile $expectedFile): void
    {
        $this->mockServiceExpectingSubmitAssignmentWithPayload($token, $expectedFile, 9000, 29833, 2);
    }

    private function mockServiceExpectingSubmitAssignmentWithPayload(
        string $token,
        UploadedFile $expectedFile,
        int $courseId,
        int $discussionId,
        int $meetingNumber
    ): void {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token, $expectedFile, $courseId, $discussionId, $meetingNumber): void {
                $mock->shouldReceive('submitAssignment')
                    ->once()
                    ->withArgs(function ($sessionToken, $actualCourseId, $actualDiscussionId, $actualMeetingNumber, $file, $overrides) use ($token, $expectedFile, $courseId, $discussionId, $meetingNumber) {
                        return $sessionToken === $token
                            && $actualCourseId === $courseId
                            && $actualDiscussionId === $discussionId
                            && $actualMeetingNumber === $meetingNumber
                            && $file instanceof UploadedFile
                            && $file->getClientOriginalName() === $expectedFile->getClientOriginalName()
                            && (isset($overrides['keterangan']) || $overrides === []);
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
                            && isset($overrides['kode'])
                            && $overrides['kode'] === '35533429';
                    })
                    ->andReturn([
                        'success' => true,
                        'message' => 'Attendance marked successfully.',
                        'data' => [
                            'status' => 'marked',
                            'course_id' => 8837,
                            'discussion_id' => 29808,
                            'meeting_number' => 2,
                        ],
                    ]);
            })
        );
    }

    private function mockServiceExpectingDeleteAssignment(string $token): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('deleteAssignment')
                    ->once()
                    ->withArgs(function ($sessionToken, $submissionId, $discussionId, $meetingNumber) use ($token) {
                        return $sessionToken === $token
                            && $submissionId === 126663
                            && $discussionId === 30095
                            && $meetingNumber === 6;
                    })
                    ->andReturn([
                        'success' => true,
                        'message' => 'Assignment deleted',
                        'data' => [
                            'alerts' => ['Deleted'],
                        ],
                    ]);
            })
        );
    }

    private function mockServiceExpectingGetExamList(string $token): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('getExamList')
                    ->once()
                    ->with($token)
                    ->andReturn([
                        [
                            'id' => 8833,
                            'course_code' => 'IF101',
                            'course_name' => 'Algoritma',
                            'status' => 'Belum Upload',
                        ],
                    ]);
            })
        );
    }

    private function mockServiceExpectingGetExamDetail(string $token): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('getExamDetail')
                    ->once()
                    ->with($token, 8810)
                    ->andReturn([
                        'exam_id' => 8810,
                        'status' => 'not_uploaded',
                        'form' => [
                            'file_field' => 'file',
                        ],
                    ]);
            })
        );
    }

    private function mockServiceExpectingSubmitExam(string $token, UploadedFile $expectedFile): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token, $expectedFile): void {
                $mock->shouldReceive('submitExam')
                    ->once()
                    ->withArgs(function ($sessionToken, $examId, $file, $overrides) use ($token, $expectedFile) {
                        return $sessionToken === $token
                            && $examId === 8833
                            && $file instanceof UploadedFile
                            && $file->getClientOriginalName() === $expectedFile->getClientOriginalName()
                            && ($overrides['catatan'] ?? null) === 'Upload via test';
                    })
                    ->andReturn([
                        'success' => true,
                        'message' => 'Exam uploaded successfully.',
                        'data' => [
                            'status' => 'uploaded',
                            'exam_id' => 8833,
                        ],
                    ]);
            })
        );
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
