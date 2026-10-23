<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DashboardMetricsBuilder;
use Tests\TestCase;

class DashboardMetricsBuilderTest extends TestCase
{
    public function test_metrics_read_real_values_from_the_snapshots(): void
    {
        $metrics = $this->builder()->fromSnapshots([
            'grades' => $this->read(['summary' => [
                'ipk' => 3.1,
                'source' => 'official',
                'sks_passed' => 86,
                'conversion_sks' => 66,
                'predicate_estimate' => 'Sangat Memuaskan',
                'progress' => ['target_sks' => 144, 'remaining_sks' => 58, 'progress_percent' => 59.7],
            ]]),
            'krs' => $this->read(['found' => true, 'totals' => ['courses' => 6, 'sks' => 14]], 'syncing'),
            'payments' => $this->read(['outstanding_summary' => ['status' => 'outstanding', 'has_outstanding' => true, 'total_amount' => 1250000, 'total_amount_label' => 'Rp 1.250.000', 'count' => 1]]),
            'skkm' => $this->read(['found' => true, 'stats' => ['required_points' => 100], 'graduation_status' => ['is_met' => false], 'summary' => ['valid_points' => 70, 'remaining_points' => 30, 'progress_percent' => 70.0]]),
        ]);

        $this->assertSame(['value' => 3.1, 'source' => 'official', 'predicate_estimate' => 'Sangat Memuaskan', 'status' => 'ready'], $metrics['ipk']);
        $this->assertSame(['value' => 86, 'conversion_sks' => 66, 'target_sks' => 144, 'remaining_sks' => 58, 'progress_percent' => 59.7, 'status' => 'ready'], $metrics['sks_passed']);
        $this->assertSame(['value' => 14, 'courses' => 6, 'status' => 'ready'], $metrics['sks_current']);
        $this->assertSame('outstanding', $metrics['bills']['status']);
        $this->assertSame('Rp 1.250.000', $metrics['bills']['total_amount_label']);
        $this->assertSame(70, $metrics['skkm']['valid_points']);
        $this->assertFalse($metrics['skkm']['is_met']);
        $this->assertSame(['krs'], $metrics['syncing_sources']);
        $this->assertSame([], $metrics['pending_sources']);
    }

    public function test_missing_or_unreadable_values_stay_null_never_zero(): void
    {
        $metrics = $this->builder()->fromSnapshots([
            'grades' => ['data' => null, 'meta' => ['status' => 'pending', 'synced_at' => null]],
            'krs' => $this->read(['found' => false, 'totals' => ['courses' => 0, 'sks' => 0]]),
            'payments' => $this->read(['outstanding_summary' => ['status' => 'unknown', 'total_amount' => null]]),
            'skkm' => ['data' => null, 'meta' => ['status' => 'failed', 'synced_at' => null]],
        ]);

        $this->assertNull($metrics['ipk']['value']);
        $this->assertSame('pending', $metrics['ipk']['status']);
        $this->assertSame('pending', $metrics['sks_passed']['status']);
        $this->assertSame(['value' => null, 'courses' => null, 'status' => 'unavailable'], $metrics['sks_current']);
        $this->assertSame(['status' => 'unknown', 'has_outstanding' => null, 'total_amount' => null, 'total_amount_label' => null, 'count' => null], $metrics['bills']);
        $this->assertSame('failed', $metrics['skkm']['status']);
        $this->assertSame(['grades'], $metrics['pending_sources']);
        $this->assertStringNotContainsString('Rp 0', (string) json_encode($metrics));
    }

    public function test_a_student_without_any_grade_yet_has_an_empty_ipk_not_an_unread_one(): void
    {
        $noKhs = $this->builder()->fromSnapshots([
            'grades' => $this->read(['found' => false, 'semesters' => [], 'summary' => ['ipk' => null, 'sks_passed' => 0, 'computed' => ['ipk' => null, 'course_count' => 0]]]),
        ]);
        $emptyCards = $this->builder()->fromSnapshots([
            'grades' => $this->read(['found' => true, 'semesters' => [['semester' => 1, 'courses' => []]], 'summary' => ['ipk' => null, 'computed' => ['ipk' => null, 'course_count' => 0]]]),
        ]);

        $this->assertSame(['value' => null, 'source' => null, 'predicate_estimate' => null, 'status' => 'empty'], $noKhs['ipk']);
        $this->assertSame('empty', $emptyCards['ipk']['status']);
    }

    public function test_an_ipk_missing_from_graded_courses_is_still_unavailable(): void
    {
        $graded = $this->builder()->fromSnapshots([
            'grades' => $this->read(['found' => true, 'summary' => ['ipk' => null, 'computed' => ['ipk' => null, 'course_count' => 8]]]),
        ]);
        $legacy = $this->builder()->fromSnapshots([
            'grades' => $this->read(['summary' => ['ipk' => null]]),
        ]);
        $failed = $this->builder()->fromSnapshots([
            'grades' => ['data' => null, 'meta' => ['status' => 'failed', 'synced_at' => null]],
        ]);

        $this->assertSame('unavailable', $graded['ipk']['status']);
        $this->assertSame('unavailable', $legacy['ipk']['status']);
        $this->assertSame('failed', $failed['ipk']['status']);
    }

    private function builder(): DashboardMetricsBuilder
    {
        return app(DashboardMetricsBuilder::class);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function read(array $data, string $status = 'synced'): array
    {
        return ['data' => $data, 'meta' => ['status' => $status, 'synced_at' => '2026-10-20T02:00:00+00:00', 'data_origin' => 'snapshot']];
    }
}
