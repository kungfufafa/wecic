<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PortalOperationStage;
use App\Events\PortalUploadProgressed;
use App\Exceptions\PortalOfflineException;
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

        Storage::fake();
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
                'status' => 'success',
                'data' => [
                    'status' => 'queued',
                ],
            ])
            ->assertJsonStructure(['data' => ['operation_id'], 'meta' => ['timestamp']]);

        // The upload starts right after the response instead of waiting for a queue worker.
        Bus::assertDispatchedAfterResponse(ProcessPortalAssignmentUploadJob::class);

        $operation = app(PortalOperationTracker::class)->get($response->json('data.operation_id'));
        $this->assertSame('assignment_upload', $operation['type']);
        $this->assertNotEmpty($operation['session_id']);
    }

    public function test_upload_assignment_carries_the_override_flag_to_the_job(): void
    {
        Bus::fake();

        $this->mockValidCampusSession('token-override');

        $this->withSession(['campus_session_token' => 'token-override'])
            ->post('/api/assignment', [
                'courses_id' => 9496,
                'id' => 36633,
                'pertemuan' => 4,
                'allow_closed' => '1',
                'attachment' => UploadedFile::fake()->create('task.pdf', 64, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(
            ProcessPortalAssignmentUploadJob::class,
            fn (ProcessPortalAssignmentUploadJob $job): bool => (new \ReflectionProperty($job, 'allowClosed'))->getValue($job) === true
        );
    }

    public function test_upload_assignment_does_not_override_the_closed_window_by_default(): void
    {
        Bus::fake();

        $this->mockValidCampusSession('token-default');

        $this->withSession(['campus_session_token' => 'token-default'])
            ->post('/api/assignment', [
                'courses_id' => 9496,
                'id' => 36633,
                'pertemuan' => 4,
                'attachment' => UploadedFile::fake()->create('task.pdf', 64, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(
            ProcessPortalAssignmentUploadJob::class,
            fn (ProcessPortalAssignmentUploadJob $job): bool => (new \ReflectionProperty($job, 'allowClosed'))->getValue($job) === false
        );
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
                        'attendance_target_reliable' => true,
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

    public function test_mark_attendance_refuses_a_shortcut_target_without_todays_material(): void
    {
        $this->mockValidCampusSession('token-context');

        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('resolveAttendanceShortcutForClass')->once()->andReturn([
                    'attendance_target_reliable' => false,
                    'attendance_payload' => ['courseid' => '8837', 'modulesid' => '29001', 'pertemuan' => 1],
                ]);
            })
        );
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldNotReceive('submitAttendance');
            })
        );

        $this->withSession(['campus_session_token' => 'token-context'])
            ->postJson('/api/attendance', [
                'kode' => '35533429',
                'shortcut_context' => ['class_id' => 2265, 'dosen_id' => 5240, 'course_id' => 8810],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_mark_attendance_sends_a_typed_hex_code_like_a_scanned_one(): void
    {
        $this->mockValidCampusSession('token-typed-code');
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('invalidate')->once();
        }));
        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('submitAttendance')
                    ->once()
                    ->withArgs(fn ($token, $courseId, $moduleId, $meetingNumber, $overrides): bool => $overrides['kode'] === '8a2b9c1d')
                    ->andReturn(['success' => true, 'message' => 'Absensi berhasil dicatat.', 'data' => ['status' => 'marked']]);
            })
        );

        $this->withSession(['campus_session_token' => 'token-typed-code'])
            ->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'pertemuan' => 2, 'kode' => ' 8A2B9C1D '])
            ->assertOk();
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

    public function test_pending_assignments_explain_offline_mode_instead_of_failing(): void
    {
        $this->mockValidCampusSession('token-offline');
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')->once()->andThrow(PortalOfflineException::offlineSession());
        }));

        $this->withSession(['campus_session_token' => 'token-offline'])
            ->getJson('/api/assignment/pending')
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.offline', true)
            ->assertJsonStructure(['meta' => ['timestamp']]);
    }

    public function test_exam_list_explains_offline_mode_instead_of_failing(): void
    {
        $this->mockValidCampusSession('token-offline');
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')->once()->andThrow(PortalOfflineException::offlineSession());
        }));

        $this->withSession(['campus_session_token' => 'token-offline'])
            ->getJson('/api/exam')
            ->assertStatus(503)
            ->assertJsonPath('meta.offline', true);
    }

    public function test_upload_assignment_refuses_a_second_upload_of_the_same_assignment_while_the_first_runs(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-123', 2);

        $first = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task-lagi.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data.operation_id', $first->json('data.operation_id'));

        Bus::assertDispatchedAfterResponseTimes(ProcessPortalAssignmentUploadJob::class, 1);
    }

    public function test_an_upload_file_is_named_after_its_operation_which_remembers_its_lock(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-123', 2);
        $this->mockExamListSnapshot('token-123', $this->openExamRow());

        $response = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        $operationId = (string) $response->json('data.operation_id');
        $upload = app(PortalOperationTracker::class)->get($operationId)['upload'];

        $this->assertSame('portal-uploads/'.$operationId.'.pdf', $upload['file']);
        Storage::assertExists($upload['file']);
        $this->assertStringEndsWith(':assignment:9000:29833:2', $upload['lock_key']);
        $this->assertFalse(Cache::lock($upload['lock_key'], 600)->get());
        $this->assertTrue(Cache::restoreLock($upload['lock_key'], $upload['lock_owner'])->release());
        Bus::assertDispatchedAfterResponse(
            ProcessPortalAssignmentUploadJob::class,
            fn (ProcessPortalAssignmentUploadJob $job): bool => (fn (): string => $this->storedFilePath)->call($job) === $upload['file']
        );

        $exam = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/exam', ['id' => 8833, 'attachment' => UploadedFile::fake()->create('uas.zip', 64, 'application/zip')], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Storage::assertExists('portal-uploads/'.$exam->json('data.operation_id').'.zip');
    }

    public function test_an_upload_that_cannot_be_registered_leaves_no_file_and_frees_its_target(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-123', 2);
        $tracker = Mockery::mock(PortalOperationTracker::class)->makePartial();
        $tracker->shouldReceive('start')->once()->andThrow(new \RuntimeException('Cache store unavailable'));
        $this->instance(PortalOperationTracker::class, $tracker);

        $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Upload tugas gagal diproses.');

        $this->assertSame([], Storage::allFiles());
        Bus::assertNotDispatchedAfterResponse(ProcessPortalAssignmentUploadJob::class);

        $tracker->shouldReceive('start')->passthru();

        $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);
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

    public function test_exam_detail_keeps_direct_upload_available_when_exam_list_snapshot_has_no_upload_url(): void
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
                            'portal_form_open' => false,
                            'fields' => [
                                'data[id]' => '8810',
                                'data[tipe]' => 'UTS',
                                'data[kelas]' => '',
                            ],
                        ],
                    ]);
            })
        );
        $this->mockExamListSnapshot('token-exam-detail', [
            'source' => 'class_schedule',
            'schedule' => 'Kamis, 26-11-2026 | 10:00 WIB Ujian : TEORI',
            'upload_url' => null,
            'can_upload' => false,
        ]);

        $response = $this->withSession(['campus_session_token' => 'token-exam-detail'])
            ->getJson('/api/exam/8810')
            ->assertOk()
            ->assertJsonPath('data.exam_detail.form.available', true)
            ->assertJsonPath('data.exam_detail.form.portal_form_open', false);

        $this->assertSame('TEORI', $response->json('data.exam_detail.form.fields')['data[kelas]']);
    }

    public function test_exam_detail_forwards_fresh_flag_to_exam_list_read(): void
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
        $this->instance(
            PortalDatasetRepository::class,
            Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
                $mock->shouldReceive('read')
                    ->once()
                    ->withArgs(fn (string $token, string $dataset, array $params = [], bool $forceFresh = false): bool => $token === 'token-exam-detail'
                        && $dataset === 'exams'
                        && $forceFresh === true)
                    ->andReturn([
                        'data' => ['exams' => [[
                            'id' => 8810,
                            'course_code' => 'IF101',
                            'course_name' => 'Algoritma',
                            'schedule' => 'Senin 09:30',
                            'status' => 'Terjadwal',
                            'upload_url' => '?mod=sipandai&act=sendujian&id=8810',
                            'can_upload' => true,
                        ]]],
                        'meta' => ['data_origin' => 'upstream', 'status' => 'synced'],
                    ]);
            })
        );

        $this->withSession(['campus_session_token' => 'token-exam-detail'])
            ->getJson('/api/exam/8810?fresh=1')
            ->assertOk()
            ->assertJsonPath('data.exam_detail.form.available', true);
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
        $this->mockExamListSnapshot('token-exam-upload', $this->openExamRow());

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

    public function test_upload_exam_refuses_an_id_outside_the_exam_list(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-exam-upload');
        $this->mockExamListSnapshot('token-exam-upload', $this->openExamRow());

        $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', [
                'id' => 9524,
                'attachment' => UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertNotFound()
            ->assertJsonPath('success', false);

        Bus::assertNotDispatchedAfterResponse(ProcessPortalExamUploadJob::class);
    }

    public function test_upload_exam_queues_direct_upload_even_when_exam_list_marks_upload_closed(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-exam-upload');
        $this->mockExamListSnapshot('token-exam-upload', [
            'id' => 8833,
            'source' => 'exam_list_with_class_id',
            'schedule' => 'Kamis, 26-11-2026 | 10:00 WIB Ujian : TEORI',
            'upload_url' => null,
            'can_upload' => false,
        ]);

        $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', [
                'id' => 8833,
                'attachment' => UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(ProcessPortalExamUploadJob::class, function (ProcessPortalExamUploadJob $job): bool {
            $formValues = (new \ReflectionProperty($job, 'formValues'))->getValue($job);

            return ($formValues['data[kelas]'] ?? null) === 'TEORI';
        });
    }

    public function test_upload_exam_preserves_explicit_exam_class_over_schedule_fallback(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-exam-upload');
        $this->mockExamListSnapshot('token-exam-upload', [
            'id' => 8833,
            'source' => 'exam_list_with_class_id',
            'schedule' => 'Kamis, 26-11-2026 | 10:00 WIB Ujian : TEORI',
            'upload_url' => '?mod=sipandai&act=sendujian&id=8833',
            'can_upload' => true,
        ]);

        $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', [
                'id' => 8833,
                'form_values' => ['data[kelas]' => 'PRAKTIKUM'],
                'attachment' => UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(ProcessPortalExamUploadJob::class, function (ProcessPortalExamUploadJob $job): bool {
            $formValues = (new \ReflectionProperty($job, 'formValues'))->getValue($job);

            return ($formValues['data[kelas]'] ?? null) === 'PRAKTIKUM';
        });
    }

    public function test_upload_exam_still_reaches_the_portal_check_when_the_exam_list_cannot_be_read(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-exam-upload');
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')->once()->andThrow(new \RuntimeException('Exam page failed'));
        }));

        $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', ['id' => 8833, 'attachment' => UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(ProcessPortalExamUploadJob::class);
    }

    public function test_upload_exam_refuses_a_second_upload_while_the_first_runs(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-exam-upload', 2);
        $this->mockExamListSnapshot('token-exam-upload', $this->openExamRow());

        $first = $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', ['id' => 8833, 'attachment' => UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(202);

        $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', ['id' => 8833, 'attachment' => UploadedFile::fake()->create('uas-revisi.pdf', 64, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('data.operation_id', $first->json('data.operation_id'));
    }

    public function test_an_upload_that_stopped_reporting_no_longer_blocks_the_next_one(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-exam-upload', 3);
        $this->mockExamListSnapshot('token-exam-upload', $this->openExamRow());

        $first = $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', ['id' => 8833, 'attachment' => UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(202);

        $this->travel(2)->minutes();

        $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', ['id' => 8833, 'attachment' => UploadedFile::fake()->create('uas-lagi.pdf', 64, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('data.operation_id', $first->json('data.operation_id'));

        $this->travel(2)->minutes();

        $retry = $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', ['id' => 8833, 'attachment' => UploadedFile::fake()->create('uas-lagi.pdf', 64, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(202);

        $this->assertNotSame($first->json('data.operation_id'), $retry->json('data.operation_id'));
        Bus::assertDispatchedAfterResponseTimes(ProcessPortalExamUploadJob::class, 2);
    }

    public function test_a_finished_upload_whose_lock_was_never_released_does_not_block_the_next_one(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-123', 2);

        $first = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        app(PortalOperationTracker::class)->progress((string) $first->json('data.operation_id'), PortalOperationStage::Unverified, 'Status upload tugas belum pasti.', 100);

        $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task-lagi.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponseTimes(ProcessPortalAssignmentUploadJob::class, 2);
    }

    public function test_upload_exam_uses_the_open_row_when_a_class_row_shares_its_id(): void
    {
        Bus::fake();
        $this->mockValidCampusSession('token-exam-upload');
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')->once()->andReturn([
                'data' => ['exams' => [
                    ['id' => 8833, 'course_name' => 'Algoritma', 'status' => 'UTS', 'source' => 'class_schedule_fallback', 'upload_url' => null, 'can_upload' => false],
                    ['id' => 8833, 'course_name' => 'Algoritma', 'status' => 'UAS', ...$this->openExamRow()],
                ]],
                'meta' => [],
            ]);
        }));

        $this->withSession(['campus_session_token' => 'token-exam-upload'])
            ->post('/api/exam', ['id' => 8833, 'attachment' => UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(ProcessPortalExamUploadJob::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function openExamRow(): array
    {
        return [
            'id' => 8833,
            'upload_url' => '?mod=sipandai&act=sendujian&id=8833',
            'can_upload' => true,
        ];
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

    private function mockValidCampusSession(string $token, int $requests = 1): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token, $requests): void {
                $mock->shouldReceive('validateSession')
                    ->times($requests)
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }
}
