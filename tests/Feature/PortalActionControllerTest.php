<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalUploadProgressed;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Jobs\ProcessPortalExamUploadJob;
use App\Services\AttendanceShortcutService;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalActionProjector;
use App\Services\PortalDatasetRepository;
use App\Services\PortalOperationTracker;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalActionControllerTest extends TestCase
{
    public function test_mutating_action_rejects_missing_csrf_token_when_csrf_middleware_is_enabled(): void
    {
        $this->withMiddleware(VerifyCsrfTokenMiddleware::class);

        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $response = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(419);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfTokenMiddleware::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();

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
        Bus::fake();

        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $this->mockValidCampusSession('token-123');

        $response = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'form_values' => ['keterangan' => 'Test upload'],
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'queued',
                ],
            ])
            ->assertJsonStructure(['data' => ['operation_id']]);

        // The upload starts right after the response instead of waiting for a queue worker.
        Bus::assertDispatchedAfterResponse(ProcessPortalAssignmentUploadJob::class);

        $operation = app(PortalOperationTracker::class)->get($response->json('data.operation_id'));
        $this->assertSame('assignment_upload', $operation['type']);
        $this->assertNotEmpty($operation['session_id']);
    }

    public function test_upload_assignment_runs_after_the_response_and_clears_the_pending_task(): void
    {
        Storage::fake();
        Event::fake([PortalUploadProgressed::class]);

        $snapshots = app(PortalSnapshotStore::class);
        $snapshots->put('token-run', 'assignments', ['assignments' => [
            ['meeting_id' => '29833', 'assignment_payload' => ['courses_id' => '9000', 'id' => '29833', 'pertemuan' => 2]],
        ]]);

        $this->mockValidCampusSession('token-run');
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('submitAssignment')->once()->andReturn([
                    'success' => true,
                    'message' => 'File uploaded successfully.',
                    'data' => ['status' => 'uploaded'],
                ]);
                $mock->shouldReceive('inspectAssignmentSubmissionState')->once()->andReturn([
                    'status' => 'uploaded',
                    'file_info' => ['href' => 'files/task.pdf', 'text' => 'task.pdf'],
                    'delete_payload' => ['id' => '126663', 'idx' => '29833', 'pertemuan' => '2'],
                ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-run'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(202);

        $operation = app(PortalOperationTracker::class)->get($response->json('data.operation_id'));
        $this->assertSame('completed', $operation['stage']);
        $this->assertSame([], $snapshots->get('token-run', 'assignments')['data']['assignments']);
        Event::assertDispatched(
            PortalUploadProgressed::class,
            fn (PortalUploadProgressed $event): bool => $event->payload['status'] === 'completed'
        );
    }

    public function test_upload_assignment_can_resolve_target_from_meeting_context(): void
    {
        Queue::fake();

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
        $response = $this->withSession(['campus_session_token' => 'token-meeting-upload'])
            ->post('/api/assignment', [
                'courses_id' => 9202,
                'meeting_id' => 33244,
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'queued',
                ],
            ]);

        Queue::assertPushed(ProcessPortalAssignmentUploadJob::class);
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

    public function test_upload_assignment_queues_job_when_session_is_still_valid(): void
    {
        Queue::fake();

        $file = UploadedFile::fake()->create('task.pdf', 128, 'application/pdf');

        $this->mockValidCampusSession('token-expired-upload');

        $response = $this->withSession(['campus_session_token' => 'token-expired-upload'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'queued',
                ],
            ]);

        Queue::assertPushed(ProcessPortalAssignmentUploadJob::class);
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

    public function test_action_routes_are_rate_limited_per_session_token(): void
    {
        config([
            'portal.security.action_rate_limit_attempts' => 1,
            'portal.security.action_rate_limit_decay' => 60,
        ]);

        $this->mockValidCampusSession('token-rate-limited-action');
        $this->mockServiceExpectingSubmitAttendance('token-rate-limited-action');

        $this->withSession(['campus_session_token' => 'token-rate-limited-action'])
            ->postJson('/api/attendance', [
                'courseid' => 8837,
                'modulesid' => 29808,
                'kode' => '35533429',
                'pertemuan' => 2,
            ])
            ->assertOk();

        $this->withSession(['campus_session_token' => 'token-rate-limited-action'])
            ->postJson('/api/attendance', [
                'courseid' => 8837,
                'modulesid' => 29808,
                'kode' => '35533429',
                'pertemuan' => 2,
            ])
            ->assertStatus(429);
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

    public function test_mark_attendance_success_shows_the_class_as_attended_right_away(): void
    {
        $snapshots = app(PortalSnapshotStore::class);
        $snapshots->put('token-abc', 'dashboard', ['classes_summary' => ['today_classes' => [[
            'attendance' => [
                'actionable' => true,
                'meeting_id' => '29808',
                'status' => ['already_marked' => false, 'status' => null, 'label' => null, 'time' => null],
                'payload' => ['courseid' => '8837', 'modulesid' => '29808', 'pertemuan' => 2],
            ],
        ]]]]);

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
            ->assertJsonPath('data.attendance.already_marked', true)
            ->assertJsonPath('data.attendance.label', 'Hadir');

        $attendance = $snapshots->get('token-abc', 'dashboard')['data']['classes_summary']['today_classes'][0]['attendance'];
        $this->assertTrue($attendance['status']['already_marked']);
        $this->assertFalse($attendance['actionable']);
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

    public function test_delete_assignment_with_course_id_reopens_the_upload_form_right_away(): void
    {
        $params = ['meeting_id' => '30095', 'courses_id' => '9000'];
        $snapshots = app(PortalSnapshotStore::class);
        $snapshots->put('token-del', 'meeting_detail', ['assignment_info' => [
            'status' => 'uploaded',
            'upload_link' => '?act=send&courses_id=9000&id=30095&pertemuan=6',
            'submission' => ['file_info' => ['href' => 'files/task.pdf']],
            'delete_payload' => ['id' => '126663', 'idx' => '30095', 'pertemuan' => '6'],
        ]], params: $params);

        $this->mockValidCampusSession('token-del');
        $this->mockServiceExpectingDeleteAssignment('token-del');

        $this->withSession(['campus_session_token' => 'token-del'])
            ->deleteJson('/api/assignment', [
                'id' => 126663,
                'idx' => 30095,
                'pertemuan' => 6,
                'courses_id' => 9000,
            ])
            ->assertStatus(200);

        $assignmentInfo = $snapshots->get('token-del', 'meeting_detail', $params)['data']['assignment_info'];
        $this->assertSame('not_uploaded', $assignmentInfo['status']);
        $this->assertArrayNotHasKey('delete_payload', $assignmentInfo);
    }

    public function test_inconclusive_delete_is_confirmed_on_the_assignment_page(): void
    {
        $this->mockValidCampusSession('token-del');
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('deleteAssignment')->once()->andReturn([
                    'success' => false,
                    'message' => 'Delete status could not be determined. Please verify manually.',
                    'data' => ['status' => 'unknown'],
                ]);
                $mock->shouldReceive('inspectAssignmentSubmissionState')
                    ->once()
                    ->with('token-del', 9000, 30095, 6)
                    ->andReturn(['status' => 'not_uploaded', 'delete_payload' => null, 'file_info' => null]);
            })
        );

        $this->withSession(['campus_session_token' => 'token-del'])
            ->deleteJson('/api/assignment', ['id' => 126663, 'idx' => 30095, 'pertemuan' => 6, 'courses_id' => 9000])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'deleted');
    }

    public function test_unconfirmed_delete_stops_showing_the_recorded_upload(): void
    {
        $params = ['meeting_id' => '30095', 'courses_id' => '9000'];
        $projector = app(PortalActionProjector::class);
        $projector->recordAssignmentUploaded('token-del', 9000, 30095, 6, ['href' => 'files/task.pdf']);

        $this->mockValidCampusSession('token-del');
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('deleteAssignment')->once()->andReturn([
                    'success' => false,
                    'message' => 'Delete status could not be determined. Please verify manually.',
                    'data' => ['status' => 'unknown'],
                ]);
                $mock->shouldReceive('inspectAssignmentSubmissionState')->once()->andReturn(['status' => 'unknown']);
            })
        );

        $this->withSession(['campus_session_token' => 'token-del'])
            ->deleteJson('/api/assignment', ['id' => 126663, 'idx' => 30095, 'pertemuan' => 6, 'courses_id' => 9000])
            ->assertStatus(422);

        $scraped = ['assignment_info' => ['status' => 'not_uploaded', 'upload_link' => '?act=send']];
        $this->assertSame($scraped, $projector->apply('token-del', 'meeting_detail', $params, $scraped));
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

    public function test_list_exams_falls_back_to_sipandai_classes_when_exam_page_is_empty(): void
    {
        $this->mockValidCampusSession('token-exam-class-fallback');
        $this->mockServiceExpectingEmptyExamList('token-exam-class-fallback');
        $this->mockSipandaiClassesExtractorExpectingExamFallback('token-exam-class-fallback');

        $response = $this->withSession(['campus_session_token' => 'token-exam-class-fallback'])
            ->getJson('/api/exam');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.exams.0.id', 8810)
            ->assertJsonPath('data.exams.0.course_code', 'UC01024011')
            ->assertJsonPath('data.exams.0.course_name', 'Bahasa Indonesia')
            ->assertJsonPath('data.exams.0.schedule', 'Senin 09:30 - 11:00')
            ->assertJsonPath('data.exams.0.source', 'class_schedule')
            ->assertJsonPath('data.exams.0.upload_url', null)
            ->assertJsonPath('data.exams.0.can_upload', false);
    }

    public function test_list_exams_fills_missing_exam_ids_from_sipandai_classes(): void
    {
        $this->mockValidCampusSession('token-exam-id-fallback');
        $this->mockServiceExpectingExamListWithoutIds('token-exam-id-fallback');
        $this->mockSipandaiClassesExtractorExpectingExamFallback('token-exam-id-fallback');

        $response = $this->withSession(['campus_session_token' => 'token-exam-id-fallback'])
            ->getJson('/api/exam');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.exams.0.id', 8810)
            ->assertJsonPath('data.exams.0.course_code', 'UC01024011')
            ->assertJsonPath('data.exams.0.source', 'exam_list_with_class_id');
    }

    public function test_list_exams_fills_missing_ids_when_exam_course_text_is_concatenated(): void
    {
        $this->mockValidCampusSession('token-exam-concat-id-fallback');
        $this->mockServiceExpectingConcatenatedExamListWithoutIds('token-exam-concat-id-fallback');
        $this->mockSipandaiClassesExtractorExpectingMachineLearningFallback('token-exam-concat-id-fallback');

        $response = $this->withSession(['campus_session_token' => 'token-exam-concat-id-fallback'])
            ->getJson('/api/exam');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.exams.0.id', 9204)
            ->assertJsonPath('data.exams.0.course_code', 'SI043020Machine Learning')
            ->assertJsonPath('data.exams.0.source', 'exam_list_with_class_id');
    }

    public function test_list_exams_falls_back_to_sipandai_classes_when_exam_page_fails(): void
    {
        $this->mockValidCampusSession('token-exam-page-failed');
        $this->mockServiceExpectingFailedExamList('token-exam-page-failed');
        $this->mockSipandaiClassesExtractorExpectingExamFallback('token-exam-page-failed');

        $response = $this->withSession(['campus_session_token' => 'token-exam-page-failed'])
            ->getJson('/api/exam');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Exam list retrieved from class schedule fallback.')
            ->assertJsonPath('data.exams.0.id', 8810)
            ->assertJsonPath('data.exams.0.source', 'class_schedule');
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
        $this->mockExamListSnapshot('token-exam-detail');

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
                        // The upload page has no course name; the exam list does.
                        'course' => [
                            'code' => 'IF101',
                            'name' => 'Algoritma',
                        ],
                    ],
                ],
            ]);
    }

    public function test_exam_detail_keeps_upload_closed_for_an_exam_id_guessed_from_the_class_list(): void
    {
        $this->mockValidCampusSession('token-exam-detail');
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('getExamDetail')
                    ->once()
                    ->with('token-exam-detail', 8810)
                    ->andReturn([
                        'exam_id' => 8810,
                        'status' => 'not_uploaded',
                        'form' => [
                            'file_field' => 'file',
                            'available' => true,
                        ],
                    ]);
            })
        );
        $this->mockExamListSnapshot('token-exam-detail', [
            'source' => 'class_schedule',
            'upload_url' => null,
            'can_upload' => false,
        ]);

        $this->withSession(['campus_session_token' => 'token-exam-detail'])
            ->getJson('/api/exam/8810')
            ->assertOk()
            ->assertJsonPath('data.exam_detail.form.available', false);
    }

    public function test_exam_detail_is_not_found_for_an_id_outside_the_exam_list(): void
    {
        $this->mockValidCampusSession('token-exam-detail');
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldNotReceive('getExamDetail');
            })
        );
        $this->mockExamListSnapshot('token-exam-detail');

        $this->withSession(['campus_session_token' => 'token-exam-detail'])
            ->getJson('/api/exam/999999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_upload_assignment_rejects_files_that_are_not_pdf(): void
    {
        $this->mockValidCampusSession('token-123');

        $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task.zip', 128, 'application/zip'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.attachment.0', 'File tugas harus berformat PDF.');
    }

    /**
     * @param  array<string, mixed>  $examOverrides
     */
    private function mockExamListSnapshot(string $token, array $examOverrides = []): void
    {
        $this->instance(
            PortalDatasetRepository::class,
            Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock) use ($token, $examOverrides): void {
                $mock->shouldReceive('read')
                    ->withArgs(fn (string $sessionToken, string $dataset): bool => $sessionToken === $token && $dataset === 'exams')
                    ->andReturn([
                        'data' => ['exams' => [
                            [
                                'id' => 8810,
                                'course_code' => 'IF101',
                                'course_name' => 'Algoritma',
                                'schedule' => 'Senin 09:30',
                                'status' => 'Terjadwal',
                                ...$examOverrides,
                            ],
                        ]],
                        'meta' => [],
                    ]);
            })
        );
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
        Bus::fake();

        $file = UploadedFile::fake()->create('uts.zip', 256, 'application/zip');

        $this->mockValidCampusSession('token-exam-upload');

        $response = $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', [
                'id' => 8833,
                'form_values' => ['catatan' => 'Upload via test'],
                'attachment' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'queued',
                ],
            ])
            ->assertJsonStructure(['data' => ['operation_id']]);

        Bus::assertDispatchedAfterResponse(ProcessPortalExamUploadJob::class);
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
            PortalDatasetRepository::class,
            Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('invalidate')
                    ->once()
                    ->with(
                        $token,
                        ['dashboard', 'assignments', 'class_detail', 'meeting_detail'],
                        ['class_detail', 'meeting_detail']
                    );
            })
        );

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

    private function mockServiceExpectingEmptyExamList(string $token): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('getExamList')
                    ->once()
                    ->with($token)
                    ->andReturn([]);
            })
        );
    }

    private function mockServiceExpectingExamListWithoutIds(string $token): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('getExamList')
                    ->once()
                    ->with($token)
                    ->andReturn([
                        [
                            'id' => null,
                            'course_code' => 'UC01024011',
                            'course_name' => 'Bahasa Indonesia',
                            'schedule' => 'Senin 09:30 - 11:00',
                            'status' => 'Terjadwal',
                            'upload_url' => null,
                        ],
                    ]);
            })
        );
    }

    private function mockServiceExpectingConcatenatedExamListWithoutIds(string $token): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('getExamList')
                    ->once()
                    ->with($token)
                    ->andReturn([
                        [
                            'id' => null,
                            'course_code' => 'SI043020Machine Learning',
                            'course_name' => 'SI043020Machine Learning',
                            'schedule' => 'Senin, 18 Mei 2026 13:00:00 Wib sd 17:00:00 Wib Ujian : TEORI',
                            'status' => 'Terjadwal',
                            'upload_url' => null,
                        ],
                    ]);
            })
        );
    }

    private function mockServiceExpectingFailedExamList(string $token): void
    {
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('getExamList')
                    ->once()
                    ->with($token)
                    ->andThrow(new \RuntimeException('Exam page failed'));
            })
        );
    }

    private function mockSipandaiClassesExtractorExpectingMachineLearningFallback(string $token): void
    {
        $this->instance(
            SipandaiClassesExtractor::class,
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->once()
                    ->with($token)
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'formatted_time' => '13:00 - 17:00',
                                ],
                                'course_info' => [
                                    'code' => 'SI043020',
                                    'name' => 'Machine Learning',
                                ],
                                'join_link' => [
                                    'params' => [
                                        'id' => '33744',
                                        'course_id' => '9204',
                                    ],
                                ],
                            ],
                        ],
                    ]);
            })
        );
    }

    private function mockSipandaiClassesExtractorExpectingExamFallback(string $token): void
    {
        $this->instance(
            SipandaiClassesExtractor::class,
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->once()
                    ->with($token)
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'start_time' => '09:30:00',
                                    'end_time' => '11:00:00',
                                    'time_range' => '09:30:00-11:00:00',
                                    'formatted_time' => '09:30 - 11:00',
                                ],
                                'course_info' => [
                                    'code' => 'UC01024011',
                                    'name' => 'Bahasa Indonesia',
                                ],
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
