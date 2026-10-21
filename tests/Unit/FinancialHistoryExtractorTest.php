<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extractors\FinancialHistoryExtractor;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class FinancialHistoryExtractorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_student_payment_history_from_finance_page(): void
    {
        cache()->forget('student_financial_history_'.md5('fake-session-token'));

        $sessionManager = Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')
                ->once()
                ->with('fake-session-token')
                ->andReturn(new CookieJar);
        });

        $extractor = Mockery::mock(FinancialHistoryExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->once()
            ->andReturn(<<<'HTML'
                <html>
                    <body>
                        <div class="card">
                            <table>
                                <tr><td>NIM</td><td>:</td><td>20990000001</td></tr>
                                <tr><td>Nama</td><td>:</td><td>Mahasiswa Uji</td></tr>
                                <tr><td>Program Studi</td><td>:</td><td>Sistem Informasi</td></tr>
                            </table>
                        </div>

                        <div class="card">
                            <h4>Histori Pembayaran</h4>
                            <table>
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Tanggal</th>
                                        <th>Uraian Pembayaran</th>
                                        <th>Nominal</th>
                                        <th>Bank</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>1</td>
                                        <td>12 Mei 2026</td>
                                        <td>SPP Semester Genap</td>
                                        <td>Rp 1.250.000</td>
                                        <td>BCA</td>
                                        <td>Lunas</td>
                                    </tr>
                                    <tr>
                                        <td>2</td>
                                        <td>20 April 2026</td>
                                        <td>Praktikum</td>
                                        <td>Rp 300.000</td>
                                        <td>Mandiri</td>
                                        <td>Lunas</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </body>
                </html>
            HTML);

        $data = $extractor->extractPaymentHistoryData('fake-session-token');

        $this->assertSame('20990000001', $data['student_info']['nim']);
        $this->assertSame('Mahasiswa Uji', $data['student_info']['nama']);
        $this->assertCount(2, $data['payment_history']);
        $this->assertSame('12 Mei 2026', $data['payment_history'][0]['paid_at_label']);
        $this->assertSame('SPP Semester Genap', $data['payment_history'][0]['description']);
        $this->assertSame('Rp 1.250.000', $data['payment_history'][0]['amount_label']);
        $this->assertSame(1250000, $data['payment_history'][0]['amount']);
        $this->assertSame(1550000, $data['summary']['total_paid_amount']);
        $this->assertSame('upstream', $data['_sync']['data_origin']);
    }
}
