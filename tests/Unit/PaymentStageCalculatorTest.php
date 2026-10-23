<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PaymentStageCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PaymentStageCalculatorTest extends TestCase
{
    private const TODAY = '2026-10-20';

    public function test_a_stage_missing_from_a_past_year_is_unknown_not_unpaid(): void
    {
        $result = $this->calculate([
            $this->payment(2, 1, '2026-02-06'),
            $this->payment(3, 1, '2026-03-12'),
            $this->payment(4, 1, '2026-05-14'),
            $this->payment(1, 2, '2026-09-08'),
        ]);

        $firstYear = $result['years'][0];

        $this->assertSame('2025/2026', $firstYear['academic_year']);
        $this->assertSame('unknown', $firstYear['stages'][0]['status']);
        $this->assertSame('not_recorded', $firstYear['stages'][0]['reason']);
        $this->assertSame(0, $firstYear['unpaid_count']);
        $this->assertSame(2, $result['current_study_year']);
        $this->assertSame('academic_year', $result['current_study_year_source']);
        $this->assertTrue($result['stage1_paid_for_current_year']);
    }

    public function test_the_current_year_follows_the_academic_year_before_anything_is_paid_this_year(): void
    {
        $result = $this->calculate([
            $this->payment(1, 1, '2025-09-10'),
            $this->payment(2, 1, '2025-12-10'),
            $this->payment(3, 1, '2026-03-10'),
            $this->payment(4, 1, '2026-05-10'),
        ]);

        $this->assertSame(2, $result['current_study_year']);
        $this->assertSame('academic_year', $result['current_study_year_source']);
        $this->assertCount(2, $result['years']);
        $this->assertSame(['paid', 'paid', 'paid', 'paid'], array_column($result['years'][0]['stages'], 'status'));
        $this->assertSame(['unknown', 'unknown', 'unknown', 'unknown'], array_column($result['years'][1]['stages'], 'status'));
        $this->assertSame('no_deadline', $result['years'][1]['stages'][0]['reason']);
        $this->assertSame('2026/2027', $result['years'][1]['academic_year']);
        $this->assertSame([], $result['current_year_stages_paid']);
        $this->assertSame('unknown', $result['stage1_paid_for_current_year']);
        $this->assertSame(['study_year' => 2, 'academic_year' => '2026/2027', 'stage' => 1, 'status' => 'unknown', 'reason' => 'no_deadline', 'deadline' => null], $result['next_stage']);
    }

    public function test_a_billed_stage_one_is_unpaid_and_not_paid_for_the_current_year(): void
    {
        $result = $this->calculate(
            [$this->payment(4, 1, '2026-05-14')],
            [['installment_stage' => 1, 'study_year' => 2, 'amount' => 1250000]]
        );

        $stage = $result['years'][1]['stages'][0];

        $this->assertSame('unpaid', $stage['status']);
        $this->assertSame('in_bills', $stage['reason']);
        $this->assertSame(1250000, $stage['bill_amount']);
        $this->assertFalse($result['stage1_paid_for_current_year']);
        $this->assertSame('unpaid', $result['next_stage']['status']);
    }

    public function test_a_bill_without_a_study_year_belongs_to_the_current_year(): void
    {
        $result = $this->calculate(
            [$this->payment(1, 2, '2026-09-08')],
            [['installment_stage' => 2, 'study_year' => null, 'amount' => 1250000]]
        );

        $this->assertSame('unpaid', $result['years'][1]['stages'][1]['status']);
        $this->assertSame('unknown', $result['years'][0]['stages'][1]['status']);
    }

    public function test_a_past_year_bill_is_unpaid(): void
    {
        $result = $this->calculate(
            [$this->payment(1, 2, '2026-09-08')],
            [['installment_stage' => 4, 'study_year' => 1, 'amount' => 1250000]]
        );

        $this->assertSame(['unknown', 'unknown', 'unknown', 'unpaid'], array_column($result['years'][0]['stages'], 'status'));
    }

    public function test_deadlines_only_turn_unrecorded_current_stages_into_upcoming(): void
    {
        $result = $this->calculate(
            [$this->payment(1, 2, '2026-09-08')],
            [['installment_stage' => 4, 'study_year' => 2, 'amount' => 1250000]],
            deadlines: [
                2 => '2026-10-20',
                3 => CarbonImmutable::parse('2026-10-19', 'Asia/Jakarta'),
                4 => '2026-09-30',
            ]
        );

        $stages = $result['years'][1]['stages'];

        $this->assertSame('upcoming', $stages[1]['status']);
        $this->assertSame('deadline_ahead', $stages[1]['reason']);
        $this->assertSame('2026-10-20', $stages[1]['deadline']);
        $this->assertSame('unknown', $stages[2]['status']);
        $this->assertSame('deadline_passed_without_bill', $stages[2]['reason']);
        $this->assertSame('unpaid', $stages[3]['status']);
        $this->assertSame('in_bills', $stages[3]['reason']);
        $this->assertNull($result['years'][0]['stages'][1]['deadline']);
        $this->assertSame(['study_year' => 2, 'academic_year' => '2026/2027', 'stage' => 2, 'status' => 'upcoming', 'reason' => 'deadline_ahead', 'deadline' => '2026-10-20'], $result['next_stage']);
    }

    public function test_invalid_deadlines_are_ignored(): void
    {
        $result = $this->calculate(
            [$this->payment(1, 2, '2026-09-08')],
            deadlines: [2 => 'bukan tanggal', 0 => '2026-12-01', 3 => '2026-02-30']
        );

        $this->assertSame(['paid', 'unknown', 'unknown', 'unknown'], array_column($result['years'][1]['stages'], 'status'));
        $this->assertSame('no_deadline', $result['years'][1]['stages'][1]['reason']);
    }

    public function test_a_current_stage_before_a_later_paid_stage_is_not_recorded(): void
    {
        $result = $this->calculate([
            $this->payment(4, 1, '2026-05-14'),
            $this->payment(2, 2, '2026-12-05'),
        ]);

        $stages = $result['years'][1]['stages'];

        $this->assertSame(['unknown', 'paid', 'unknown', 'unknown'], array_column($stages, 'status'));
        $this->assertSame('not_recorded', $stages[0]['reason']);
        $this->assertSame('no_deadline', $stages[2]['reason']);
        $this->assertSame('unknown', $result['stage1_paid_for_current_year']);
        // Stage 1 was settled before the paid stage 2: the next one is stage 3.
        $this->assertSame(['study_year' => 2, 'academic_year' => '2026/2027', 'stage' => 3, 'status' => 'unknown', 'reason' => 'no_deadline', 'deadline' => null], $result['next_stage']);
    }

    public function test_a_first_year_stage_one_paid_at_admission_is_never_the_next_stage(): void
    {
        $result = (new PaymentStageCalculator)->calculate(
            [$this->payment(2, 1, '2026-12-05')],
            [],
            '2026/2027',
            [],
            CarbonImmutable::parse('2027-01-10 10:00:00', 'Asia/Jakarta')
        );

        $this->assertSame(1, $result['current_study_year']);
        $this->assertSame(['unknown', 'paid', 'unknown', 'unknown'], array_column($result['years'][0]['stages'], 'status'));
        $this->assertSame(3, $result['next_stage']['stage']);
        $this->assertSame('no_deadline', $result['next_stage']['reason']);
    }

    public function test_a_billed_stage_below_a_paid_stage_is_still_the_next_one(): void
    {
        $result = $this->calculate(
            [$this->payment(4, 1, '2026-05-14'), $this->payment(2, 2, '2026-12-05')],
            [['installment_stage' => 1, 'study_year' => 2, 'amount' => 1250000]]
        );

        $this->assertSame(['unpaid', 'paid', 'unknown', 'unknown'], array_column($result['years'][1]['stages'], 'status'));
        $this->assertSame(['stage' => 1, 'status' => 'unpaid', 'reason' => 'in_bills'], array_intersect_key($result['next_stage'], array_flip(['stage', 'status', 'reason'])));
    }

    public function test_only_unrecorded_stages_left_means_no_next_stage(): void
    {
        $result = $this->calculate([
            $this->payment(4, 1, '2026-05-14'),
            $this->payment(4, 2, '2027-05-14'),
        ]);

        $this->assertSame(['unknown', 'unknown', 'unknown', 'paid'], array_column($result['years'][1]['stages'], 'status'));
        $this->assertNull($result['next_stage']);
    }

    public function test_late_and_early_payments_stay_in_their_own_academic_year(): void
    {
        $result = $this->calculate([
            $this->payment(4, 1, '2026-09-15'),
            $this->payment(1, 2, '2026-07-20'),
        ]);

        $this->assertSame(2, $result['current_study_year']);
        $this->assertSame('academic_year', $result['current_study_year_source']);
        $this->assertSame('2025/2026', $result['years'][0]['academic_year']);
        $this->assertSame('2026/2027', $result['years'][1]['academic_year']);
    }

    public function test_an_inconsistent_academic_year_falls_back_to_the_recorded_study_year(): void
    {
        $result = $this->calculate([$this->payment(1, 3, '2026-09-08')], currentAcademicYear: '2025/2026');

        $this->assertSame(3, $result['current_study_year']);
        $this->assertSame('records', $result['current_study_year_source']);
        $this->assertSame('2025/2026', $result['current_academic_year']);
        $this->assertTrue($result['stage1_paid_for_current_year']);
    }

    public function test_without_an_academic_year_the_recorded_study_year_is_used(): void
    {
        $result = $this->calculate([$this->payment(2, 1, '2026-02-06')], currentAcademicYear: null);

        $this->assertSame(1, $result['current_study_year']);
        $this->assertSame('records', $result['current_study_year_source']);
        $this->assertSame('2025/2026', $result['current_academic_year']);
    }

    public function test_partial_payments_are_flagged_and_summed(): void
    {
        $result = $this->calculate([
            $this->payment(1, 2, '2026-09-08', amount: 500000),
            $this->payment(1, 2, '2026-10-01', amount: 250000),
        ]);

        $stage = $result['years'][1]['stages'][0];

        $this->assertSame('paid', $stage['status']);
        $this->assertSame(750000, $stage['paid_amount']);
        $this->assertSame(1250000, $stage['installment_amount']);
        $this->assertTrue($stage['is_partial']);
        $this->assertSame('2026-10-01', $stage['paid_at']);
        $this->assertSame('01 Oktober 2026', $stage['paid_at_label']);
    }

    public function test_a_partly_paid_stage_still_listed_in_the_bills_is_unpaid(): void
    {
        $result = $this->calculate(
            [$this->payment(4, 1, '2026-05-14'), $this->payment(1, 2, '2026-09-08', amount: 500000)],
            [['installment_stage' => 1, 'study_year' => 2, 'amount' => 750000]]
        );

        $stage = $result['years'][1]['stages'][0];

        $this->assertSame('unpaid', $stage['status']);
        $this->assertSame('in_bills', $stage['reason']);
        $this->assertSame(500000, $stage['paid_amount']);
        $this->assertSame(1250000, $stage['installment_amount']);
        $this->assertTrue($stage['is_partial']);
        $this->assertSame(750000, $stage['bill_amount']);
        $this->assertSame('2026-09-08', $stage['paid_at']);
        $this->assertSame([], $result['current_year_stages_paid']);
        $this->assertFalse($result['stage1_paid_for_current_year']);
        $this->assertSame(['stage' => 1, 'status' => 'unpaid'], array_intersect_key($result['next_stage'], array_flip(['stage', 'status'])));
        $this->assertSame(1, $result['years'][1]['unpaid_count']);
        $this->assertSame(0, $result['years'][1]['paid_count']);
    }

    public function test_a_bill_row_left_behind_after_the_whole_installment_was_paid_keeps_the_stage_paid(): void
    {
        $result = $this->calculate(
            [$this->payment(4, 1, '2026-05-14'), $this->payment(1, 2, '2026-09-08')],
            [['installment_stage' => 1, 'study_year' => 2, 'amount' => 1250000]]
        );

        $stage = $result['years'][1]['stages'][0];

        $this->assertSame('paid', $stage['status']);
        $this->assertSame('in_history', $stage['reason']);
        $this->assertFalse($stage['is_partial']);
        $this->assertSame(1250000, $stage['bill_amount']);
        $this->assertSame([1], $result['current_year_stages_paid']);
        $this->assertTrue($result['stage1_paid_for_current_year']);
        $this->assertSame(2, $result['next_stage']['stage']);

        // Without the installment amount the history cannot prove it whole: the bill wins.
        $unknown = $this->payment(1, 2, '2026-09-08');
        $unknown['installment_amount'] = null;
        $result = $this->calculate([$this->payment(4, 1, '2026-05-14'), $unknown], [['installment_stage' => 1, 'study_year' => 2, 'amount' => 1250000]]);

        $this->assertSame('unpaid', $result['years'][1]['stages'][0]['status']);
        $this->assertFalse($result['stage1_paid_for_current_year']);
    }

    public function test_nothing_is_guessed_without_any_study_year(): void
    {
        $result = $this->calculate([
            ['installment_stage' => null, 'study_year' => null, 'paid_at' => '2026-09-08', 'amount' => 300000],
        ]);

        $this->assertNull($result['current_study_year']);
        $this->assertNull($result['current_study_year_source']);
        $this->assertSame('2026/2027', $result['current_academic_year']);
        $this->assertSame([], $result['years']);
        $this->assertSame([], $result['current_year_stages_paid']);
        $this->assertSame('unknown', $result['stage1_paid_for_current_year']);
        $this->assertNull($result['next_stage']);
    }

    public function test_a_fully_paid_current_year_has_no_next_stage(): void
    {
        $result = $this->calculate([
            $this->payment(1, 1, '2025-09-10'),
            $this->payment(2, 1, '2025-12-10'),
            $this->payment(3, 1, '2026-03-10'),
            $this->payment(4, 1, '2026-05-10'),
        ], currentAcademicYear: '2025/2026');

        $this->assertSame(1, $result['current_study_year']);
        $this->assertSame([1, 2, 3, 4], $result['current_year_stages_paid']);
        $this->assertSame(4, $result['years'][0]['paid_count']);
        $this->assertNull($result['next_stage']);
    }

    public function test_more_stages_than_usual_widen_every_year(): void
    {
        $result = $this->calculate([
            $this->payment(1, 2, '2026-09-08'),
            $this->payment(5, 1, '2026-07-01'),
        ]);

        $this->assertSame(5, $result['stages_per_year']);
        $this->assertCount(5, $result['years'][0]['stages']);
        $this->assertCount(5, $result['years'][1]['stages']);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @param  list<array<string, mixed>>  $bills
     * @param  array<int, CarbonImmutable|string>  $deadlines
     * @return array<string, mixed>
     */
    private function calculate(array $payments, array $bills = [], ?string $currentAcademicYear = '2026/2027', array $deadlines = []): array
    {
        return (new PaymentStageCalculator)->calculate(
            $payments,
            $bills,
            $currentAcademicYear,
            $deadlines,
            CarbonImmutable::parse(self::TODAY.' 10:00:00', 'Asia/Jakarta')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(int $stage, int $studyYear, string $paidAt, int $amount = 1250000): array
    {
        return [
            'installment_stage' => $stage,
            'study_year' => $studyYear,
            'paid_at' => $paidAt,
            'paid_at_label' => CarbonImmutable::parse($paidAt)->locale('id')->translatedFormat('d F Y'),
            'amount' => $amount,
            'installment_amount' => 1250000,
        ];
    }
}
