<?php

declare(strict_types=1);

namespace Tests\Unit;

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

        $extractor = $this->makeExtractor(
            array_fill(0, 9, $this->saturdayClass()),
            [file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html'))]
        );

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('9', $data['dashboard_overview']['total_courses']);
        $this->assertSame('27', $data['dashboard_overview']['total_credits']);
        // SIPANDAI shows neither an IPK nor a bill: unknown, never "0.00" / "Rp 0" (Fase 0.7).
        $this->assertNull($data['dashboard_overview']['gpa']);
        $this->assertNull($data['dashboard_overview']['outstanding_payments']);
        $this->assertSame(9, $data['classes_summary']['total_classes']);
        $this->assertSame(9, $data['classes_summary']['today_classes_count']);
        $this->assertSame('2265', $data['classes_summary']['today_classes'][0]['class_id']);
    }

    /**
     * The schedule never reads a class or meeting page: today's attendance
     * is its own snapshot, joined when GET /api/dashboard is read.
     */
    public function test_today_classes_are_built_without_reading_class_or_meeting_pages(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 11, 10, 0, 0, 'Asia/Jakarta'));

        $extractor = $this->makeExtractor(
            [$this->saturdayClass()],
            [file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html'))]
        );

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame(1, $data['classes_summary']['today_classes_count']);
        $this->assertSame(0, $data['classes_summary']['actionable_attendance_count']);
        $this->assertArrayNotHasKey('attendance', $data['classes_summary']['today_classes'][0]);
        $this->assertSame('2026-04-11', $data['classes_summary']['date']);
    }

    public function test_it_falls_back_to_credit_page_when_dashboard_and_classes_have_no_credit_totals(): void
    {
        cache()->forget('dashboard_credit_total_v1_'.md5('fake-session-token'));

        $extractor = $this->makeExtractor(
            [['course_info' => ['code' => 'IF101', 'name' => 'Algoritma']]],
            [
                file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html')),
                '<table><tr><td>Total SKS</td><td>24 SKS</td></tr></table>',
            ]
        );

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('24', $data['dashboard_overview']['total_credits']);

        cache()->forget('dashboard_credit_total_v1_'.md5('fake-session-token'));
    }

    public function test_it_marks_dashboard_data_as_cached_when_a_schedule_page_came_from_the_cache(): void
    {
        $extractor = $this->makeExtractor(
            [[
                'schedule' => ['day' => 'Senin', 'start_time' => '09:30:00', 'end_time' => '11:00:00', 'formatted_time' => '09:30 - 11:00'],
                'course_info' => ['code' => 'IF101', 'name' => 'Algoritma', 'credits' => '3'],
            ]],
            [file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html'))],
            ['classes' => [], 'semester_info' => ['semester' => '6'], '_sync' => ['data_origin' => 'server_cache']]
        );

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('server_cache', $data['_sync']['data_origin']);
    }

    public function test_it_marks_dashboard_data_as_cached_when_credit_total_uses_cached_fallback(): void
    {
        app(PortalDataCache::class)->remember('fake-session-token', 'credit_total', [], fn (): array => ['total' => 24]);

        $extractor = $this->makeExtractor(
            [['course_info' => ['code' => 'IF101', 'name' => 'Algoritma']]],
            [file_get_contents(base_path('tests/Fixtures/portal/sipandai_dashboard.html'))]
        );

        $data = $extractor->extractDashboardData('fake-session-token');

        $this->assertSame('24', $data['dashboard_overview']['total_credits']);
        $this->assertSame('server_cache', $data['_sync']['data_origin']);

        cache()->forget('dashboard_credit_total_v1_'.md5('fake-session-token'));
    }

    /**
     * @param  list<array<string, mixed>>  $sipandaiClasses
     * @param  list<string>  $pages  What each `get()` returns, in order.
     * @param  array<string, mixed>|null  $studentCourseData
     */
    private function makeExtractor(array $sipandaiClasses, array $pages, ?array $studentCourseData = null): DashboardExtractor
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
                ->andReturn(['user_info' => ['nama' => 'Mahasiswa Uji']]);
        });

        $classesExtractor = Mockery::mock(ClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractClassDetail');
            $mock->shouldNotReceive('extractMeetingDetail');
        });

        $sipandaiClassesExtractor = Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock) use ($sipandaiClasses): void {
            $mock->shouldReceive('extractSipandaiClassesData')
                ->once()
                ->with('fake-session-token')
                ->andReturn([
                    'classes' => $sipandaiClasses,
                    'semester_info' => ['semester' => '6'],
                ]);
        });

        $studentCourseExtractor = Mockery::mock(StudentCourseExtractor::class, function (MockInterface $mock) use ($studentCourseData): void {
            $mock->shouldReceive('extractStudentCourseData')
                ->once()
                ->with('fake-session-token')
                ->andReturn($studentCourseData ?? [
                    'classes' => [],
                    'semester_info' => ['semester' => '6'],
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
            ->times(count($pages))
            ->andReturn(...$pages);

        return $extractor;
    }

    /**
     * @return array<string, mixed>
     */
    private function saturdayClass(): array
    {
        return [
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
        ];
    }
}
