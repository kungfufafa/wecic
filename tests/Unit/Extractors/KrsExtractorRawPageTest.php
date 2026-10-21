<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\KrsExtractor;
use App\Services\PortalEndpointRegistry;
use Carbon\CarbonImmutable;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * Reg & KRS Online (act=credit) as the live portal sends it (K14 sanitized
 * copy), parsed by DomCrawler's HTML5 parser like a live response: tables
 * without tbody, the status written as misnested `<b><i><font>..</b></i></font>`
 * and an unclosed `<tr>` before `</table>`.
 */
class KrsExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const RAW_FIXTURE = 'tests/Fixtures/portal/student_credit_raw.html';

    private const NORMALIZED_FIXTURE = 'tests/Fixtures/portal/student_credit.html';

    /**
     * @var list<string>
     */
    private const RAW_CODES = [
        'SIX053301', 'DSX052302', 'DSX052303', 'AIX052304', 'SIX033305',
        'SIX033306', 'SIX033307', 'SIX032308', 'SIX013309', 'SIX012310',
    ];

    /**
     * @var list<string>
     */
    private array $temporaryFixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFixtures as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_the_raw_page_is_requested_once_and_its_krs_is_found(): void
    {
        /** @var KrsExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(KrsExtractor::class, self::RAW_FIXTURE, $requestedUrls);

        $krs = $extractor->extractKrsData('fake-session-token');

        $this->assertSame([PortalEndpointRegistry::url('student_credit')], $requestedUrls);
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
        $this->assertSame([[
            'kind' => 'perwalian_perbaikan',
            'start' => '2026-09-17',
            'end' => '2026-10-31',
            'label' => 'Perwalian & perbaikan KRS',
            'source' => 'credit',
            'source_ref' => 'table_row',
        ]], $krs['periods']);
        $this->assertSame('krs_period', $krs['notice']['kind']);
        $this->assertSame(['2026-09-17', '2026-10-31'], $krs['notice']['dates']);
        $this->assertTrue($krs['notice']['is_future']);
        $this->assertStringStartsWith('Jika Saudara sudah melakukan KRS semester ini', (string) $krs['notice_text']);
        $this->assertTrue($krs['print_available']);
        $this->assertFalse($krs['registration_form_detected']);
        $this->assertNull($krs['registration_form']);
    }

    public function test_the_misnested_raw_status_is_read_without_the_dpa_name(): void
    {
        $this->assertStringContainsString('Belum Validasi DPA </b></i></font>=>', $this->fixtureHtml(self::RAW_FIXTURE));

        $krs = $this->extractRaw();

        $this->assertSame(['code' => 'pending_dpa', 'label' => 'Menunggu validasi DPA', 'raw_text' => 'Belum Validasi DPA'], $krs['status']);
        $this->assertSame([
            'name' => 'Dosen Pembimbing, M.Kom.',
            'nidn' => null,
            'phone' => '081200000000',
            'whatsapp' => '6281200000000',
            'email' => 'dosen@example.test',
        ], $krs['dpa']);
    }

    public function test_the_raw_course_list_adds_up_to_the_page_totals(): void
    {
        $krs = $this->extractRaw();

        $this->assertSame(self::RAW_CODES, array_column($krs['courses'], 'code'));
        $this->assertSame([2, 2, 2, 2, 3, 3, 3, 2, 3, 2], array_column($krs['courses'], 'sks'));
        $this->assertSame([5, 5, 5, 5, 3, 3, 3, 3, 1, 1], array_column($krs['courses'], 'semester'));
        $this->assertSame(['code' => 'SIX053301', 'name' => 'Mata Kuliah Berjalan Satu', 'sks' => 2, 'semester' => 5], $krs['courses'][0]);
        $this->assertSame('Mata Kuliah Berjalan Tiga', $krs['courses'][2]['name']);
        $this->assertSame('Mata Kuliah Berjalan Lima (Teori, Praktikum)', $krs['courses'][4]['name']);
        $this->assertSame(['courses' => 10, 'sks' => 24, 'source' => 'portal', 'consistent' => true], $krs['totals']);
        $this->assertSame(24, array_sum(array_column($krs['courses'], 'sks')));
    }

    public function test_the_raw_page_has_the_same_shape_as_its_browser_normalized_copy(): void
    {
        $raw = $this->extractRaw();

        /** @var KrsExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(KrsExtractor::class, self::NORMALIZED_FIXTURE);
        $normalized = $extractor->extractKrsData('other-session-token');

        $this->assertSame(array_keys($normalized), array_keys($raw));
        $this->assertSame($normalized['academic_period'], $raw['academic_period']);
        $this->assertSame($normalized['student'], $raw['student']);
        $this->assertSame($normalized['periods'], $raw['periods']);
        $this->assertSame($normalized['status'], $raw['status']);
        $this->assertSame($normalized['dpa'], $raw['dpa']);
        $this->assertSame($normalized['notice_text'], $raw['notice_text']);
        $this->assertSame(array_keys($normalized['courses'][0]), array_keys($raw['courses'][0]));
    }

    /**
     * @param  callable(string): string  $quirk
     */
    #[DataProvider('rawMarkupQuirks')]
    public function test_raw_markup_quirks_give_the_same_krs(callable $quirk): void
    {
        $html = $quirk($this->fixtureHtml(self::RAW_FIXTURE));

        $this->assertNotSame($this->fixtureHtml(self::RAW_FIXTURE), $html);

        $krs = $this->extractRawHtml($html);

        $this->assertSame(self::RAW_CODES, array_column($krs['courses'], 'code'));
        $this->assertSame(['courses' => 10, 'sks' => 24, 'source' => 'portal', 'consistent' => true], $krs['totals']);
        $this->assertSame('pending_dpa', $krs['status']['code']);
        $this->assertSame('Dosen Pembimbing, M.Kom.', $krs['dpa']['name'] ?? null);
        $this->assertSame('20990000001', $krs['student']['nim']);
    }

    /**
     * @return array<string, array{0: callable(string): string}>
     */
    public static function rawMarkupQuirks(): array
    {
        return [
            'course header cells inside a stray b' => [static fn (string $html): string => (string) preg_replace(
                '/(<tr bgcolor="#c8c1bd">)(.*?)(<\/tr>)/s',
                '$1<b>$2</b>$3',
                $html,
                1
            )],
            'course table inside a layout table' => [static fn (string $html): string => (string) preg_replace(
                '/<table  class="table table-hover">.*?<\/table>/s',
                '<table width="100%"><tr><td>$0</td></tr></table>',
                $html,
                1
            )],
            'course cells wrapped in font' => [static fn (string $html): string => (string) preg_replace(
                '/(<td align="left">[A-Z]+X\d+<\/td>)/',
                '<font face="Arial">$1</font>',
                $html
            )],
            'a nested table inside a course name cell' => [static fn (string $html): string => str_replace(
                '<td>Mata Kuliah Berjalan Dua</td>',
                '<td>Mata Kuliah Berjalan Dua<table><tr><td colspan="3">Total SKS</td><td>99 SKS</td></tr></table></td>',
                $html
            )],
            'label rows wrapped in font' => [static fn (string $html): string => (string) preg_replace(
                '/<tr>(\s*<td>N I M<\/td><td>:<\/td>\s*<td><b>[^<]*<\/b><\/td>\s*)<\/tr>/',
                '<tr><font size="2">$1</font></tr>',
                $html,
                1
            )],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRaw(): array
    {
        /** @var KrsExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(KrsExtractor::class, self::RAW_FIXTURE);

        return $extractor->extractKrsData('fake-session-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawHtml(string $html): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'credit-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        /** @var KrsExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(KrsExtractor::class, $path);

        return $extractor->extractKrsData('fake-session-token');
    }

    private function fixtureHtml(string $fixture): string
    {
        return (string) file_get_contents(base_path($fixture));
    }
}
