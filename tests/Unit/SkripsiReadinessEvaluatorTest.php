<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AcademicProgressCalculator;
use App\Services\SkripsiReadinessEvaluator;
use Tests\TestCase;

class SkripsiReadinessEvaluatorTest extends TestCase
{
    private const THRESHOLDS = ['min_sks' => ['D3' => 90, 'S1' => 130], 'min_ipk' => 2.75, 'max_d' => 2, 'allow_e' => false];

    public function test_the_minimum_sks_follows_the_students_level(): void
    {
        $grades = $this->grades(sks: 100, ipk: 3.2);

        $s1 = $this->evaluate($grades, jenjang: 'S1');
        $d3 = $this->evaluate($grades, jenjang: 'D3');

        $this->assertSame('failed', $this->item($s1, 'sks')['status']);
        $this->assertSame(130, $this->item($s1, 'sks')['target']);
        $this->assertSame(30, $s1['sks_progress']['remaining']);
        $this->assertSame('passed', $this->item($d3, 'sks')['status']);
        $this->assertSame(90, $d3['thresholds']['min_sks']);
        $this->assertSame('portal', $d3['jenjang_source']);
    }

    public function test_an_unknown_level_uses_s1_and_says_so(): void
    {
        $result = $this->evaluate($this->grades(sks: 100, ipk: 3.2), jenjang: '');

        $this->assertSame('S1', $result['jenjang']);
        $this->assertSame('default', $result['jenjang_source']);
        $this->assertSame(130, $result['thresholds']['min_sks']);
    }

    public function test_an_ipk_exactly_at_the_minimum_passes(): void
    {
        $atMinimum = $this->evaluate($this->grades(sks: 140, ipk: 2.75));
        $below = $this->evaluate($this->grades(sks: 140, ipk: 2.74));

        $this->assertSame('passed', $this->item($atMinimum, 'ipk')['status']);
        $this->assertSame('failed', $this->item($below, 'ipk')['status']);
        $this->assertStringContainsString('0,01', (string) $this->item($below, 'ipk')['hint']);
        $this->assertSame('ipk_near_minimum', $atMinimum['insights']['warnings'][0]['key']);
    }

    public function test_a_third_d_fails_and_two_ds_warn(): void
    {
        $twoDs = $this->evaluate($this->grades(courses: [['MK01', 'D', 1.0], ['MK02', 'D', 1.0], ['MK03', 'A', 4.0]]));
        $threeDs = $this->evaluate($this->grades(courses: [['MK01', 'D', 1.0], ['MK02', 'D', 1.0], ['MK03', 'D', 1.0]]));

        $this->assertSame('passed', $this->item($twoDs, 'nilai_d')['status']);
        $this->assertContains('d_at_limit', array_column($twoDs['insights']['warnings'], 'key'));
        $this->assertSame('failed', $this->item($threeDs, 'nilai_d')['status']);
        $this->assertSame(3, $this->item($threeDs, 'nilai_d')['current']);
        $this->assertCount(3, $threeDs['courses']['d']);
        $this->assertFalse($threeDs['summary']['academic_ready']);
    }

    public function test_any_e_fails(): void
    {
        $result = $this->evaluate($this->grades(courses: [['MK01', 'E', 0.0], ['MK02', 'A', 4.0]]));

        $this->assertSame('failed', $this->item($result, 'nilai_e')['status']);
        $this->assertSame(1, $this->item($result, 'nilai_e')['current']);
        $this->assertSame('MK01', $result['courses']['e'][0]['code']);
    }

    public function test_a_retaken_course_counts_with_its_best_grade(): void
    {
        $result = $this->evaluate($this->grades(semesters: [
            $this->semester(1, [$this->course('MK01', 'D', 1.0), $this->course('MK02', 'E', 0.0)]),
            $this->semester(2, [$this->course('MK01', 'B', 3.0), $this->course('MK02', 'C', 2.0)]),
        ]));

        $this->assertSame(0, $this->item($result, 'nilai_d')['current']);
        $this->assertSame(0, $this->item($result, 'nilai_e')['current']);
        $this->assertSame('passed', $this->item($result, 'nilai_e')['status']);
    }

    public function test_sks_and_ipk_come_from_the_official_summary(): void
    {
        $grades = $this->grades(sks: 120, ipk: 3.1);
        $grades['summary']['official'] = ['ipk' => 3.42, 'sks_total' => 131];

        $result = $this->evaluate($grades);

        $this->assertSame(131, $this->item($result, 'sks')['current']);
        $this->assertSame('passed', $this->item($result, 'sks')['status']);
        $this->assertSame(3.42, $this->item($result, 'ipk')['current']);
        $this->assertSame(100.0, $result['summary']['progress_percent']);
    }

    public function test_conversion_sks_count_towards_the_minimum_sks(): void
    {
        $grades = $this->grades(sks: 108);
        $grades['summary']['conversion_sks'] = 66;

        $this->assertSame(108, $this->item($this->evaluate($grades), 'sks')['current']);

        $grades['summary']['official'] = ['ipk' => 3.21, 'sks_total' => 42];

        $this->assertSame(108, $this->item($this->evaluate($grades), 'sks')['current']);
    }

    public function test_missing_grades_leave_the_academic_items_unknown(): void
    {
        foreach ([[], ['found' => false, 'semesters' => [], 'summary' => ['ipk' => null, 'sks_passed' => 0]]] as $grades) {
            $result = $this->evaluate($grades);

            foreach (['sks', 'ipk', 'nilai_d', 'nilai_e'] as $key) {
                $this->assertSame('unknown', $this->item($result, $key)['status'], $key);
            }

            $this->assertFalse($result['grades_available']);
            $this->assertNull($result['summary']['academic_ready']);
            $this->assertNull($result['summary']['progress_percent']);
        }
    }

    public function test_default_thresholds_fill_what_simasita_does_not_state(): void
    {
        config()->set('portal.thesis.default_thresholds', ['min_sks' => ['D3' => 88, 'S1' => 118], 'min_ipk' => 2.25, 'max_d' => 1, 'allow_e' => false]);

        $result = (new SkripsiReadinessEvaluator(new AcademicProgressCalculator))->evaluate([], $this->grades(sks: 120, ipk: 2.3), [], 'S1');

        $this->assertSame(['min_sks' => 118, 'min_ipk' => 2.25, 'max_d' => 1, 'allow_e' => false], $result['thresholds']);
        $this->assertSame('passed', $this->item($result, 'sks')['status']);
    }

    public function test_finance_fails_only_with_an_outstanding_bill(): void
    {
        $outstanding = $this->evaluate($this->grades(), payments: ['outstanding_summary' => ['status' => 'outstanding', 'total_amount_label' => 'Rp 1.500.000']]);
        $clear = $this->evaluate($this->grades(), payments: ['outstanding_summary' => ['status' => 'clear']]);
        $missing = $this->evaluate($this->grades(), payments: []);

        $this->assertSame('failed', $this->item($outstanding, 'keuangan')['status']);
        $this->assertSame('Rp 1.500.000', $this->item($outstanding, 'keuangan')['current']);
        $this->assertSame('unknown', $this->item($clear, 'keuangan')['status']);
        $this->assertSame('unknown', $this->item($missing, 'keuangan')['status']);
        $this->assertSame('Data keuangan belum tersinkron.', $this->item($missing, 'keuangan')['hint']);
    }

    public function test_exam_preparation_items_are_manual(): void
    {
        $result = $this->evaluate($this->grades());
        $manual = array_values(array_filter($result['checklist_items'], static fn (array $item): bool => $item['kind'] === 'manual'));

        $this->assertSame(['lembar_pengesahan', 'naskah', 'jadwal_ujian'], array_column($manual, 'key'));
        $this->assertSame(['manual'], array_values(array_unique(array_column($manual, 'status'))));
        $this->assertSame(3, $result['summary']['manual']);
    }

    public function test_a_ready_student_is_academically_ready_with_an_estimate_of_zero(): void
    {
        $result = $this->evaluate($this->grades(sks: 135, ipk: 3.5));

        $this->assertTrue($result['summary']['academic_ready']);
        $this->assertSame(0, $result['insights']['semesters_to_min_sks']);
        $this->assertSame([], $result['insights']['warnings']);
    }

    public function test_the_estimate_divides_the_remaining_sks_by_the_average_load(): void
    {
        $grades = $this->grades(sks: 100, ipk: 3.2);
        $grades['summary']['progress']['average_sks_per_semester'] = 20.0;

        $result = $this->evaluate($grades);

        $this->assertSame(2, $result['insights']['semesters_to_min_sks']);
        $this->assertStringContainsString('2 semester', (string) $this->item($result, 'sks')['hint']);
    }

    /**
     * @param  array<string, mixed>  $grades
     * @param  array<string, mixed>  $payments
     * @return array<string, mixed>
     */
    private function evaluate(array $grades, array $payments = ['outstanding_summary' => ['status' => 'clear']], string $jenjang = 'S1'): array
    {
        return (new SkripsiReadinessEvaluator(new AcademicProgressCalculator))->evaluate(self::THRESHOLDS, $grades, $payments, $jenjang);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function item(array $result, string $key): array
    {
        foreach ($result['checklist_items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail("Checklist item [{$key}] is missing.");
    }

    /**
     * A `grades` snapshot: the summary the grades resolver chose plus KHS semesters
     *
     * @param  list<array{0: string, 1: string, 2: float}>|null  $courses
     * @param  list<array<string, mixed>>|null  $semesters
     * @return array<string, mixed>
     */
    private function grades(int $sks = 120, float $ipk = 3.0, ?array $courses = null, ?array $semesters = null): array
    {
        $semesters ??= [$this->semester(1, array_map(
            fn (array $course): array => $this->course(...$course),
            $courses ?? [['MK01', 'A', 4.0], ['MK02', 'B', 3.0]]
        ))];

        return [
            'found' => true,
            'semesters' => $semesters,
            'summary' => [
                'official' => null,
                'ipk' => $ipk,
                'sks_passed' => $sks,
                'source' => 'computed',
                'progress' => ['average_sks_per_semester' => null],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $courses
     * @return array<string, mixed>
     */
    private function semester(int $number, array $courses): array
    {
        return ['label' => 'KHS Semester : '.$number, 'semester' => $number, 'is_regular' => true, 'courses' => $courses, 'ips' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function course(string $code, string $letter, float $weight): array
    {
        return [
            'code' => $code,
            'name' => 'Mata Kuliah '.$code,
            'sks' => 3,
            'components' => [],
            'score' => null,
            'letter' => $letter,
            'weight' => $weight,
            'quality_points' => 3 * $weight,
            'is_graded' => true,
        ];
    }
}
