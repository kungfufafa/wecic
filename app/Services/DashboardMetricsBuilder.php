<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Beranda metric tiles (K11): IPK · SKS lulus · SKS semester ini · Tagihan,
 * plus the SKKM progress tile, read from the grades/krs/payments/skkm
 * snapshots only (PortalDatasetRepository::peekMany()).
 *
 * The dashboard never waits on the portal for these: a missing snapshot is
 * queued in the background and its tile reports `pending`. A value the
 * portal did not give stays null (`unavailable`), so the UI shows "–",
 * never a made-up 0 or "Rp 0" (Fase 0.7). An IPK is `empty`, not
 * `unavailable`, when the KHS was read and simply has no grade yet.
 *
 * @phpstan-type MetricStatus 'ready'|'pending'|'unavailable'|'failed'
 * @phpstan-type IpkStatus 'ready'|'pending'|'unavailable'|'failed'|'empty'
 */
class DashboardMetricsBuilder
{
    /**
     * @var list<string>
     */
    public const SOURCES = ['grades', 'krs', 'payments', 'skkm'];

    public function __construct(private readonly PortalFinanceStatusResolver $financeStatusResolver) {}

    /**
     * Build the tiles from snapshot reads of SOURCES (see GET /api/dashboard,
     * which reads them together with `portal_context` in one call).
     *
     * @param  array<string, array{data: array<string, mixed>|null, meta: array<string, mixed>}>  $reads  results of PortalDatasetRepository::peekMany()
     * @return array{
     *     ipk: array{value: float|null, source: 'official'|'computed'|null, predicate_estimate: string|null, status: IpkStatus},
     *     sks_passed: array{value: int|null, conversion_sks: int|null, target_sks: int|null, remaining_sks: int|null, progress_percent: float|null, status: MetricStatus},
     *     sks_current: array{value: int|null, courses: int|null, status: MetricStatus},
     *     bills: array{status: 'clear'|'outstanding'|'unknown'|'pending'|'failed', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int|null},
     *     skkm: array{valid_points: int|float|null, required_points: int|float|null, remaining_points: int|float|null, progress_percent: float|null, is_met: bool|null, status: MetricStatus},
     *     sources: array<string, array{status: 'ready'|'syncing'|'pending'|'failed', synced_at: string|null}>,
     *     pending_sources: list<string>,
     *     syncing_sources: list<string>
     * }
     */
    public function fromSnapshots(array $reads): array
    {
        $data = [];
        $sources = [];

        foreach (self::SOURCES as $source) {
            $result = $reads[$source] ?? ['data' => null, 'meta' => ['status' => 'missing']];
            $status = (string) ($result['meta']['status'] ?? 'synced');
            $data[$source] = $result['data'];
            $sources[$source] = [
                'status' => match (true) {
                    $result['data'] === null && $status === 'failed' => 'failed',
                    $result['data'] === null => 'pending',
                    $status === 'syncing' => 'syncing',
                    default => 'ready',
                },
                'synced_at' => is_string($result['meta']['synced_at'] ?? null) ? $result['meta']['synced_at'] : null,
            ];
        }

        return [
            'ipk' => $this->ipk($data['grades'] ?? null, $sources['grades']['status']),
            'sks_passed' => $this->sksPassed($data['grades'] ?? null, $sources['grades']['status']),
            'sks_current' => $this->sksCurrent($data['krs'] ?? null, $sources['krs']['status']),
            'bills' => $this->bills($data['payments'] ?? null, $sources['payments']['status']),
            'skkm' => $this->skkm($data['skkm'] ?? null, $sources['skkm']['status']),
            'sources' => $sources,
            'pending_sources' => array_keys(array_filter($sources, static fn (array $source): bool => $source['status'] === 'pending')),
            'syncing_sources' => array_keys(array_filter($sources, static fn (array $source): bool => $source['status'] === 'syncing')),
        ];
    }

    /**
     * `empty` when the KHS was read but has no grade yet (a first-semester
     * student): a normal state, unlike `unavailable` (the IPK could not be read).
     *
     * @param  array<string, mixed>|null  $grades
     * @return array{value: float|null, source: 'official'|'computed'|null, predicate_estimate: string|null, status: string}
     */
    private function ipk(?array $grades, string $sourceStatus): array
    {
        $summary = is_array($grades['summary'] ?? null) ? $grades['summary'] : [];
        $ipk = is_int($summary['ipk'] ?? null) || is_float($summary['ipk'] ?? null) ? (float) $summary['ipk'] : null;
        $source = in_array($summary['source'] ?? null, ['official', 'computed'], true) ? $summary['source'] : null;
        $status = $this->status($sourceStatus, $ipk !== null);

        return [
            'value' => $ipk,
            'source' => $ipk !== null ? $source : null,
            'predicate_estimate' => $ipk !== null && is_string($summary['predicate_estimate'] ?? null) ? $summary['predicate_estimate'] : null,
            'status' => $status === 'unavailable' && $grades !== null && $this->hasNoGradesYet($grades) ? 'empty' : $status,
        ];
    }

    /**
     * The grades snapshot says there is nothing to average yet: no KHS at all,
     * or KHS cards whose courses have no grade.
     *
     * @param  array<string, mixed>  $grades
     */
    private function hasNoGradesYet(array $grades): bool
    {
        if (($grades['found'] ?? null) === false) {
            return true;
        }

        $computed = $grades['summary']['computed'] ?? null;

        return is_array($computed) && ($computed['course_count'] ?? null) === 0;
    }

    /**
     * `value` already includes the conversion SKS of the KRS `T`.
     *
     * @param  array<string, mixed>|null  $grades
     * @return array{value: int|null, conversion_sks: int|null, target_sks: int|null, remaining_sks: int|null, progress_percent: float|null, status: string}
     */
    private function sksPassed(?array $grades, string $sourceStatus): array
    {
        $summary = is_array($grades['summary'] ?? null) ? $grades['summary'] : [];
        $progress = is_array($summary['progress'] ?? null) ? $summary['progress'] : [];
        $value = is_int($summary['sks_passed'] ?? null) ? $summary['sks_passed'] : null;

        return [
            'value' => $value,
            'conversion_sks' => $value !== null && is_int($summary['conversion_sks'] ?? null) ? $summary['conversion_sks'] : null,
            'target_sks' => $value !== null && is_int($progress['target_sks'] ?? null) ? $progress['target_sks'] : null,
            'remaining_sks' => $value !== null && is_int($progress['remaining_sks'] ?? null) ? $progress['remaining_sks'] : null,
            'progress_percent' => $value !== null && is_numeric($progress['progress_percent'] ?? null) ? (float) $progress['progress_percent'] : null,
            'status' => $this->status($sourceStatus, $value !== null),
        ];
    }

    /**
     * "SKS semester ini" is the current KRS load, distinct from "SKS lulus" (K11).
     *
     * @param  array<string, mixed>|null  $krs
     * @return array{value: int|null, courses: int|null, status: string}
     */
    private function sksCurrent(?array $krs, string $sourceStatus): array
    {
        $found = ($krs['found'] ?? false) === true && is_array($krs['totals'] ?? null);

        return [
            'value' => $found ? (int) ($krs['totals']['sks'] ?? 0) : null,
            'courses' => $found ? (int) ($krs['totals']['courses'] ?? 0) : null,
            'status' => $this->status($sourceStatus, $found),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payments
     * @return array{status: 'clear'|'outstanding'|'unknown'|'pending'|'failed', has_outstanding: bool|null, total_amount: int|null, total_amount_label: string|null, count: int|null}
     */
    private function bills(?array $payments, string $sourceStatus): array
    {
        if ($payments === null) {
            return [
                'status' => $sourceStatus === 'failed' ? 'failed' : 'pending',
                'has_outstanding' => null,
                'total_amount' => null,
                'total_amount_label' => null,
                'count' => null,
            ];
        }

        $summary = $this->financeStatusResolver->summarize($payments)['outstanding_summary'];

        return [
            'status' => $summary['status'],
            'has_outstanding' => $summary['has_outstanding'],
            'total_amount' => $summary['total_amount'],
            'total_amount_label' => $summary['total_amount_label'],
            'count' => $summary['status'] === 'unknown' ? null : $summary['count'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $skkm
     * @return array{valid_points: int|float|null, required_points: int|float|null, remaining_points: int|float|null, progress_percent: float|null, is_met: bool|null, status: string}
     */
    private function skkm(?array $skkm, string $sourceStatus): array
    {
        $found = ($skkm['found'] ?? false) === true;
        $summary = $found && is_array($skkm['summary'] ?? null) ? $skkm['summary'] : [];
        $validPoints = $this->number($summary['valid_points'] ?? null);

        return [
            'valid_points' => $validPoints,
            'required_points' => $found ? $this->number($skkm['stats']['required_points'] ?? null) : null,
            'remaining_points' => $this->number($summary['remaining_points'] ?? null),
            'progress_percent' => is_numeric($summary['progress_percent'] ?? null) ? (float) $summary['progress_percent'] : null,
            'is_met' => $found && is_bool($skkm['graduation_status']['is_met'] ?? null) ? $skkm['graduation_status']['is_met'] : null,
            'status' => $this->status($sourceStatus, $found && $validPoints !== null),
        ];
    }

    private function status(string $sourceStatus, bool $hasValue): string
    {
        return match (true) {
            $sourceStatus === 'pending', $sourceStatus === 'failed' => $sourceStatus,
            $hasValue => 'ready',
            default => 'unavailable',
        };
    }

    private function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }
}
