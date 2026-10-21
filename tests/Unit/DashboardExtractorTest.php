<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AttendanceShortcutService;
use App\Services\DashboardTodayClassesBuilder;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use App\Services\PortalDataCache;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardExtractorTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_uses_classes_count_for_total_courses_metric(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 11, 10, 0, 0, 'Asia/Jakarta'));

        $sessionManager = Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')
                ->once()
                ->with('fake-session-token')
                ->andReturn(new CookieJar);
        });

        $profileExtractor = Mockery::mock(ProfileExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractProfileData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'user_info' => [
                        'nama' => 'Mahasiswa Uji',
                    ],
                ]);
        });

        $classesExtractor = Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldIgnoreMissing();
        });

        $sipandaiClassesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => array_fill(0, 9, [
                        'schedule' => [
                            'day' => 'Sabtu',
                            'start_time' => '09:30:00',
                            'end_time' => '11:00:00',
                            'time_range' => '09:30:00-11:00:00',
                            'formatted_time' => '09:30 - 11:00',
                        ],
                        'course_info' => [
                            'code' => 'IF101',
                            'name' => 'Algoritma',
                            'credits' => '3',
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
                    ]),
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $studentCourseExtractor = Mockery::mock(StudentCourseExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractStudentCourseData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => [],
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $attendanceShortcutService = Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTodayShortcutsSnapshot')
                ->once()
                ->with('fake-session-token', Mockery::type(\Carbon\CarbonImmutable::class), Mockery::type('array'))
                ->andReturn([
                    'classes' => [
                        [
                            'class_id' => '2265',
                            'meeting_id' => '445',
                            'meeting_number' => 6,
                            'course_code' => 'IF101',
                            'today_material' => [
                                'title' => 'Pertemuan 6',
                                'meeting_number' => 6,
                            ],
                            'attendance_target_reliable' => true,
                            'schedule' => [
                                'day' => 'Sabtu',
                                'start_time' => '09:30:00',
                                'time' => '09:30 - 11:00',
                            ],
                            'attendance_status' => [
                                'already_marked' => false,
                                'status' => null,
                                'label' => null,
                                'time' => null,
                            ],
                            'attendance_payload' => [
                                'courseid' => '8810',
                                'modulesid' => '445',
                                'pertemuan' => 6,
                            ],
                            'assignment_payload' => [
                                'courses_id' => '8810',
                            ],
                        ],
                    ],
                    '_sync' => [
                        'data_origin' => 'upstream',
                        'synced_at' => '2026-04-11T10:00:00+07:00',
                    ],
                ]);
        });

        $extractor = Mockery::mock(DashboardExtractor::class, [
            $sessionManager,
            $profileExtractor,
            $classesExtractor,
            $sipandaiClassesExtractor,
            $studentCourseExtractor,
            new DashboardTodayClassesBuilder,
            $attendanceShortcutService,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->once()
            ->andReturn(file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html')));

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('9', $data['dashboard_overview']['total_courses']);
        $this->assertSame('27', $data['dashboard_overview']['total_credits']);
        // SIPANDAI shows neither an IPK nor a bill: unknown, never "0.00" / "Rp 0" (Fase 0.7).
        $this->assertNull($data['dashboard_overview']['gpa']);
        $this->assertNull($data['dashboard_overview']['outstanding_payments']);
        $this->assertSame(9, $data['classes_summary']['total_classes']);
        $this->assertSame(9, $data['classes_summary']['actionable_attendance_count']);
        $this->assertTrue($data['classes_summary']['today_classes'][0]['attendance']['actionable']);
        $this->assertSame('445', $data['classes_summary']['today_classes'][0]['attendance']['meeting_id']);
    }

    public function test_it_falls_back_to_credit_page_when_dashboard_and_classes_have_no_credit_totals(): void
    {
        cache()->forget('dashboard_credit_total_v1_'.md5('fake-session-token'));

        $sessionManager = Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')
                ->once()
                ->with('fake-session-token')
                ->andReturn(new CookieJar);
        });

        $profileExtractor = Mockery::mock(ProfileExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractProfileData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'user_info' => [
                        'nama' => 'Mahasiswa Uji',
                    ],
                ]);
        });

        $classesExtractor = Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldIgnoreMissing();
        });

        $sipandaiClassesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => [
                        [
                            'course_info' => [
                                'code' => 'IF101',
                                'name' => 'Algoritma',
                            ],
                        ],
                    ],
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $studentCourseExtractor = Mockery::mock(StudentCourseExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractStudentCourseData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => [],
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $attendanceShortcutService = Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTodayShortcutsSnapshot')
                ->once()
                ->with('fake-session-token', Mockery::type(\Carbon\CarbonImmutable::class), Mockery::type('array'))
                ->andReturn([
                    'classes' => [],
                    '_sync' => [
                        'data_origin' => 'upstream',
                        'synced_at' => '2026-04-11T10:00:00+07:00',
                    ],
                ]);
        });

        $extractor = Mockery::mock(DashboardExtractor::class, [
            $sessionManager,
            $profileExtractor,
            $classesExtractor,
            $sipandaiClassesExtractor,
            $studentCourseExtractor,
            new DashboardTodayClassesBuilder,
            $attendanceShortcutService,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->twice()
            ->andReturn(
                file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html')),
                '<table><tr><td>Total SKS</td><td>24 SKS</td></tr></table>'
            );

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('24', $data['dashboard_overview']['total_credits']);

        cache()->forget('dashboard_credit_total_v1_'.md5('fake-session-token'));
    }

    public function test_it_marks_dashboard_data_as_cached_when_attendance_shortcuts_use_cached_details(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')
                ->once()
                ->with('fake-session-token')
                ->andReturn(new CookieJar);
        });

        $profileExtractor = Mockery::mock(ProfileExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractProfileData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'user_info' => [
                        'nama' => 'Mahasiswa Uji',
                    ],
                ]);
        });

        $classesExtractor = Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldIgnoreMissing();
        });

        $sipandaiClassesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->with('fake-session-token')
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
                                'credits' => '3',
                            ],
                        ],
                    ],
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $studentCourseExtractor = Mockery::mock(StudentCourseExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractStudentCourseData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => [],
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $attendanceShortcutService = Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTodayShortcutsSnapshot')
                ->once()
                ->with('fake-session-token', Mockery::type(\Carbon\CarbonImmutable::class), Mockery::type('array'))
                ->andReturn([
                    'classes' => [],
                    '_sync' => [
                        'data_origin' => 'server_cache',
                        'synced_at' => '2026-04-11T10:00:00+07:00',
                    ],
                ]);
        });

        $extractor = Mockery::mock(DashboardExtractor::class, [
            $sessionManager,
            $profileExtractor,
            $classesExtractor,
            $sipandaiClassesExtractor,
            $studentCourseExtractor,
            new DashboardTodayClassesBuilder,
            $attendanceShortcutService,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->once()
            ->andReturn(file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html')));

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('server_cache', $data['_sync']['data_origin']);
    }

    public function test_it_marks_dashboard_data_as_cached_when_credit_total_uses_cached_fallback(): void
    {
        app(PortalDataCache::class)->remember('fake-session-token', 'credit_total', [], fn (): array => ['total' => 24]);

        $sessionManager = Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')
                ->once()
                ->with('fake-session-token')
                ->andReturn(new CookieJar);
        });

        $profileExtractor = Mockery::mock(ProfileExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractProfileData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'user_info' => [
                        'nama' => 'Mahasiswa Uji',
                    ],
                ]);
        });

        $classesExtractor = Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldIgnoreMissing();
        });

        $sipandaiClassesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => [
                        [
                            'course_info' => [
                                'code' => 'IF101',
                                'name' => 'Algoritma',
                            ],
                        ],
                    ],
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $studentCourseExtractor = Mockery::mock(StudentCourseExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractStudentCourseData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => [],
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $attendanceShortcutService = Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTodayShortcutsSnapshot')
                ->once()
                ->with('fake-session-token', Mockery::type(\Carbon\CarbonImmutable::class), Mockery::type('array'))
                ->andReturn([
                    'classes' => [],
                    '_sync' => [
                        'data_origin' => 'upstream',
                        'synced_at' => '2026-04-11T10:00:00+07:00',
                    ],
                ]);
        });

        $extractor = Mockery::mock(DashboardExtractor::class, [
            $sessionManager,
            $profileExtractor,
            $classesExtractor,
            $sipandaiClassesExtractor,
            $studentCourseExtractor,
            new DashboardTodayClassesBuilder,
            $attendanceShortcutService,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->once()
            ->andReturn(file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html')));

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('24', $data['dashboard_overview']['total_credits']);
        $this->assertSame('server_cache', $data['_sync']['data_origin']);

        cache()->forget('dashboard_credit_total_v1_'.md5('fake-session-token'));
    }

    public function test_it_does_not_expose_unreliable_attendance_targets_as_actionable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 11, 10, 0, 0, 'Asia/Jakarta'));

        $sessionManager = Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')
                ->once()
                ->with('fake-session-token')
                ->andReturn(new CookieJar);
        });

        $profileExtractor = Mockery::mock(ProfileExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractProfileData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'user_info' => [
                        'nama' => 'Mahasiswa Uji',
                    ],
                ]);
        });

        $classesExtractor = Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldIgnoreMissing();
        });

        $sipandaiClassesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => [
                        [
                            'schedule' => [
                                'day' => 'Sabtu',
                                'start_time' => '09:30:00',
                                'end_time' => '11:00:00',
                                'time_range' => '09:30:00-11:00:00',
                                'formatted_time' => '09:30 - 11:00',
                            ],
                            'course_info' => [
                                'code' => 'IF101',
                                'name' => 'Algoritma',
                                'credits' => '3',
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
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $studentCourseExtractor = Mockery::mock(StudentCourseExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractStudentCourseData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => [],
                    'semester_info' => [
                        'semester' => '6',
                    ],
                ]);
        });

        $attendanceShortcutService = Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTodayShortcutsSnapshot')
                ->once()
                ->with('fake-session-token', Mockery::type(\Carbon\CarbonImmutable::class), Mockery::type('array'))
                ->andReturn([
                    'classes' => [
                        [
                            'class_id' => '2265',
                            'meeting_id' => '445',
                            'meeting_number' => 6,
                            'course_code' => 'IF101',
                            'today_material' => [
                                'title' => 'Pertemuan 5',
                                'meeting_number' => 5,
                            ],
                            'attendance_target_reliable' => false,
                            'schedule' => [
                                'day' => 'Sabtu',
                                'start_time' => '09:30:00',
                                'time' => '09:30 - 11:00',
                            ],
                            'attendance_status' => [
                                'already_marked' => false,
                                'status' => null,
                                'label' => null,
                                'time' => null,
                            ],
                            'attendance_payload' => [
                                'courseid' => '8810',
                                'modulesid' => '445',
                                'pertemuan' => 6,
                            ],
                            'assignment_payload' => [
                                'courses_id' => '8810',
                            ],
                        ],
                    ],
                    '_sync' => [
                        'data_origin' => 'upstream',
                        'synced_at' => '2026-04-11T10:00:00+07:00',
                    ],
                ]);
        });

        $extractor = Mockery::mock(DashboardExtractor::class, [
            $sessionManager,
            $profileExtractor,
            $classesExtractor,
            $sipandaiClassesExtractor,
            $studentCourseExtractor,
            new DashboardTodayClassesBuilder,
            $attendanceShortcutService,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->once()
            ->andReturn(file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html')));

        $data = $extractor->extractDashboardData('fake-session-token');
        $attendance = $data['classes_summary']['today_classes'][0]['attendance'];

        $this->assertSame(0, $data['classes_summary']['actionable_attendance_count']);
        $this->assertTrue($attendance['available']);
        $this->assertFalse($attendance['actionable']);
        $this->assertNull($attendance['payload']);
        $this->assertNull($attendance['context']);
    }
}
