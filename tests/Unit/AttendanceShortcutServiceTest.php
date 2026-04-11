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
        $this->assertSame('late', $result['classes'][0]['attendance_status']['status']);
        $this->assertSame('Terlambat', $result['classes'][0]['attendance_status']['label']);
        $this->assertSame('09:35', $result['classes'][0]['attendance_status']['time']);
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
}
