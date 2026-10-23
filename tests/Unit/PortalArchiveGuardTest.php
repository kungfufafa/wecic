<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalArchiveGuard;
use Tests\TestCase;

class PortalArchiveGuardTest extends TestCase
{
    public function test_a_graded_course_lost_while_a_new_course_appears_is_a_finding(): void
    {
        $trusted = $this->grades(['Ganjil 2025/2026' => ['IF101' => 'A', 'IF102' => 'B']]);
        $candidate = $this->grades(['Ganjil 2025/2026' => ['IF102' => 'B'], 'Genap 2025/2026' => ['IF201' => '']]);

        $findings = app(PortalArchiveGuard::class)->inspect('grades', $trusted, $candidate);

        $this->assertSame([[
            'rule' => 'values',
            'label' => 'Nilai huruf',
            'message' => 'Nilai huruf hilang pada 1 data: Ganjil 2025/2026|IF101 (A).',
        ]], $findings);
    }

    public function test_a_course_without_a_grade_yet_may_disappear(): void
    {
        $trusted = $this->grades(['Ganjil 2025/2026' => ['IF101' => 'A', 'IF102' => '']]);
        $candidate = $this->grades(['Ganjil 2025/2026' => ['IF101' => 'A', 'IF103' => '']]);

        $this->assertSame([], app(PortalArchiveGuard::class)->inspect('grades', $trusted, $candidate));
    }

    public function test_a_changed_grade_and_a_lost_one_are_both_named(): void
    {
        $trusted = $this->grades(['Ganjil 2025/2026' => ['IF101' => 'A', 'IF102' => 'B', 'IF103' => 'A']]);
        $candidate = $this->grades(['Ganjil 2025/2026' => ['IF101' => 'C', 'IF103' => 'A', 'IF104' => '']]);

        $findings = app(PortalArchiveGuard::class)->inspect('grades', $trusted, $candidate);

        $this->assertSame(
            'Nilai huruf berubah pada 1 data: Ganjil 2025/2026|IF101: A → C. Nilai huruf hilang pada 1 data: Ganjil 2025/2026|IF102 (B).',
            $findings[0]['message']
        );
        $this->assertCount(1, $findings);
    }

    public function test_a_payment_whose_reference_changed_is_not_skipped(): void
    {
        $trusted = ['summary' => ['history_available' => true], 'payment_history' => [
            ['paid_at' => '2026-01-05', 'reference' => 'R1', 'installment_stage' => '1', 'study_year' => '2025', 'amount' => 1500000],
        ]];
        $candidate = ['summary' => ['history_available' => true], 'payment_history' => [
            ['paid_at' => '2026-01-05', 'reference' => 'R1-X', 'installment_stage' => '1', 'study_year' => '2025', 'amount' => 150000],
        ]];

        $findings = app(PortalArchiveGuard::class)->inspect('payments', $trusted, $candidate);

        $this->assertSame(['Nominal pembayaran hilang pada 1 data: 2026-01-05|R1|1|2025 (1500000).'], array_column($findings, 'message'));
    }

    public function test_only_records_whose_value_the_guard_protects_count_as_lost(): void
    {
        $trusted = $this->classDetail([1 => 'present', 2 => 'absent']);
        $withoutAbsent = $this->classDetail([1 => 'present', 3 => 'present']);
        $withoutPresent = $this->classDetail([2 => 'absent', 3 => 'present']);

        $this->assertSame([], app(PortalArchiveGuard::class)->inspect('class_detail', $trusted, $withoutAbsent));
        $this->assertSame(
            ['Status presensi pertemuan hilang pada 1 data: 1 (Hadir).'],
            array_column(app(PortalArchiveGuard::class)->inspect('class_detail', $trusted, $withoutPresent), 'message')
        );
    }

    public function test_many_lost_records_are_summarised(): void
    {
        $trusted = $this->grades(['Ganjil 2025/2026' => ['IF101' => 'A', 'IF102' => 'B', 'IF103' => 'A', 'IF104' => 'B', 'IF105' => 'C']]);
        $candidate = $this->grades(['Ganjil 2025/2026' => ['IF105' => 'C'], 'Genap 2025/2026' => ['IF201' => '', 'IF202' => '', 'IF203' => '', 'IF204' => '']]);

        $findings = app(PortalArchiveGuard::class)->inspect('grades', $trusted, $candidate);

        $this->assertSame(
            'Nilai huruf hilang pada 4 data: Ganjil 2025/2026|IF101 (A); Ganjil 2025/2026|IF102 (B); Ganjil 2025/2026|IF103 (A) (dan 1 lainnya).',
            $findings[0]['message']
        );
    }

    /**
     * @param  array<string, array<string, string>>  $semesters  Label => course code => letter.
     * @return array<string, mixed>
     */
    private function grades(array $semesters): array
    {
        return [
            'found' => true,
            'semesters' => array_map(static fn (string $label, array $courses): array => [
                'label' => $label,
                'courses' => array_map(static fn (string $code, string $letter): array => [
                    'code' => $code,
                    'sks' => 3,
                    'letter' => $letter,
                ], array_keys($courses), $courses),
            ], array_keys($semesters), $semesters),
        ];
    }

    /**
     * @param  array<int, string>  $statuses  Meeting number => status.
     * @return array<string, mixed>
     */
    private function classDetail(array $statuses): array
    {
        return [
            'content_type' => 'students_list',
            'attendance_info' => [
                'meetings' => array_map(static fn (int $number, string $status): array => [
                    'meeting_number' => $number,
                    'status' => $status,
                ], array_keys($statuses), $statuses),
            ],
            'assignment_history' => ['assignments' => []],
        ];
    }
}
