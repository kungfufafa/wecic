<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\AdvisingExtractor;
use App\Services\PortalEndpointRegistry;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The Perwalian page as the live portal sends it (K14 sanitized copy), parsed
 * by DomCrawler's HTML5 parser like a live response: the history table has no
 * tbody and its header cells sit inside a stray `<b>` (`<tr><b><td>..`).
 */
class AdvisingExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const RAW_FIXTURE = 'tests/Fixtures/portal/student_advising_raw.html';

    private const NORMALIZED_FIXTURE = 'tests/Fixtures/portal/student_advising.html';

    /**
     * @var list<array{krs_id: int, academic_year: string, date: string, semester: int|string, semester_is_regular: bool, semester_label: string}>
     */
    private const RAW_HISTORY = [
        ['krs_id' => 90004, 'academic_year' => '2026/2027', 'date' => '2026-09-14', 'semester' => 3, 'semester_is_regular' => true, 'semester_label' => 'Semester 3'],
        ['krs_id' => 90003, 'academic_year' => '2025/2026', 'date' => '2026-03-02', 'semester' => 2, 'semester_is_regular' => true, 'semester_label' => 'Semester 2'],
        ['krs_id' => 90002, 'academic_year' => '2025/2026', 'date' => '2025-09-22', 'semester' => 1, 'semester_is_regular' => true, 'semester_label' => 'Semester 1'],
        ['krs_id' => 90001, 'academic_year' => '2024/2025', 'date' => '2026-02-23', 'semester' => 'T', 'semester_is_regular' => false, 'semester_label' => 'Semester T (non-reguler, makna belum diketahui)'],
    ];

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

    public function test_the_raw_header_cells_are_not_children_of_their_row(): void
    {
        $crawler = new Crawler($this->fixtureHtml(self::RAW_FIXTURE));
        $header = $crawler->filter('td')->reduce(static fn (Crawler $cell): bool => trim($cell->text('')) === 'T.A');

        $this->assertCount(1, $header);
        $this->assertSame('b', $header->getNode(0)?->parentNode?->nodeName);
        $this->assertCount(0, $crawler->filter('tbody'));
    }

    public function test_the_raw_page_gives_one_history_row_per_lihat_krs_link(): void
    {
        /** @var AdvisingExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(AdvisingExtractor::class, self::RAW_FIXTURE, $requestedUrls);

        $advising = $extractor->extractAdvisingData('fake-session-token');

        $this->assertSame([PortalEndpointRegistry::url('student_advising')], $requestedUrls);
        $this->assertSame(4, substr_count($this->fixtureHtml(self::RAW_FIXTURE), '>Lihat KRS</font>'));
        $this->assertTrue($advising['found']);
        $this->assertSame(self::RAW_HISTORY, $advising['krs_history']);
    }

    public function test_the_latest_raw_krs_is_the_highest_numeric_semester(): void
    {
        $advising = $this->extractRaw();

        $this->assertSame(90004, $advising['latest_krs_id']);
        $this->assertSame(3, $advising['latest_semester_number']);
        $this->assertSame([90001], array_column(
            array_filter($advising['krs_history'], static fn (array $row): bool => ! $row['semester_is_regular']),
            'krs_id'
        ));
    }

    public function test_the_raw_student_and_dpa_blocks_are_read(): void
    {
        $advising = $this->extractRaw();

        $this->assertSame([
            'photo_path' => null,
            'program' => 'SISTEM INFORMASI - S1',
            'cohort' => '2025',
            'nim' => '20990000001',
            'name' => 'Mahasiswa Contoh',
            'status' => 'Aktif',
        ], $advising['student']);
        $this->assertSame([
            'name' => 'Dosen Pembimbing, M.Kom.',
            'nidn' => null,
            'phone' => '081200000000',
            'whatsapp' => '6281200000000',
            'email' => 'dosen@example.test',
        ], $advising['dpa']);
        $this->assertFalse($advising['krs_add_action_detected']);
    }

    public function test_raw_birth_data_gender_and_sso_values_are_not_kept(): void
    {
        $encoded = (string) json_encode($this->extractRaw());

        foreach (['Kota Contoh', '01-01-2005', 'Perempuan', 'SiPANDAI', 'loginact'] as $value) {
            $this->assertStringNotContainsString($value, $encoded);
        }
    }

    public function test_the_raw_page_has_the_same_shape_as_its_browser_normalized_copy(): void
    {
        $raw = $this->extractRaw();

        /** @var AdvisingExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(AdvisingExtractor::class, self::NORMALIZED_FIXTURE);
        $normalized = $extractor->extractAdvisingData('other-session-token');

        $this->assertSame(array_keys($normalized), array_keys($raw));
        $this->assertSame($normalized['student'], $raw['student']);
        $this->assertSame($normalized['dpa'], $raw['dpa']);
        $this->assertSame(array_column($normalized['krs_history'], 'semester'), array_column($raw['krs_history'], 'semester'));
        $this->assertSame(array_column($normalized['krs_history'], 'academic_year'), array_column($raw['krs_history'], 'academic_year'));
        $this->assertSame($normalized['latest_semester_number'], $raw['latest_semester_number']);
    }

    /**
     * @param  callable(string): string  $quirk
     */
    #[DataProvider('rawMarkupQuirks')]
    public function test_raw_markup_quirks_give_the_same_history(callable $quirk): void
    {
        $html = $quirk($this->fixtureHtml(self::RAW_FIXTURE));

        $this->assertNotSame($this->fixtureHtml(self::RAW_FIXTURE), $html);

        $advising = $this->extractRawHtml($html);

        $this->assertSame(self::RAW_HISTORY, $advising['krs_history']);
        $this->assertSame(90004, $advising['latest_krs_id']);
        $this->assertSame('Dosen Pembimbing, M.Kom.', $advising['dpa']['name'] ?? null);
    }

    /**
     * @return array<string, array{0: callable(string): string}>
     */
    public static function rawMarkupQuirks(): array
    {
        return [
            'history rows inside a form' => [static fn (string $html): string => (string) preg_replace(
                '/(<td align="center"><b>Aksi<\/b><\/td>\s*<\/b>\s*<\/tr>)(.*?)(\s*<\/table>)/s',
                '$1<form method="post" action="?mod=student&act=perwalian">$2</form>$3',
                $html,
                1
            )],
            'date cells wrapped in font' => [static fn (string $html): string => (string) preg_replace(
                '/<td valign="top">((?:Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu),[^<]*)<\/td>/',
                '<font size="2"><td valign="top">$1</td></font>',
                $html
            )],
            'history table inside a layout table' => [static fn (string $html): string => (string) preg_replace(
                '/<table class="table table-hover table-striped">\s*<tr><b>.*?<\/table>/s',
                '<table width="100%"><tr><td>$0</td></tr></table>',
                $html,
                1
            )],
            'a nested table inside an action cell' => [static fn (string $html): string => (string) preg_replace(
                '/(<a href="\?mod=student&act=viewkrs&id=90004">)/',
                '<table><tr><td>T.A</td><td>Semester</td></tr><tr><td>2030/2031</td><td>9</td></tr></table>$1',
                $html,
                1
            )],
            'header cells written as th without the stray b' => [static fn (string $html): string => (string) preg_replace_callback(
                '/<tr><b>(.*?)<\/b>\s*<\/tr>/s',
                static fn (array $matches): string => '<tr>'.preg_replace('/<td([^>]*)><b>([^<]*)<\/b><\/td>/', '<th$1>$2</th>', $matches[1]).'</tr>',
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
        /** @var AdvisingExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(AdvisingExtractor::class, self::RAW_FIXTURE);

        return $extractor->extractAdvisingData('fake-session-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawHtml(string $html): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'advising-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        /** @var AdvisingExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(AdvisingExtractor::class, $path);

        return $extractor->extractAdvisingData('fake-session-token');
    }

    private function fixtureHtml(string $fixture): string
    {
        return (string) file_get_contents(base_path($fixture));
    }
}
