<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\FinancialHistoryExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use ReflectionMethod;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class FinancialHistoryExtractorRowTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_payment_row_separates_date_from_type_and_normalises_the_amount(): void
    {
        $row = (new Crawler(
            '<table><tr><td>1</td><td>08 September 2026 Pembayaran UKT</td><td>Tahap 1 tahun ke 2 = 1.250.000</td><td>Rp. 1,250,000,-</td></tr></table>'
        ))->filter('tr')->first();

        $extractor = new FinancialHistoryExtractor(Mockery::mock(PortalSessionManager::class));
        $payment = (new ReflectionMethod($extractor, 'extractPaymentRow'))->invoke($extractor, $row, ['No', 'Tanggal', 'Keterangan', 'Jumlah']);

        $this->assertSame('08 September 2026', $payment['paid_at_label']);
        $this->assertSame('Pembayaran UKT', $payment['category']);
        $this->assertSame('Tahap 1 tahun ke 2', $payment['description']);
        $this->assertSame(1250000, $payment['amount']);
        $this->assertSame('Rp 1.250.000', $payment['amount_label']);
    }
}
