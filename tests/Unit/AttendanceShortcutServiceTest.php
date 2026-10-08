<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AttendanceShortcutService;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use Carbon\CarbonImmutable;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AttendanceShortcutServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_marks_shortcut_attendance_as_recorded_when_meeting_has_explicit_attendance_details(): void
    {
        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-123', [
                        'id' => '2265',
                        'dosen_id' => '5240',
                        'course_id' => '8810',
                    ])
                    ->andReturn([
                        'course_materials' => [
                            [
                                'title' => 'Pertemuan 1',
                                'date' => '01 April 2026',
                                'join_link' => [
                                    'href' => '?id=440&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 2',
                                'date' => '02 April 2026',
                                'join_link' => [
                                    'href' => '?id=441&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 3',
                                'date' => '03 April 2026',
                                'join_link' => [
                                    'href' => '?id=442&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 4',
                                'date' => '04 April 2026',
                                'join_link' => [
                                    'href' => '?id=443&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 5',
                                'date' => '05 April 2026',
                                'join_link' => [
                                    'href' => '?id=444&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 6',
                                'date' => '13 April 2026',
                                'join_link' => [
                                    'href' => '?id=445&courses_id=8810',
                                ],
                            ],
                        ],
                        'attendance_info' => [
                            'meetings' => [
                                [
                                    'meeting_number' => 6,
                                    'status' => 'late',
                                    'details' => [
                                        'button_text' => 'H',
                                        'attendance_date' => '13 April 2026',
                                        'attendance_time' => '09:35',
                                    ],
                                ],
                            ],
                        ],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-123', [
                        'meeting_id' => '445',
                        'courses_id' => '8810',
                    ])
                    ->andReturn([
                        'attendance_info' => [
                            'status' => 'Hadir Absen Jam : 09:35:00',
                            'time' => '09:35:00',
                        ],
                    ]);
            }),
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->once()
                    ->with('token-123')
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'start_time' => '09:30:00',
                                    'end_time' => '11:00:00',
                                    'formatted_time' => '09:30 - 11:00',
                                ],
                                'course_info' => [
                                    'code' => 'IF101',
                                    'name' => 'Algoritma',
                                ],
                                'lecturer_info' => [
                                    'name' => 'Dosen Uji',
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
            }),
        );

        $result = $service->getTodayShortcuts(
            'token-123',
            CarbonImmutable::create(2026, 4, 13, 10, 0, 0, 'Asia/Jakarta')
        );

        $this->assertTrue($result['classes'][0]['attendance_status']['already_marked']);
        $this->assertSame('present', $result['classes'][0]['attendance_status']['status']);
        $this->assertSame('Hadir', $result['classes'][0]['attendance_status']['label']);
        $this->assertSame('09:35:00', $result['classes'][0]['attendance_status']['time']);
    }

    public function test_it_keeps_shortcut_attendance_action_when_only_absent_placeholder_exists(): void
    {
        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-456', [
                        'id' => '2265',
                        'dosen_id' => '5240',
                        'course_id' => '8810',
                    ])
                    ->andReturn([
                        'course_materials' => [
                            [
                                'title' => 'Pertemuan 1',
                                'date' => '01 April 2026',
                                'join_link' => [
                                    'href' => '?id=440&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 2',
                                'date' => '02 April 2026',
                                'join_link' => [
                                    'href' => '?id=441&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 3',
                                'date' => '03 April 2026',
                                'join_link' => [
                                    'href' => '?id=442&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 4',
                                'date' => '04 April 2026',
                                'join_link' => [
                                    'href' => '?id=443&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 5',
                                'date' => '05 April 2026',
                                'join_link' => [
                                    'href' => '?id=444&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 6',
                                'date' => '13 April 2026',
                                'join_link' => [
                                    'href' => '?id=445&courses_id=8810',
                                ],
                            ],
                        ],
                        'attendance_info' => [
                            'meetings' => [
                                [
                                    'meeting_number' => 6,
                                    'status' => 'absent',
                                    'details' => [
                                        'image_src' => '../images/bolos.jpg',
                                    ],
                                ],
                            ],
                        ],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-456', [
                        'meeting_id' => '445',
                        'courses_id' => '8810',
                        'fresh' => true,
                    ])
                    ->andReturn([
                        'attendance_info' => [
                            'status' => 'Silakan scan QR untuk absensi',
                            'time' => '',
                        ],
                    ]);
            }),
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->once()
                    ->with('token-456')
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'start_time' => '09:30:00',
                                    'end_time' => '11:00:00',
                                    'formatted_time' => '09:30 - 11:00',
                                ],
                                'course_info' => [
                                    'code' => 'IF101',
                                    'name' => 'Algoritma',
                                ],
                                'lecturer_info' => [
                                    'name' => 'Dosen Uji',
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
            }),
        );

        $result = $service->getTodayShortcuts(
            'token-456',
            CarbonImmutable::create(2026, 4, 13, 10, 0, 0, 'Asia/Jakarta')
        );

        $this->assertFalse($result['classes'][0]['attendance_status']['already_marked']);
        $this->assertSame('absent', $result['classes'][0]['attendance_status']['status']);
        $this->assertSame('Absen', $result['classes'][0]['attendance_status']['label']);
        $this->assertNull($result['classes'][0]['attendance_status']['time']);
    }

    public function test_it_exposes_pending_assignments_separately_from_today_attendance(): void
    {
        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->twice()
                    ->with('token-789', [
                        'id' => '2265',
                        'dosen_id' => '5240',
                        'course_id' => '8810',
                    ])
                    ->andReturn([
                        'course_materials' => [
                            [
                                'title' => 'Pertemuan 1',
                                'date' => '01 April 2026',
                                'join_link' => [
                                    'href' => '?id=440&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 2',
                                'date' => '13 April 2026',
                                'join_link' => [
                                    'href' => '?id=441&courses_id=8810',
                                ],
                            ],
                        ],
                        'attendance_info' => [
                            'meetings' => [],
                        ],
                        'assignment_history' => [
                            'assignments' => [
                                [
                                    'meeting' => '1',
                                ],
                            ],
                        ],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-789', [
                        'meeting_id' => '441',
                        'courses_id' => '8810',
                        'fresh' => true,
                    ])
                    ->andReturn([
                        'attendance_info' => [
                            'status' => '',
                            'time' => '',
                        ],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-789', [
                        'meeting_id' => '441',
                        'courses_id' => '8810',
                    ])
                    ->andReturn([
                        'assignment_info' => [
                            'deadline' => '14 April 2026 23:59',
                            'upload_link' => '?mod=sipandai&act=send&courses_id=8810&id=441&pertemuan=2',
                            'upload_text' => 'Upload Tugas Pertemuan 2',
                        ],
                        'materials' => [
                            'assignment_topic' => 'Pertemuan 2',
                        ],
                    ]);
            }),
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->twice()
                    ->with('token-789')
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'start_time' => '09:30:00',
                                    'end_time' => '11:00:00',
                                    'formatted_time' => '09:30 - 11:00',
                                ],
                                'course_info' => [
                                    'code' => 'IF101',
                                    'name' => 'Algoritma',
                                ],
                                'lecturer_info' => [
                                    'name' => 'Dosen Uji',
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
            }),
        );

        $attendanceResult = $service->getTodayShortcuts(
            'token-789',
            CarbonImmutable::create(2026, 4, 13, 10, 0, 0, 'Asia/Jakarta')
        );
        $pendingAssignments = $service->getPendingAssignments('token-789');

        $this->assertCount(1, $attendanceResult['classes']);
        $this->assertCount(1, $pendingAssignments);
        $this->assertSame('Algoritma', $pendingAssignments[0]['course_name']);
        $this->assertSame(2, $pendingAssignments[0]['meeting_number']);
        $this->assertSame('/meeting/441?courses_id=8810', $pendingAssignments[0]['meeting_url']);
        $this->assertTrue($pendingAssignments[0]['can_submit_directly']);
        $this->assertSame('8810', $pendingAssignments[0]['assignment_payload']['courses_id']);
    }

    public function test_it_keeps_pending_assignment_when_material_has_no_submission_history(): void
    {
        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-101', [
                        'id' => '1681',
                        'dosen_id' => '2744',
                        'course_id' => '9186',
                    ])
                    ->andReturn([
                        'course_materials' => [
                            [
                                'title' => 'Pertemuan 3',
                                'date' => '07 April 2026',
                                'meeting_number' => 3,
                                'join_link' => [
                                    'href' => '?id=32993&courses_id=9186',
                                ],
                            ],
                        ],
                        'attendance_info' => [
                            'meetings' => [],
                        ],
                        'assignment_history' => [
                            'assignments' => [],
                        ],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-101', [
                        'meeting_id' => '32993',
                        'courses_id' => '9186',
                    ])
                    ->andReturn([
                        'assignment_info' => [
                            'deadline' => ':',
                            'upload_link' => '',
                            'upload_text' => '',
                        ],
                        'materials' => [
                            'assignment_topic' => 'Pertemuan 3',
                            'assignment_instructions' => 'Jumat, 10 April 2026',
                            'assignment_material_iframe' => '../files/latihan/task.pdf',
                        ],
                    ]);
            }),
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->once()
                    ->with('token-101')
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'start_time' => '09:30:00',
                                    'end_time' => '11:00:00',
                                    'formatted_time' => '09:30 - 11:00',
                                ],
                                'course_info' => [
                                    'code' => 'UC01022004',
                                    'name' => 'Teknologi dan trend E-commerce',
                                ],
                                'lecturer_info' => [
                                    'name' => 'Lena Magdalena, MMSI.',
                                ],
                                'join_link' => [
                                    'params' => [
                                        'id' => '1681',
                                        'dosen_id' => '2744',
                                        'course_id' => '9186',
                                    ],
                                ],
                            ],
                        ],
                    ]);
            }),
        );

        $pendingAssignments = $service->getPendingAssignments('token-101');

        $this->assertCount(1, $pendingAssignments);
        $this->assertSame('Teknologi dan trend E-commerce', $pendingAssignments[0]['course_name']);
        $this->assertTrue($pendingAssignments[0]['can_submit_directly']);
        $this->assertSame('/meeting/32993?courses_id=9186', $pendingAssignments[0]['meeting_url']);
        $this->assertSame('9186', $pendingAssignments[0]['assignment_payload']['courses_id']);
    }

    public function test_it_uses_latest_detected_material_for_display_when_today_material_is_missing(): void
    {
        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-202', [
                        'id' => '2265',
                        'dosen_id' => '5240',
                        'course_id' => '8810',
                    ])
                    ->andReturn([
                        'course_materials' => [
                            [
                                'title' => 'Pertemuan 14',
                                'date' => '10 April 2026',
                                'meeting_number' => 14,
                                'join_link' => [
                                    'href' => '?id=454&courses_id=8810',
                                ],
                            ],
                        ],
                        'attendance_info' => [
                            'summary' => [
                                'total_meetings' => 14,
                            ],
                            'meetings' => [],
                        ],
                    ]);

                // Without today's material the latest meeting is last week's:
                // its attendance must not be read as today's.
                $mock->shouldNotReceive('extractMeetingDetail');
            }),
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->once()
                    ->with('token-202')
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'start_time' => '09:30:00',
                                    'end_time' => '11:00:00',
                                    'formatted_time' => '09:30 - 11:00',
                                ],
                                'course_info' => [
                                    'code' => 'IF101',
                                    'name' => 'Algoritma',
                                ],
                                'lecturer_info' => [
                                    'name' => 'Dosen Uji',
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
            }),
        );

        $result = $service->getTodayShortcuts(
            'token-202',
            CarbonImmutable::create(2026, 4, 13, 10, 0, 0, 'Asia/Jakarta')
        );

        $this->assertSame(15, $result['classes'][0]['meeting_number']);
        $this->assertSame('Pertemuan 14', $result['classes'][0]['today_material']['title']);
        $this->assertSame(14, $result['classes'][0]['today_material']['meeting_number']);
        $this->assertFalse($result['classes'][0]['today_material']['is_today']);
        $this->assertFalse($result['classes'][0]['attendance_target_reliable']);
        $this->assertFalse($result['classes'][0]['attendance_status']['already_marked']);
    }

    public function test_it_uses_app_timezone_when_reference_is_not_provided(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 20, 22, 24, 0, 'UTC'));

        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-timezone', [
                        'id' => '3001',
                        'dosen_id' => '9001',
                        'course_id' => '9921',
                    ])
                    ->andReturn([
                        'course_materials' => [
                            [
                                'title' => 'Pertemuan 1',
                                'date' => '21 April 2026',
                                'meeting_number' => 1,
                                'join_link' => [
                                    'href' => '?id=551&courses_id=9921',
                                ],
                                'time' => '07:30',
                            ],
                        ],
                        'attendance_info' => [
                            'meetings' => [],
                        ],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-timezone', [
                        'meeting_id' => '551',
                        'courses_id' => '9921',
                        'fresh' => true,
                    ])
                    ->andReturn([
                        'attendance_info' => [
                            'status' => '',
                            'time' => '',
                        ],
                    ]);
            }),
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->once()
                    ->with('token-timezone')
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'start_time' => '07:30:00',
                                    'end_time' => '09:00:00',
                                    'formatted_time' => '07:30 - 09:00',
                                ],
                                'course_info' => [
                                    'code' => 'OLD101',
                                    'name' => 'Kelas Kemarin',
                                ],
                                'lecturer_info' => [
                                    'name' => 'Dosen Lama',
                                ],
                                'join_link' => [
                                    'params' => [
                                        'id' => '3000',
                                        'dosen_id' => '9000',
                                        'course_id' => '9920',
                                    ],
                                ],
                            ],
                            [
                                'schedule' => [
                                    'day' => 'Selasa',
                                    'start_time' => '07:30:00',
                                    'end_time' => '09:00:00',
                                    'formatted_time' => '07:30 - 09:00',
                                ],
                                'course_info' => [
                                    'code' => 'TZ101',
                                    'name' => 'Kelas Hari Ini',
                                ],
                                'lecturer_info' => [
                                    'name' => 'Dosen Zona Waktu',
                                ],
                                'join_link' => [
                                    'params' => [
                                        'id' => '3001',
                                        'dosen_id' => '9001',
                                        'course_id' => '9921',
                                    ],
                                ],
                            ],
                        ],
                    ]);
            }),
        );

        $result = $service->getTodayShortcuts('token-timezone');

        $this->assertSame('2026-04-21', $result['date']);
        $this->assertSame('Selasa', $result['day']);
        $this->assertSame('05:24', $result['current_time']);
        $this->assertCount(1, $result['classes']);
        $this->assertSame('TZ101', $result['classes'][0]['course_code']);
        $this->assertSame('Selasa', $result['classes'][0]['schedule']['day']);
    }

    public function test_it_can_resolve_assignment_upload_payload_from_meeting_materials(): void
    {
        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-assignment-payload', [
                        'meeting_id' => '33244',
                        'courses_id' => '9202',
                    ])
                    ->andReturn([
                        'assignment_info' => [
                            'deadline' => 'Selasa, 21 April 2026',
                            'upload_link' => '',
                            'upload_text' => '',
                        ],
                        'materials' => [
                            'assignment_topic' => 'Pertemuan 5',
                            'main_topic' => 'Materi Pertemuan 5',
                        ],
                    ]);
            }),
            Mockery::mock(SipandaiClassesExtractor::class),
        );

        $payload = $service->resolveAssignmentUploadPayload('token-assignment-payload', 33244, 9202);

        $this->assertSame([
            'courses_id' => '9202',
            'id' => '33244',
            'pertemuan' => 5,
        ], $payload);
    }

    public function test_it_retries_assignment_upload_payload_with_fresh_meeting_detail_when_cache_is_incomplete(): void
    {
        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-assignment-refresh', [
                        'meeting_id' => '33244',
                        'courses_id' => '9202',
                    ])
                    ->andReturn([
                        '_sync' => [
                            'data_origin' => 'server_cache',
                        ],
                        'assignment_info' => [
                            'upload_link' => '',
                        ],
                        'materials' => [],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-assignment-refresh', [
                        'meeting_id' => '33244',
                        'courses_id' => '9202',
                        'fresh' => true,
                    ])
                    ->andReturn([
                        '_sync' => [
                            'data_origin' => 'upstream',
                        ],
                        'assignment_info' => [
                            'upload_link' => '?mod=sipandai&act=send&courses_id=9202&id=99887&pertemuan=5',
                        ],
                        'materials' => [],
                    ]);
            }),
            Mockery::mock(SipandaiClassesExtractor::class),
        );

        $payload = $service->resolveAssignmentUploadPayload('token-assignment-refresh', 33244, 9202);

        $this->assertSame([
            'courses_id' => '9202',
            'id' => '99887',
            'pertemuan' => 5,
        ], $payload);
    }

    public function test_it_returns_multiple_pending_assignments_from_the_same_class(): void
    {
        $service = new AttendanceShortcutService(
            Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractClassDetail')
                    ->once()
                    ->with('token-multi', [
                        'id' => '2265',
                        'dosen_id' => '5240',
                        'course_id' => '8810',
                    ])
                    ->andReturn([
                        'course_materials' => [
                            [
                                'title' => 'Pertemuan 2',
                                'date' => '08 April 2026',
                                'meeting_number' => 2,
                                'join_link' => [
                                    'href' => '?id=441&courses_id=8810',
                                ],
                            ],
                            [
                                'title' => 'Pertemuan 3',
                                'date' => '15 April 2026',
                                'meeting_number' => 3,
                                'join_link' => [
                                    'href' => '?id=442&courses_id=8810',
                                ],
                            ],
                        ],
                        'attendance_info' => [
                            'meetings' => [],
                        ],
                        'assignment_history' => [
                            'assignments' => [],
                        ],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-multi', [
                        'meeting_id' => '442',
                        'courses_id' => '8810',
                    ])
                    ->andReturn([
                        'assignment_info' => [
                            'deadline' => '16 April 2026 23:59',
                            'upload_link' => '?mod=sipandai&act=send&courses_id=8810&id=442&pertemuan=3',
                            'upload_text' => 'Upload Tugas Pertemuan 3',
                        ],
                        'materials' => [
                            'assignment_topic' => 'Pertemuan 3',
                        ],
                    ]);

                $mock->shouldReceive('extractMeetingDetail')
                    ->once()
                    ->with('token-multi', [
                        'meeting_id' => '441',
                        'courses_id' => '8810',
                    ])
                    ->andReturn([
                        'assignment_info' => [
                            'deadline' => '09 April 2026 23:59',
                            'upload_link' => '?mod=sipandai&act=send&courses_id=8810&id=441&pertemuan=2',
                            'upload_text' => 'Upload Tugas Pertemuan 2',
                        ],
                        'materials' => [
                            'assignment_topic' => 'Pertemuan 2',
                        ],
                    ]);
            }),
            Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
                $mock->shouldReceive('extractSipandaiClassesData')
                    ->once()
                    ->with('token-multi')
                    ->andReturn([
                        'classes' => [
                            [
                                'schedule' => [
                                    'day' => 'Senin',
                                    'start_time' => '09:30:00',
                                    'end_time' => '11:00:00',
                                    'formatted_time' => '09:30 - 11:00',
                                ],
                                'course_info' => [
                                    'code' => 'IF101',
                                    'name' => 'Algoritma',
                                ],
                                'lecturer_info' => [
                                    'name' => 'Dosen Uji',
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
            }),
        );

        $pendingAssignments = $service->getPendingAssignments('token-multi', 10);

        // Soonest deadline first, whatever order the portal lists them in.
        $this->assertCount(2, $pendingAssignments);
        $this->assertSame([2, 3], array_column($pendingAssignments, 'meeting_number'));
        $this->assertSame(['/meeting/441?courses_id=8810', '/meeting/442?courses_id=8810'], array_column($pendingAssignments, 'meeting_url'));
        $this->assertSame(['2026-04-09', '2026-04-16'], array_column($pendingAssignments, 'deadline_date'));
    }

    public function test_it_scans_pending_assignments_beyond_the_first_four_classes(): void
    {
        $classesExtractor = Mockery::mock(ClassesExtractor::class);
        $sipandaiClassesExtractor = Mockery::mock(SipandaiClassesExtractor::class);

        $classesExtractor->shouldReceive('extractClassDetail')
            ->times(5)
            ->andReturnUsing(function (string $token, array $params): array {
                $courseId = (string) $params['course_id'];

                return [
                    'course_materials' => [
                        [
                            'title' => sprintf('Pertemuan %s', $courseId),
                            'date' => sprintf('%02d April 2026', (int) $courseId),
                            'meeting_number' => 1,
                            'join_link' => [
                                'href' => sprintf('?id=44%s&courses_id=%s', $courseId, $courseId),
                            ],
                        ],
                    ],
                    'attendance_info' => [
                        'meetings' => [],
                    ],
                    'assignment_history' => [
                        'assignments' => [],
                    ],
                ];
            });

        $classesExtractor->shouldReceive('extractMeetingDetail')
            ->times(5)
            ->andReturnUsing(function (string $token, array $params): array {
                $courseId = (string) $params['courses_id'];

                return [
                    'assignment_info' => [
                        'deadline' => sprintf('%02d April 2026 23:59', (int) $courseId),
                        'upload_link' => sprintf('?mod=sipandai&act=send&courses_id=%s&id=44%s&pertemuan=1', $courseId, $courseId),
                        'upload_text' => sprintf('Upload Tugas %s', $courseId),
                    ],
                    'materials' => [
                        'assignment_topic' => sprintf('Pertemuan %s', $courseId),
                    ],
                ];
            });

        $sipandaiClassesExtractor->shouldReceive('extractSipandaiClassesData')
            ->once()
            ->with('token-scan-all')
            ->andReturn([
                'classes' => collect(range(1, 5))
                    ->map(fn (int $courseId): array => [
                        'schedule' => [
                            'day' => 'Senin',
                            'start_time' => '09:30:00',
                            'end_time' => '11:00:00',
                            'formatted_time' => '09:30 - 11:00',
                        ],
                        'course_info' => [
                            'code' => sprintf('IF10%s', $courseId),
                            'name' => sprintf('Kelas %s', $courseId),
                        ],
                        'lecturer_info' => [
                            'name' => sprintf('Dosen %s', $courseId),
                        ],
                        'join_link' => [
                            'params' => [
                                'id' => (string) (2000 + $courseId),
                                'dosen_id' => (string) (5000 + $courseId),
                                'course_id' => (string) $courseId,
                            ],
                        ],
                    ])
                    ->all(),
            ]);

        $service = new AttendanceShortcutService($classesExtractor, $sipandaiClassesExtractor);

        $pendingAssignments = $service->getPendingAssignments('token-scan-all', 10);

        $this->assertCount(5, $pendingAssignments);
        $this->assertSame(['Kelas 1', 'Kelas 2', 'Kelas 3', 'Kelas 4', 'Kelas 5'], array_column($pendingAssignments, 'course_name'));
    }
}
