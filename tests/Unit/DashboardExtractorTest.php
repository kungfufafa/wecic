<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DashboardTodayClassesBuilder;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardExtractorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_uses_classes_count_for_total_courses_metric(): void
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

        $extractor = Mockery::mock(DashboardExtractor::class, [
            $sessionManager,
            $profileExtractor,
            $classesExtractor,
            $sipandaiClassesExtractor,
            $studentCourseExtractor,
            new DashboardTodayClassesBuilder,
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->once()
            ->andReturn(file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html')));

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('9', $data['dashboard_overview']['total_courses']);
        $this->assertSame('27', $data['dashboard_overview']['total_credits']);
        $this->assertSame(9, $data['classes_summary']['total_classes']);
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

        $extractor = Mockery::mock(DashboardExtractor::class, [
            $sessionManager,
            $profileExtractor,
            $classesExtractor,
            $sipandaiClassesExtractor,
            $studentCourseExtractor,
            new DashboardTodayClassesBuilder,
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
}
