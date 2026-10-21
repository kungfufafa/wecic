<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalFinanceStatusResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PortalFinanceStatusResolverTest extends TestCase
{
    public function test_outstanding_bills_are_summarized_with_their_stage(): void
    {
        $status = $this->resolver()->summarize($this->payments('outstanding'), [], $this->today());

        $this->assertSame('outstanding', $status['outstanding_summary']['status']);
        $this->assertTrue($status['has_outstanding']);
        $this->assertSame(1250000, $status['outstanding_total']);
        $this->assertSame('Rp 1.250.000', $status['outstanding_total_label']);
        $this->assertCount(1, $status['outstanding_bills']);
        $this->assertSame(1, $status['current_study_year']);
        $this->assertSame([1], $status['current_year_stages_paid']);
        $this->assertSame(['stage' => 2, 'status' => 'unpaid'], [
            'stage' => $status['next_stage']['stage'],
            'status' => $status['next_stage']['status'],
        ]);
        $this->assertTrue($status['current_year']['is_current']);
        $this->assertTrue($status['history_available']);
        $this->assertNull($status['active_deadline']);
    }

    public function test_unreadable_bill_table_stays_unknown_and_never_reports_rp_zero(): void
    {
        $status = $this->resolver()->summarize($this->payments('unknown'), [], $this->today());

        $this->assertSame('unknown', $status['outstanding_summary']['status']);
        $this->assertNull($status['has_outstanding']);
        $this->assertNull($status['outstanding_total']);
        $this->assertNull($status['outstanding_total_label']);

        // A snapshot from before the bill table was parsed has no summary at all.
        $legacy = $this->resolver()->summarize(['payment_history' => [], 'summary' => []], [], $this->today());
        $this->assertSame('unknown', $legacy['outstanding_summary']['status']);
        $this->assertNull($legacy['outstanding_total']);
        $this->assertFalse($legacy['history_available']);
    }

    public function test_future_payment_notice_becomes_the_active_deadline_and_an_upcoming_stage(): void
    {
        $status = $this->resolver()->summarize($this->payments('clear'), [
            null,
            [
                'text' => 'Kewajiban Pembayaran Kuliah Tahap ke 2 paling lambat pada Hari Senin, 2 November 2026 untuk menghindari pemblokiran sistem MyCIC',
                'dates' => ['2026-10-01', '2026-11-02'],
                'is_future' => true,
                'kind' => 'payment',
            ],
        ], $this->today());

        $this->assertSame([
            'stage' => 2,
            'date' => '2026-11-02',
            'days_left' => 13,
            'is_future' => true,
            'source' => 'portal_notice',
        ], array_intersect_key($status['active_deadline'], array_flip(['stage', 'date', 'days_left', 'is_future', 'source'])));
        $this->assertSame('upcoming', $status['next_stage']['status']);
        $this->assertSame('2026-11-02', $status['next_stage']['deadline']);
    }

    public function test_deadline_for_a_paid_stage_or_in_the_past_is_dropped(): void
    {
        $notice = [
            'text' => 'Kewajiban Pembayaran Kuliah Tahap ke 1 paling lambat 2 November 2026',
            'dates' => ['2026-11-02'],
            'is_future' => true,
            'kind' => 'payment',
        ];

        $this->assertNull($this->resolver()->summarize($this->payments('clear'), [$notice], $this->today())['active_deadline']);

        $past = [...$notice, 'text' => 'Pembayaran Tahap ke 2 paling lambat 1 Oktober 2026', 'dates' => ['2026-10-01']];
        $this->assertNull($this->resolver()->summarize($this->payments('clear'), [$past], $this->today())['active_deadline']);
    }

    public function test_payment_deadline_reads_only_payment_notices(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->paymentDeadline([
            ['text' => 'Pastikan status absensi kamu', 'dates' => ['2026-10-30'], 'is_future' => true, 'kind' => 'attendance'],
            ['text' => 'Pembayaran UKT tanpa tanggal', 'dates' => [], 'is_future' => false, 'kind' => 'payment'],
        ], $this->today()));

        $deadline = $resolver->paymentDeadline([
            ['text' => 'Batas pembayaran UKT 5 November 2026', 'dates' => ['2026-11-05'], 'is_future' => true, 'kind' => 'general'],
        ], $this->today());

        $this->assertNull($deadline['stage']);
        $this->assertSame('2026-11-05', $deadline['date']);
        $this->assertSame(16, $deadline['days_left']);
    }

    public function test_an_old_dated_notice_never_hides_a_newer_deadline_from_another_source(): void
    {
        // portal_context (6 h snapshot) still carries the stage 1 marquee; the
        // announcement page already shows the stage 2 deadline.
        $pastStageOne = ['text' => 'Kewajiban Pembayaran Kuliah Tahap ke 1 paling lambat 1 September 2026', 'dates' => ['2026-09-01'], 'is_future' => false, 'kind' => 'payment'];
        $futureStageTwo = ['text' => 'Kewajiban Pembayaran Kuliah Tahap ke 2 paling lambat 2 November 2026', 'dates' => ['2026-11-02'], 'is_future' => true, 'kind' => 'payment'];

        $status = $this->resolver()->summarize($this->payments('clear'), [$pastStageOne, $futureStageTwo], $this->today());

        $this->assertSame(2, $status['active_deadline']['stage']);
        $this->assertSame('2026-11-02', $status['active_deadline']['date']);

        // With only past notices the deadline is still reported, as not ahead.
        $deadline = $this->resolver()->paymentDeadline([$pastStageOne], $this->today());
        $this->assertSame('2026-09-01', $deadline['date']);
        $this->assertFalse($deadline['is_future']);
    }

    public function test_notice_stage_paid_checks_the_named_stage_against_the_payments(): void
    {
        $resolver = $this->resolver();
        $stageOne = ['text' => 'Kewajiban Pembayaran Kuliah Tahap ke 1 paling lambat 2 November 2026', 'dates' => ['2026-11-02'], 'kind' => 'payment'];
        $stageTwo = [...$stageOne, 'text' => 'Kewajiban Pembayaran Kuliah Tahap ke 2 paling lambat 2 November 2026'];

        $this->assertTrue($resolver->noticeStagePaid($stageOne, $this->payments('clear'), $this->today()));
        $this->assertFalse($resolver->noticeStagePaid($stageTwo, $this->payments('clear'), $this->today()));
        // Unknown without payments, without a stage, or for a non-payment notice.
        $this->assertNull($resolver->noticeStagePaid($stageOne, null, $this->today()));
        $this->assertNull($resolver->noticeStagePaid([...$stageOne, 'text' => 'Batas pembayaran UKT 5 November 2026'], $this->payments('clear'), $this->today()));
        $this->assertNull($resolver->noticeStagePaid(['text' => 'Pengisian absensi Tahap ke 1', 'kind' => 'attendance'], $this->payments('clear'), $this->today()));
    }

    private function resolver(): PortalFinanceStatusResolver
    {
        return app(PortalFinanceStatusResolver::class);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 10, 20, 9, 0, 0, 'Asia/Jakarta');
    }

    /**
     * @return array<string, mixed>
     */
    private function payments(string $status): array
    {
        return [
            'student_info' => ['academic_year' => '2026/2027'],
            'payment_history' => [[
                'no' => '1',
                'paid_at' => '2026-09-05',
                'paid_at_label' => '05 September 2026',
                'category' => 'Pembayaran UKT',
                'amount' => 1250000,
                'installment_stage' => 1,
                'study_year' => 1,
                'installment_amount' => 1250000,
            ]],
            'summary' => ['total_paid_amount' => 1250000, 'history_available' => true, 'latest_payment' => ['no' => '1', 'paid_at' => '2026-09-05']],
            'outstanding_bills' => $status === 'outstanding' ? [[
                'no' => '1',
                'name' => 'UKT Tahap 2 tahun ke-1',
                'amount' => 1250000,
                'amount_label' => 'Rp 1.250.000',
                'installment_stage' => 2,
                'study_year' => 1,
            ]] : [],
            'outstanding_summary' => match ($status) {
                'outstanding' => ['status' => 'outstanding', 'has_outstanding' => true, 'total_amount' => 1250000, 'total_amount_label' => 'Rp 1.250.000', 'count' => 1, 'rows_sum_matches_total' => true],
                'clear' => ['status' => 'clear', 'has_outstanding' => false, 'total_amount' => 0, 'total_amount_label' => 'Rp 0', 'count' => 0, 'rows_sum_matches_total' => true],
                default => ['status' => 'unknown', 'has_outstanding' => null, 'total_amount' => null, 'total_amount_label' => null, 'count' => 0, 'rows_sum_matches_total' => null],
            },
            'extracted_at' => '2026-10-20T02:00:00+00:00',
        ];
    }
}
