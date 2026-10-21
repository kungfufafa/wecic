<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\PortalContextExtractor;
use App\Services\PortalSessionManager;
use App\Services\PortalUrlGuard;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class PortalContextExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURE = 'tests/Fixtures/portal/student_dashboard.html';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_the_portal_context_from_the_dashboard_fixture(): void
    {
        /** @var PortalContextExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(PortalContextExtractor::class, self::FIXTURE);

        $data = $extractor->extractPortalContextData('fake-session-token');

        $this->assertSame([
            'label' => 'T.A 2026/2027 Semester Ganjil',
            'academic_year' => '2026/2027',
            'term' => 'ganjil',
            'source' => 'dashboard',
        ], $data['academic_period']);
        $this->assertSame('2026-10-12', $data['server_date']);
        $this->assertSame('Senin, 12 Oktober 2026', $data['server_date_label']);
        $this->assertSame([
            [
                'label' => 'Panduan Her-registrasi Online',
                'path' => 'files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
                'filename' => 'Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
            ],
        ], $data['guide_links']);
        $this->assertNotNull(PortalUrlGuard::campusFileUrl($data['guide_links'][0]['path']));
        $this->assertNull($data['notice']);
        $this->assertArrayHasKey('extracted_at', $data);
        $this->assertSame('upstream', $data['_sync']['data_origin']);
    }

    public function test_it_reads_the_vmts_questionnaire_popup(): void
    {
        /** @var PortalContextExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(PortalContextExtractor::class, self::FIXTURE);

        $questionnaire = $extractor->extractPortalContextData('fake-session-token')['questionnaire'];

        $this->assertTrue($questionnaire['available']);
        $this->assertSame('Kuesioner Pemahaman VMTS Untuk Tahun Akademik 2026/2027', $questionnaire['title']);
        $this->assertSame('2026/2027', $questionnaire['academic_year']);
        $this->assertStringStartsWith('Silakan saudara mengisi kuesioner pemahaman', (string) $questionnaire['description']);
        $this->assertCount(2, $questionnaire['details']);
        $this->assertStringStartsWith('Tujuan Kuesioner', $questionnaire['details'][0]);
        $this->assertSame('Kegiatan ini dilakukan secara reguler pada awal tahun akademik', $questionnaire['details'][1]);
    }

    public function test_questionnaire_is_unavailable_when_the_popup_is_gone(): void
    {
        $html = (string) preg_replace('#<div class="popup-wrapper" id="popup">.*?</form>\s*</div>\s*</div>#s', '', $this->fixtureHtml());
        $this->assertStringNotContainsString('popup-form"', $html);

        $data = $this->makeExtractorWithHtml($html)->extractPortalContextData('fake-session-token');

        $this->assertSame([
            'available' => false,
            'title' => null,
            'academic_year' => null,
            'description' => null,
            'details' => [],
        ], $data['questionnaire']);
        $this->assertSame('2026/2027', $data['academic_period']['academic_year']);
    }

    public function test_it_records_the_sidebar_menu_with_only_the_presence_of_the_sipandai_sso_form(): void
    {
        /** @var PortalContextExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(PortalContextExtractor::class, self::FIXTURE);

        $this->assertSame([
            ['label' => 'Dashboard', 'href_act' => 'dashboard'],
            ['label' => 'Pengumuman', 'href_act' => 'pengumuman'],
            ['label' => 'Profil Mahasiswa', 'href_act' => 'profile'],
            ['label' => 'Jadwal Kuliah', 'href_act' => 'course'],
            ['label' => 'Reg & KRS Online', 'href_act' => 'credit'],
            ['label' => 'Perwalian', 'href_act' => 'perwalian'],
            ['label' => 'SiPANDAI', 'href_act' => null],
            ['label' => 'SIREKA (SKKM)', 'href_act' => 'skkm'],
            ['label' => 'SiMASITA', 'href_act' => 'simasita'],
            ['label' => 'Keuangan', 'href_act' => 'keuangan'],
            ['label' => 'e-Book', 'href_act' => 'ebook'],
        ], $extractor->extractPortalContextData('fake-session-token')['sidebar_menu']);
    }

    public function test_it_never_returns_sso_fields_or_the_questionnaire_write_payload(): void
    {
        $html = str_replace(
            ['name="id" value=""', 'name="p" value=""', 'name="who" value=""', 'name="ak" value=""'],
            ['name="id" value="20990000001"', 'name="p" value="ssohashpmarker"', 'name="who" value="ssohashwhomarker"', 'name="ak" value="ssohashakmarker"'],
            $this->fixtureHtml()
        );
        $this->assertStringContainsString('ssohashakmarker', $html);

        $data = $this->makeExtractorWithHtml($html)->extractPortalContextData('fake-session-token');
        $encoded = (string) json_encode($data);

        foreach (['20990000001', 'ssohashpmarker', 'ssohashwhomarker', 'ssohashakmarker', 'loginact', 'kuesionerform', 'Klik Kuesioner', 'vmts'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_it_reads_a_dated_notice_from_the_page_content_but_not_from_the_sidebar(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 10, 12)->setTime(9, 0));

        $html = str_replace(
            '<div class="logo">',
            '<div class="logo"><marquee><font color="red">Pastikan pembayaran tahap ke 1 lunas sebelum 01 Januari 2020</font></marquee>',
            $this->fixtureHtml()
        );
        $html = str_replace(
            '<div class="popup-wrapper" id="popup">',
            '<marquee><h4><font color="red"><b>Kewajiban Pembayaran Kuliah Tahap ke 2 Tahun Berjalan, Pembayaran paling lambat pada Hari Senin, 26&nbsp;Oktober 2026</b>.. </font></h4></marquee><div class="popup-wrapper" id="popup">',
            $html
        );

        $notice = $this->makeExtractorWithHtml($html)->extractPortalContextData('fake-session-token')['notice'];

        $this->assertSame([
            'text' => 'Kewajiban Pembayaran Kuliah Tahap ke 2 Tahun Berjalan, Pembayaran paling lambat pada Hari Senin, 26 Oktober 2026.',
            'dates' => ['2026-10-26'],
            'is_future' => true,
            'kind' => 'payment',
        ], $notice);
    }

    public function test_guide_links_keep_only_pdf_files_the_campus_file_proxy_serves(): void
    {
        $html = str_replace(
            '<li><a href="../files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf">Panduan Her-registrasi Online</a></li>',
            implode('', [
                '<li><a href="../files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf">Panduan Her-registrasi Online</a></li>',
                '<li><a href="../files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf">Duplikat</a></li>',
                '<li><a href="https://files.example.test/Panduan_Luar.pdf">Panduan Luar</a></li>',
                '<li><a href="../files/SOP_Tugas_Akhir.pdf"> SOP&nbsp;Tugas Akhir </a></li>',
                '<li><a href="../files/Kalender_Akademik.pdf">Kalender Akademik</a></li>',
                '<li><a href="../files/../mahasiswa/?mod=student&amp;act=dashboard">Bukan PDF</a></li>',
            ]),
            $this->fixtureHtml()
        );

        $guideLinks = $this->makeExtractorWithHtml($html)->extractPortalContextData('fake-session-token')['guide_links'];

        $this->assertSame([
            [
                'label' => 'Panduan Her-registrasi Online',
                'path' => 'files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
                'filename' => 'Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
            ],
            [
                'label' => 'SOP Tugas Akhir',
                'path' => 'files/SOP_Tugas_Akhir.pdf',
                'filename' => 'SOP_Tugas_Akhir.pdf',
            ],
        ], $guideLinks);
    }

    public function test_it_parses_academic_period_headings(): void
    {
        $extractor = $this->makeExtractorWithHtml('<html><body></body></html>');

        $this->assertSame(
            ['label' => 'T.A 2025/2026 Semester Genap', 'academic_year' => '2025/2026', 'term' => 'genap'],
            $extractor->parseAcademicPeriod('PORTAL MyCIC T.A 2025/2026 SEMESTER GENAP')
        );
        $this->assertSame(
            ['label' => 'T.A 2026/2027 Semester Ganjil', 'academic_year' => '2026/2027', 'term' => 'ganjil'],
            $extractor->parseAcademicPeriod('JADWAL KULIAH T.A&nbsp;2026 / 2027 semester ganjil')
        );
        $this->assertSame(
            ['label' => 'T.A 2026/2027', 'academic_year' => '2026/2027', 'term' => null],
            $extractor->parseAcademicPeriod('PORTAL MyCIC T.A 2026/2027 SEMESTER PENDEK')
        );
        $this->assertNull($extractor->parseAcademicPeriod('PORTAL MyCIC'));
    }

    public function test_it_keeps_the_context_when_the_server_date_cannot_be_read(): void
    {
        $html = str_replace('Senin, 12 Oktober 2026', 'Tanggal tidak tersedia', $this->fixtureHtml());

        $data = $this->makeExtractorWithHtml($html)->extractPortalContextData('fake-session-token');

        $this->assertNull($data['server_date']);
        $this->assertNull($data['server_date_label']);
        $this->assertSame('ganjil', $data['academic_period']['term']);
    }

    public function test_it_rejects_the_login_page(): void
    {
        $html = '<!doctype html><html><head><title>MyUCIC</title></head><body><form method="post" action="?mod=loginact">'
            .'<input type="text" name="txt_username"><input type="password" name="txt_password"><input type="submit" value="Login"></form></body></html>';

        try {
            $this->makeExtractorWithHtml($html)->extractPortalContextData('fake-session-token');
            $this->fail('The login page must not be parsed as a portal context.');
        } catch (PortalScrapingException $exception) {
            $this->assertSame(403, $exception->getCode());
        }
    }

    public function test_it_rejects_a_page_without_navbar_or_sidebar_and_does_not_cache_it(): void
    {
        $extractor = $this->makeExtractorWithHtml('<!doctype html><html><head><title>MyUCIC</title></head><body><div class="main-panel"><div class="content"></div></div></body></html>');

        try {
            $extractor->extractPortalContextData('fake-session-token');
            $this->fail('An empty dashboard shell must not be accepted.');
        } catch (PortalScrapingException $exception) {
            $this->assertSame(422, $exception->getCode());
            $this->assertStringContainsString('portal context', $exception->getMessage());
        }

        $recovered = $this->makeExtractorWithFixture(PortalContextExtractor::class, self::FIXTURE)
            ->extractPortalContextData('fake-session-token');

        $this->assertSame('upstream', $recovered['_sync']['data_origin']);
    }

    public function test_a_parsed_context_is_served_from_the_resource_cache(): void
    {
        $first = $this->makeExtractorWithFixture(PortalContextExtractor::class, self::FIXTURE)
            ->extractPortalContextData('fake-session-token');
        $second = $this->makeExtractorWithHtml('<html><body>tidak dipakai</body></html>')
            ->extractPortalContextData('fake-session-token');

        $this->assertSame('upstream', $first['_sync']['data_origin']);
        $this->assertSame('server_cache', $second['_sync']['data_origin']);
        $this->assertSame($first['academic_period'], $second['academic_period']);
    }

    private function fixtureHtml(): string
    {
        return (string) file_get_contents(base_path(self::FIXTURE));
    }

    private function makeExtractorWithHtml(string $html): PortalContextExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(PortalContextExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor;
    }
}
