<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalResponseRecorder;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

/**
 * PortalResponseRecorder replaces lecturers' names with "Dosen Uji A", "Dosen Uji B", …:
 * those the page shows in its lecturer fields and those of the student's
 * stored class list, wherever they appear in the page.
 */
class PortalResponseRecorderLecturerNamesTest extends TestCase
{
    private const NIM = '20231234567';

    private const SESSION_TOKEN = 'mycic-session-token-0123456789abcdef';

    public function test_the_lecturer_fields_of_a_meeting_page_and_its_author_become_numbered_placeholders(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <!DOCTYPE html>
            <html><head>
                <meta name="description" content="Rahmat Hidayat">
                <meta name="author" content="Rahmat Hidayat">
            </head><body>
            <div class="card"><div class="header"><h4 class="title"><a href="?mod=sipandai&act=download&id=1&dosen_id=2&course_id=3">Kelas Data Governance</a> >Pertemuan 4</h4></div>
            <div class="content"><table class="table">
                <tr><td width="40%"><b>Nama Dosen</b></td><td>:</td><td><b>Siti Aminah, M.Kom.</b></td></tr>
                <tr><td><b>Nomor Kontak Dosen</b></td><td>:</td><td><b>081211112222</b></td></tr>
                <tr><td><b>Absensi Kehadiran</b></td><td>:</td><td><b>Waktu Absensi Tidak Tersedia</b></td></tr>
            </table></div></div>
            <p>Diskusi: Pak Rahmat meminta tugas dikirim ke Bu SITI AMINAH sebelum Jumat.</p>
            </body></html>
            HTML);

        foreach (['Rahmat', 'Hidayat', 'Siti', 'Aminah', 'SITI'] as $name) {
            $this->assertStringNotContainsString($name, $sanitized);
        }

        $this->assertStringContainsString('<meta name="author" content="Dosen Uji A">', $sanitized);
        $this->assertStringContainsString('<meta name="description" content="Dosen Uji A">', $sanitized);
        $this->assertStringContainsString('<td><b>Dosen Uji B, M.Kom.</b></td>', $sanitized);
        $this->assertStringContainsString('Pak Dosen Uji A meminta tugas dikirim ke Bu DOSEN UJI B sebelum Jumat.', $sanitized);
        $this->assertStringContainsString('<b>Nama Dosen</b>', $sanitized);
        $this->assertStringContainsString('<b>Nomor Kontak Dosen</b>', $sanitized);
        $this->assertStringContainsString('Waktu Absensi Tidak Tersedia', $sanitized);
        $this->assertStringContainsString('Kelas Data Governance', $sanitized);
    }

    public function test_a_lecturer_column_is_scrubbed_row_by_row_and_one_lecturer_keeps_one_number(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <!DOCTYPE html>
            <html><body><table>
                <tr><td>Program Studi</td><td>:</td><td>SISTEM INFORMASI - S1</td></tr>
                <tr><td>Nama</td><td>:</td><td><b>Budi Santoso</b></td></tr>
            </table>
            <table>
                <tr><th>No.</th><th>Nama Mata Kuliah</th><th>Nama Dosen / Jadwal Ujian</th><th>Ujian Reguler</th></tr>
                <tr><td>1.</td><td><b>DS052002<br/>Data Governance</b></td><td><b>Andi Wijaya, M.Kom.</b><br/>Senin, 12-01-2027 08:00</td><td></td></tr>
                <tr><td>2.</td><td><b>SI033017<br/>Statistik Inferensial</b></td><td><b>Dr. Ir. Sri Lestari, M.T.</b><br/></td><td></td></tr>
                <tr><td>3.</td><td><b>DS052001<br/>Teknik Penambangan Data</b></td><td><b>Andi Wijaya, M.Kom.</b><br/></td><td></td></tr>
            </table></body></html>
            HTML);

        foreach (['Andi', 'Wijaya', 'Sri ', 'Lestari', 'Budi'] as $name) {
            $this->assertStringNotContainsString($name, $sanitized);
        }

        $this->assertSame(2, substr_count($sanitized, '<b>Dosen Uji A, M.Kom.</b>'));
        $this->assertStringContainsString('<b>Dr. Ir. Dosen Uji B, M.T.</b>', $sanitized);
        $this->assertStringContainsString('Senin, 12-01-2027 08:00', $sanitized);
        $this->assertStringContainsString('Data Governance', $sanitized);
        $this->assertStringContainsString('Teknik Penambangan Data', $sanitized);
        $this->assertStringContainsString('<b>Mahasiswa Uji</b>', $sanitized, 'The student keeps the student placeholder.');
    }

    public function test_the_stored_class_lists_lecturers_are_scrubbed_wherever_the_page_names_them(): void
    {
        app(PortalSnapshotStore::class)->put(self::SESSION_TOKEN, 'lms_classes', ['classes' => [
            ['course_info' => ['code' => 'SI033017', 'name' => 'Statistik Inferensial'], 'lecturer_info' => ['name' => 'Yusuf Maulana, M.Kom.']],
            ['course_info' => ['code' => 'DS052002', 'name' => 'Data Governance'], 'lecturer_info' => ['name' => 'Dewi Kartika, M.Si.']],
        ]]);
        $recorder = app(PortalResponseRecorder::class);
        $context = $recorder->contextFromSession($this->campusSession());

        $this->assertSame(['Yusuf Maulana, M.Kom.', 'Dewi Kartika, M.Si.'], $context['lecturers']);

        $sanitized = $recorder->sanitize(<<<'HTML'
            <!DOCTYPE html>
            <html><body>
            <table><tr><td>Dosen</td><td>:</td><td><b>Dewi Kartika, M.Si.</b></td></tr></table>
            <p>Pengumuman: kelas Yusuf Maulana pindah ke Rabu.</p>
            </body></html>
            HTML, $context);

        $this->assertStringNotContainsString('Yusuf', $sanitized);
        $this->assertStringNotContainsString('Dewi', $sanitized);
        $this->assertStringContainsString('<b>Dosen Uji B, M.Si.</b>', $sanitized, 'Letters follow the class list, the same in every page of a capture.');
        $this->assertStringContainsString('kelas Dosen Uji A pindah ke Rabu.', $sanitized);
    }

    public function test_empty_lecturer_fields_and_page_words_are_left_alone(): void
    {
        $page = <<<'HTML'
            <!DOCTYPE html>
            <html><body><table>
                <tr><td>Dosen</td><td>:</td><td>-</td></tr>
                <tr><td>Nama Dosen</td><td>:</td><td><b>Dosen</b></td></tr>
                <tr><td>Tanggal Pertemuan</td><td>:</td><td><b>Rabu, 14 Oktober 2026</b></td></tr>
            </table><p>Hubungi dosen pengampu sebelum Jumat.</p></body></html>
            HTML;

        $this->assertSame($page, $this->sanitize($page));
    }

    /**
     * The exam list tells its lecturer from a schedule by the schedule's
     * digits or day name: a scrubbed lecturer still reads as a lecturer.
     */
    public function test_a_scrubbed_exam_list_still_reads_its_lecturer_column_as_lecturers(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <!DOCTYPE html>
            <html><body><table>
                <tr><th>No.</th><th>Nama Mata Kuliah</th><th>Nama Dosen / Jadwal Ujian</th><th>Ujian Reguler</th><th>Ujian Susulan</th></tr>
                <tr><td>1.</td><td><b>DS052002<br/>Data Governance<br/> </b></td><td><b>Andi Wijaya, M.Kom.</b><br/></td><td></td><td></td></tr>
            </table></body></html>
            HTML);

        $exams = (new \ReflectionMethod(SipandaiSubmissionService::class, 'extractExamList'))
            ->invoke(new SipandaiSubmissionService($this->createMock(PortalSessionManager::class)), new Crawler($sanitized));

        $this->assertSame('Dosen Uji A, M.Kom.', $exams[0]['lecturer']);
        $this->assertSame('', $exams[0]['schedule']);
    }

    public function test_lecturer_letters_go_on_past_z(): void
    {
        $letters = new \ReflectionMethod(PortalResponseRecorder::class, 'lecturerLetters');
        $recorder = app(PortalResponseRecorder::class);

        $this->assertSame(['A', 'B', 'Z', 'AA', 'AZ', 'BA'], array_map(
            static fn (int $number): string => $letters->invoke($recorder, $number),
            [1, 2, 26, 27, 52, 53]
        ));
    }

    public function test_a_sanitized_page_sanitized_again_keeps_its_placeholders(): void
    {
        $page = <<<'HTML'
            <!DOCTYPE html>
            <html><head><meta name="author" content="Rahmat Hidayat"></head><body><table>
                <tr><td>Nama Dosen</td><td>:</td><td><b>Siti Aminah, M.Kom.</b></td></tr>
            </table></body></html>
            HTML;

        $sanitized = $this->sanitize($page);

        $this->assertSame($sanitized, $this->sanitize($sanitized));
    }

    private function sanitize(string $html): string
    {
        $recorder = app(PortalResponseRecorder::class);

        return $recorder->sanitize($html, $recorder->contextFromSession($this->campusSession()));
    }

    /**
     * @return array<string, mixed>
     */
    private function campusSession(): array
    {
        return [
            'token' => self::SESSION_TOKEN,
            'username' => self::NIM,
            'user_info' => ['name' => 'Budi Santoso', 'student_id' => self::NIM],
        ];
    }
}
