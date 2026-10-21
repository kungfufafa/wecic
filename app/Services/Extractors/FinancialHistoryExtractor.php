<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PaymentStageCalculator;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Illuminate\Support\Arr;
use Symfony\Component\DomCrawler\Crawler;

class FinancialHistoryExtractor extends PortalScrapingService
{
    private const STAGE_AND_YEAR_PATTERN = '/tahap\s*(?:ke\s*)?-?\s*(\d{1,2})\s*,?\s*tahun\s*(?:ke\s*)?-?\s*(\d{1,2})/iu';

    private const STAGE_PATTERN = '/tahap\s*(?:ke\s*)?-?\s*(\d{1,2})/iu';

    private const INSTALLMENT_AMOUNT_PATTERN = '/\s*=\s*([\d.,]+)\s*$/';

    /**
     * Extract student payment history and outstanding bills from
     * portal/mahasiswa/?mod=student&act=keuangan.
     *
     * The page holds three tables, told apart by their headers: student info,
     * the bill table ("Nama Tagihan") and the payment history ("Histori").
     * Each table is read through its own rows and cells only (tableRows(),
     * rowCells()), so the raw server page (no tbody, header cells inside a
     * stray `<b>`, a layout or unclosed table around another) reads the same
     * as the browser view. A missing table never turns into a zero: its
     * status is "unknown". A page with neither table (an empty portal shell)
     * is not cached, and the login page is rejected instead of being read as
     * an empty history.
     * `payment_stages` is the `years` list of PaymentStageCalculator::calculate()
     * and `payment_stage_summary` the rest of that result (see its shape).
     *
     * @return array{
     *     student_info: array<string, string>,
     *     payment_history: list<array<string, mixed>>,
     *     summary: array{
     *         total_records: int,
     *         total_paid_amount: int,
     *         currency: string,
     *         latest_payment_label: string|null,
     *         history_available: bool,
     *         portal_total_amount: int|null,
     *         portal_total_amount_label: string|null,
     *         totals_match: bool|null,
     *         latest_payment: array<string, mixed>|null,
     *         by_study_year: list<array{study_year: int, academic_year: string|null, payment_count: int, total_amount: int, total_amount_label: string, stages_paid: list<int>}>
     *     },
     *     outstanding_bills: list<array{no: string|null, name: string, amount: int|null, amount_label: string|null, description: string|null, installment_stage: int|null, study_year: int|null, installment_amount: int|null, academic_year: string|null, raw: array<string, string>}>,
     *     outstanding_summary: array{status: 'clear'|'outstanding'|'unknown', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int, rows_sum_matches_total: bool|null},
     *     outstanding_total: int|null,
     *     outstanding_total_label: string|null,
     *     has_outstanding: bool|null,
     *     payment_stages: list<array<string, mixed>>,
     *     payment_stage_summary: array<string, mixed>,
     *     extracted_at: string,
     *     _sync?: array<string, mixed>
     * }
     */
    public function extractPaymentHistoryData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting student financial payment history', ['session_token' => $sessionToken]);

            return $this->rememberResource('payments', [], function (): array {
                $url = PortalEndpointRegistry::url('student_finance');
                $html = $this->get($url);

                $this->ensureNotLoginPage($html, $url);

                $content = $this->mainContent($this->createCrawler($html));

                $studentInfo = $this->extractStudentInfo($content);
                $history = $this->extractPaymentHistory($content);
                $billTable = $this->extractOutstandingBills($content);
                $outstandingSummary = $this->summarizeOutstandingBills($billTable);

                $stages = app(PaymentStageCalculator::class)->calculate(
                    $history['payments'],
                    $billTable['bills'],
                    $studentInfo['academic_year'] ?? null
                );

                $payments = $this->withAcademicYears($history['payments'], $stages['years']);
                $bills = $this->withAcademicYears($billTable['bills'], $stages['years']);

                $financeData = [
                    'student_info' => $studentInfo,
                    'payment_history' => $payments,
                    'summary' => $this->summarizePayments($payments, $history['table_found'], $history['portal_total_amount']),
                    'outstanding_bills' => $bills,
                    'outstanding_summary' => $outstandingSummary,
                    'outstanding_total' => $outstandingSummary['total_amount'],
                    'outstanding_total_label' => $outstandingSummary['total_amount_label'],
                    'has_outstanding' => $outstandingSummary['has_outstanding'],
                    'payment_stages' => $stages['years'],
                    'payment_stage_summary' => Arr::except($stages, ['years']),
                    'extracted_at' => now()->toISOString(),
                ];

                $this->logFinanceLayoutSignals($history, $billTable, $outstandingSummary);

                $this->logScraping('Student financial payment history extracted successfully', [
                    'payment_count' => count($payments),
                    'bill_count' => count($bills),
                    'outstanding_status' => $outstandingSummary['status'],
                    'history_table_found' => $history['table_found'],
                ]);

                return $financeData;
            }, static fn (array $financeData): bool => ($financeData['summary']['history_available'] ?? false) === true
                || ($financeData['outstanding_summary']['status'] ?? 'unknown') !== 'unknown');
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
    private function extractStudentInfo(Crawler $content): array
    {
        $studentInfo = [];

        foreach ($this->contentTables($content) as $table) {
            foreach ($this->tableRows($table) as $row) {
                $cells = $this->rowCells($row);
                $cellCount = count($cells);

                if ($cellCount < 2 || $cellCount > 4) {
                    continue;
                }

                $valueIndex = $cellCount >= 3 && $this->cellText($cells[1]) === ':' ? 2 : 1;
                $key = $this->studentInfoKey($this->cellText($cells[0]));
                $value = $this->cellText($cells[$valueIndex]);

                if ($key !== null && $value !== '') {
                    $studentInfo[$key] = $value;
                }
            }
        }

        return $studentInfo;
    }

    /**
     * Every table of the content, nested ones included; each is read through
     * its own rows only, so no row is seen twice
     *
     * @return list<Crawler>
     */
    private function contentTables(Crawler $content): array
    {
        return array_values($content->filter('table')->each(static fn (Crawler $table): Crawler => $table));
    }

    private function studentInfoKey(string $label): ?string
    {
        $normalized = $this->normalizeLabel($label);

        return match (true) {
            in_array($normalized, ['nim', 'n i m'], true) => 'nim',
            $normalized === 'nama' || str_contains($normalized, 'nama mahasiswa') => 'nama',
            str_contains($normalized, 'program studi') => 'program_studi',
            str_contains($normalized, 'akademik') => 'academic_year',
            str_contains($normalized, 'angkatan') => 'angkatan',
            str_contains($normalized, 'status') => 'status',
            default => null,
        };
    }

    /**
     * Payments from the table headed "Histori"; pages without one fall back
     * to any table that looks like a payment list (never the bill table).
     *
     * @return array{payments: list<array<string, mixed>>, table_found: bool, portal_total_amount: int|null}
     */
    private function extractPaymentHistory(Crawler $content): array
    {
        $tables = $this->contentTables($content);
        $historyTables = array_values(array_filter(
            $tables,
            fn (Crawler $table): bool => $this->hasHeaderLabel($table, 'histori')
        ));

        if ($historyTables === []) {
            $historyTables = array_values(array_filter(
                $tables,
                fn (Crawler $table): bool => ! $this->isBillTable($table) && $this->looksLikePaymentTable($table)
            ));
        }

        $payments = [];
        $portalTotal = null;

        foreach ($historyTables as $table) {
            $rows = $this->tableRows($table);
            $header = $this->headerRow($rows) ?? $this->firstRowWithCells($rows);

            foreach ($rows as $index => $row) {
                if ($index === $header['index']) {
                    continue;
                }

                $cells = $this->rowCells($row);

                if ($this->hasColspanCell($cells)) {
                    $total = $this->totalRowAmount($cells);

                    if ($total !== null) {
                        $portalTotal ??= $total;

                        continue;
                    }
                }

                $payment = $this->extractPaymentRow($row, $header['labels']);

                if ($payment !== null) {
                    $payments[] = $payment;
                }
            }
        }

        return [
            'payments' => $this->uniquePayments($payments),
            'table_found' => $historyTables !== [],
            'portal_total_amount' => $portalTotal,
        ];
    }

    /**
     * Bills from the table headed "Nama Tagihan" (F34). Bill rows have no
     * payment date, so they never pass the history filters.
     *
     * @return array{
     *     table_found: bool,
     *     bills: list<array{no: string|null, name: string, amount: int|null, amount_label: string|null, description: string|null, installment_stage: int|null, study_year: int|null, installment_amount: int|null, raw: array<string, string>}>,
     *     total_amount: int|null,
     *     header_labels: list<string>,
     *     row_cell_counts: list<int>
     * }
     */
    private function extractOutstandingBills(Crawler $content): array
    {
        $billTable = null;

        foreach ($this->contentTables($content) as $table) {
            if ($this->isBillTable($table)) {
                $billTable = $table;

                break;
            }
        }

        if (! $billTable instanceof Crawler) {
            return ['table_found' => false, 'bills' => [], 'total_amount' => null, 'header_labels' => [], 'row_cell_counts' => []];
        }

        $rows = $this->tableRows($billTable);
        $header = $this->headerRow($rows);
        $headers = $header['labels'] ?? [];
        $bills = [];
        $total = null;
        $rowCellCounts = [];

        foreach ($rows as $index => $row) {
            $cells = $this->rowCells($row);

            if ($index === ($header['index'] ?? null) || ! $this->hasDataCell($cells)) {
                continue;
            }

            $rowCellCounts[] = count($cells);

            if ($this->hasColspanCell($cells)) {
                $total ??= $this->totalRowAmount($cells);

                continue;
            }

            if (count($cells) < 3) {
                continue;
            }

            $bill = $this->extractBillRow($cells, $headers);

            if ($bill !== null) {
                $bills[] = $bill;
            }
        }

        return [
            'table_found' => true,
            'bills' => $bills,
            'total_amount' => $total,
            'header_labels' => $headers,
            'row_cell_counts' => $rowCellCounts,
        ];
    }

    /**
     * @param  list<Crawler>  $cells
     * @param  list<string>  $headers
     * @return array{no: string|null, name: string, amount: int|null, amount_label: string|null, description: string|null, installment_stage: int|null, study_year: int|null, installment_amount: int|null, raw: array<string, string>}|null
     */
    private function extractBillRow(array $cells, array $headers): ?array
    {
        $raw = [];
        $fields = ['no' => null, 'name' => null, 'amount' => null, 'description' => null];

        foreach ($cells as $index => $cell) {
            $value = $this->cellText($cell);
            $header = $headers[$index] ?? 'column_'.($index + 1);
            $key = $this->billKey($header);

            $raw[$header] = $value;

            if ($key !== null && $value !== '' && $fields[$key] === null) {
                $fields[$key] = $value;
            }
        }

        if ($fields['name'] === null && $fields['amount'] === null && $fields['description'] === null) {
            return null;
        }

        $amount = $fields['amount'] !== null && preg_match('/\d/', $fields['amount']) === 1
            ? $this->parseAmount($fields['amount'])
            : null;
        $installmentAmount = $fields['description'] !== null ? $this->installmentAmount($fields['description']) : null;
        $description = $fields['description'] !== null ? $this->withoutInstallmentAmount($fields['description']) : null;
        [$stage, $studyYear] = $this->parseInstallment(trim(($fields['name'] ?? '').' '.($fields['description'] ?? '')));

        return [
            'no' => $fields['no'],
            'name' => $fields['name'] ?? $description ?? 'Tagihan',
            'amount' => $amount,
            'amount_label' => $amount !== null ? $this->formatRupiah($amount) : null,
            'description' => $description !== '' ? $description : null,
            'installment_stage' => $stage,
            'study_year' => $studyYear,
            'installment_amount' => $installmentAmount,
            'raw' => $raw,
        ];
    }

    private function billKey(string $header): ?string
    {
        $normalized = $this->normalizeLabel($header);

        return match (true) {
            $normalized === 'no' || $normalized === 'nomor' => 'no',
            str_contains($normalized, 'nominal') || str_contains($normalized, 'jumlah') || str_contains($normalized, 'besar') || str_contains($normalized, 'amount') => 'amount',
            str_contains($normalized, 'keterangan') || str_contains($normalized, 'catatan') || $normalized === 'ket' => 'description',
            str_contains($normalized, 'tagihan') || str_contains($normalized, 'nama') || str_contains($normalized, 'jenis') || str_contains($normalized, 'uraian') => 'name',
            default => null,
        };
    }

    /**
     * @param  array{table_found: bool, bills: list<array<string, mixed>>, total_amount: int|null}  $billTable
     * @return array{status: 'clear'|'outstanding'|'unknown', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int, rows_sum_matches_total: bool|null}
     */
    private function summarizeOutstandingBills(array $billTable): array
    {
        $bills = $billTable['bills'];
        $portalTotal = $billTable['total_amount'];

        if (! $billTable['table_found'] || ($portalTotal === null && $bills === [])) {
            return [
                'status' => 'unknown',
                'has_outstanding' => null,
                'total_amount' => null,
                'total_amount_label' => null,
                'count' => count($bills),
                'rows_sum_matches_total' => null,
            ];
        }

        $rowsSum = array_sum(array_map(static fn (array $bill): int => (int) ($bill['amount'] ?? 0), $bills));
        $total = $portalTotal ?? $rowsSum;
        $status = $total > 0 || $bills !== [] ? 'outstanding' : 'clear';

        return [
            'status' => $status,
            'has_outstanding' => $status === 'outstanding',
            'total_amount' => $total,
            'total_amount_label' => $this->formatRupiah($total),
            'count' => count($bills),
            'rows_sum_matches_total' => $portalTotal !== null ? $rowsSum === $portalTotal : null,
        ];
    }

    private function isBillTable(Crawler $table): bool
    {
        return $this->hasHeaderLabel($table, 'nama tagihan')
            || in_array('tagihan', array_map(fn (string $label): string => $this->normalizeLabel($label), $this->headerLabels($table)), true);
    }

    private function hasHeaderLabel(Crawler $table, string $needle): bool
    {
        foreach ($this->headerLabels($table) as $label) {
            if (str_contains($this->normalizeLabel($label), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function headerLabels(Crawler $table): array
    {
        return $this->headerRow($this->tableRows($table))['labels'] ?? [];
    }

    /**
     * The table's header row among its own rows: the first one with a `th`
     * cell (thead or not), else one of the first three rows with cells that
     * the raw portal writes as bold `td` cells (`<tr><b><td><b>No</b></td>`).
     * Rows of nested tables never count, so a layout table wrapping the
     * finance tables has no header.
     *
     * @param  list<Crawler>  $rows
     * @return array{index: int, labels: list<string>}|null
     */
    private function headerRow(array $rows): ?array
    {
        $rowsWithCells = [];

        foreach ($rows as $index => $row) {
            $cells = $this->rowCells($row);

            if ($cells === []) {
                continue;
            }

            if (array_filter($cells, static fn (Crawler $cell): bool => strtolower($cell->nodeName()) === 'th') !== []) {
                return ['index' => $index, 'labels' => $this->cellLabels($cells)];
            }

            $rowsWithCells[$index] = $cells;
        }

        foreach (array_slice($rowsWithCells, 0, 3, true) as $index => $cells) {
            if ($this->isBoldLabelRow($cells)) {
                return ['index' => $index, 'labels' => $this->cellLabels($cells)];
            }
        }

        return null;
    }

    /**
     * Fallback header of a history table without a header row: its first
     * row with cells (that row is never read as a payment)
     *
     * @param  list<Crawler>  $rows
     * @return array{index: int|null, labels: list<string>}
     */
    private function firstRowWithCells(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $cells = $this->rowCells($row);

            if ($cells !== []) {
                return ['index' => $index, 'labels' => $this->cellLabels($cells)];
            }
        }

        return ['index' => null, 'labels' => []];
    }

    /**
     * @param  list<Crawler>  $cells
     * @return list<string>
     */
    private function cellLabels(array $cells): array
    {
        return array_map(fn (Crawler $cell): string => $this->cellText($cell), $cells);
    }

    /**
     * At least two labelled cells, every one entirely bold, and no colspan
     * (a total row has a single bold amount next to a colspan cell)
     *
     * @param  list<Crawler>  $cells
     */
    private function isBoldLabelRow(array $cells): bool
    {
        if ($this->hasColspanCell($cells)) {
            return false;
        }

        $labelled = array_values(array_filter($cells, fn (Crawler $cell): bool => $this->cellText($cell) !== ''));

        if (count($labelled) < 2) {
            return false;
        }

        foreach ($labelled as $cell) {
            $node = $cell->getNode(0);

            if (! $node instanceof \DOMNode || $this->normalizePortalText($this->textOutsideBold($node)) !== '') {
                return false;
            }
        }

        return true;
    }

    private function textOutsideBold(\DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->textContent;
            } elseif ($child instanceof \DOMElement && ! in_array(strtolower($child->nodeName), ['b', 'strong'], true)) {
                $text .= $this->textOutsideBold($child);
            }
        }

        return $text;
    }

    /**
     * @param  list<Crawler>  $cells
     */
    private function hasColspanCell(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell->attr('colspan') !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Crawler>  $cells
     */
    private function hasDataCell(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (strtolower($cell->nodeName()) === 'td') {
                return true;
            }
        }

        return false;
    }

    /**
     * Amount of a total row: a colspan cell next to a right-aligned amount,
     * usually in bold (`<td align="right"><h4><b>Rp. 0,-</b></h4></td>`)
     *
     * @param  list<Crawler>  $cells
     */
    private function totalRowAmount(array $cells): ?int
    {
        $rightAligned = array_values(array_filter(
            $cells,
            static fn (Crawler $cell): bool => strtolower(trim((string) $cell->attr('align'))) === 'right'
        ));

        $candidates = [];

        foreach ($rightAligned as $cell) {
            foreach ($cell->filter('b') as $bold) {
                $candidates[] = $this->normalizePortalText((string) $bold->textContent);
            }
        }

        foreach ($rightAligned as $cell) {
            $candidates[] = $this->cellText($cell);
        }

        foreach ($cells as $cell) {
            if ($cell->attr('colspan') === null) {
                $candidates[] = $this->cellText($cell);
            }
        }

        foreach ($candidates as $text) {
            if (preg_match('/\d/', $text) === 1) {
                return $this->parseAmount($text);
            }
        }

        return null;
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
     * @param  list<string>  $headers
     * @return array<string, mixed>|null
     */
    private function extractPaymentRow(Crawler $row, array $headers): ?array
    {
        $cells = $this->rowCells($row);

        if (count($cells) < 2 || ! $this->hasDataCell($cells)) {
            return null;
        }

        $raw = [];
        $payment = [];

        foreach ($cells as $index => $cell) {
            $value = $this->cellText($cell);
            $header = $headers[$index] ?? 'column_'.($index + 1);
            $key = $this->paymentKey($header, $index);

            $raw[$header] = $value;

            if ($value !== '') {
                $payment[$key] = $value;
            }
        }

        if ($this->isHeaderLikeRow(array_values($raw), $headers)) {
            return null;
        }

        if (! $this->hasPaymentSignal($payment)) {
            return null;
        }

        if (isset($payment['amount_label'])) {
            $payment['amount'] = $this->parseAmount((string) $payment['amount_label']);

            // One format everywhere ("Rp 1.250.000"), not the portal's "Rp. 1,250,000,-".
            if ($payment['amount'] > 0) {
                $payment['amount_label'] = $this->formatRupiah($payment['amount']);
            }
        }

        // The date column also carries the payment type ("08 September 2026 Pembayaran UKT").
        if (isset($payment['paid_at_label']) && preg_match('/^(\d{1,2}\s+[A-Za-z]+\s+\d{4})\s*(.*)$/u', (string) $payment['paid_at_label'], $matches)) {
            $payment['paid_at_label'] = $matches[1];

            if (trim($matches[2]) !== '') {
                $payment['category'] = trim($matches[2]);
            }
        }

        $payment['paid_at'] = isset($payment['paid_at_label'])
            ? $this->parseIndonesianDate((string) $payment['paid_at_label'])?->format('Y-m-d')
            : null;

        [$stage, $studyYear] = $this->parseInstallment((string) ($payment['description'] ?? ''));
        $payment['installment_stage'] = $stage;
        $payment['study_year'] = $studyYear;
        $payment['installment_amount'] = isset($payment['description']) ? $this->installmentAmount((string) $payment['description']) : null;

        // "Tahap 1 tahun ke 2 = 1.250.000" repeats the amount column.
        if (isset($payment['description'])) {
            $payment['description'] = $this->withoutInstallmentAmount((string) $payment['description']);
        }

        $payment['raw'] = $raw;

        return $payment;
    }

    /**
     * "Tahap <n> tahun ke <m>" as [stage, study year]; either may be null.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function parseInstallment(string $text): array
    {
        if (preg_match(self::STAGE_AND_YEAR_PATTERN, $text, $matches) === 1) {
            return [$this->positiveInt($matches[1]), $this->positiveInt($matches[2])];
        }

        if (preg_match(self::STAGE_PATTERN, $text, $matches) === 1) {
            return [$this->positiveInt($matches[1]), null];
        }

        return [null, null];
    }

    private function installmentAmount(string $description): ?int
    {
        if (preg_match(self::INSTALLMENT_AMOUNT_PATTERN, $description, $matches) !== 1 || preg_match('/\d/', $matches[1]) !== 1) {
            return null;
        }

        return $this->parseAmount($matches[1]);
    }

    private function withoutInstallmentAmount(string $description): string
    {
        return trim((string) preg_replace(self::INSTALLMENT_AMOUNT_PATTERN, '', $description));
    }

    private function positiveInt(string $digits): ?int
    {
        $value = (int) $digits;

        return $value > 0 ? $value : null;
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

    private function formatRupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
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
                $payment['no'] ?? null,
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
     * Fill `academic_year` ("2025/2026") from the study year, unless the
     * portal gave one in its own column.
     *
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows
     * @param  list<array{study_year: int, academic_year: string|null}>  $stageYears
     * @return list<TRow>
     */
    private function withAcademicYears(array $rows, array $stageYears): array
    {
        $labels = [];
        foreach ($stageYears as $year) {
            $labels[$year['study_year']] = $year['academic_year'];
        }

        return array_map(static function (array $row) use ($labels): array {
            if (! array_key_exists('academic_year', $row)) {
                $studyYear = $row['study_year'] ?? null;
                $academicYear = is_int($studyYear) ? ($labels[$studyYear] ?? null) : null;

                $rawIndex = array_search('raw', array_keys($row), true);
                $row = $rawIndex === false
                    ? [...$row, 'academic_year' => $academicYear]
                    : [...array_slice($row, 0, $rawIndex, true), 'academic_year' => $academicYear, ...array_slice($row, $rawIndex, null, true)];
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array{
     *     total_records: int,
     *     total_paid_amount: int,
     *     currency: string,
     *     latest_payment_label: string|null,
     *     history_available: bool,
     *     portal_total_amount: int|null,
     *     portal_total_amount_label: string|null,
     *     totals_match: bool|null,
     *     latest_payment: array<string, mixed>|null,
     *     by_study_year: list<array{study_year: int, academic_year: string|null, payment_count: int, total_amount: int, total_amount_label: string, stages_paid: list<int>}>
     * }
     */
    private function summarizePayments(array $payments, bool $historyAvailable, ?int $portalTotal): array
    {
        $totalPaid = array_sum(array_map(
            static fn (array $payment): int => (int) ($payment['amount'] ?? 0),
            $payments
        ));

        return [
            'total_records' => count($payments),
            'total_paid_amount' => $totalPaid,
            'currency' => 'IDR',
            'latest_payment_label' => $payments[0]['paid_at_label'] ?? null,
            'history_available' => $historyAvailable,
            'portal_total_amount' => $portalTotal,
            'portal_total_amount_label' => $portalTotal !== null ? $this->formatRupiah($portalTotal) : null,
            'totals_match' => $portalTotal !== null ? $portalTotal === $totalPaid : null,
            'latest_payment' => $this->latestPayment($payments),
            'by_study_year' => $this->paymentsByStudyYear($payments),
        ];
    }

    /**
     * The payment with the latest date, whatever its row position.
     *
     * @param  list<array<string, mixed>>  $payments
     * @return array{no: string|null, paid_at: string, paid_at_label: string|null, category: string|null, description: string|null, amount: int|null, amount_label: string|null, installment_stage: int|null, study_year: int|null, academic_year: string|null}|null
     */
    private function latestPayment(array $payments): ?array
    {
        $latest = null;

        foreach ($payments as $payment) {
            $paidAt = $payment['paid_at'] ?? null;

            if (is_string($paidAt) && ($latest === null || $paidAt > $latest['paid_at'])) {
                $latest = $payment;
            }
        }

        if ($latest === null) {
            return null;
        }

        return [
            'no' => $latest['no'] ?? null,
            'paid_at' => $latest['paid_at'],
            'paid_at_label' => $latest['paid_at_label'] ?? null,
            'category' => $latest['category'] ?? null,
            'description' => $latest['description'] ?? null,
            'amount' => $latest['amount'] ?? null,
            'amount_label' => $latest['amount_label'] ?? null,
            'installment_stage' => $latest['installment_stage'] ?? null,
            'study_year' => $latest['study_year'] ?? null,
            'academic_year' => $latest['academic_year'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return list<array{study_year: int, academic_year: string|null, payment_count: int, total_amount: int, total_amount_label: string, stages_paid: list<int>}>
     */
    private function paymentsByStudyYear(array $payments): array
    {
        $groups = [];

        foreach ($payments as $payment) {
            $studyYear = $payment['study_year'] ?? null;

            if (! is_int($studyYear)) {
                continue;
            }

            $group = $groups[$studyYear] ?? [
                'study_year' => $studyYear,
                'academic_year' => null,
                'payment_count' => 0,
                'total_amount' => 0,
                'stages_paid' => [],
            ];

            $group['academic_year'] ??= is_string($payment['academic_year'] ?? null) ? $payment['academic_year'] : null;
            $group['payment_count']++;
            $group['total_amount'] += (int) ($payment['amount'] ?? 0);

            if (is_int($payment['installment_stage'] ?? null)) {
                $group['stages_paid'][] = $payment['installment_stage'];
            }

            $groups[$studyYear] = $group;
        }

        ksort($groups);

        return array_values(array_map(function (array $group): array {
            $stages = array_values(array_unique($group['stages_paid']));
            sort($stages);

            return [
                'study_year' => $group['study_year'],
                'academic_year' => $group['academic_year'],
                'payment_count' => $group['payment_count'],
                'total_amount' => $group['total_amount'],
                'total_amount_label' => $this->formatRupiah($group['total_amount']),
                'stages_paid' => $stages,
            ];
        }, $groups));
    }

    /**
     * Anonymous layout hints (labels and counts, never values) for states not
     * yet seen on the live portal: a non-zero bill total, or history rows the
     * stage pattern cannot read (§8).
     *
     * @param  array{payments: list<array<string, mixed>>}  $history
     * @param  array{header_labels: list<string>, row_cell_counts: list<int>}  $billTable
     * @param  array{status: string, total_amount: int|null, count: int, rows_sum_matches_total: bool|null}  $outstandingSummary
     */
    private function logFinanceLayoutSignals(array $history, array $billTable, array $outstandingSummary): void
    {
        $stagePatternMisses = count(array_filter(
            $history['payments'],
            static fn (array $payment): bool => isset($payment['description']) && $payment['installment_stage'] === null
        ));

        if ($outstandingSummary['status'] !== 'outstanding' && $stagePatternMisses === 0) {
            return;
        }

        $this->logScraping('Student finance page layout signals', [
            'bill_header_labels' => $billTable['header_labels'],
            'bill_row_cell_counts' => $billTable['row_cell_counts'],
            'bill_count' => $outstandingSummary['count'],
            'bill_rows_sum_matches_total' => $outstandingSummary['rows_sum_matches_total'],
            'history_stage_pattern_misses' => $stagePatternMisses,
        ]);
    }

    private function cleanFinanceText(string $value): string
    {
        return $this->normalizePortalText($value);
    }

    private function normalizeLabel(string $value): string
    {
        $value = strtolower($this->cleanFinanceText($value));
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
