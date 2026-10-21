<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\FinancialHistoryExtractor;
use App\Services\PortalEndpointRegistry;
use Carbon\CarbonImmutable;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The finance page as the live portal sends it (K14 sanitized copy): no
 * tbody, `<br/>` inside cells, an unclosed `<strong>` and the SSO form in
 * the sidebar, parsed by DomCrawler's HTML5 parser like a live response.
 */
class FinancialHistoryExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const RAW_FIXTURE = 'tests/Fixtures/portal/student_finance_bills_raw.html';

    private const NORMALIZED_FIXTURE = 'tests/Fixtures/portal/student_finance_bills.html';

    /**
     * @var list<string>
     */
    private array $temporaryFixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFixtures as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_the_raw_page_is_requested_once_and_parsed_by_the_html5_parser(): void
    {
        /** @var FinancialHistoryExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(FinancialHistoryExtractor::class, self::RAW_FIXTURE, $requestedUrls);

        $extractor->extractPaymentHistoryData('fake-session-token');

        $this->assertSame([PortalEndpointRegistry::url('student_finance')], $requestedUrls);
        $this->assertStringNotContainsString('<tbody', $this->fixtureHtml(self::RAW_FIXTURE));
    }

    public function test_every_history_row_of_the_raw_page_is_read_with_its_stage_and_date(): void
    {
        $data = $this->extractRaw();

        $this->assertSame(['1', '2', '3', '4'], array_column($data['payment_history'], 'no'));
        $this->assertSame(['2026-09-08', '2026-05-14', '2026-03-12', '2026-02-06'], array_column($data['payment_history'], 'paid_at'));
        $this->assertSame([1, 4, 3, 2], array_column($data['payment_history'], 'installment_stage'));
        $this->assertSame([2, 1, 1, 1], array_column($data['payment_history'], 'study_year'));
        $this->assertSame(['Pembayaran UKT'], array_values(array_unique(array_column($data['payment_history'], 'category'))));
        $this->assertSame([1250000, 1250000, 1250000, 1250000], array_column($data['payment_history'], 'amount'));
        $this->assertSame('Tahap 4 tahun ke 1', $data['payment_history'][1]['description']);
        $this->assertSame([
            'No' => '1',
            'Histori' => '08 September 2026 Pembayaran UKT',
            'Nominal' => 'Rp. 1,250,000,-',
            'Keterangan' => 'Tahap 1 tahun ke 2 = 1.250.000',
        ], $data['payment_history'][0]['raw']);
    }

    public function test_the_raw_total_line_is_parsed_and_matches_the_rows(): void
    {
        $summary = $this->extractRaw()['summary'];

        $this->assertTrue($summary['history_available']);
        $this->assertSame(4, $summary['total_records']);
        $this->assertSame(5000000, $summary['total_paid_amount']);
        $this->assertSame(5000000, $summary['portal_total_amount']);
        $this->assertSame('Rp 5.000.000', $summary['portal_total_amount_label']);
        $this->assertTrue($summary['totals_match']);
        $this->assertSame('2026-09-08', $summary['latest_payment']['paid_at']);
        $this->assertSame([3, 1], array_column($summary['by_study_year'], 'payment_count'));
    }

    public function test_the_empty_raw_bill_table_is_recognised_as_clear(): void
    {
        $data = $this->extractRaw();

        $this->assertSame([], $data['outstanding_bills']);
        $this->assertSame([
            'status' => 'clear',
            'has_outstanding' => false,
            'total_amount' => 0,
            'total_amount_label' => 'Rp 0',
            'count' => 0,
            'rows_sum_matches_total' => true,
        ], $data['outstanding_summary']);
        $this->assertFalse($data['has_outstanding']);
        $this->assertSame(0, $data['outstanding_total']);
    }

    public function test_raw_student_info_comes_from_the_info_table_not_the_sidebar_form(): void
    {
        $this->assertSame([
            'program_studi' => 'PROGRAM STUDI CONTOH - S1',
            'academic_year' => '2026/2027',
            'nim' => '20990000001',
            'nama' => 'Mahasiswa Contoh',
        ], $this->extractRaw()['student_info']);
    }

    public function test_raw_payment_stages_follow_the_descriptions(): void
    {
        $data = $this->extractRaw();

        $this->assertSame([1, 2], array_column($data['payment_stages'], 'study_year'));
        $this->assertSame(['unknown', 'paid', 'paid', 'paid'], array_column($data['payment_stages'][0]['stages'], 'status'));
        $this->assertSame(['paid', 'unknown', 'unknown', 'unknown'], array_column($data['payment_stages'][1]['stages'], 'status'));
        $this->assertSame(2, $data['payment_stage_summary']['current_study_year']);
        $this->assertSame([1], $data['payment_stage_summary']['current_year_stages_paid']);
        $this->assertTrue($data['payment_stage_summary']['stage1_paid_for_current_year']);
        $this->assertSame(2, $data['payment_stage_summary']['next_stage']['stage']);
    }

    public function test_the_raw_page_reads_exactly_like_its_browser_normalized_copy(): void
    {
        $raw = $this->extractRaw();

        /** @var FinancialHistoryExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(FinancialHistoryExtractor::class, self::NORMALIZED_FIXTURE);
        $normalized = $extractor->extractPaymentHistoryData('other-session-token');

        $this->assertSame(
            $this->withoutVolatileKeys($normalized),
            $this->withoutVolatileKeys($raw)
        );
    }

    public function test_bill_rows_written_like_the_raw_history_rows_are_outstanding(): void
    {
        $billRows = $this->rawRow(['1', 'UKT Tahap 2 Tahun ke 2', 'Rp. 1,250,000,-', 'Tahap 2 tahun ke 2 = 1.250.000'])
            .$this->rawRow(['2', 'Biaya Praktikum Laboratorium', 'Rp. 300,000,-', "Semester Ganjil 2026/2027\t"]);

        $html = (string) preg_replace('/(<th>Nama Tagihan<\/th>.*?<\/tr>\n)/s', '$1'.$billRows, $this->fixtureHtml(self::RAW_FIXTURE), 1);
        $html = $this->replaceOnce('<h4><b>Rp. 0,-</b></h4>', '<h4><b>Rp. 1,550,000,-</b></h4>', $html);

        $data = $this->extractRawHtml($html);

        $this->assertSame(['UKT Tahap 2 Tahun ke 2', 'Biaya Praktikum Laboratorium'], array_column($data['outstanding_bills'], 'name'));
        $this->assertSame([1250000, 300000], array_column($data['outstanding_bills'], 'amount'));
        $this->assertSame([2, null], array_column($data['outstanding_bills'], 'installment_stage'));
        $this->assertSame('outstanding', $data['outstanding_summary']['status']);
        $this->assertTrue($data['has_outstanding']);
        $this->assertSame(1550000, $data['outstanding_total']);
        $this->assertTrue($data['outstanding_summary']['rows_sum_matches_total']);
        $this->assertSame(['paid', 'unpaid', 'unknown', 'unknown'], array_column($data['payment_stages'][1]['stages'], 'status'));
        $this->assertSame(['1', '2', '3', '4'], array_column($data['payment_history'], 'no'));
    }

    /**
     * @param  callable(string): string  $quirk
     */
    #[DataProvider('rawMarkupQuirks')]
    public function test_raw_markup_quirks_give_the_same_finance_data(callable $quirk): void
    {
        $html = $quirk($this->fixtureHtml(self::RAW_FIXTURE));

        $this->assertNotSame($this->fixtureHtml(self::RAW_FIXTURE), $html);

        $data = $this->extractRawHtml($html);

        $this->assertSame('20990000001', $data['student_info']['nim'] ?? null);
        $this->assertSame(['1', '2', '3', '4'], array_column($data['payment_history'], 'no'));
        $this->assertSame([1, 4, 3, 2], array_column($data['payment_history'], 'installment_stage'));
        $this->assertSame(5000000, $data['summary']['portal_total_amount']);
        $this->assertTrue($data['summary']['totals_match']);
        $this->assertSame([], $data['outstanding_bills']);
        $this->assertSame('clear', $data['outstanding_summary']['status']);
        $this->assertFalse($data['has_outstanding']);
        $this->assertSame(0, $data['outstanding_total']);
    }

    /**
     * @return array<string, array{0: callable(string): string}>
     */
    public static function rawMarkupQuirks(): array
    {
        return [
            'header cells written as bold td inside a stray b' => [static fn (string $html): string => (string) preg_replace_callback(
                '/<tr>(\s*)((?:<th>[^<]*<\/th>\s*)+)<\/tr>/',
                static fn (array $matches): string => '<tr><b>'.$matches[1]
                    .preg_replace('/<th>([^<]*)<\/th>/', '<td><b>$1</b></td>', $matches[2]).'</b></tr>',
                $html
            )],
            'all tables inside a layout table' => [static fn (string $html): string => (string) preg_replace(
                '/<div class="content table-responsive table-full-width">.*<\/table>/s',
                '<table width="100%"><tr><td>$0</td></tr></table>',
                $html,
                1
            )],
            'bill table left unclosed' => [static fn (string $html): string => (string) preg_replace(
                '/(<th>Nama Tagihan<\/th>.*?)<\/table>/s',
                '$1',
                $html,
                1
            )],
            'history rows inside a form' => [static fn (string $html): string => (string) preg_replace(
                '/(<th>Histori<\/th>.*?<\/tr>)(.*?)(\s*<tr>\s*<td colspan="2"><\/td>\s*<td align="right"><b>)/s',
                '$1<form method="post" action="?mod=student&act=keuangan">$2</form>$3',
                $html,
                1
            )],
            'a nested table inside a history cell' => [static fn (string $html): string => str_replace(
                '<td><br/>14 Mei 2026<br/> Pembayaran UKT</td>',
                '<td><br/>14 Mei 2026<br/> Pembayaran UKT<table><tr><td>Bank</td><td colspan="2">Rp. 9,000,-</td></tr></table></td>',
                $html
            )],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRaw(): array
    {
        /** @var FinancialHistoryExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(FinancialHistoryExtractor::class, self::RAW_FIXTURE);

        return $extractor->extractPaymentHistoryData('fake-session-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawHtml(string $html): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'finance-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        /** @var FinancialHistoryExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(FinancialHistoryExtractor::class, $path);

        return $extractor->extractPaymentHistoryData('fake-session-token');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutVolatileKeys(array $data): array
    {
        unset($data['extracted_at'], $data['_sync']);

        return $data;
    }

    /**
     * A table row written the way the portal writes its history rows
     *
     * @param  array{0: string, 1: string, 2: string, 3: string}  $cells
     */
    private function rawRow(array $cells): string
    {
        [$no, $name, $amount, $description] = $cells;
        $indent = str_repeat("\t", 9);

        return str_repeat("\t", 16)."<tr>\n"
            ."{$indent}<td>{$no}</td>\n"
            ."{$indent}<td>{$name}</td>\n"
            ."{$indent}<td align=\"right\">{$amount}</td>\n"
            ."{$indent}<td>{$description}</td>\t\t\n"
            .str_repeat("\t", 16)."\n{$indent}\n"
            .str_repeat("\t", 8)."</tr>\n";
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
