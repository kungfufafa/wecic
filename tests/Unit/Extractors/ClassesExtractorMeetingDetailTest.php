<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class ClassesExtractorMeetingDetailTest extends TestCase
{
    use InteractsWithPortalFixtures;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_assignment_materials_from_meeting_detail_fixture(): void
    {
        /** @var ClassesExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(
            ClassesExtractor::class,
            'tests/Fixtures/portal/meeting_assignment_detail.html'
        );

        $data = $extractor->extractMeetingDetail('fake-session-token', [
            'meeting_id' => '33118',
            'courses_id' => '9188',
        ]);

        $this->assertSame('Pokok Bahasan : Bussines Model', $data['materials']['main_topic']);
        $this->assertSame('../files/modul/ae614c557843b1df326cb29c57225459-3-mod-fa8.pdf', $data['materials']['main_material_iframe']);
        $this->assertSame('Pertemuan 3', $data['materials']['assignment_topic']);
        $this->assertSame('Kamis, 09 April 2026', $data['materials']['assignment_instructions']);
        $this->assertSame('../files/latihan/ae614c557843b1df326cb29c57225459-3-lat-fa8.pdf', $data['materials']['assignment_material_iframe']);
        // The fixture was saved without the page header: no breadcrumb, so no class link.
        $this->assertNull($data['meeting_info']['class_ref']);
    }

    public function test_label_rows_are_read_from_the_cell_after_the_colon(): void
    {
        $data = $this->extractMeeting($this->meetingPage(
            '<a href="?mod=sipandai&act=send&courses_id=9198&id=33317&pertemuan=5" class="btn"><button type="button">Upload Tugas</button></a>'
        ));

        $this->assertTrue($data['found']);
        $this->assertSame('Dosen Contoh Empat, M.Kom.', $data['lecturer_info']['name']);
        $this->assertSame('Senin, 20 April 2026', $data['schedule_info']['date']);
        $this->assertSame(['status' => 'Hadir', 'time' => '08:05:12', 'state' => 'present'], $data['attendance_info']);
        $this->assertSame('', $data['assignment_info']['deadline'], 'The ":" separator is never a deadline.');
        $this->assertSame('?mod=sipandai&act=send&courses_id=9198&id=33317&pertemuan=5', $data['assignment_info']['upload_link']);
        $this->assertSame('Upload Tugas', $data['assignment_info']['upload_text']);
        $this->assertSame('Senin, 27 April 2026', $data['instructions']['deadline_text']);
    }

    public function test_the_breadcrumb_class_link_becomes_the_class_ref(): void
    {
        $data = $this->extractMeeting($this->meetingPage('<b>Tidak ada tugas perkuliahan untuk pertemuan tersebut</b>', withAssignment: false));

        $this->assertSame(['id' => '1688', 'dosen_id' => '2744', 'course_id' => '9198'], $data['meeting_info']['class_ref']);
        $this->assertStringContainsString('Kelas Data Governance', $data['meeting_info']['breadcrumb']);
    }

    public function test_an_entity_encoded_breadcrumb_link_is_read_too(): void
    {
        $html = str_replace(
            'href="?mod=sipandai&act=download&id=1688&dosen_id=2744&course_id=9198"',
            'href="?mod=sipandai&amp;act=download&amp;id=1688&amp;dosen_id=2744&amp;course_id=9198"',
            $this->meetingPage('<b>Tidak ada tugas perkuliahan untuk pertemuan tersebut</b>', withAssignment: false)
        );

        $this->assertSame(['id' => '1688', 'dosen_id' => '2744', 'course_id' => '9198'], $this->extractMeeting($html)['meeting_info']['class_ref']);
    }

    public function test_a_breadcrumb_without_a_complete_class_link_has_no_class_ref(): void
    {
        $page = $this->meetingPage('<b>Tidak ada tugas perkuliahan untuk pertemuan tersebut</b>', withAssignment: false);

        $withoutLink = str_replace(
            '<a href="?mod=sipandai&act=download&id=1688&dosen_id=2744&course_id=9198">Kelas Data Governance</a>',
            'Kelas Data Governance',
            $page
        );
        $withoutDosen = str_replace('&dosen_id=2744', '', $page);

        $this->assertNull($this->extractMeeting($withoutLink, '33317')['meeting_info']['class_ref']);
        $this->assertNull($this->extractMeeting($withoutDosen, '33318')['meeting_info']['class_ref']);
    }

    public function test_a_meeting_without_an_assignment_has_no_deadline_and_no_upload_link(): void
    {
        $data = $this->extractMeeting($this->meetingPage('<b>Tidak ada tugas perkuliahan untuk pertemuan tersebut</b>', withAssignment: false));

        $this->assertSame('', $data['assignment_info']['deadline']);
        $this->assertSame('', $data['assignment_info']['upload_link']);
        $this->assertSame('Tidak ada tugas perkuliahan untuk pertemuan tersebut', $data['assignment_info']['status_text']);
        $this->assertSame('', $data['materials']['assignment_topic']);
        $this->assertSame('', $data['materials']['assignment_instructions']);
    }

    public function test_assignment_headings_are_read_whatever_their_spacing(): void
    {
        $data = $this->extractMeeting($this->meetingPage('<b>Tidak ada tugas perkuliahan untuk pertemuan tersebut</b>', headings: [
            '<h4>Instruksi Tugas Perkuliahan  Pertemuan 5:</h4>',
            '<h4>  Due Date Tugas Senin,&nbsp;27 April 2026</h4>',
        ]));

        $this->assertSame('Pertemuan 5', $data['materials']['assignment_topic']);
        $this->assertSame('Senin, 27 April 2026', $data['materials']['assignment_instructions']);
        $this->assertSame("Konsep data governance\nPeran data owner", $data['materials']['main_description']);
    }

    /**
     * A meeting page laid out as PORTAL_FEATURE_MAP F25 describes it:
     * `<b>label</b> | : | value` info rows, then the material card
     *
     * @param  list<string>|null  $headings
     */
    private function meetingPage(string $uploadCell, bool $withAssignment = true, ?array $headings = null): string
    {
        $headings ??= [
            '<h4> Instruksi Tugas Perkuliahan Pertemuan 5 :</h4>',
            '<h4>Due Date Tugas Senin, 27 April 2026 </h4>',
        ];
        $assignmentBlock = $withAssignment
            ? '<b>'.$headings[0].'</b>'.$headings[1].'<iframe src="../files/latihan/abc-5-lat-x.pdf"></iframe>'
            : '';

        return <<<HTML
            <!DOCTYPE html>
            <html><body><div class="app-wrapper"><div class="app-content">
            <div class="card">
                <div class="header"><h4 class="title"><a href="?mod=sipandai&act=dashboardmahasiswa">Beranda</a> > <a href="?mod=sipandai&act=download&id=1688&dosen_id=2744&course_id=9198">Kelas Data Governance</a> > Pertemuan 5</h4></div>
                <div class="content table-responsive">
                    <table class="table">
                        <tr><td width="40%"><b>Nama Dosen</b></td><td>:</td><td>Dosen Contoh Empat, M.Kom.</td></tr>
                        <tr><td><b>Tanggal Pertemuan</b></td><td>:</td><td>Senin, 20 April 2026</td></tr>
                        <tr><td><b>Mulai Kelas Jam</b></td><td>:</td><td>08:00 Wib</td></tr>
                        <tr><td><b>Absensi Kehadiran</b></td><td>:</td><td>Hadir Absen Jam : 08:05:12</td></tr>
                        <tr><td><b>Upload Tugas </b></td><td>:</td><td>{$uploadCell}</td></tr>
                        <tr><td><b>Batas Waktu Upload</b></td><td>:</td><td>Senin, 27 April 2026</td></tr>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="content table-responsive">
                    <table class="table"><tr><td align="justify">
                        <b><h5>Pokok Bahasan : Data Governance</h5></b>
                        <b><h5>Uraian Pokok Pembahasan :</h5></b>
                        <p>Konsep data governance</p><p>Peran data owner</p>
                        <b><h4>Materi Perkuliahan</h4><br></b>
                        <iframe src="../files/modul/abc-5-mod-x.pdf"></iframe>
                        <hr>
                        {$assignmentBlock}
                    </td></tr></table>
                </div>
            </div>
            </div></div></body></html>
            HTML;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMeeting(string $html, string $meetingId = '33317'): array
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(ClassesExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor->extractMeetingDetail('fake-session-token', ['meeting_id' => $meetingId, 'courses_id' => '9198']);
    }
}
