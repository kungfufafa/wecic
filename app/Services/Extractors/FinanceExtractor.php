<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\CampusScrapingException;
use App\Services\CampusScrapingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class FinanceExtractor extends CampusScrapingService
{
    /**
     * Extract student financial data
     */
    public function extractFinanceData(string $sessionToken, array $options = []): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting financial data', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'student_finance_' . md5($sessionToken . serialize($options));
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached financial data');
                return json_decode($cachedData, true);
            }

            // Try different possible finance page URLs
            $financeUrls = $this->getFinancePageUrls($options);
            $financeData = null;

            foreach ($financeUrls as $url) {
                try {
                    $html = $this->get($url);
                    $crawler = $this->createCrawler($html);

                    // Check if this page contains financial data
                    if ($this->containsFinanceData($crawler)) {
                        $financeData = $this->extractFinanceFromPage($crawler, $url);
                        break;
                    }
                } catch (\Exception $e) {
                    $this->logScraping('Failed to access finance page', ['url' => $url, 'error' => $e->getMessage()]);
                    continue;
                }
            }

            if (!$financeData) {
                throw new CampusScrapingException::elementNotFound('financial data', 'any finance page');
            }

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($financeData));

            $this->logScraping('Financial data extracted successfully', [
                'balance' => $financeData['summary']['current_balance'] ?? 'N/A',
                'transactions_count' => count($financeData['transactions'] ?? []),
            ]);

            return $financeData;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting financial data', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'financial', $e->getMessage());
        }
    }

    /**
     * Get possible finance page URLs
     */
    private function getFinancePageUrls(array $options): array
    {
        $baseUrl = config('campus.base_url');
        $semester = $options['semester'] ?? null;
        $academicYear = $options['academic_year'] ?? null;

        $urls = [
            $baseUrl . '/portal/mahasiswa/?mod=student&act=finance',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=keuangan',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=payment',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=pembayaran',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=billing',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=invoice',
        ];

        // Add semester-specific URLs if provided
        if ($semester) {
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=finance&semester=' . $semester;
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=keuangan&semester=' . $semester;
        }

        if ($academicYear) {
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=finance&year=' . $academicYear;
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=keuangan&year=' . $academicYear;
        }

        return $urls;
    }

    /**
     * Check if page contains financial data
     */
    private function containsFinanceData(Crawler $crawler): bool
    {
        $financeIndicators = [
            'keuangan',
            'finance',
            'pembayaran',
            'payment',
            'tagihan',
            'billing',
            'invoice',
            'saldo',
            'balance',
            'biaya',
            'cost',
            'uang',
            'rupiah',
            'rp',
        ];

        $pageText = strtolower($crawler->text());

        foreach ($financeIndicators as $indicator) {
            if (Str::contains($pageText, $indicator)) {
                return true;
            }
        }

        // Check for financial table structures
        $hasFinanceTable = $crawler->filter('table')->reduce(function (Crawler $table) {
            $tableText = strtolower($table->text());
            return Str::contains($tableText, ['tagihan', 'pembayaran', 'saldo', 'biaya']);
        })->count() > 0;

        return $hasFinanceTable;
    }

    /**
     * Extract financial data from the page
     */
    private function extractFinanceFromPage(Crawler $crawler, string $url): array
    {
        $financeData = [
            'summary' => $this->extractFinancialSummary($crawler),
            'transactions' => $this->extractTransactions($crawler),
            'bills' => $this->extractBills($crawler),
            'payment_history' => $this->extractPaymentHistory($crawler),
            'outstanding_balance' => $this->extractOutstandingBalance($crawler),
            'semester_info' => $this->extractSemesterFinancialInfo($crawler),
            'extracted_at' => now()->toISOString(),
            'source_url' => $url,
        ];

        return $financeData;
    }

    /**
     * Extract financial summary information
     */
    private function extractFinancialSummary(Crawler $crawler): array
    {
        $summary = [];

        // Look for balance information
        $balanceSelectors = [
            '.balance',
            '.saldo',
            '.current-balance',
            '.total-balance',
        ];

        $balance = $this->extractText($crawler, $balanceSelectors);
        if ($balance) {
            $summary['current_balance'] = $balance;
        }

        // Look for outstanding amount
        $outstandingSelectors = [
            '.outstanding',
            '.tagihan',
            '.total-due',
            '.amount-due',
        ];

        $outstanding = $this->extractText($crawler, $outstandingSelectors);
        if ($outstanding) {
            $summary['outstanding_amount'] = $outstanding;
        }

        // Look for total paid
        $paidSelectors = [
            '.total-paid',
            '.total-pembayaran',
            '.paid-amount',
        ];

        $paid = $this->extractText($crawler, $paidSelectors);
        if ($paid) {
            $summary['total_paid'] = $paid;
        }

        // Extract from summary cards
        $summarySelectors = [
            '.finance-summary',
            '.financial-cards',
            '.summary-cards',
            '.info-cards',
        ];

        foreach ($summarySelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $summary = array_merge($summary, $this->extractSummaryCards($element));
            }
        }

        return $summary;
    }

    /**
     * Extract summary cards data
     */
    private function extractSummaryCards(Crawler $element): array
    {
        $summary = [];

        $element->filter('.card, .summary-item, .info-item, .stat-item')->each(function (Crawler $card) use (&$summary) {
            $label = $this->extractText($card, ['.label', '.title', '.card-title', 'h4', 'h5']);
            $value = $this->extractText($card, ['.value', '.content', '.card-body', '.stat-value']);

            if ($label && $value) {
                $key = strtolower(str_replace([' ', '-', ':'], '_', $label));
                $summary[$key] = $value;
            }
        });

        return $summary;
    }

    /**
     * Extract transaction data
     */
    private function extractTransactions(Crawler $crawler): array
    {
        $transactions = [];

        // Find transaction tables
        $transactionTables = $crawler->filter('table')->reduce(function (Crawler $table) {
            $tableText = strtolower($table->text());
            return Str::contains($tableText, ['transaksi', 'transaction', 'pembayaran', 'payment']);
        });

        $transactionTables->each(function (Crawler $table) use (&$transactions) {
            $tableData = $this->extractTableData($table);
            $transactionsFromTable = $this->processTransactionTableData($tableData);
            $transactions = array_merge($transactions, $transactionsFromTable);
        });

        // Also look for transaction items in other formats
        $crawler->filter('.transaction-item, .payment-item, .finance-item')->each(function (Crawler $item) use (&$transactions) {
            $transaction = $this->extractTransactionFromItem($item);
            if ($transaction) {
                $transactions[] = $transaction;
            }
        });

        return $transactions;
    }

    /**
     * Process transaction table data
     */
    private function processTransactionTableData(array $tableData): array
    {
        $transactions = [];
        $headers = array_keys($tableData[0] ?? []);
        $columnMap = $this->mapTransactionColumns($headers);

        foreach ($tableData as $rowIndex => $row) {
            if ($rowIndex === 0 || $this->isTransactionHeaderRow($row)) {
                continue; // Skip header rows
            }

            $transaction = $this->buildTransactionFromRow($row, $columnMap);
            if ($transaction) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    /**
     * Map table columns to transaction data fields
     */
    private function mapTransactionColumns(array $headers): array
    {
        $map = [];
        $headers = array_map('strtolower', $headers);

        foreach ($headers as $index => $header) {
            if (Str::contains($header, ['tanggal', 'date', 'tgl'])) {
                $map['date'] = $index;
            } elseif (Str::contains($header, ['keterangan', 'description', 'deskripsi'])) {
                $map['description'] = $index;
            } elseif (Str::contains($header, ['jumlah', 'amount', 'total', 'nominal'])) {
                $map['amount'] = $index;
            } elseif (Str::contains($header, ['status', 'keterangan status'])) {
                $map['status'] = $index;
            } elseif (Str::contains($header, ['jenis', 'type', 'kategori'])) {
                $map['type'] = $index;
            } elseif (Str::contains($header, ['semester', 'tahun'])) {
                $map['semester'] = $index;
            }
        }

        return $map;
    }

    /**
     * Check if row is a header row
     */
    private function isTransactionHeaderRow(array $row): bool
    {
        $headerKeywords = ['tanggal', 'keterangan', 'jumlah', 'status', 'jenis', 'date', 'description', 'amount'];
        $firstRowText = strtolower(implode(' ', $row));

        foreach ($headerKeywords as $keyword) {
            if (Str::contains($firstRowText, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build transaction data from table row
     */
    private function buildTransactionFromRow(array $row, array $columnMap): ?array
    {
        $transaction = [];

        foreach ($columnMap as $field => $index) {
            $value = $row[$index] ?? null;
            if ($value !== null) {
                $transaction[$field] = $this->cleanText($value);
            }
        }

        // Clean and format amount
        if (isset($transaction['amount'])) {
            $transaction['amount'] = $this->cleanAmount($transaction['amount']);
            $transaction['amount_numeric'] = $this->extractNumber($transaction['amount']);
        }

        return empty($transaction) ? null : $transaction;
    }

    /**
     * Extract transaction from item element
     */
    private function extractTransactionFromItem(Crawler $item): ?array
    {
        $date = $this->extractText($item, ['.date', '.transaction-date', '.time']);
        $description = $this->extractText($item, ['.description', '.transaction-desc', '.title']);
        $amount = $this->extractText($item, ['.amount', '.value', '.total']);
        $status = $this->extractText($item, ['.status', '.transaction-status']);

        if ($description && $amount) {
            return array_filter([
                'date' => $date,
                'description' => $description,
                'amount' => $this->cleanAmount($amount),
                'amount_numeric' => $this->extractNumber($amount),
                'status' => $status,
            ]);
        }

        return null;
    }

    /**
     * Extract bills/invoices
     */
    private function extractBills(Crawler $crawler): array
    {
        $bills = [];

        $crawler->filter('.bill-item, .invoice-item, .tagihan-item')->each(function (Crawler $item) use (&$bills) {
            $bill = $this->extractBillFromItem($item);
            if ($bill) {
                $bills[] = $bill;
            }
        });

        // Also check for bill tables
        $billTables = $crawler->filter('table')->reduce(function (Crawler $table) {
            $tableText = strtolower($table->text());
            return Str::contains($tableText, ['tagihan', 'bill', 'invoice']);
        });

        $billTables->each(function (Crawler $table) use (&$bills) {
            $tableData = $this->extractTableData($table);
            $billsFromTable = $this->processBillTableData($tableData);
            $bills = array_merge($bills, $billsFromTable);
        });

        return $bills;
    }

    /**
     * Extract bill from item element
     */
    private function extractBillFromItem(Crawler $item): ?array
    {
        $title = $this->extractText($item, ['.title', '.bill-title', '.invoice-title']);
        $amount = $this->extractText($item, ['.amount', '.total-amount', '.due-amount']);
        $dueDate = $this->extractText($item, ['.due-date', '.bill-due', '.tanggal-jatuh-tempo']);
        $status = $this->extractText($item, ['.status', '.bill-status']);

        if ($title && $amount) {
            return array_filter([
                'title' => $title,
                'amount' => $this->cleanAmount($amount),
                'amount_numeric' => $this->extractNumber($amount),
                'due_date' => $dueDate,
                'status' => $status,
            ]);
        }

        return null;
    }

    /**
     * Process bill table data
     */
    private function processBillTableData(array $tableData): array
    {
        $bills = [];
        $headers = array_keys($tableData[0] ?? []);
        $columnMap = $this->mapBillColumns($headers);

        foreach ($tableData as $rowIndex => $row) {
            if ($rowIndex === 0 || $this->isBillHeaderRow($row)) {
                continue;
            }

            $bill = $this->buildBillFromRow($row, $columnMap);
            if ($bill) {
                $bills[] = $bill;
            }
        }

        return $bills;
    }

    /**
     * Map bill table columns
     */
    private function mapBillColumns(array $headers): array
    {
        $map = [];
        $headers = array_map('strtolower', $headers);

        foreach ($headers as $index => $header) {
            if (Str::contains($header, ['tagihan', 'bill', 'uraian'])) {
                $map['title'] = $index;
            } elseif (Str::contains($header, ['jumlah', 'amount', 'total'])) {
                $map['amount'] = $index;
            } elseif (Str::contains($header, ['jatuh tempo', 'due date'])) {
                $map['due_date'] = $index;
            } elseif (Str::contains($header, ['status'])) {
                $map['status'] = $index;
            }
        }

        return $map;
    }

    /**
     * Check if row is bill header
     */
    private function isBillHeaderRow(array $row): bool
    {
        $headerKeywords = ['tagihan', 'jumlah', 'status', 'jatuh tempo', 'bill'];
        $firstRowText = strtolower(implode(' ', $row));

        foreach ($headerKeywords as $keyword) {
            if (Str::contains($firstRowText, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build bill from row
     */
    private function buildBillFromRow(array $row, array $columnMap): ?array
    {
        $bill = [];

        foreach ($columnMap as $field => $index) {
            $value = $row[$index] ?? null;
            if ($value !== null) {
                $bill[$field] = $this->cleanText($value);
            }
        }

        if (isset($bill['amount'])) {
            $bill['amount_numeric'] = $this->extractNumber($bill['amount']);
        }

        return empty($bill) ? null : $bill;
    }

    /**
     * Extract payment history
     */
    private function extractPaymentHistory(Crawler $crawler): array
    {
        $payments = [];

        $crawler->filter('.payment-history-item, .payment-record')->each(function (Crawler $item) use (&$payments) {
            $payment = $this->extractPaymentFromItem($item);
            if ($payment) {
                $payments[] = $payment;
            }
        });

        return $payments;
    }

    /**
     * Extract payment from item
     */
    private function extractPaymentFromItem(Crawler $item): ?array
    {
        $date = $this->extractText($item, ['.date', '.payment-date']);
        $description = $this->extractText($item, ['.description', '.payment-desc']);
        $amount = $this->extractText($item, ['.amount', '.payment-amount']);
        $method = $this->extractText($item, ['.method', '.payment-method']);

        if ($date && $amount) {
            return array_filter([
                'date' => $date,
                'description' => $description,
                'amount' => $this->cleanAmount($amount),
                'amount_numeric' => $this->extractNumber($amount),
                'method' => $method,
            ]);
        }

        return null;
    }

    /**
     * Extract outstanding balance
     */
    private function extractOutstandingBalance(Crawler $crawler): array
    {
        $outstanding = [];

        $outstandingSelectors = [
            '.outstanding-balance',
            '.total-tagihan',
            '.remaining-balance',
        ];

        foreach ($outstandingSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $amount = $this->cleanText($element->text());
                $outstanding['amount'] = $amount;
                $outstanding['amount_numeric'] = $this->extractNumber($amount);
                break;
            }
        }

        return $outstanding;
    }

    /**
     * Extract semester financial info
     */
    private function extractSemesterFinancialInfo(Crawler $crawler): array
    {
        $info = [];

        $semesterSelectors = [
            '.semester-finance',
            '.academic-finance',
            '.finance-semester',
        ];

        foreach ($semesterSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $info = array_merge($info, $this->extractSummaryCards($element));
            }
        }

        return $info;
    }

    /**
     * Clean amount text
     */
    private function cleanAmount(string $amount): string
    {
        return $this->cleanText($amount);
    }

    /**
     * Get financial summary without detailed transactions
     */
    public function getFinanceSummary(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);

            $financeUrls = $this->getFinancePageUrls();

            foreach ($financeUrls as $url) {
                try {
                    $html = $this->get($url);
                    $crawler = $this->createCrawler($html);

                    if ($this->containsFinanceData($crawler)) {
                        return [
                            'summary' => $this->extractFinancialSummary($crawler),
                            'outstanding_balance' => $this->extractOutstandingBalance($crawler),
                            'semester_info' => $this->extractSemesterFinancialInfo($crawler),
                            'bills_count' => count($this->extractBills($crawler)),
                            'recent_transactions_count' => count(array_slice($this->extractTransactions($crawler), 0, 5)),
                            'has_finance_data' => true,
                            'source_url' => $url,
                            'extracted_at' => now()->toISOString(),
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            return [
                'has_finance_data' => false,
                'message' => 'No financial data found',
                'extracted_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            $this->logScrapingError('Getting finance summary', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'finance summary', $e->getMessage());
        }
    }
}