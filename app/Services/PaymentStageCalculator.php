<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * UKT stage status per study year (F35) and the KRS-relevant facts (F36)
 * that can be read from act=keuangan.
 *
 * Only the portal's own records decide a status: a stage is "paid" when the
 * payment history lists it and "unpaid" when the bill table lists it. The
 * history can miss stages (stage 1 of the first year is often paid at
 * admission), so every other stage is "unknown", or "upcoming" when a known
 * deadline is still ahead.
 */
class PaymentStageCalculator
{
    public const STAGES_PER_STUDY_YEAR = 4;

    private const MAX_STUDY_YEAR = 10;

    private const MAX_STAGE = 12;

    private const TIMEZONE = 'Asia/Jakarta';

    /**
     * Stage 1 falls around September and later stages follow about every
     * three months; used to tell which academic year a payment belongs to.
     */
    private const FIRST_STAGE_MONTH = 9;

    private const MONTHS_BETWEEN_STAGES = 3;

    /**
     * @param  list<array<string, mixed>>  $payments  payment_history rows: installment_stage, study_year, paid_at (Y-m-d), paid_at_label, amount, installment_amount
     * @param  list<array<string, mixed>>  $bills  outstanding_bills rows: installment_stage, study_year, amount; a bill without a study year belongs to the current one
     * @param  string|null  $currentAcademicYear  "Th Akademik" of the finance page, e.g. "2026/2027"
     * @param  array<int, CarbonImmutable|string>  $deadlines  stage number => deadline in the current study year
     * @return array{
     *     current_study_year: int|null,
     *     current_study_year_source: 'academic_year'|'records'|null,
     *     current_academic_year: string|null,
     *     stages_per_year: int,
     *     years: list<array{
     *         study_year: int,
     *         academic_year: string|null,
     *         is_current: bool,
     *         paid_count: int,
     *         unpaid_count: int,
     *         upcoming_count: int,
     *         unknown_count: int,
     *         stages: list<array{
     *             stage: int,
     *             status: 'paid'|'unpaid'|'upcoming'|'unknown',
     *             reason: 'in_history'|'in_bills'|'future_year'|'not_recorded'|'deadline_ahead'|'deadline_passed_without_bill'|'no_deadline',
     *             paid_at: string|null,
     *             paid_at_label: string|null,
     *             paid_amount: int|null,
     *             installment_amount: int|null,
     *             is_partial: bool,
     *             bill_amount: int|null,
     *             deadline: string|null
     *         }>
     *     }>,
     *     current_year_stages_paid: list<int>,
     *     stage1_paid_for_current_year: bool|'unknown',
     *     next_stage: array{study_year: int, academic_year: string|null, stage: int, status: 'unpaid'|'upcoming'|'unknown', reason: string, deadline: string|null}|null
     * }
     */
    public function calculate(
        array $payments,
        array $bills,
        ?string $currentAcademicYear,
        array $deadlines = [],
        ?CarbonImmutable $today = null
    ): array {
        $today = ($today ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE)->startOfDay();
        $academicYearStart = $this->academicYearStart($currentAcademicYear);
        $entryYear = $this->entryAcademicYearStart($payments);
        $paidStages = $this->paidStages($payments);
        $maxRecordedYear = $this->maxRecordedStudyYear($payments, $bills);

        [$currentStudyYear, $source] = $this->currentStudyYear($academicYearStart, $entryYear, $maxRecordedYear);

        $billedStages = $this->billedStages($bills, $currentStudyYear);
        $stagesPerYear = max(self::STAGES_PER_STUDY_YEAR, $this->maxStage($paidStages), $this->maxStage($billedStages));
        $deadlines = $currentStudyYear !== null ? $this->normalizeDeadlines($deadlines) : [];

        $currentAcademicYearLabel = match (true) {
            $academicYearStart !== null => $this->academicYearLabel($academicYearStart),
            $entryYear !== null && $currentStudyYear !== null => $this->academicYearLabel($entryYear + $currentStudyYear - 1),
            default => null,
        };

        $years = [];
        $lastStudyYear = max($currentStudyYear ?? 0, $maxRecordedYear ?? 0);

        for ($studyYear = 1; $studyYear <= $lastStudyYear; $studyYear++) {
            $academicYear = match (true) {
                $entryYear !== null => $this->academicYearLabel($entryYear + $studyYear - 1),
                $studyYear === $currentStudyYear => $currentAcademicYearLabel,
                default => null,
            };

            $years[] = $this->buildYear(
                $studyYear,
                $academicYear,
                $currentStudyYear,
                $stagesPerYear,
                $paidStages[$studyYear] ?? [],
                $billedStages[$studyYear] ?? [],
                $studyYear === $currentStudyYear ? $deadlines : [],
                $today
            );
        }

        $currentYear = null;
        foreach ($years as $year) {
            if ($year['is_current']) {
                $currentYear = $year;
            }
        }

        return [
            'current_study_year' => $currentStudyYear,
            'current_study_year_source' => $source,
            'current_academic_year' => $currentAcademicYearLabel,
            'stages_per_year' => $stagesPerYear,
            'years' => $years,
            'current_year_stages_paid' => $currentYear === null ? [] : array_values(array_map(
                static fn (array $stage): int => $stage['stage'],
                array_filter($currentYear['stages'], static fn (array $stage): bool => $stage['status'] === 'paid')
            )),
            'stage1_paid_for_current_year' => $this->stageOnePaid($currentYear),
            'next_stage' => $this->nextStage($currentYear),
        ];
    }

    /**
     * @param  array<int, array{paid_at: string|null, paid_at_label: string|null, paid_amount: int|null, installment_amount: int|null}>  $paidStages
     * @param  array<int, int|null>  $billedStages
     * @param  array<int, CarbonImmutable>  $deadlines
     * @return array{
     *     study_year: int,
     *     academic_year: string|null,
     *     is_current: bool,
     *     paid_count: int,
     *     unpaid_count: int,
     *     upcoming_count: int,
     *     unknown_count: int,
     *     stages: list<array{stage: int, status: 'paid'|'unpaid'|'upcoming'|'unknown', reason: 'in_history'|'in_bills'|'future_year'|'not_recorded'|'deadline_ahead'|'deadline_passed_without_bill'|'no_deadline', paid_at: string|null, paid_at_label: string|null, paid_amount: int|null, installment_amount: int|null, is_partial: bool, bill_amount: int|null, deadline: string|null}>
     * }
     */
    private function buildYear(
        int $studyYear,
        ?string $academicYear,
        ?int $currentStudyYear,
        int $stagesPerYear,
        array $paidStages,
        array $billedStages,
        array $deadlines,
        CarbonImmutable $today
    ): array {
        $latestPaidStage = $paidStages === [] ? 0 : max(array_keys($paidStages));
        $stages = [];

        for ($stage = 1; $stage <= $stagesPerYear; $stage++) {
            $paid = $paidStages[$stage] ?? null;
            $deadline = $deadlines[$stage] ?? null;

            [$status, $reason] = match (true) {
                $paid !== null => ['paid', 'in_history'],
                array_key_exists($stage, $billedStages) => ['unpaid', 'in_bills'],
                $currentStudyYear !== null && $studyYear > $currentStudyYear => ['upcoming', 'future_year'],
                ($currentStudyYear !== null && $studyYear < $currentStudyYear) || $stage < $latestPaidStage => ['unknown', 'not_recorded'],
                $deadline !== null && $deadline->greaterThanOrEqualTo($today) => ['upcoming', 'deadline_ahead'],
                $deadline !== null => ['unknown', 'deadline_passed_without_bill'],
                default => ['unknown', 'no_deadline'],
            };

            $stages[] = [
                'stage' => $stage,
                'status' => $status,
                'reason' => $reason,
                'paid_at' => $paid['paid_at'] ?? null,
                'paid_at_label' => $paid['paid_at_label'] ?? null,
                'paid_amount' => $paid['paid_amount'] ?? null,
                'installment_amount' => $paid['installment_amount'] ?? null,
                'is_partial' => $paid !== null
                    && $paid['paid_amount'] !== null
                    && $paid['installment_amount'] !== null
                    && $paid['paid_amount'] < $paid['installment_amount'],
                'bill_amount' => $billedStages[$stage] ?? null,
                'deadline' => $deadline?->format('Y-m-d'),
            ];
        }

        $countStatus = static fn (string $status): int => count(array_filter(
            $stages,
            static fn (array $stage): bool => $stage['status'] === $status
        ));

        return [
            'study_year' => $studyYear,
            'academic_year' => $academicYear,
            'is_current' => $studyYear === $currentStudyYear,
            'paid_count' => $countStatus('paid'),
            'unpaid_count' => $countStatus('unpaid'),
            'upcoming_count' => $countStatus('upcoming'),
            'unknown_count' => $countStatus('unknown'),
            'stages' => $stages,
        ];
    }

    /**
     * @param  array{stages: list<array{stage: int, status: string}>}|null  $currentYear
     */
    private function stageOnePaid(?array $currentYear): bool|string
    {
        $status = $currentYear['stages'][0]['status'] ?? null;

        return match ($status) {
            'paid' => true,
            'unpaid' => false,
            default => 'unknown',
        };
    }

    /**
     * @param  array{study_year: int, academic_year: string|null, stages: list<array{stage: int, status: string, reason: string, deadline: string|null}>}|null  $currentYear
     * @return array{study_year: int, academic_year: string|null, stage: int, status: string, reason: string, deadline: string|null}|null
     */
    private function nextStage(?array $currentYear): ?array
    {
        if ($currentYear === null) {
            return null;
        }

        foreach ($currentYear['stages'] as $stage) {
            if ($stage['status'] !== 'paid') {
                return [
                    'study_year' => $currentYear['study_year'],
                    'academic_year' => $currentYear['academic_year'],
                    'stage' => $stage['stage'],
                    'status' => $stage['status'],
                    'reason' => $stage['reason'],
                    'deadline' => $stage['deadline'],
                ];
            }
        }

        return null;
    }

    /**
     * The current study year comes from the page's academic year and the
     * entry year implied by the history; the highest recorded study year is
     * only a fallback, because a student who has not paid anything this year
     * would otherwise look like they are still in last year.
     *
     * @return array{0: int|null, 1: 'academic_year'|'records'|null}
     */
    private function currentStudyYear(?int $academicYearStart, ?int $entryYear, ?int $maxRecordedYear): array
    {
        if ($academicYearStart !== null && $entryYear !== null) {
            $studyYear = $academicYearStart - $entryYear + 1;

            if ($studyYear >= 1 && $studyYear <= self::MAX_STUDY_YEAR && ($maxRecordedYear === null || $studyYear >= $maxRecordedYear)) {
                return [$studyYear, 'academic_year'];
            }
        }

        if ($maxRecordedYear !== null) {
            return [$maxRecordedYear, 'records'];
        }

        return [null, null];
    }

    /**
     * Start year of the academic year in which study year 1 began, by
     * majority vote over the dated history rows (ties go to the latest row).
     *
     * @param  list<array<string, mixed>>  $payments
     */
    private function entryAcademicYearStart(array $payments): ?int
    {
        $votes = [];
        $latestVote = null;
        $latestPaidAt = null;

        foreach ($payments as $payment) {
            $stage = $this->intInRange($payment['installment_stage'] ?? null, self::MAX_STAGE);
            $studyYear = $this->intInRange($payment['study_year'] ?? null, self::MAX_STUDY_YEAR);
            $paidAt = $this->dateFromIso($payment['paid_at'] ?? null);

            if ($stage === null || $studyYear === null || $paidAt === null) {
                continue;
            }

            $entryYear = $this->academicYearStartOfStagePayment($paidAt, $stage) - ($studyYear - 1);
            $votes[$entryYear] = ($votes[$entryYear] ?? 0) + 1;

            if ($latestPaidAt === null || $paidAt->greaterThan($latestPaidAt)) {
                $latestPaidAt = $paidAt;
                $latestVote = $entryYear;
            }
        }

        if ($votes === []) {
            return null;
        }

        $winners = array_keys($votes, max($votes), true);

        return in_array($latestVote, $winners, true) ? $latestVote : max($winners);
    }

    /**
     * The academic year (start year) whose expected date for this stage is
     * closest to the payment date, so a late stage 4 paid in September stays
     * in the previous year and an early stage 1 paid in July moves forward.
     */
    private function academicYearStartOfStagePayment(CarbonImmutable $paidAt, int $stage): int
    {
        $offsetMonths = (min($stage, self::STAGES_PER_STUDY_YEAR) - 1) * self::MONTHS_BETWEEN_STAGES;
        $bestYear = $paidAt->year;
        $bestDistance = null;

        foreach ([$paidAt->year - 1, $paidAt->year] as $candidate) {
            $expected = CarbonImmutable::parse(sprintf('%04d-%02d-01', $candidate, self::FIRST_STAGE_MONTH), self::TIMEZONE)->addMonths($offsetMonths);
            $distance = abs($paidAt->getTimestamp() - $expected->getTimestamp());

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestYear = $candidate;
            }
        }

        return $bestYear;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array<int, array<int, array{paid_at: string|null, paid_at_label: string|null, paid_amount: int|null, installment_amount: int|null}>>
     */
    private function paidStages(array $payments): array
    {
        $paidStages = [];

        foreach ($payments as $payment) {
            $stage = $this->intInRange($payment['installment_stage'] ?? null, self::MAX_STAGE);
            $studyYear = $this->intInRange($payment['study_year'] ?? null, self::MAX_STUDY_YEAR);

            if ($stage === null || $studyYear === null) {
                continue;
            }

            $current = $paidStages[$studyYear][$stage] ?? [
                'paid_at' => null,
                'paid_at_label' => null,
                'paid_amount' => null,
                'installment_amount' => null,
            ];

            $amount = is_int($payment['amount'] ?? null) ? $payment['amount'] : null;
            $installmentAmount = is_int($payment['installment_amount'] ?? null) ? $payment['installment_amount'] : null;
            $paidAt = $this->dateFromIso($payment['paid_at'] ?? null)?->format('Y-m-d');

            if ($amount !== null) {
                $current['paid_amount'] = ($current['paid_amount'] ?? 0) + $amount;
            }

            if ($installmentAmount !== null) {
                $current['installment_amount'] = max($current['installment_amount'] ?? 0, $installmentAmount);
            }

            if ($paidAt !== null && ($current['paid_at'] === null || $paidAt > $current['paid_at'])) {
                $current['paid_at'] = $paidAt;
                $current['paid_at_label'] = is_string($payment['paid_at_label'] ?? null) ? $payment['paid_at_label'] : null;
            } elseif ($current['paid_at'] === null && $current['paid_at_label'] === null && is_string($payment['paid_at_label'] ?? null)) {
                $current['paid_at_label'] = $payment['paid_at_label'];
            }

            $paidStages[$studyYear][$stage] = $current;
        }

        return $paidStages;
    }

    /**
     * @param  list<array<string, mixed>>  $bills
     * @return array<int, array<int, int|null>>
     */
    private function billedStages(array $bills, ?int $currentStudyYear): array
    {
        $billedStages = [];

        foreach ($bills as $bill) {
            $stage = $this->intInRange($bill['installment_stage'] ?? null, self::MAX_STAGE);
            $studyYear = $this->intInRange($bill['study_year'] ?? null, self::MAX_STUDY_YEAR) ?? $currentStudyYear;

            if ($stage === null || $studyYear === null) {
                continue;
            }

            $amount = is_int($bill['amount'] ?? null) ? $bill['amount'] : null;
            $existing = $billedStages[$studyYear][$stage] ?? null;

            $billedStages[$studyYear][$stage] = $amount === null ? $existing : ($existing ?? 0) + $amount;
        }

        return $billedStages;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @param  list<array<string, mixed>>  $bills
     */
    private function maxRecordedStudyYear(array $payments, array $bills): ?int
    {
        $studyYears = [];

        foreach ([...$payments, ...$bills] as $row) {
            $studyYear = $this->intInRange($row['study_year'] ?? null, self::MAX_STUDY_YEAR);

            if ($studyYear !== null) {
                $studyYears[] = $studyYear;
            }
        }

        return $studyYears === [] ? null : max($studyYears);
    }

    /**
     * @param  array<int, array<int, mixed>>  $stagesByYear
     */
    private function maxStage(array $stagesByYear): int
    {
        $max = 0;

        foreach ($stagesByYear as $stages) {
            if ($stages !== []) {
                $max = max($max, ...array_keys($stages));
            }
        }

        return $max;
    }

    /**
     * @param  array<int, CarbonImmutable|string>  $deadlines
     * @return array<int, CarbonImmutable>
     */
    private function normalizeDeadlines(array $deadlines): array
    {
        $normalized = [];

        foreach ($deadlines as $stage => $deadline) {
            $stage = $this->intInRange($stage, self::MAX_STAGE);
            $date = $deadline instanceof CarbonImmutable
                ? $deadline->setTimezone(self::TIMEZONE)->startOfDay()
                : $this->dateFromIso($deadline);

            if ($stage !== null && $date !== null) {
                $normalized[$stage] = $date;
            }
        }

        return $normalized;
    }

    private function academicYearStart(?string $academicYear): ?int
    {
        if ($academicYear === null || preg_match('/(\d{4})\s*\/\s*(\d{4})/', $academicYear, $matches) !== 1) {
            return null;
        }

        return (int) $matches[2] === (int) $matches[1] + 1 ? (int) $matches[1] : null;
    }

    private function academicYearLabel(int $startYear): string
    {
        return $startYear.'/'.($startYear + 1);
    }

    private function dateFromIso(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::parse($value, self::TIMEZONE)->startOfDay();
    }

    private function intInRange(mixed $value, int $max): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 && $value <= $max ? $value : null;
    }
}
