<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Symfony\Component\DomCrawler\Crawler;

class FinancialHistoryExtractor extends PortalScrapingService
{
    /**
     * Extract student payment history from portal/mahasiswa/?mod=student&act=keuangan.
     *
     * @return array{
     *     student_info: array<string, string>,
     *     payment_history: list<array<string, mixed>>,
     *     summary: array{total_records: int, total_paid_amount: int, currency: string, latest_payment_label: string|null},
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractPaymentHistoryData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting student financial payment history', ['session_token' => $sessionToken]);

            $cacheKey = 'student_financial_history_'.md5($sessionToken);
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached student financial payment history');
                $decodedData = json_decode($cachedData, true);

                if (is_array($decodedData)) {
                    return $this->withSyncMeta($decodedData, 'server_cache');
                }
            }

            $financeUrl = PortalEndpointRegistry::url('student_finance');
            $html = $this->get($financeUrl);
            $crawler = $this->createCrawler($html);
            $payments = $this->extractPaymentHistory($crawler);

            $financeData = [
                'student_info' => $this->extractStudentInfo($crawler),
                'payment_history' => $payments,
                'summary' => $this->summarizePayments($payments),
                'extracted_at' => now()->toISOString(),
            ];

            $encodedData = json_encode($financeData);
            if ($encodedData !== false) {
                $this->cacheResponse($cacheKey, $encodedData);
            }

            $this->logScraping('Student financial payment history extracted successfully', [
                'payment_count' => count($payments),
            ]);

            return $this->withSyncMeta($financeData, 'upstream');
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting student financial payment history', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('financial payment history', $e->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    private function extractStudentInfo(Crawler $crawler): array
    {
        $studentInfo = [];

        $crawler->filter('table tr')->each(function (Crawler $row) use (&$studentInfo): void {
            $cells = $row->filter('td, th');

            if ($cells->count() < 2 || $cells->count() > 4) {
                return;
            }

            $label = $this->cleanFinanceText($cells->eq(0)->text());
            $valueIndex = $cells->count() >= 3 && trim($cells->eq(1)->text()) === ':' ? 2 : 1;
            $value = $this->cleanFinanceText($cells->eq($valueIndex)->text());
            $key = $this->studentInfoKey($label);

            if ($key !== null && $value !== '') {
                $studentInfo[$key] = $value;
            }
        });

        return $studentInfo;
    }

    private function studentInfoKey(string $label): ?string
    {
        $normalized = $this->normalizeLabel($label);

        return match (true) {
            in_array($normalized, ['nim', 'n i m'], true) => 'nim',
            $normalized === 'nama' || str_contains($normalized, 'nama mahasiswa') => 'nama',
            str_contains($normalized, 'program studi') => 'program_studi',
            str_contains($normalized, 'angkatan') => 'angkatan',
            str_contains($normalized, 'status') => 'status',
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractPaymentHistory(Crawler $crawler): array
    {
        $payments = [];

        $crawler->filter('table')->each(function (Crawler $table) use (&$payments): void {
            if (! $this->looksLikePaymentTable($table)) {
                return;
            }

            $headers = $this->extractHeaders($table);
            $rows = $table->filter('tbody tr');

            if ($rows->count() === 0) {
                $rows = $table->filter('tr');
            }

            $rows->each(function (Crawler $row) use (&$payments, $headers): void {
                $payment = $this->extractPaymentRow($row, $headers);

                if ($payment !== null) {
                    $payments[] = $payment;
                }
            });
        });

        return $this->uniquePayments($payments);
    }

    private function looksLikePaymentTable(Crawler $table): bool
    {
        $text = $this->normalizeLabel($table->text());

        if (str_contains($text, 'nim') && str_contains($text, 'nama') && ! str_contains($text, 'pembayaran')) {
            return false;
        }

        $signals = [
            'pembayaran',
            'bayar',
            'keuangan',
            'kwitansi',
            'kuitansi',
            'nominal',
            'jumlah',
            'tanggal',
            'semester',
            'bank',
            'tagihan',
        ];

        $hits = 0;
        foreach ($signals as $signal) {
            if (str_contains($text, $signal)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    /**
     * @return list<string>
     */
    private function extractHeaders(Crawler $table): array
    {
        $headerCells = $table->filter('thead tr')->first()->filter('th, td');

        if ($headerCells->count() === 0) {
            $headerCells = $table->filter('tr')->first()->filter('th, td');
        }

        $headers = [];
        $headerCells->each(function (Crawler $cell) use (&$headers): void {
            $headers[] = $this->cleanFinanceText($cell->text());
        });

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, mixed>|null
     */
    private function extractPaymentRow(Crawler $row, array $headers): ?array
    {
        $cells = $row->filter('td');

        if ($cells->count() < 2) {
            return null;
        }

        $raw = [];
        $payment = [];

        $cells->each(function (Crawler $cell, int $index) use (&$raw, &$payment, $headers): void {
            $value = $this->cleanFinanceText($cell->text());
            $header = $headers[$index] ?? 'column_'.($index + 1);
            $key = $this->paymentKey($header, $index);

            $raw[$header] = $value;

            if ($value !== '') {
                $payment[$key] = $value;
            }
        });

        if ($this->isHeaderLikeRow(array_values($raw), $headers)) {
            return null;
        }

        if (! $this->hasPaymentSignal($payment)) {
            return null;
        }

        if (isset($payment['amount_label'])) {
            $payment['amount'] = $this->parseAmount((string) $payment['amount_label']);
        }

        $payment['raw'] = $raw;

        return $payment;
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $headers
     */
    private function isHeaderLikeRow(array $values, array $headers): bool
    {
        if ($headers === [] || count($values) !== count($headers)) {
            return false;
        }

        foreach ($values as $index => $value) {
            if ($this->normalizeLabel($value) !== $this->normalizeLabel($headers[$index] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function paymentKey(string $header, int $index): string
    {
        $normalized = $this->normalizeLabel($header);

        return match (true) {
            $normalized === 'no' || $normalized === 'nomor' => 'no',
            str_contains($normalized, 'tanggal') || str_contains($normalized, 'tgl') || str_contains($normalized, 'histori') => 'paid_at_label',
            str_contains($normalized, 'tahun akademik') || str_contains($normalized, 'tahun') => 'academic_year',
            str_contains($normalized, 'semester') => 'semester',
            str_contains($normalized, 'kwitansi') || str_contains($normalized, 'kuitansi') || str_contains($normalized, 'invoice') || str_contains($normalized, 'referensi') => 'reference',
            str_contains($normalized, 'bank') || str_contains($normalized, 'channel') || str_contains($normalized, 'metode') => 'payment_channel',
            str_contains($normalized, 'jumlah') || str_contains($normalized, 'nominal') || str_contains($normalized, 'dibayar') || str_contains($normalized, 'nilai') || str_contains($normalized, 'amount') => 'amount_label',
            str_contains($normalized, 'status') => 'status',
            str_contains($normalized, 'jenis') || str_contains($normalized, 'uraian') || str_contains($normalized, 'keterangan') || str_contains($normalized, 'tagihan') || str_contains($normalized, 'pembayaran') => 'description',
            default => 'column_'.($index + 1),
        };
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function hasPaymentSignal(array $payment): bool
    {
        if (empty($payment['paid_at_label']) && empty($payment['reference'])) {
            return false;
        }

        foreach (['amount_label', 'description'] as $key) {
            if (! empty($payment[$key])) {
                return true;
            }
        }

        return false;
    }

    private function parseAmount(string $value): int
    {
        $digits = preg_replace('/[^\d]/', '', $value);

        return $digits === '' ? 0 : (int) $digits;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return list<array<string, mixed>>
     */
    private function uniquePayments(array $payments): array
    {
        $unique = [];
        $seen = [];

        foreach ($payments as $payment) {
            $encodedKey = json_encode([
                $payment['reference'] ?? null,
                $payment['paid_at_label'] ?? null,
                $payment['amount_label'] ?? null,
                $payment['description'] ?? null,
            ]);
            $key = strtolower($encodedKey !== false ? $encodedKey : (string) spl_object_id((object) $payment));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $payment;
        }

        return $unique;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array{total_records: int, total_paid_amount: int, currency: string, latest_payment_label: string|null}
     */
    private function summarizePayments(array $payments): array
    {
        return [
            'total_records' => count($payments),
            'total_paid_amount' => array_sum(array_map(
                static fn (array $payment): int => (int) ($payment['amount'] ?? 0),
                $payments
            )),
            'currency' => 'IDR',
            'latest_payment_label' => $payments[0]['paid_at_label'] ?? null,
        ];
    }

    private function cleanFinanceText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeLabel(string $value): string
    {
        $value = strtolower($this->cleanFinanceText($value));
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
