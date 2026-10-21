<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\FinancialHistoryExtractor;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class FinancialHistoryExtractorBillsTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const CLEAR_FIXTURE = 'tests/Fixtures/portal/student_finance_bills.html';

    private const OUTSTANDING_FIXTURE = 'tests/Fixtures/portal/student_finance_bills_outstanding.html';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_empty_bill_table_is_clear_with_a_real_zero_total(): void
    {
        $data = $this->extractFixture(self::CLEAR_FIXTURE);

        $this->assertSame([], $data['outstanding_bills']);
        $this->assertSame([
            'status' => 'clear',
            'has_outstanding' => false,
            'total_amount' => 0,
            'total_amount_label' => 'Rp 0',
            'count' => 0,
            'rows_sum_matches_total' => true,
        ], $data['outstanding_summary']);
        $this->assertSame(0, $data['outstanding_total']);
        $this->assertSame('Rp 0', $data['outstanding_total_label']);
        $this->assertFalse($data['has_outstanding']);
    }

    public function test_two_outstanding_bills_are_extracted_and_match_the_portal_total(): void
    {
        $data = $this->extractFixture(self::OUTSTANDING_FIXTURE);

        $this->assertCount(2, $data['outstanding_bills']);

        [$tuition, $practicum] = $data['outstanding_bills'];

        $this->assertSame('1', $tuition['no']);
        $this->assertSame('UKT Tahap 2 Tahun ke 2', $tuition['name']);
        $this->assertSame(1250000, $tuition['amount']);
        $this->assertSame('Rp 1.250.000', $tuition['amount_label']);
        $this->assertSame('Tahap 2 tahun ke 2', $tuition['description']);
        $this->assertSame(2, $tuition['installment_stage']);
        $this->assertSame(2, $tuition['study_year']);
        $this->assertSame(1250000, $tuition['installment_amount']);
        $this->assertSame('2026/2027', $tuition['academic_year']);
        $this->assertSame([
            'No' => '1',
            'Nama Tagihan' => 'UKT Tahap 2 Tahun ke 2',
            'Nominal' => 'Rp. 1,250,000,-',
            'Keterangan' => 'Tahap 2 tahun ke 2 = 1.250.000',
        ], $tuition['raw']);

        $this->assertSame('2', $practicum['no']);
        $this->assertSame('Biaya Praktikum Laboratorium', $practicum['name']);
        $this->assertSame(300000, $practicum['amount']);
        $this->assertSame('Rp 300.000', $practicum['amount_label']);
        $this->assertSame('Semester Ganjil 2026/2027', $practicum['description']);
        $this->assertNull($practicum['installment_stage']);
        $this->assertNull($practicum['study_year']);
        $this->assertNull($practicum['academic_year']);

        $this->assertSame([
            'status' => 'outstanding',
            'has_outstanding' => true,
            'total_amount' => 1550000,
            'total_amount_label' => 'Rp 1.550.000',
            'count' => 2,
            'rows_sum_matches_total' => true,
        ], $data['outstanding_summary']);
        $this->assertSame(1550000, $data['outstanding_total']);
        $this->assertSame('Rp 1.550.000', $data['outstanding_total_label']);
        $this->assertTrue($data['has_outstanding']);
    }

    public function test_bill_rows_and_total_rows_never_enter_the_payment_history(): void
    {
        $data = $this->extractFixture(self::OUTSTANDING_FIXTURE);

        $this->assertSame(['1', '2', '3', '4'], array_column($data['payment_history'], 'no'));
        $this->assertSame(5000000, $data['summary']['total_paid_amount']);
    }

    public function test_a_bill_total_that_disagrees_with_its_rows_is_flagged(): void
    {
        $html = str_replace(
            '<h4><b>Rp. 1,550,000,-</b></h4>',
            '<h4><b>Rp. 1,800,000,-</b></h4>',
            $this->fixtureHtml(self::OUTSTANDING_FIXTURE)
        );

        $summary = $this->extractHtml($html)['outstanding_summary'];

        $this->assertSame('outstanding', $summary['status']);
        $this->assertSame(1800000, $summary['total_amount']);
        $this->assertFalse($summary['rows_sum_matches_total']);
    }

    public function test_a_missing_bill_table_is_unknown_instead_of_zero(): void
    {
        $data = $this->extractHtml($this->withoutTableContaining($this->fixtureHtml(self::CLEAR_FIXTURE), 'Nama Tagihan'));

        $this->assertSame([
            'status' => 'unknown',
            'has_outstanding' => null,
            'total_amount' => null,
            'total_amount_label' => null,
            'count' => 0,
            'rows_sum_matches_total' => null,
        ], $data['outstanding_summary']);
        $this->assertNull($data['outstanding_total']);
        $this->assertNull($data['outstanding_total_label']);
        $this->assertNull($data['has_outstanding']);
        $this->assertCount(4, $data['payment_history']);
    }

    public function test_a_bill_table_without_a_readable_total_is_unknown(): void
    {
        $html = str_replace('<h4><b>Rp. 0,-</b></h4>', '', $this->fixtureHtml(self::CLEAR_FIXTURE));

        $data = $this->extractHtml($html);

        $this->assertSame('unknown', $data['outstanding_summary']['status']);
        $this->assertNull($data['outstanding_total']);
        $this->assertNull($data['has_outstanding']);
    }

    public function test_it_reads_student_info_from_the_main_content(): void
    {
        $data = $this->extractFixture(self::CLEAR_FIXTURE);

        $this->assertSame([
            'program_studi' => 'PROGRAM STUDI CONTOH - S1',
            'academic_year' => '2026/2027',
            'nim' => '20990000001',
            'nama' => 'Mahasiswa Contoh',
        ], $data['student_info']);
    }

    public function test_history_rows_carry_stage_study_year_iso_date_and_installment_amount(): void
    {
        $data = $this->extractFixture(self::CLEAR_FIXTURE);
        [$first, $second] = $data['payment_history'];

        $this->assertSame('1', $first['no']);
        $this->assertSame('08 September 2026', $first['paid_at_label']);
        $this->assertSame('2026-09-08', $first['paid_at']);
        $this->assertSame('Pembayaran UKT', $first['category']);
        $this->assertSame('Tahap 1 tahun ke 2', $first['description']);
        $this->assertSame(1, $first['installment_stage']);
        $this->assertSame(2, $first['study_year']);
        $this->assertSame(1250000, $first['installment_amount']);
        $this->assertSame(1250000, $first['amount']);
        $this->assertSame('Rp 1.250.000', $first['amount_label']);
        $this->assertSame('2026/2027', $first['academic_year']);
        $this->assertSame([
            'No' => '1',
            'Histori' => '08 September 2026 Pembayaran UKT',
            'Nominal' => 'Rp. 1,250,000,-',
            'Keterangan' => 'Tahap 1 tahun ke 2 = 1.250.000',
        ], $first['raw']);

        $this->assertSame('Tahap 4 tahun ke 1', $second['description']);
        $this->assertSame(4, $second['installment_stage']);
        $this->assertSame(1, $second['study_year']);
        $this->assertSame('2026-05-14', $second['paid_at']);
        $this->assertSame('2025/2026', $second['academic_year']);
    }

    public function test_summary_keeps_its_keys_and_adds_portal_totals_and_study_years(): void
    {
        $summary = $this->extractFixture(self::CLEAR_FIXTURE)['summary'];

        $this->assertSame(4, $summary['total_records']);
        $this->assertSame(5000000, $summary['total_paid_amount']);
        $this->assertSame('IDR', $summary['currency']);
        $this->assertSame('08 September 2026', $summary['latest_payment_label']);
        $this->assertTrue($summary['history_available']);
        $this->assertSame(5000000, $summary['portal_total_amount']);
        $this->assertSame('Rp 5.000.000', $summary['portal_total_amount_label']);
        $this->assertTrue($summary['totals_match']);
        $this->assertSame('1', $summary['latest_payment']['no']);
        $this->assertSame('2026-09-08', $summary['latest_payment']['paid_at']);
        $this->assertSame([
            [
                'study_year' => 1,
                'academic_year' => '2025/2026',
                'payment_count' => 3,
                'total_amount' => 3750000,
                'total_amount_label' => 'Rp 3.750.000',
                'stages_paid' => [2, 3, 4],
            ],
            [
                'study_year' => 2,
                'academic_year' => '2026/2027',
                'payment_count' => 1,
                'total_amount' => 1250000,
                'total_amount_label' => 'Rp 1.250.000',
                'stages_paid' => [1],
            ],
        ], $summary['by_study_year']);
    }

    public function test_latest_payment_follows_the_date_not_the_row_order(): void
    {
        $html = str_replace('06 Februari 2026', '06 Oktober 2026', $this->fixtureHtml(self::CLEAR_FIXTURE));

        $summary = $this->extractHtml($html)['summary'];

        $this->assertSame('4', $summary['latest_payment']['no']);
        $this->assertSame('2026-10-06', $summary['latest_payment']['paid_at']);
        $this->assertSame('08 September 2026', $summary['latest_payment_label']);
    }

    public function test_an_unrecorded_stage_of_a_past_year_is_unknown_instead_of_unpaid(): void
    {
        $data = $this->extractFixture(self::CLEAR_FIXTURE);

        $this->assertCount(2, $data['payment_stages']);

        [$firstYear, $secondYear] = $data['payment_stages'];

        $this->assertSame(1, $firstYear['study_year']);
        $this->assertSame('2025/2026', $firstYear['academic_year']);
        $this->assertFalse($firstYear['is_current']);
        $this->assertSame(['unknown', 'paid', 'paid', 'paid'], array_column($firstYear['stages'], 'status'));
        $this->assertSame('not_recorded', $firstYear['stages'][0]['reason']);
        $this->assertSame(3, $firstYear['paid_count']);
        $this->assertSame(1, $firstYear['unknown_count']);
        $this->assertSame(0, $firstYear['unpaid_count']);

        $this->assertSame(2, $secondYear['study_year']);
        $this->assertSame('2026/2027', $secondYear['academic_year']);
        $this->assertTrue($secondYear['is_current']);
        $this->assertSame(['paid', 'unknown', 'unknown', 'unknown'], array_column($secondYear['stages'], 'status'));
        $this->assertSame('no_deadline', $secondYear['stages'][1]['reason']);
        $this->assertSame('2026-09-08', $secondYear['stages'][0]['paid_at']);
        $this->assertSame(1250000, $secondYear['stages'][0]['paid_amount']);
        $this->assertFalse($secondYear['stages'][0]['is_partial']);

        $this->assertSame([
            'current_study_year' => 2,
            'current_study_year_source' => 'academic_year',
            'current_academic_year' => '2026/2027',
            'stages_per_year' => 4,
            'current_year_stages_paid' => [1],
            'stage1_paid_for_current_year' => true,
            'next_stage' => [
                'study_year' => 2,
                'academic_year' => '2026/2027',
                'stage' => 2,
                'status' => 'unknown',
                'reason' => 'no_deadline',
                'deadline' => null,
            ],
        ], $data['payment_stage_summary']);
    }

    public function test_a_billed_stage_is_unpaid(): void
    {
        $data = $this->extractFixture(self::OUTSTANDING_FIXTURE);
        $secondYear = $data['payment_stages'][1];

        $this->assertSame(['paid', 'unpaid', 'unknown', 'unknown'], array_column($secondYear['stages'], 'status'));
        $this->assertSame('in_bills', $secondYear['stages'][1]['reason']);
        $this->assertSame(1250000, $secondYear['stages'][1]['bill_amount']);
        $this->assertSame('unpaid', $data['payment_stage_summary']['next_stage']['status']);
        $this->assertSame(2, $data['payment_stage_summary']['next_stage']['stage']);
    }

    public function test_tables_in_the_sidebar_are_never_parsed(): void
    {
        $sidebarTables = '<table><tbody><tr><th>No</th><th>Histori</th><th>Nominal</th><th>Keterangan</th></tr>'
            .'<tr><td>9</td><td><br>01 September 2025<br> Pembayaran UKT</td><td align="right">Rp. 9,999,000,-</td><td>Tahap 1 tahun ke 1 = 9.999.000</td></tr></tbody></table>'
            .'<table><tbody><tr><th>No</th><th>Nama Tagihan</th><th>Nominal</th><th>Keterangan</th></tr>'
            .'<tr><td>1</td><td>Tagihan Samping</td><td align="right">Rp. 7,000,-</td><td></td></tr></tbody></table>'
            .'<table><tbody><tr><td>N I M</td><td>:</td><td>20990000999</td></tr></tbody></table>';

        $html = str_replace(
            '<div class="sidebar-wrapper">',
            '<div class="sidebar-wrapper">'.$sidebarTables,
            $this->fixtureHtml(self::CLEAR_FIXTURE)
        );

        $data = $this->extractHtml($html);

        $this->assertSame('20990000001', $data['student_info']['nim']);
        $this->assertSame(['1', '2', '3', '4'], array_column($data['payment_history'], 'no'));
        $this->assertSame('clear', $data['outstanding_summary']['status']);
        $this->assertSame('unknown', $data['payment_stages'][0]['stages'][0]['status']);
    }

    public function test_the_page_reads_the_same_without_an_html5_doctype(): void
    {
        $html = str_replace('<!doctype html>', '', $this->fixtureHtml(self::OUTSTANDING_FIXTURE));

        $data = $this->extractHtml($html);

        $this->assertSame('20990000001', $data['student_info']['nim']);
        $this->assertSame(['1', '2', '3', '4'], array_column($data['payment_history'], 'no'));
        $this->assertSame(5000000, $data['summary']['portal_total_amount']);
        $this->assertSame(['UKT Tahap 2 Tahun ke 2', 'Biaya Praktikum Laboratorium'], array_column($data['outstanding_bills'], 'name'));
        $this->assertSame(1550000, $data['outstanding_total']);
    }

    public function test_identical_rows_with_different_numbers_are_both_kept(): void
    {
        $firstRow = "<tr>\n<td>1</td>\n<td><br>08 September 2026<br> Pembayaran UKT</td>\n<td align=\"right\">Rp. 1,250,000,-</td>\n<td>Tahap 1 tahun ke 2 = 1.250.000</td>\n</tr>";
        $html = $this->fixtureHtml(self::CLEAR_FIXTURE);

        $this->assertStringContainsString($firstRow, $html);

        $data = $this->extractHtml(str_replace($firstRow, $firstRow."\n".str_replace('<td>1</td>', '<td>5</td>', $firstRow), $html));

        $this->assertSame(['1', '5', '2', '3', '4'], array_column($data['payment_history'], 'no'));
        $this->assertSame(6250000, $data['summary']['total_paid_amount']);
        $this->assertFalse($data['summary']['totals_match']);
    }

    public function test_an_empty_history_table_has_no_records_and_no_guessed_stages(): void
    {
        $html = (string) preg_replace(
            '/(<th>Keterangan<\/th>\s*<\/tr>)(?:(?!<\/tbody>).)*?(<tr>\s*<td colspan="2"><\/td>\s*<td align="right"><b>)Rp\. 5,000,000,-/s',
            '$1$2Rp. 0,-',
            $this->fixtureHtml(self::CLEAR_FIXTURE)
        );

        $data = $this->extractHtml($html);

        $this->assertSame([], $data['payment_history']);
        $this->assertTrue($data['summary']['history_available']);
        $this->assertSame(0, $data['summary']['total_records']);
        $this->assertSame(0, $data['summary']['portal_total_amount']);
        $this->assertTrue($data['summary']['totals_match']);
        $this->assertNull($data['summary']['latest_payment']);
        $this->assertSame([], $data['payment_stages']);
        $this->assertNull($data['payment_stage_summary']['current_study_year']);
        $this->assertSame('unknown', $data['payment_stage_summary']['stage1_paid_for_current_year']);
        $this->assertNull($data['payment_stage_summary']['next_stage']);
        $this->assertSame('clear', $data['outstanding_summary']['status']);
    }

    public function test_a_missing_history_table_is_not_reported_as_zero(): void
    {
        $data = $this->extractHtml($this->withoutTableContaining($this->fixtureHtml(self::CLEAR_FIXTURE), '<th>Histori</th>'));

        $this->assertSame([], $data['payment_history']);
        $this->assertFalse($data['summary']['history_available']);
        $this->assertNull($data['summary']['portal_total_amount']);
        $this->assertNull($data['summary']['portal_total_amount_label']);
        $this->assertNull($data['summary']['totals_match']);
        $this->assertSame('clear', $data['outstanding_summary']['status']);
    }

    public function test_the_login_page_is_rejected_instead_of_read_as_an_empty_history(): void
    {
        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionCode(403);

        $this->extractHtml(
            '<!doctype html><html><head><title>MyUCIC - Login</title></head><body>'
            .'<form method="post" action="?mod=login&act=loginact"><input type="text" name="txt_username">'
            .'<input type="password" name="txt_password"><input type="submit" value="Login"></form></body></html>'
        );
    }

    public function test_an_empty_portal_shell_is_unknown_and_not_cached(): void
    {
        $html = $this->withoutTableContaining($this->fixtureHtml(self::CLEAR_FIXTURE), '<th>Histori</th>');
        $html = $this->withoutTableContaining($html, '<th>Nama Tagihan</th>');
        $extractor = $this->extractorReturning($html, expectedCalls: 2);

        $first = $extractor->extractPaymentHistoryData('fake-session-token');
        $second = $extractor->extractPaymentHistoryData('fake-session-token');

        $this->assertSame('unknown', $first['outstanding_summary']['status']);
        $this->assertNull($first['has_outstanding']);
        $this->assertFalse($first['summary']['history_available']);
        $this->assertSame('upstream', $second['_sync']['data_origin']);
    }

    public function test_a_readable_finance_page_is_served_from_the_resource_cache(): void
    {
        $extractor = $this->extractorReturning($this->fixtureHtml(self::CLEAR_FIXTURE), expectedCalls: 1);

        $extractor->extractPaymentHistoryData('fake-session-token');
        $cached = $extractor->extractPaymentHistoryData('fake-session-token');

        $this->assertSame('server_cache', $cached['_sync']['data_origin']);
        $this->assertSame('clear', $cached['outstanding_summary']['status']);
    }

    #[DataProvider('indonesianMonths')]
    public function test_history_dates_in_every_indonesian_month_become_iso_dates(string $month, string $expected): void
    {
        $row = (new Crawler(
            '<table><tr><td>1</td><td><br>07 '.$month.' 2026<br> Pembayaran UKT</td><td align="right">Rp. 1,250,000,-</td><td>Tahap 1 tahun ke 2 = 1.250.000</td></tr></table>'
        ))->filter('tr')->first();

        $extractor = new FinancialHistoryExtractor(Mockery::mock(PortalSessionManager::class));
        $payment = (new ReflectionMethod($extractor, 'extractPaymentRow'))->invoke($extractor, $row, ['No', 'Histori', 'Nominal', 'Keterangan']);

        $this->assertSame('07 '.$month.' 2026', $payment['paid_at_label']);
        $this->assertSame($expected, $payment['paid_at']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function indonesianMonths(): array
    {
        return [
            'januari' => ['Januari', '2026-01-07'],
            'februari' => ['Februari', '2026-02-07'],
            'maret' => ['Maret', '2026-03-07'],
            'april' => ['April', '2026-04-07'],
            'mei' => ['Mei', '2026-05-07'],
            'juni' => ['Juni', '2026-06-07'],
            'juli' => ['Juli', '2026-07-07'],
            'agustus' => ['Agustus', '2026-08-07'],
            'september' => ['September', '2026-09-07'],
            'oktober' => ['Oktober', '2026-10-07'],
            'november' => ['November', '2026-11-07'],
            'desember' => ['Desember', '2026-12-07'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFixture(string $fixture): array
    {
        /** @var FinancialHistoryExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(FinancialHistoryExtractor::class, $fixture);

        return $extractor->extractPaymentHistoryData('fake-session-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractHtml(string $html): array
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(FinancialHistoryExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor->extractPaymentHistoryData('fake-session-token');
    }

    private function extractorReturning(string $html, int $expectedCalls): FinancialHistoryExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        /** @var FinancialHistoryExtractor&\Mockery\MockInterface $extractor */
        $extractor = Mockery::mock(FinancialHistoryExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->times($expectedCalls)->andReturn($html);

        return $extractor;
    }

    private function fixtureHtml(string $fixture): string
    {
        return (string) file_get_contents(base_path($fixture));
    }

    private function withoutTableContaining(string $html, string $needle): string
    {
        return (string) preg_replace_callback(
            '/<table\b.*?<\/table>/s',
            static fn (array $matches): string => str_contains($matches[0], $needle) ? '' : $matches[0],
            $html
        );
    }
}
