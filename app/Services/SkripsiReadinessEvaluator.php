<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Extractors\SkripsiExtractor;

/**
 * Thesis readiness checklist (F44) against the SiMASITA thresholds (F42)
 *
 * Pure: it reads snapshots it is given and never talks to the portal. SKS
 * and IPK come from the official cumulative summary (`grades.summary.official`,
 * falling back to the summary the grades resolver already chose); D and E
 * grades are counted by AcademicProgressCalculator over the best attempt of
 * every course (K8). Nothing is computed here that the calculator owns.
 *
 * Automatic items: `sks`, `ipk`, `nilai_d`, `nilai_e`, `keuangan`. Finance is
 * `failed` only with an outstanding bill; without one it stays `unknown`,
 * because the portal never states that administration is complete. Exam
 * preparation items are `manual`: the student ticks them on the device.
 *
 * @phpstan-type ChecklistItem array{
 *     key: string,
 *     kind: 'auto'|'manual',
 *     label: string,
 *     status: 'passed'|'failed'|'unknown'|'manual',
 *     current: int|float|string|null,
 *     target: int|float|string|null,
 *     hint: string|null
 * }
 */
class SkripsiReadinessEvaluator
{
    public const JENJANG_DEFAULT = 'S1';

    /**
     * IPK this close above the minimum earns an early warning.
     */
    private const IPK_WARNING_MARGIN = 0.1;

    private const IPK_EPSILON = 0.000001;

    /**
     * @var array<string, string>
     */
    private const MANUAL_ITEMS = [
        'lembar_pengesahan' => 'Lembar pengesahan ditandatangani pembimbing',
        'naskah' => 'Naskah skripsi (hardcopy dan softcopy)',
        'jadwal_ujian' => 'Jadwal ujian akhir dari program studi',
    ];

    public function __construct(private readonly AcademicProgressCalculator $calculator) {}

    /**
     * @param  array<string, mixed>  $thresholds  `thesis.thresholds` (min_sks per level, min_ipk, max_d, allow_e); missing values use the defaults
     * @param  array<string, mixed>  $grades  `grades` dataset, or [] when it is not synced yet
     * @param  array<string, mixed>  $payments  `payments` dataset, or [] when it is not synced yet
     * @param  string  $jenjang  "S1" or "D3" from SiMASITA; anything else falls back to S1
     * @return array{
     *     jenjang: 'D3'|'S1',
     *     jenjang_source: 'portal'|'default',
     *     thresholds: array{min_sks: int, min_ipk: float, max_d: int, allow_e: bool},
     *     checklist_items: list<ChecklistItem>,
     *     summary: array{passed: int, failed: int, unknown: int, manual: int, automatic_total: int, academic_ready: bool|null, progress_percent: float|null},
     *     sks_progress: array{current: int|null, target: int, remaining: int|null, percent: float|null},
     *     courses: array{d: list<array<string, mixed>>, e: list<array<string, mixed>>},
     *     insights: array{semesters_to_min_sks: int|null, average_sks_per_semester: float|null, warnings: list<array{key: string, message: string}>},
     *     grades_available: bool
     * }
     */
    public function evaluate(array $thresholds, array $grades, array $payments, string $jenjang): array
    {
        $normalizedJenjang = strtoupper(trim($jenjang));
        $jenjangKnown = in_array($normalizedJenjang, ['D3', 'S1'], true);
        $level = $jenjangKnown ? $normalizedJenjang : self::JENJANG_DEFAULT;
        $resolved = $this->resolveThresholds($thresholds, $level);

        $academic = $this->academicFigures($grades);
        $sks = $academic['sks'];
        $remaining = $sks === null ? null : max(0, $resolved['min_sks'] - $sks);
        $semestersToMin = $remaining !== null && $remaining > 0 && $academic['average_sks'] !== null && $academic['average_sks'] > 0
            ? (int) ceil($remaining / $academic['average_sks'])
            : ($remaining === 0 ? 0 : null);

        $items = [
            $this->sksItem($sks, $resolved['min_sks'], $remaining, $semestersToMin),
            $this->ipkItem($academic['ipk'], $resolved['min_ipk']),
            $this->dItem($academic['d_courses'], $resolved['max_d']),
            $this->eItem($academic['e_courses'], $resolved['allow_e']),
            $this->financeItem($payments),
        ];

        foreach (self::MANUAL_ITEMS as $key => $label) {
            $items[] = $this->item($key, 'manual', $label, 'manual', null, null, null);
        }

        $statuses = array_count_values(array_column($items, 'status'));
        $academicStatuses = array_column(array_slice($items, 0, 4), 'status');

        return [
            'jenjang' => $level,
            'jenjang_source' => $jenjangKnown ? 'portal' : 'default',
            'thresholds' => $resolved,
            'checklist_items' => $items,
            'summary' => [
                'passed' => $statuses['passed'] ?? 0,
                'failed' => $statuses['failed'] ?? 0,
                'unknown' => $statuses['unknown'] ?? 0,
                'manual' => $statuses['manual'] ?? 0,
                'automatic_total' => 5,
                'academic_ready' => match (true) {
                    in_array('failed', $academicStatuses, true) => false,
                    in_array('unknown', $academicStatuses, true) => null,
                    default => true,
                },
                'progress_percent' => $sks === null ? null : round(min(100, $sks / max(1, $resolved['min_sks']) * 100), 1),
            ],
            'sks_progress' => [
                'current' => $sks,
                'target' => $resolved['min_sks'],
                'remaining' => $remaining,
                'percent' => $sks === null ? null : round(min(100, $sks / max(1, $resolved['min_sks']) * 100), 1),
            ],
            'courses' => [
                'd' => $academic['d_courses'] ?? [],
                'e' => $academic['e_courses'] ?? [],
            ],
            'insights' => [
                'semesters_to_min_sks' => $semestersToMin,
                'average_sks_per_semester' => $academic['average_sks'],
                'warnings' => $this->warnings($academic, $resolved),
            ],
            'grades_available' => $academic['available'],
        ];
    }

    /**
     * @param  array<string, mixed>  $thresholds
     * @return array{min_sks: int, min_ipk: float, max_d: int, allow_e: bool}
     */
    private function resolveThresholds(array $thresholds, string $level): array
    {
        $defaults = SkripsiExtractor::defaultThresholds();
        $minSks = is_array($thresholds['min_sks'] ?? null) ? $thresholds['min_sks'] : [];

        return [
            'min_sks' => is_numeric($minSks[$level] ?? null) ? (int) $minSks[$level] : $defaults['min_sks'][$level],
            'min_ipk' => is_numeric($thresholds['min_ipk'] ?? null) ? (float) $thresholds['min_ipk'] : $defaults['min_ipk'],
            'max_d' => is_numeric($thresholds['max_d'] ?? null) ? (int) $thresholds['max_d'] : $defaults['max_d'],
            'allow_e' => is_bool($thresholds['allow_e'] ?? null) ? $thresholds['allow_e'] : $defaults['allow_e'],
        ];
    }

    /**
     * @param  array<string, mixed>  $grades
     * @return array{available: bool, sks: int|null, ipk: float|null, d_courses: list<array<string, mixed>>|null, e_courses: list<array<string, mixed>>|null, average_sks: float|null}
     */
    private function academicFigures(array $grades): array
    {
        $summary = is_array($grades['summary'] ?? null) ? $grades['summary'] : null;

        if ($summary === null || ($grades['found'] ?? false) !== true) {
            return ['available' => false, 'sks' => null, 'ipk' => null, 'd_courses' => null, 'e_courses' => null, 'average_sks' => null];
        }

        $official = is_array($summary['official'] ?? null) ? $summary['official'] : [];
        $sks = $official['sks_total'] ?? $summary['sks_passed'] ?? null;
        $ipk = $official['ipk'] ?? $summary['ipk'] ?? null;
        $semesters = array_values(array_filter(is_array($grades['semesters'] ?? null) ? $grades['semesters'] : [], 'is_array'));
        $computed = $this->calculator->summarize($semesters);
        $graded = $computed['course_count'] > 0;
        $average = $summary['progress']['average_sks_per_semester'] ?? $computed['average_sks_per_semester'];

        return [
            'available' => true,
            'sks' => is_numeric($sks) ? (int) $sks : null,
            'ipk' => is_numeric($ipk) ? (float) $ipk : null,
            'd_courses' => $graded ? $computed['d_courses'] : null,
            'e_courses' => $graded ? $computed['e_courses'] : null,
            'average_sks' => is_numeric($average) ? (float) $average : null,
        ];
    }

    /**
     * @return ChecklistItem
     */
    private function sksItem(?int $sks, int $target, ?int $remaining, ?int $semestersToMin): array
    {
        if ($sks === null) {
            return $this->item('sks', 'auto', 'SKS lulus', 'unknown', null, $target, 'Nilai belum tersinkron dari portal.');
        }

        if ($remaining === 0) {
            return $this->item('sks', 'auto', 'SKS lulus', 'passed', $sks, $target, null);
        }

        $estimate = $semestersToMin === null ? '' : " Perkiraan {$semestersToMin} semester lagi dengan rata-rata SKS-mu.";

        return $this->item('sks', 'auto', 'SKS lulus', 'failed', $sks, $target, "Kurang {$remaining} SKS lagi.{$estimate}");
    }

    /**
     * @return ChecklistItem
     */
    private function ipkItem(?float $ipk, float $minimum): array
    {
        if ($ipk === null) {
            return $this->item('ipk', 'auto', 'IPK', 'unknown', null, $minimum, 'IPK belum tersinkron dari portal.');
        }

        // An IPK exactly at the minimum meets it.
        if ($ipk + self::IPK_EPSILON >= $minimum) {
            return $this->item('ipk', 'auto', 'IPK', 'passed', $ipk, $minimum, null);
        }

        return $this->item('ipk', 'auto', 'IPK', 'failed', $ipk, $minimum, sprintf('Butuh kenaikan %s poin IPK. Coba simulasi target di halaman Transkrip.', number_format($minimum - $ipk, 2, ',', '.')));
    }

    /**
     * @param  list<array<string, mixed>>|null  $courses
     * @return ChecklistItem
     */
    private function dItem(?array $courses, int $maximum): array
    {
        if ($courses === null) {
            return $this->item('nilai_d', 'auto', 'Nilai D', 'unknown', null, $maximum, 'Nilai belum tersinkron dari portal.');
        }

        $count = count($courses);

        if ($count <= $maximum) {
            return $this->item('nilai_d', 'auto', 'Nilai D', 'passed', $count, $maximum, null);
        }

        return $this->item('nilai_d', 'auto', 'Nilai D', 'failed', $count, $maximum, sprintf('Ulang %d MK bernilai D di Semester Pendek atau semester berikutnya.', $count - $maximum));
    }

    /**
     * @param  list<array<string, mixed>>|null  $courses
     * @return ChecklistItem
     */
    private function eItem(?array $courses, bool $allowed): array
    {
        if ($courses === null) {
            return $this->item('nilai_e', 'auto', 'Nilai E', 'unknown', null, 0, 'Nilai belum tersinkron dari portal.');
        }

        $count = count($courses);

        if ($count === 0 || $allowed) {
            return $this->item('nilai_e', 'auto', 'Nilai E', 'passed', $count, 0, null);
        }

        return $this->item('nilai_e', 'auto', 'Nilai E', 'failed', $count, 0, 'Semua MK bernilai E harus diulang sampai lulus.');
    }

    /**
     * @param  array<string, mixed>  $payments
     * @return ChecklistItem
     */
    private function financeItem(array $payments): array
    {
        $summary = is_array($payments['outstanding_summary'] ?? null) ? $payments['outstanding_summary'] : [];

        if (($summary['status'] ?? null) === 'outstanding') {
            $amount = is_string($summary['total_amount_label'] ?? null) ? $summary['total_amount_label'] : null;

            return $this->item('keuangan', 'auto', 'Administrasi keuangan', 'failed', $amount, null, 'Masih ada tagihan. Lunasi dulu sebelum mendaftar skripsi.');
        }

        $hint = $payments === []
            ? 'Data keuangan belum tersinkron.'
            : 'Portal tidak menyatakan administrasi keuangan sudah beres; pastikan ke bagian keuangan.';

        return $this->item('keuangan', 'auto', 'Administrasi keuangan', 'unknown', null, null, $hint);
    }

    /**
     * @param  array{d_courses: list<array<string, mixed>>|null, ipk: float|null}  $academic
     * @param  array{min_ipk: float, max_d: int}  $thresholds
     * @return list<array{key: string, message: string}>
     */
    private function warnings(array $academic, array $thresholds): array
    {
        $warnings = [];
        $ipk = $academic['ipk'];

        if ($ipk !== null && $ipk + self::IPK_EPSILON >= $thresholds['min_ipk'] && $ipk - $thresholds['min_ipk'] < self::IPK_WARNING_MARGIN) {
            $warnings[] = ['key' => 'ipk_near_minimum', 'message' => 'IPK-mu tipis di atas batas minimal. Jaga nilai semester ini.'];
        }

        if ($academic['d_courses'] !== null && $thresholds['max_d'] > 0 && count($academic['d_courses']) === $thresholds['max_d']) {
            $warnings[] = ['key' => 'd_at_limit', 'message' => 'Nilai D sudah di batas maksimal. Satu nilai D lagi membuatmu belum memenuhi syarat.'];
        }

        return $warnings;
    }

    /**
     * @return ChecklistItem
     */
    private function item(string $key, string $kind, string $label, string $status, int|float|string|null $current, int|float|string|null $target, ?string $hint): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'label' => $label,
            'status' => $status,
            'current' => $current,
            'target' => $target,
            'hint' => $hint,
        ];
    }
}
