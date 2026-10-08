<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
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
