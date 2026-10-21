<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\KrsExtractor;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class KrsExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURE = 'tests/Fixtures/portal/student_credit.html';

    private const DPA_NAME = 'Dosen Pembimbing, M.Kom.';

    private const STATUS_FONT = '<b><i><font color="black">Belum Validasi DPA </font></i></b>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_the_current_krs_from_the_credit_page(): void
    {
        /** @var KrsExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(KrsExtractor::class, self::FIXTURE);

        $krs = $extractor->extractKrsData('fake-session-token');

        $this->assertTrue($krs['found']);
        $this->assertSame([
            'label' => 'T.A 2026/2027 Semester Ganjil',
            'academic_year' => '2026/2027',
            'term' => 'ganjil',
            'source' => 'credit',
        ], $krs['academic_period']);
        $this->assertSame([
            'faculty' => 'FAKULTAS TEKNOLOGI INFORMASI',
            'program' => 'SISTEM INFORMASI - Strata S1',
            'academic_year' => '2026/2027',
            'nim' => '20990000001',
            'name' => 'Mahasiswa Contoh',
        ], $krs['student']);
        $this->assertSame([
            [
                'kind' => 'perwalian_perbaikan',
                'start' => '2026-09-17',
                'end' => '2026-10-31',
                'label' => 'Perwalian & perbaikan KRS',
                'source' => 'credit',
                'source_ref' => 'table_row',
            ],
        ], $krs['periods']);
        $this->assertSame(['start' => '2026-09-17', 'end' => '2026-10-31'], $krs['period']);
        $this->assertSame('krs_period', $krs['notice']['kind']);
        $this->assertSame(['2026-09-17', '2026-10-31'], $krs['notice']['dates']);
        $this->assertTrue($krs['notice']['is_future']);
        $this->assertStringContainsString('click menu KRS Online', $krs['notice']['text']);
        $this->assertStringStartsWith('Jika Saudara sudah melakukan KRS semester ini', (string) $krs['notice_text']);
        $this->assertSame(['code' => 'pending_dpa', 'label' => 'Menunggu validasi DPA', 'raw_text' => 'Belum Validasi DPA'], $krs['status']);
        $this->assertSame([
            'name' => self::DPA_NAME,
            'nidn' => null,
            'phone' => '081200000000',
            'whatsapp' => '6281200000000',
            'email' => 'dosen@example.test',
        ], $krs['dpa']);
        $this->assertTrue($krs['print_available']);
        $this->assertArrayHasKey('extracted_at', $krs);
    }

    public function test_the_academic_year_row_backs_up_a_title_without_t_a(): void
    {
        $html = $this->replaceOnce(
            $this->fixtureHtml(),
            'Layanan Pengisian Kartu Rencana Studi (KRS Online) T.A 2026/2027 Semester GANJIL',
            'Layanan Pengisian Kartu Rencana Studi (KRS Online) Semester GANJIL'
        );

        $krs = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token');

        $this->assertSame([
            'label' => 'T.A 2026/2027 Semester Ganjil',
            'academic_year' => '2026/2027',
            'term' => 'ganjil',
            'source' => 'credit',
        ], $krs['academic_period']);
    }

    public function test_it_maps_planned_courses_by_header_label(): void
    {
        /** @var KrsExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(KrsExtractor::class, self::FIXTURE);

        $krs = $extractor->extractKrsData('fake-session-token');

        $this->assertCount(6, $krs['courses']);
        $this->assertSame(['code' => 'MK5001', 'name' => 'Mata Kuliah Lima Satu', 'sks' => 2, 'semester' => 5], $krs['courses'][0]);
        $this->assertSame('Mata Kuliah Tiga Satu', $krs['courses'][2]['name']);
        $this->assertSame('Mata Kuliah Tiga Dua (Teori & Praktik)', $krs['courses'][3]['name']);
        $this->assertSame(['code' => 'MK1004', 'name' => 'Mata Kuliah Empat', 'sks' => 2, 'semester' => 1], $krs['courses'][5]);
        $this->assertSame(['courses' => 6, 'sks' => 14, 'source' => 'portal', 'consistent' => true], $krs['totals']);
    }

    public function test_column_mapping_follows_header_labels_not_positions(): void
    {
        $html = $this->replaceOnce(
            $this->fixtureHtml(),
            "<td width=\"40\"><b>KODE</b></td>\n<td width=\"320\"><b>NAMA MATA KULIAH</b></td>",
            "<td width=\"320\"><b>NAMA MATA KULIAH</b></td>\n<td width=\"40\"><b>KODE</b></td>"
        );
        $html = $this->replaceOnce(
            $html,
            "<td align=\"left\">MK5001</td>\n<td>Mata Kuliah Lima Satu</td>",
            "<td>Mata Kuliah Lima Satu</td>\n<td align=\"left\">MK5001</td>"
        );

        $krs = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token');

        $this->assertSame('MK5001', $krs['courses'][0]['code']);
        $this->assertSame('Mata Kuliah Lima Satu', $krs['courses'][0]['name']);
    }

    public function test_totals_fall_back_to_the_parsed_rows(): void
    {
        $html = (string) preg_replace('/<tr>\s*<td colspan="3" align="right">Total[^<]*<\/td>.*?<\/tr>/s', '', $this->fixtureHtml());

        $krs = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token');

        $this->assertSame(['courses' => 6, 'sks' => 14, 'source' => 'computed', 'consistent' => true], $krs['totals']);
    }

    public function test_status_raw_text_never_contains_the_dpa_name(): void
    {
        /** @var KrsExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(KrsExtractor::class, self::FIXTURE);

        $status = $extractor->extractKrsData('fake-session-token')['status'];

        $this->assertStringNotContainsString(self::DPA_NAME, (string) $status['raw_text']);
        $this->assertStringNotContainsString('Dosen Pembimbing', $status['label']);
    }

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function statusTextProvider(): array
    {
        return [
            'belum validasi' => ['Belum Validasi DPA', 'pending_dpa', 'Menunggu validasi DPA'],
            'belum divalidasi' => ['KRS belum divalidasi', 'pending_dpa', 'Menunggu validasi DPA'],
            'segera menghubungi dosen dpa' => ['Segera menghubungi Dosen DPA untuk validasi KRS', 'pending_dpa', 'Menunggu validasi DPA'],
            'sudah divalidasi' => ['Sudah Divalidasi DPA', 'validated', 'Sudah divalidasi DPA'],
            'sudah validasi' => ['Sudah Validasi DPA', 'validated', 'Sudah divalidasi DPA'],
            'belum mengisi' => ['Belum Mengisi KRS', 'not_submitted', 'KRS belum diajukan'],
            'belum mengajukan' => ['Anda belum mengajukan KRS', 'not_submitted', 'KRS belum diajukan'],
            'negated validation' => ['KRS tidak tervalidasi', 'unknown', null],
            'rejected' => ['KRS Ditolak DPA, silakan revisi', 'unknown', null],
            'validated with an unrelated negation' => ['Status sudah divalidasi oleh Dosen Pembimbing Akademik, maka saudara tidak bisa merubah Data KRS', 'validated', 'Sudah divalidasi DPA'],
            'validated then cancelled' => ['KRS sudah divalidasi, lalu dibatalkan DPA', 'unknown', null],
            'submitted but not yet validated' => ['KRS sudah diajukan tetapi belum divalidasi', 'pending_dpa', 'Menunggu validasi DPA'],
        ];
    }

    #[DataProvider('statusTextProvider')]
    public function test_it_classifies_the_krs_status_text(string $text, string $expectedCode, ?string $expectedLabel): void
    {
        $html = $this->replaceOnce(
            $this->fixtureHtml(),
            self::STATUS_FONT,
            '<b><i><font color="black">'.$text.' </font></i></b>'
        );

        $status = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token')['status'];

        $this->assertSame($expectedCode, $status['code']);
        $this->assertSame($text, $status['raw_text']);
        $this->assertSame($expectedLabel ?? $text, $status['label']);
        $this->assertStringNotContainsString(self::DPA_NAME, (string) $status['raw_text']);
    }

    public function test_status_without_formatting_tags_still_drops_the_dpa_name(): void
    {
        $html = (string) preg_replace(
            '/<span class="blink">.*?<\/span>/s',
            '<span class="blink"> Status KRS Sudah Divalidasi DPA =&gt; oleh '.self::DPA_NAME.'</span>',
            $this->fixtureHtml()
        );

        $status = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token')['status'];

        $this->assertSame(['code' => 'validated', 'label' => 'Sudah divalidasi DPA', 'raw_text' => 'Sudah Divalidasi DPA'], $status);
    }

    public function test_missing_status_row_is_unknown(): void
    {
        $html = (string) preg_replace('/<tr>\s*<td>Status KRS Online<\/td>.*?<\/td>\s*<\/tr>/s', '', $this->fixtureHtml());

        $krs = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token');

        $this->assertTrue($krs['found']);
        $this->assertSame(['code' => 'unknown', 'label' => 'Status KRS tidak dikenali', 'raw_text' => null], $krs['status']);
        $this->assertFalse($krs['print_available']);
    }

    public function test_her_registration_and_perwalian_periods_stay_separate(): void
    {
        $html = (string) preg_replace(
            '/<marquee>.*?<\/marquee>/s',
            '<marquee><font color="red"> Pengisian Her-registrasi dan KRS tanggal 7&nbsp;s/d 9 September 2026; Perwalian dan Perbaikan KRS Online 17 September 2026 s.d. 31 Oktober 2026. Sebelum periode Her-registrasi berakhir. </font></marquee>',
            $this->fixtureHtml()
        );

        $krs = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token');

        $this->assertSame([
            ['perwalian_perbaikan', '2026-09-17', '2026-10-31', 'table_row'],
            ['her_registrasi', '2026-09-07', '2026-09-09', 'notice'],
        ], array_map(
            static fn (array $period): array => [$period['kind'], $period['start'], $period['end'], $period['source_ref']],
            $krs['periods']
        ));
        $this->assertSame('Her-registrasi & pengisian KRS', $krs['periods'][1]['label']);
        $this->assertSame(['start' => '2026-09-17', 'end' => '2026-10-31'], $krs['period']);
    }

    public function test_sidebar_sso_form_is_not_a_registration_form(): void
    {
        $html = $this->fixtureHtml();

        $this->assertStringContainsString('loginact.php', $html);

        $krs = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token');

        $this->assertFalse($krs['registration_form_detected']);
        $this->assertNull($krs['registration_form']);
    }

    public function test_sso_form_alone_is_not_a_registration_form(): void
    {
        $html = '<!doctype html><html><head><title>MyUCIC</title></head><body>'
            .'<h4 class="title">Layanan Pengisian Kartu Rencana Studi (KRS Online) T.A 2026/2027 Semester GENAP</h4>'
            .'<form class="login100-form validate-form" method="post" action="../sipandai/?mod=login&act=loginact.php">'
            .'<input type="hidden" name="id" value=""><input type="hidden" name="p" value="">'
            .'<input type="hidden" name="who" value=""><input type="hidden" name="ak" value="">'
            .'<p><input type="submit" value="SiPANDAI"></p></form></body></html>';

        $krs = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token');

        $this->assertTrue($krs['found']);
        $this->assertSame('genap', $krs['academic_period']['term']);
        $this->assertFalse($krs['registration_form_detected']);
    }

    public function test_a_form_inside_the_content_is_detected_without_its_values(): void
    {
        $form = '<form method="post" action="?mod=student&act=simpankrs&token=rahasia-token">'
            .'<input type="hidden" name="txt_creditid" value="90004">'
            .'<input type="checkbox" name="mk[]" value="MK7001-NILAI"> <input type="checkbox" name="mk[]" value="MK7002-NILAI">'
            .'<select name="kelas"><option value="KELAS-A">A</option><option value="KELAS-B">B</option></select>'
            .'<input type="submit" name="cmd" value="Simpan KRS"></form>';
        $html = $this->replaceOnce(
            $this->fixtureHtml(),
            '<h4 class="title">DAFTAR MATA KULIAH YANG DIRENCANAKAN (KRS)</h4>',
            '<h4 class="title">DAFTAR MATA KULIAH YANG DIRENCANAKAN (KRS)</h4>'.$form
        );

        $krs = $this->makeExtractorWithHtml($html)->extractKrsData('fake-session-token');

        $this->assertTrue($krs['registration_form_detected']);
        $this->assertSame([
            'forms' => [[
                'action' => '?mod=student&act=simpankrs',
                'method' => 'POST',
                'field_names' => ['txt_creditid', 'mk[]', 'kelas', 'cmd'],
                'option_count' => 4,
            ]],
            'control_count' => 4,
        ], $krs['registration_form']);

        $encoded = (string) json_encode($krs['registration_form']);

        foreach (['rahasia-token', '90004', 'MK7001-NILAI', 'KELAS-A', 'Simpan KRS'] as $value) {
            $this->assertStringNotContainsString($value, $encoded);
        }
    }

    public function test_empty_portal_shell_is_not_found_and_not_cached(): void
    {
        $extractor = $this->makeExtractorWithHtml($this->shellHtml(), expectedCalls: 2);

        $first = $extractor->extractKrsData('fake-session-token');
        $second = $extractor->extractKrsData('fake-session-token');

        $this->assertFalse($first['found']);
        $this->assertFalse($second['found']);
        $this->assertSame([], $first['courses']);
        $this->assertSame([], $first['periods']);
        $this->assertNull($first['academic_period']);
        $this->assertSame('unknown', $first['status']['code']);
        $this->assertFalse($first['registration_form_detected']);
    }

    public function test_found_krs_is_served_from_the_resource_cache(): void
    {
        $extractor = $this->makeExtractorWithHtml($this->fixtureHtml(), expectedCalls: 1);

        $extractor->extractKrsData('fake-session-token');
        $cached = $extractor->extractKrsData('fake-session-token');

        $this->assertSame('server_cache', $cached['_sync']['data_origin']);
    }

    public function test_login_page_throws_instead_of_returning_an_empty_krs(): void
    {
        $extractor = $this->makeExtractorWithHtml(
            '<!doctype html><html><head><title>MyUCIC - Login</title></head><body>'
            .'<form method="post" action="?mod=login&act=loginact"><input type="text" name="txt_username">'
            .'<input type="password" name="txt_password"><input type="submit" value="Login"></form></body></html>'
        );

        $this->expectException(PortalScrapingException::class);

        $extractor->extractKrsData('fake-session-token');
    }

    private function fixtureHtml(): string
    {
        return (string) file_get_contents(base_path(self::FIXTURE));
    }

    /**
     * The fixture page with its content removed: what the portal serves for
     * an act it does not render
     */
    private function shellHtml(): string
    {
        $html = (string) preg_replace(
            '/(<div class="content">\s*<div class="container-fluid">).*?(\s*<\/div>\s*<\/div>\s*<footer)/s',
            '$1$2',
            $this->fixtureHtml()
        );

        $this->assertStringContainsString('loginact.php', $html);
        $this->assertStringNotContainsString('Kartu Rencana Studi', $html);

        return $html;
    }

    private function replaceOnce(string $html, string $search, string $replace): string
    {
        $this->assertSame(1, substr_count($html, $search), 'Fixture snippet not found exactly once: '.$search);

        return str_replace($search, $replace, $html);
    }

    private function makeExtractorWithHtml(string $html, ?int $expectedCalls = null): KrsExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        /** @var KrsExtractor&\Mockery\MockInterface $extractor */
        $extractor = Mockery::mock(KrsExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $expectation = $extractor->shouldReceive('get')
            ->with(Mockery::on(static fn (string $url): bool => str_contains($url, 'act=credit')))
            ->andReturn($html);

        if ($expectedCalls !== null) {
            $expectation->times($expectedCalls);
        }

        return $extractor;
    }
}
