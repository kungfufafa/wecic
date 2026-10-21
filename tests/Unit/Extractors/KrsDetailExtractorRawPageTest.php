<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\AdvisingExtractor;
use App\Services\Extractors\KrsDetailExtractor;
use App\Services\Extractors\KrsExtractor;
use App\Services\PortalEndpointRegistry;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * act=viewkrs as the live portal sends it (K14 sanitized copies), parsed by
 * DomCrawler's HTML5 parser like a live response. The Semester 1 page is a
 * validated KRS whose status sentence also says "tidak bisa merubah"; the
 * Semester 3 page (shared with the grades parser) still waits for the DPA.
 * Both carry every semester's KHS below the KRS card.
 */
class KrsDetailExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const RAW_FIXTURE = 'tests/Fixtures/portal/student_krs_detail_raw.html';

    private const CURRENT_KRS_RAW_FIXTURE = 'tests/Fixtures/portal/student_krs_grades_raw.html';

    private const VALIDATED_TEXT = 'Status sudah divalidasi oleh Dosen Pembimbing Akademik, maka saudara tidak bisa merubah Data KRS';

    private const PENDING_TEXT = 'Segera menghubungi Dosen DPA (Dosen Pembimbing Akademik Saudara untuk validasi KRS';

    /**
     * @var list<string>
     */
    private const RAW_CODES = ['UCX0102201', 'SIX033203', 'SIX052202', 'SXE053204', 'SXE052206', 'SIX053201', 'SXB053205'];

    /**
     * @var list<string>
     */
    private array $temporaryFixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFixtures as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_the_raw_page_is_requested_once_and_its_header_is_read(): void
    {
        /** @var KrsDetailExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(KrsDetailExtractor::class, self::RAW_FIXTURE, $requestedUrls);

        $detail = $extractor->extractKrsDetailData('fake-session-token', 90002);

        $this->assertSame([PortalEndpointRegistry::url('student_krs_detail', ['id' => 90002])], $requestedUrls);
        $this->assertTrue($detail['found']);
        $this->assertSame(90002, $detail['krs_id']);
        $this->assertSame([
            'academic_year' => '2025/2026',
            'semester' => 1,
            'semester_is_regular' => true,
            'semester_label' => 'Semester 1',
            'date' => '2025-09-22',
            'nim' => '20990000001',
            'name' => 'Mahasiswa Contoh',
        ], $detail['header']);
        $this->assertTrue($detail['counts_as_study_load']);
        $this->assertTrue($detail['print_available']);
        $this->assertTrue($detail['krs_id_confirmed']);
    }

    public function test_the_raw_validated_status_is_validated_despite_its_unrelated_negation(): void
    {
        $detail = $this->extractRaw(self::RAW_FIXTURE, 90002);

        $this->assertSame(['code' => 'validated', 'label' => 'Sudah divalidasi DPA', 'raw_text' => self::VALIDATED_TEXT], $detail['status']);
    }

    public function test_the_raw_course_list_adds_up_to_the_page_totals(): void
    {
        $detail = $this->extractRaw(self::RAW_FIXTURE, 90002);

        $this->assertSame(self::RAW_CODES, array_column($detail['courses'], 'code'));
        $this->assertSame([2, 3, 2, 3, 2, 3, 3], array_column($detail['courses'], 'sks'));
        $this->assertSame([1, null, 5, 5, null, 5, 5], array_column($detail['courses'], 'semester'));
        $this->assertSame(['Baru'], array_values(array_unique(array_column($detail['courses'], 'status'))));
        $this->assertSame([false], array_values(array_unique(array_column($detail['courses'], 'is_retake'))));
        $this->assertSame(
            ['code' => 'UCX0102201', 'name' => 'Mata Kuliah Contoh Sepuluh', 'sks' => 2, 'semester' => 1, 'status' => 'Baru', 'is_retake' => false],
            $detail['courses'][0]
        );
        $this->assertSame('Mata Kuliah Contoh Dua Belas : Lanjutan', $detail['courses'][2]['name']);
        $this->assertSame(['courses' => 7, 'sks' => 18, 'source' => 'portal', 'consistent' => true], $detail['totals']);
    }

    public function test_the_raw_khs_cards_below_the_krs_are_ignored(): void
    {
        $html = $this->fixtureHtml(self::RAW_FIXTURE);

        $this->assertSame(4, substr_count($html, 'Kartu Hasil Studi (KHS) Semester'));

        $encoded = (string) json_encode($this->extractRaw(self::RAW_FIXTURE, 90002));

        foreach (['SIX043101', 'Indeks Prestasi', 'Jumlah Mutu', 'Total Nilai Mutu', 'BOBOT'] as $khsValue) {
            $this->assertStringNotContainsString($khsValue, $encoded);
        }
    }

    public function test_a_raw_page_for_another_krs_is_flagged(): void
    {
        $detail = $this->extractRaw(self::RAW_FIXTURE, 90004);

        $this->assertTrue($detail['found']);
        $this->assertFalse($detail['krs_id_confirmed']);
    }

    public function test_the_current_raw_krs_waits_for_the_dpa(): void
    {
        $detail = $this->extractRaw(self::CURRENT_KRS_RAW_FIXTURE, 90004);

        $this->assertTrue($detail['found']);
        $this->assertSame(3, $detail['header']['semester']);
        $this->assertTrue($detail['counts_as_study_load']);
        $this->assertSame(['code' => 'pending_dpa', 'label' => 'Menunggu validasi DPA', 'raw_text' => self::PENDING_TEXT], $detail['status']);
        $this->assertCount(10, $detail['courses']);
        $this->assertSame(['courses' => 10, 'sks' => 24, 'source' => 'portal', 'consistent' => true], $detail['totals']);
        $this->assertTrue($detail['krs_id_confirmed']);
    }

    /**
     * Cross-page contract on raw pages: the advising page's latest KRS id
     * opens the current viewkrs page, whose courses are the act=credit KRS,
     * and both KRS pages name the same DPA and status
     */
    public function test_the_raw_krs_pages_describe_one_current_krs(): void
    {
        /** @var AdvisingExtractor $advisingExtractor */
        $advisingExtractor = $this->makeExtractorWithRawFixture(AdvisingExtractor::class, 'tests/Fixtures/portal/student_advising_raw.html');
        /** @var KrsExtractor $krsExtractor */
        $krsExtractor = $this->makeExtractorWithRawFixture(KrsExtractor::class, 'tests/Fixtures/portal/student_credit_raw.html');

        $advising = $advisingExtractor->extractAdvisingData('fake-session-token');
        $krs = $krsExtractor->extractKrsData('fake-session-token');
        $detail = $this->extractRaw(self::CURRENT_KRS_RAW_FIXTURE, (int) $advising['latest_krs_id']);

        $this->assertTrue($detail['krs_id_confirmed']);
        $this->assertSame($advising['latest_semester_number'], $detail['header']['semester']);
        $this->assertSame(
            array_map(static fn (array $course): array => [$course['code'], $course['name'], $course['sks'], $course['semester']], $krs['courses']),
            array_map(static fn (array $course): array => [$course['code'], $course['name'], $course['sks'], $course['semester']], $detail['courses'])
        );
        $this->assertSame($krs['totals'], $detail['totals']);
        $this->assertSame($krs['status']['code'], $detail['status']['code']);
        $this->assertSame($advising['dpa'], $krs['dpa']);
    }

    public function test_a_raw_semester_t_krs_is_not_a_study_load(): void
    {
        $detail = $this->extractRawHtml($this->replaceOnce(
            "<td>KRS Semester</td><td>:</td>\n\t\t\t\t\t\t\t\t\t<td>1</td>",
            "<td>KRS Semester</td><td>:</td>\n\t\t\t\t\t\t\t\t\t<td>T</td>",
            $this->fixtureHtml(self::RAW_FIXTURE)
        ));

        $this->assertSame('T', $detail['header']['semester']);
        $this->assertFalse($detail['header']['semester_is_regular']);
        $this->assertSame('Semester T (non-reguler, makna belum diketahui)', $detail['header']['semester_label']);
        $this->assertFalse($detail['counts_as_study_load']);
        $this->assertSame(18, $detail['totals']['sks']);
    }

    public function test_the_raw_pending_wording_waits_for_the_dpa(): void
    {
        $detail = $this->extractRawHtml($this->replaceOnce(self::VALIDATED_TEXT, self::PENDING_TEXT, $this->fixtureHtml(self::RAW_FIXTURE)));

        $this->assertSame('pending_dpa', $detail['status']['code']);
    }

    /**
     * @param  callable(string): string  $quirk
     */
    #[DataProvider('rawMarkupQuirks')]
    public function test_raw_markup_quirks_give_the_same_krs_detail(callable $quirk): void
    {
        $html = $quirk($this->fixtureHtml(self::RAW_FIXTURE));

        $this->assertNotSame($this->fixtureHtml(self::RAW_FIXTURE), $html);

        $detail = $this->extractRawHtml($html);

        $this->assertSame(self::RAW_CODES, array_column($detail['courses'], 'code'));
        $this->assertSame(['courses' => 7, 'sks' => 18, 'source' => 'portal', 'consistent' => true], $detail['totals']);
        $this->assertSame('validated', $detail['status']['code']);
        $this->assertSame(1, $detail['header']['semester']);
        $this->assertSame('2025-09-22', $detail['header']['date']);
        $this->assertTrue($detail['krs_id_confirmed']);
    }

    /**
     * @return array<string, array{0: callable(string): string}>
     */
    public static function rawMarkupQuirks(): array
    {
        return [
            'course header cells inside a stray b' => [static fn (string $html): string => (string) preg_replace(
                '/<tr>(\s*<th>No\.<\/th>.*?)<\/tr>/s',
                '<tr><b>$1</b></tr>',
                $html,
                1
            )],
            'course rows inside a form' => [static fn (string $html): string => (string) preg_replace(
                '/(<th>Status MK<\/th>\s*<\/tr>)(.*?)(\s*<\/table>)/s',
                '$1<form method="post" action="?mod=student&act=viewkrs">$2</form>$3',
                $html,
                1
            )],
            'header label rows wrapped in font' => [static fn (string $html): string => (string) preg_replace(
                '/<tr>(\s*<td>(?:KRS Semester|Tanggal KRS|Status KRS)<\/td>.*?)<\/tr>/s',
                '<tr><font size="2">$1</font></tr>',
                $html
            )],
            'a nested table inside a course name cell' => [static fn (string $html): string => str_replace(
                '<td>Mata Kuliah Contoh Sepuluh</td>',
                '<td>Mata Kuliah Contoh Sepuluh<table><tr><td>Kode</td><td>SKS</td></tr><tr><td>MKX9999</td><td colspan="2">9</td></tr></table></td>',
                $html
            )],
            'krs tables without their wrapper divs' => [static fn (string $html): string => (string) preg_replace(
                '/<div class="content table-responsive table-full-width">\s*(<table class="table table-hover table-striped">)/',
                '$1',
                $html
            )],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRaw(string $fixture, int $krsId): array
    {
        /** @var KrsDetailExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(KrsDetailExtractor::class, $fixture);

        return $extractor->extractKrsDetailData('fake-session-token', $krsId);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawHtml(string $html): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'viewkrs-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        return $this->extractRaw($path, 90002);
    }

    private function replaceOnce(string $search, string $replace, string $subject): string
    {
        $this->assertSame(1, substr_count($subject, $search), "Raw fixture marker not found once: {$search}");

        return str_replace($search, $replace, $subject);
    }

    private function fixtureHtml(string $fixture): string
    {
        return (string) file_get_contents(base_path($fixture));
    }
}
