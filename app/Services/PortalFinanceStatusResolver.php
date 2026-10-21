<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Finance status for decisions (F34 bills, F35 UKT stages, F36 deadlines).
 *
 * Built from the `payments` snapshot plus the portal's running payment notice
 * (K5 marquee on the dashboard or the announcement page). It never invents a
 * value: an unreadable bill table stays `unknown` with null amounts, never
 * "Rp 0", and a payment deadline only counts while its date is ahead and the
 * stage it names is not recorded as paid.
 */
class PortalFinanceStatusResolver
{
    private const TIMEZONE = 'Asia/Jakarta';

    private const PAYMENT_NOTICE_PATTERN = '/pembayaran|bayar|tagihan|\bukt\b|keuangan|lunas/iu';

    public function __construct(private readonly PaymentStageCalculator $stageCalculator) {}

    /**
     * @param  array<string, mixed>  $payments  `payments` dataset (FinancialHistoryExtractor::extractPaymentHistoryData)
     * @param  list<array<string, mixed>|null>  $notices  K5 notices in order of preference (portal_context.notice, announcements.notice)
     * @return array{
     *     outstanding_summary: array{status: 'clear'|'outstanding'|'unknown', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int, rows_sum_matches_total: bool|null},
     *     outstanding_bills: list<array<string, mixed>>,
     *     has_outstanding: bool|null,
     *     outstanding_total: int|null,
     *     outstanding_total_label: string|null,
     *     current_study_year: int|null,
     *     current_academic_year: string|null,
     *     current_year: array<string, mixed>|null,
     *     current_year_stages_paid: list<int>,
     *     stage1_paid_for_current_year: bool|'unknown',
     *     next_stage: array<string, mixed>|null,
     *     active_deadline: array{stage: int|null, date: string, days_left: int, is_future: true, text: string, source: 'portal_notice'}|null,
     *     latest_payment: array<string, mixed>|null,
     *     total_paid_amount: int|null,
     *     portal_total_amount: int|null,
     *     portal_total_amount_label: string|null,
     *     history_available: bool,
     *     extracted_at: string|null
     * }
     */
    public function summarize(array $payments, array $notices = [], ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE)->startOfDay();
        $bills = $this->rows($payments['outstanding_bills'] ?? []);
        $history = $this->rows($payments['payment_history'] ?? []);
        $summary = is_array($payments['summary'] ?? null) ? $payments['summary'] : [];
        $outstanding = $this->outstandingSummary($payments['outstanding_summary'] ?? null, $bills);
        $deadline = $this->paymentDeadline($notices, $today);
        $academicYear = $payments['student_info']['academic_year'] ?? null;

        $stages = $this->stageCalculator->calculate(
            $history,
            $bills,
            is_string($academicYear) ? $academicYear : null,
            $deadline !== null && $deadline['is_future'] && $deadline['stage'] !== null ? [$deadline['stage'] => $deadline['date']] : [],
            $today
        );

        $currentYear = null;

        foreach ($stages['years'] as $year) {
            if ($year['is_current']) {
                $currentYear = $year;

                break;
            }
        }

        return [
            'outstanding_summary' => $outstanding,
            'outstanding_bills' => $bills,
            'has_outstanding' => $outstanding['has_outstanding'],
            'outstanding_total' => $outstanding['total_amount'],
            'outstanding_total_label' => $outstanding['total_amount_label'],
            'current_study_year' => $stages['current_study_year'],
            'current_academic_year' => $stages['current_academic_year'],
            'current_year' => $currentYear,
            'current_year_stages_paid' => $stages['current_year_stages_paid'],
            'stage1_paid_for_current_year' => $stages['stage1_paid_for_current_year'],
            'next_stage' => $stages['next_stage'],
            'active_deadline' => $this->activeDeadline($deadline, $stages['current_year_stages_paid']),
            'latest_payment' => is_array($summary['latest_payment'] ?? null) ? $summary['latest_payment'] : null,
            'total_paid_amount' => is_int($summary['total_paid_amount'] ?? null) ? $summary['total_paid_amount'] : null,
            'portal_total_amount' => is_int($summary['portal_total_amount'] ?? null) ? $summary['portal_total_amount'] : null,
            'portal_total_amount_label' => is_string($summary['portal_total_amount_label'] ?? null) ? $summary['portal_total_amount_label'] : null,
            'history_available' => ($summary['history_available'] ?? false) === true,
            'extracted_at' => is_string($payments['extracted_at'] ?? null) ? $payments['extracted_at'] : null,
        ];
    }

    /**
     * The payment deadline named by the first portal notice that talks about
     * payments and carries a date that is still ahead: "Kewajiban Pembayaran
     * Kuliah Tahap ke 2 … paling lambat pada Hari Senin, 2 November 2026".
     * The last date is the "paling lambat" one. A notice whose deadline has
     * passed (an old marquee left in a 6-hour snapshot) never hides a newer
     * one from another source; it is returned only when no notice is ahead.
     *
     * @param  list<array<string, mixed>|null>  $notices
     * @return array{stage: int|null, date: string, days_left: int, is_future: bool, text: string, source: 'portal_notice'}|null
     */
    public function paymentDeadline(array $notices, ?CarbonImmutable $today = null): ?array
    {
        $today = ($today ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE)->startOfDay();
        $firstPast = null;

        foreach ($notices as $notice) {
            $deadline = $this->noticeDeadline($notice, $today);

            if ($deadline === null) {
                continue;
            }

            if ($deadline['is_future']) {
                return $deadline;
            }

            $firstPast ??= $deadline;
        }

        return $firstPast;
    }

    /**
     * @return array{stage: int|null, date: string, days_left: int, is_future: bool, text: string, source: 'portal_notice'}|null
     */
    private function noticeDeadline(mixed $notice, CarbonImmutable $today): ?array
    {
        if (! is_array($notice) || ! is_string($notice['text'] ?? null) || trim($notice['text']) === '') {
            return null;
        }

        $text = $notice['text'];

        if (($notice['kind'] ?? null) !== 'payment' && ! preg_match(self::PAYMENT_NOTICE_PATTERN, $text)) {
            return null;
        }

        $dates = array_values(array_filter(array_map(
            fn (mixed $date): ?CarbonImmutable => $this->date($date),
            is_array($notice['dates'] ?? null) ? $notice['dates'] : []
        )));

        if ($dates === []) {
            return null;
        }

        usort($dates, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left <=> $right);
        $date = end($dates);
        $daysLeft = (int) round($today->diffInDays($date));

        return [
            'stage' => $this->noticeStage($text),
            'date' => $date->toDateString(),
            'days_left' => $daysLeft,
            'is_future' => $daysLeft >= 0,
            'text' => $text,
            'source' => 'portal_notice',
        ];
    }

    /**
     * The UKT stage a payment notice names ("Tahap ke 4"), if any.
     */
    public function noticeStage(string $text): ?int
    {
        return preg_match('/tahap\s*(?:ke[\s-]*)?(\d{1,2})/iu', $text, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * Whether the UKT stage a payment notice names is already recorded as
     * paid for the current study year: null when the notice names no stage,
     * is not about payments, or the payments snapshot is not available (K5).
     *
     * @param  array<string, mixed>|null  $notice
     * @param  array<string, mixed>|null  $payments  `payments` dataset
     */
    public function noticeStagePaid(?array $notice, ?array $payments, ?CarbonImmutable $today = null): ?bool
    {
        if ($notice === null || $payments === null || ! is_string($notice['text'] ?? null)) {
            return null;
        }

        if (($notice['kind'] ?? null) !== 'payment' && ! preg_match(self::PAYMENT_NOTICE_PATTERN, $notice['text'])) {
            return null;
        }

        $stage = $this->noticeStage($notice['text']);

        if ($stage === null) {
            return null;
        }

        return in_array($stage, $this->summarize($payments, [], $today)['current_year_stages_paid'], true);
    }

    /**
     * @param  array{stage: int|null, date: string, days_left: int, is_future: bool, text: string, source: 'portal_notice'}|null  $deadline
     * @param  list<int>  $paidStages
     * @return array{stage: int|null, date: string, days_left: int, is_future: true, text: string, source: 'portal_notice'}|null
     */
    private function activeDeadline(?array $deadline, array $paidStages): ?array
    {
        if ($deadline === null || ! $deadline['is_future']) {
            return null;
        }

        if ($deadline['stage'] !== null && in_array($deadline['stage'], $paidStages, true)) {
            return null;
        }

        return [...$deadline, 'is_future' => true];
    }

    /**
     * @param  list<array<string, mixed>>  $bills
     * @return array{status: 'clear'|'outstanding'|'unknown', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int, rows_sum_matches_total: bool|null}
     */
    private function outstandingSummary(mixed $summary, array $bills): array
    {
        $summary = is_array($summary) ? $summary : [];
        $status = in_array($summary['status'] ?? null, ['clear', 'outstanding', 'unknown'], true) ? $summary['status'] : 'unknown';

        if ($status === 'unknown') {
            return [
                'status' => 'unknown',
                'has_outstanding' => null,
                'total_amount' => null,
                'total_amount_label' => null,
                'count' => count($bills),
                'rows_sum_matches_total' => null,
            ];
        }

        return [
            'status' => $status,
            'has_outstanding' => $status === 'outstanding',
            'total_amount' => is_int($summary['total_amount'] ?? null) ? $summary['total_amount'] : null,
            'total_amount_label' => is_string($summary['total_amount_label'] ?? null) ? $summary['total_amount_label'] : null,
            'count' => is_int($summary['count'] ?? null) ? $summary['count'] : count($bills),
            'rows_sum_matches_total' => is_bool($summary['rows_sum_matches_total'] ?? null) ? $summary['rows_sum_matches_total'] : null,
        ];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, self::TIMEZONE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
