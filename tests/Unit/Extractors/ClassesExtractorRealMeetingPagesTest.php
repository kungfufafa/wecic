<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * ClassesExtractor::extractMeetingDetail() on real, sanitized meeting pages
 * (act=discuss, tests/Fixtures/portal/real), captured on Friday 23 October
 * 2026. The "Upload Tugas" cell reads "Batas waktu upload tugas Pertemuan N
 * Sd <date>" followed by the upload button while the upload is open, or by
 * "dan Waktu upload tugas sudah berakhir." once it has closed; a meeting
 * without an assignment says "Tidak ada tugas perkuliahan untuk pertemuan
 * tersebut".
 */
class ClassesExtractorRealMeetingPagesTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURES = 'tests/Fixtures/portal/real/';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * meeting id, courses id, pertemuan, class_ref, meeting date, attendance
     * status and time, upload link, due date, whether the upload has closed,
     * whether the student may state the material's conformity (attended).
     *
     * @return array<string, array{int, int, int, array{id: string, dosen_id: string, course_id: string}, string, string, string, string, string, bool, bool}>
     */
    public static function meetingPages(): array
    {
        $dataGovernance = ['id' => '5391', 'dosen_id' => '5933', 'course_id' => '9496'];
        $manajemenProyek = ['id' => '5413', 'dosen_id' => '2744', 'course_id' => '9521'];
        $webDesign = ['id' => '5389', 'dosen_id' => '6584', 'course_id' => '9525'];

        return [
            'Manajemen Proyek P3' => [36342, 9521, 3, $manajemenProyek, 'Senin, 05 Oktober 2026', 'Hadir', '19:36:34', '', 'Jumat, 09 Oktober 2026', true, true],
            'Manajemen Proyek P4' => [36620, 9521, 4, $manajemenProyek, 'Senin, 12 Oktober 2026', 'Hadir', '18:51:21', '', 'Sabtu, 17 Oktober 2026', true, true],
            'Sistem Enterprise P4' => [36689, 9520, 4, ['id' => '5385', 'dosen_id' => '5933', 'course_id' => '9520'], 'Rabu, 14 Oktober 2026', 'Hadir', '19:18:39', '', 'Rabu, 21 Oktober 2026', true, true],
            'AI for Web Design Advance P4' => [36783, 9525, 4, $webDesign, 'Kamis, 15 Oktober 2026', 'Waktu Absensi Tidak Tersedia', '', '', 'Selasa, 20 Oktober 2026', true, false],
            'AI for Web Design Advance P5' => [37082, 9525, 5, $webDesign, 'Kamis, 22 Oktober 2026', 'Hadir', '19:20:42', '?mod=sipandai&act=send&courses_id=9525&id=37082&pertemuan=5', 'Selasa, 27 Oktober 2026', false, true],
            'Data Governance P3' => [36365, 9496, 3, $dataGovernance, 'Rabu, 07 Oktober 2026', 'Waktu Absensi Tidak Tersedia', '', '?mod=sipandai&act=send&courses_id=9496&id=36365&pertemuan=3', 'Jumat, 23 Oktober 2026', false, false],
            'Data Governance P4' => [36633, 9496, 4, $dataGovernance, 'Rabu, 14 Oktober 2026', 'Waktu Absensi Tidak Tersedia', '', '', '', false, false],
            'Data Governance P5' => [36957, 9496, 5, $dataGovernance, 'Rabu, 21 Oktober 2026', 'Waktu Absensi Tidak Tersedia', '', '', '', false, false],
        ];
    }

    /**
     * @param  array{id: string, dosen_id: string, course_id: string}  $classRef
     */
    #[DataProvider('meetingPages')]
    public function test_the_real_meeting_page_is_read(
        int $meetingId,
        int $coursesId,
        int $meetingNumber,
        array $classRef,
        string $date,
        string $attendanceStatus,
        string $attendanceTime,
        string $uploadLink,
        string $dueDate,
        bool $uploadClosed,
        bool $attended
    ): void {
        $data = $this->extractMeeting($meetingId, $coursesId, $requestedUrls);

        $this->assertSame([PortalEndpointRegistry::url('discussion', ['id' => $meetingId, 'courses_id' => $coursesId])], $requestedUrls);
        $this->assertTrue($data['found']);
        $this->assertSame($classRef, $data['meeting_info']['class_ref']);
        $this->assertStringEndsWith(">Pertemuan {$meetingNumber}", $data['meeting_info']['breadcrumb']);
        $this->assertSame($date, $data['schedule_info']['date']);
        $this->assertSame([
            'status' => $attendanceStatus,
            'time' => $attendanceTime,
            // "Waktu Absensi Tidak Tersedia" is a window the portal does not show, never an absence.
            'state' => $attendanceTime !== '' ? 'present' : 'unavailable',
        ], $data['attendance_info']);
        $this->assertSame($attendanceTime, $data['schedule_info']['attendance_time']);
        $this->assertNotSame('', $data['lecturer_info']['name']);
        $this->assertStringContainsString("-{$meetingNumber}-mod-", $data['materials']['main_material_iframe']);
        $this->assertStringStartsWith('Pokok Bahasan : ', $data['materials']['main_topic']);

        $this->assertSame($uploadLink, $data['assignment_info']['upload_link']);
        $this->assertSame($uploadLink !== '' ? "Upload Tugas Pertemuan {$meetingNumber}" : '', $data['assignment_info']['upload_text']);
        $this->assertSame($dueDate, $data['materials']['assignment_instructions']);

        if ($dueDate === '') {
            $this->assertSame('', $data['assignment_info']['deadline']);
            $this->assertSame('Tidak ada tugas perkuliahan untuk pertemuan tersebut', $data['assignment_info']['status_text']);
            $this->assertSame('', $data['materials']['assignment_topic']);
            $this->assertSame('', $data['materials']['assignment_material_iframe']);
        } else {
            // The upload button's label is not part of the deadline.
            $this->assertSame(
                "Batas waktu upload tugas Pertemuan {$meetingNumber} Sd {$dueDate}".($uploadClosed ? ' dan Waktu upload tugas sudah berakhir.' : ''),
                $data['assignment_info']['deadline']
            );
            $this->assertSame("Pertemuan {$meetingNumber}", $data['materials']['assignment_topic']);
            $this->assertStringContainsString("-{$meetingNumber}-lat-", $data['materials']['assignment_material_iframe']);
        }

        // Only the act=kesesuaian links are the conformity buttons, offered once the student attended.
        if ($attended) {
            $this->assertSame(
                "?mod=sipandai&act=kesesuaian&subject={$classRef['id']}&pertemuan={$meetingNumber}&courses={$coursesId}&dosen={$classRef['dosen_id']}&sesuai=ya&modules={$meetingId}",
                $data['conformity_info']['yes_button']['href']
            );
            $this->assertSame('Perkuliahan Ada, Materi Sesuai Rencana', $data['conformity_info']['yes_button']['text']);
            $this->assertStringEndsWith("&sesuai=tidak&modules={$meetingId}", $data['conformity_info']['no_button']['href']);
            $this->assertSame("Kesesuaian Materi Pertemuan {$meetingNumber} dengan Rencana Pembelajaran Semester (RPS)", $data['conformity_info']['description']);
        } else {
            $this->assertSame('', $data['conformity_info']['yes_button']);
            $this->assertSame('', $data['conformity_info']['no_button']);
            $this->assertStringStartsWith("Anda belum bisa menyatakan Kesesuaian Materi Pertemuan {$meetingNumber}", $data['conformity_info']['description']);
        }
    }

    /**
     * Data Governance P4, held Rabu, 14 Oktober 2026 without the student:
     * no attendance, no assignment, and the way back to class 5391.
     */
    public function test_the_missed_data_governance_meeting_has_closed_attendance_and_no_assignment(): void
    {
        $data = $this->extractMeeting(36633, 9496);

        $this->assertSame(['id' => '5391', 'dosen_id' => '5933', 'course_id' => '9496'], $data['meeting_info']['class_ref']);
        $this->assertSame('Beranda > Kelas Data Governance >Pertemuan 4', $data['meeting_info']['breadcrumb']);
        $this->assertSame('Rabu, 14 Oktober 2026', $data['schedule_info']['date']);
        $this->assertSame('13:00 Wib', $data['schedule_info']['start_time']);
        $this->assertSame('', $data['schedule_info']['attendance_time']);
        $this->assertSame(['status' => 'Waktu Absensi Tidak Tersedia', 'time' => '', 'state' => 'unavailable'], $data['attendance_info']);
        $this->assertSame([
            'deadline' => '',
            'upload_link' => '',
            'upload_text' => '',
            'status_text' => 'Tidak ada tugas perkuliahan untuk pertemuan tersebut',
        ], $data['assignment_info']);
        $this->assertSame('../files/sap/DS052002-rps755.pdf ', $data['rps_info']['file_link']);
        $this->assertSame('Pokok Bahasan : Aspek Keamanan, Privasi, Etika dan Kepatuhan dalam Pengelolaan Data', $data['materials']['main_topic']);
        $this->assertSame("Siklus Hidup Data\nAspek Keamanan Data\nEtika dalam Penggunaan Data dan Privasi", $data['materials']['main_description']);
    }

    /**
     * The same page dated next week (only its date changed, in memory) says
     * the same about attendance: the window is not shown, which is not an
     * absence before the meeting any more than after it.
     */
    public function test_a_future_dated_copy_of_the_missed_meeting_is_an_unavailable_window_too(): void
    {
        $page = str_replace('Rabu, 14 Oktober 2026</b>', 'Rabu, 28 Oktober 2026</b>', (string) file_get_contents(base_path(self::FIXTURES.'meeting_36633_9496.html')), $replaced);
        $this->assertSame(1, $replaced);

        $data = $this->extractMeetingFrom($page, 36633, 9496);

        $this->assertSame('Rabu, 28 Oktober 2026', $data['schedule_info']['date']);
        $this->assertSame(['status' => 'Waktu Absensi Tidak Tersedia', 'time' => '', 'state' => 'unavailable'], $data['attendance_info']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMeetingFrom(string $html, int $meetingId, int $coursesId): array
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(ClassesExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor->extractMeetingDetail('token-real', ['meeting_id' => (string) $meetingId, 'courses_id' => (string) $coursesId]);
    }

    /**
     * @param  list<string>|null  $requestedUrls
     * @return array<string, mixed>
     */
    private function extractMeeting(int $meetingId, int $coursesId, ?array &$requestedUrls = null): array
    {
        /** @var ClassesExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(ClassesExtractor::class, self::FIXTURES."meeting_{$meetingId}_{$coursesId}.html", $requestedUrls);

        return $extractor->extractMeetingDetail('token-real', ['meeting_id' => (string) $meetingId, 'courses_id' => (string) $coursesId]);
    }
}
