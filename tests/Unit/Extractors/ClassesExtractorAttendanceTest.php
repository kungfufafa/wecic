<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ClassesExtractorAttendanceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_meetings_without_material_yet_are_upcoming_rather_than_absent(): void
    {
        $meetings = [];
        for ($number = 1; $number <= 6; $number++) {
            $meetings[] = [
                'meeting_number' => $number,
                'status' => $number <= 2 ? 'present' : 'absent',
                'details' => $number <= 2 ? ['attendance_date' => "0{$number} Oktober 2026"] : [],
            ];
        }

        $attendanceInfo = [
            'meetings' => $meetings,
            'summary' => ['total_meetings' => 6, 'present' => 2, 'late' => 0, 'absent' => 4, 'attendance_rate' => 33.3],
        ];
        $materials = [
            ['title' => 'Pertemuan 1', 'meeting_number' => 1],
            ['title' => 'Pertemuan 2', 'meeting_number' => 2],
            ['title' => 'Pertemuan 3', 'meeting_number' => 3],
        ];

        $result = $this->invoke('markUpcomingMeetings', [$attendanceInfo, $materials]);

        $this->assertSame(['present', 'present', 'absent', 'upcoming', 'upcoming', 'upcoming'], array_column($result['meetings'], 'status'));
        $this->assertSame(3, $result['summary']['upcoming']);
        $this->assertSame(3, $result['summary']['held_meetings']);
        $this->assertSame(1, $result['summary']['absent']);
        $this->assertSame(66.7, $result['summary']['attendance_rate']);
    }

    public function test_materials_posted_ahead_of_their_date_do_not_make_future_meetings_absences(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-10-08 10:00', 'Asia/Jakarta'));

        $meetings = [];
        $materials = [];
        $start = CarbonImmutable::parse('2025-09-29', 'Asia/Jakarta');
        for ($number = 1; $number <= 14; $number++) {
            $meetings[] = [
                'meeting_number' => $number,
                'status' => $number <= 2 ? 'present' : 'absent',
                'details' => $number <= 2 ? ['attendance_date' => $start->addWeeks($number - 1)->locale('id')->translatedFormat('d F Y')] : ['image_src' => '../images/bolos.jpg'],
            ];
            $materials[] = [
                'title' => "Materi Pertemuan {$number}",
                'meeting_number' => $number,
                'date' => 'Senin, '.$start->addWeeks($number - 1)->locale('id')->translatedFormat('d F Y'),
            ];
        }

        $result = $this->invoke('markUpcomingMeetings', [[
            'meetings' => $meetings,
            'summary' => ['total_meetings' => 14, 'present' => 2, 'late' => 0, 'absent' => 12, 'attendance_rate' => 14.3],
        ], array_reverse($materials)]);

        $this->assertSame(['present', 'present'], array_column(array_slice($result['meetings'], 0, 2), 'status'));
        $this->assertSame(array_fill(0, 12, 'upcoming'), array_column(array_slice($result['meetings'], 2), 'status'));
        $this->assertSame(12, $result['summary']['upcoming']);
        $this->assertSame(2, $result['summary']['held_meetings']);
        $this->assertSame(0, $result['summary']['absent']);
        $this->assertEquals(100, $result['summary']['attendance_rate']);
    }

    public function test_a_material_dated_today_or_earlier_still_counts_as_held(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-10-13 07:00', 'Asia/Jakarta'));

        $result = $this->invoke('markUpcomingMeetings', [[
            'meetings' => [
                ['meeting_number' => 1, 'status' => 'present', 'details' => ['attendance_date' => '06 Oktober 2025']],
                ['meeting_number' => 2, 'status' => 'absent', 'details' => []],
                ['meeting_number' => 3, 'status' => 'absent', 'details' => []],
                ['meeting_number' => 4, 'status' => 'absent', 'details' => []],
            ],
            'summary' => ['total_meetings' => 4, 'present' => 1, 'late' => 0, 'absent' => 3, 'attendance_rate' => 25.0],
        ], [
            ['meeting_number' => 3, 'date' => 'Senin, 20 Oktober 2025'],
            ['meeting_number' => 2, 'date' => 'Senin, 13 Oktober 2025'],
            ['meeting_number' => 1, 'date' => 'Senin, 06 Oktober 2025'],
        ]]);

        $this->assertSame(['present', 'absent', 'upcoming', 'upcoming'], array_column($result['meetings'], 'status'));
        $this->assertSame(2, $result['summary']['held_meetings']);
        $this->assertSame(1, $result['summary']['absent']);
        $this->assertEquals(50, $result['summary']['attendance_rate']);
    }

    public function test_unknown_meetings_that_become_upcoming_do_not_lower_the_absent_count(): void
    {
        $meetings = [];
        for ($number = 1; $number <= 14; $number++) {
            $meetings[] = [
                'meeting_number' => $number,
                'status' => match (true) {
                    $number <= 2 => 'absent',
                    $number === 3 => 'present',
                    default => 'unknown',
                },
                'details' => $number === 3 ? ['attendance_date' => '15 Oktober 2025'] : [],
            ];
        }

        $result = $this->invoke('markUpcomingMeetings', [[
            'meetings' => $meetings,
            'summary' => ['total_meetings' => 14, 'present' => 1, 'late' => 0, 'absent' => 2, 'attendance_rate' => 7.1],
        ], [
            ['meeting_number' => 3],
            ['meeting_number' => 2],
            ['meeting_number' => 1],
        ]]);

        $this->assertSame(['absent', 'absent', 'present'], array_column(array_slice($result['meetings'], 0, 3), 'status'));
        $this->assertSame(11, $result['summary']['upcoming']);
        $this->assertSame(3, $result['summary']['held_meetings']);
        $this->assertSame(2, $result['summary']['absent']);
    }

    public function test_rich_portal_html_becomes_readable_text(): void
    {
        $html = '<p>Peran Data Owner</p><p>Peran Data Steward&nbsp;</p><div>Variabel, Tipe Data &amp; Manipulasi String</div>';

        $this->assertSame(
            "Peran Data Owner\nPeran Data Steward\nVariabel, Tipe Data & Manipulasi String",
            $this->invoke('richTextToPlain', [$html])
        );
    }

    public function test_an_empty_meeting_page_is_reported_as_not_found(): void
    {
        $empty = [
            'meeting_info' => ['breadcrumb' => '', 'title' => 'SELAMAT DATANG DI APLIKASI SiPANDAI CIC'],
            'lecturer_info' => ['name' => ''],
            'schedule_info' => ['date' => ''],
            'materials' => ['main_topic' => '', 'assignment_topic' => ''],
            'attendance_info' => ['status' => ''],
        ];
        $real = array_replace_recursive($empty, ['materials' => ['main_topic' => 'Sejarah AI']]);

        $this->assertFalse($this->invoke('meetingDetailHasContent', [$empty]));
        $this->assertTrue($this->invoke('meetingDetailHasContent', [$real]));
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $extractor = new ClassesExtractor(Mockery::mock(PortalSessionManager::class));
        $reflection = new ReflectionMethod($extractor, $method);

        return $reflection->invoke($extractor, ...$arguments);
    }
}
