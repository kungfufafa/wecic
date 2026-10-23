<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AcademicProgressCalculator;
use App\Services\Extractors\KhsExtractor;
use App\Services\PortalSessionManager;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class AcademicProgressCalculatorTest extends TestCase
{
    public function test_it_summarizes_regular_semesters_with_the_best_attempt_of_each_course(): void
    {
        $summary = $this->calculator()->summarize($this->studentSemesters());

        $this->assertSame(3.1, $summary['ipk']);
        $this->assertSame(20, $summary['sks_total']);
        $this->assertSame(20, $summary['sks_passed']);
        $this->assertSame(22, $summary['sks_attempted']);
        $this->assertSame(62.0, $summary['quality_points_total']);
        $this->assertSame(8, $summary['course_count']);
        $this->assertSame(['A' => 3, 'B' => 3, 'C' => 1, 'D' => 1, 'E' => 0], $summary['letter_counts']);
        $this->assertSame(['MK1004'], array_column($summary['d_courses'], 'code'));
        $this->assertSame([], $summary['e_courses']);
        $this->assertSame(['MK1004', 'MK2003'], array_column($summary['low_grade_courses'], 'code'));
        $this->assertSame(2, $summary['graded_semester_count']);
        $this->assertSame(10.0, $summary['average_sks_per_semester']);
        $this->assertSame(['T'], $summary['excluded_semesters']);
        $this->assertSame('Sangat Memuaskan', $summary['predicate_estimate']);
    }

    public function test_a_retaken_course_counts_once_with_its_attempt_history(): void
    {
        $summary = $this->calculator()->summarize($this->studentSemesters());

        $this->assertCount(1, $summary['repeated_courses']);
        $this->assertSame([
            'code' => 'MK1003',
            'name' => 'Mata Kuliah Tiga',
            'sks' => 2,
            'letter' => 'B',
            'weight' => 3.0,
            'quality_points' => 6.0,
            'semester' => 2,
            'attempt_count' => 2,
            'attempts' => [
                ['semester' => 1, 'letter' => 'E', 'weight' => 0.0],
                ['semester' => 2, 'letter' => 'B', 'weight' => 3.0],
            ],
        ], $summary['repeated_courses'][0]);
    }

    public function test_the_best_attempt_wins_even_when_a_later_attempt_is_worse(): void
    {
        $transcript = $this->calculator()->transcript([
            $this->semester(2, [$this->course('MK1001', 3, 'C', 2)]),
            $this->semester(1, [$this->course('mk1001', 3, 'B', 3)]),
        ]);

        $this->assertCount(1, $transcript);
        $this->assertSame('B', $transcript[0]['letter']);
        $this->assertSame(1, $transcript[0]['semester']);
        $this->assertSame(2, $transcript[0]['attempt_count']);
    }

    public function test_an_equal_retake_keeps_the_latest_attempt(): void
    {
        $transcript = $this->calculator()->transcript([
            $this->semester(1, [$this->course('MK1001', 3, 'B', 3)]),
            $this->semester(3, [$this->course('MK1001', 3, 'B', 3)]),
        ]);

        $this->assertSame(3, $transcript[0]['semester']);
    }

    public function test_courses_without_a_code_are_deduplicated_by_name(): void
    {
        $transcript = $this->calculator()->transcript([
            $this->semester(1, [$this->course('', 2, 'D', 1, 'Mata Kuliah Tanpa Kode')]),
            $this->semester(2, [$this->course('', 2, 'A', 4, 'mata kuliah tanpa kode')]),
        ]);

        $this->assertCount(1, $transcript);
        $this->assertSame('A', $transcript[0]['letter']);
    }

    public function test_an_e_grade_not_yet_retaken_counts_in_ipk_but_not_in_sks_passed(): void
    {
        $summary = $this->calculator()->summarize([
            $this->semester(1, [
                $this->course('MK1001', 3, 'A', 4),
                $this->course('MK1002', 2, 'E', 0),
            ]),
        ]);

        $this->assertSame(5, $summary['sks_total']);
        $this->assertSame(3, $summary['sks_passed']);
        $this->assertSame(2.4, $summary['ipk']);
        $this->assertSame(['MK1002'], array_column($summary['e_courses'], 'code'));
        $this->assertSame([], $summary['d_courses']);
        $this->assertSame(1, $summary['letter_counts']['E']);
    }

    public function test_semester_t_is_left_out_of_every_figure(): void
    {
        $summary = $this->calculator()->summarize([
            $this->semester(1, [$this->course('MK1001', 3, 'B', 3)]),
            $this->semester('T', [$this->course('MK9001', 4, 'A', 4)]),
        ]);

        $this->assertSame(3.0, $summary['ipk']);
        $this->assertSame(3, $summary['sks_total']);
        $this->assertSame(1, $summary['course_count']);
        $this->assertSame([1], array_column($summary['ips_series'], 'semester'));
        $this->assertSame(3.0, $summary['average_sks_per_semester']);
        $this->assertSame(['T'], $summary['excluded_semesters']);
    }

    public function test_conversion_credits_count_each_ungraded_course_once(): void
    {
        $credits = $this->calculator()->conversionCredits([
            ['code' => 'KV001', 'name' => 'Konversi Satu', 'sks' => 3, 'semester' => 1],
            ['code' => ' kv001', 'name' => 'Konversi Satu', 'sks' => 3, 'semester' => 1],
            ['code' => 'mk1002', 'name' => 'Mata Kuliah Dua', 'sks' => 3, 'semester' => null],
            ['code' => 'MK9001', 'name' => 'Dinilai di kartu T', 'sks' => 4, 'semester' => null],
            ['code' => 'MK2003', 'name' => 'Sedang diulang', 'sks' => 2, 'semester' => null],
            ['code' => '', 'name' => 'Tanpa Kode', 'sks' => 2, 'semester' => null],
            ['code' => 'KV002', 'name' => '', 'sks' => 3, 'semester' => null],
            ['code' => 'KV003', 'name' => 'Tanpa SKS', 'sks' => null, 'semester' => null],
            ['code' => '', 'name' => '', 'sks' => 3, 'semester' => null],
            'bukan-baris',
        ], [
            $this->semester(3, [$this->course('MK2003', 2, null, null)]),
            $this->semester(1, [$this->course('MK1002', 3, 'B', 3)]),
            $this->semester('T', [$this->course('MK9001', 4, 'A', 4)]),
        ]);

        $this->assertSame([
            ['code' => 'KV001', 'name' => 'Konversi Satu', 'sks' => 3],
            ['code' => 'MK2003', 'name' => 'Sedang diulang', 'sks' => 2],
            ['code' => '', 'name' => 'Tanpa Kode', 'sks' => 2],
            ['code' => 'KV002', 'name' => null, 'sks' => 3],
        ], $credits['courses']);
        $this->assertSame(4, $credits['course_count']);
        $this->assertSame(10, $credits['sks']);
        $this->assertSame(['MK1002', 'MK9001'], $credits['already_graded_codes']);
    }

    public function test_graded_krs_courses_are_matched_against_the_latest_semester_only(): void
    {
        $krsCourses = [
            ['code' => 'mk2001', 'name' => 'Mata Kuliah Lima', 'sks' => 3],
            ['code' => 'MK2001', 'name' => 'Mata Kuliah Lima', 'sks' => 3],
            ['code' => 'MK2002', 'name' => 'Mata Kuliah Enam : Pengantar', 'sks' => 3],
            ['code' => 'MK2003', 'name' => 'Mata Kuliah Tujuh', 'sks' => 2],
            ['code' => 'MK1003', 'name' => 'Mata Kuliah Tiga', 'sks' => 2],
            ['code' => 'MK2005', 'name' => 'Mata Kuliah Tambahan', 'sks' => 2],
            'not a course',
        ];

        // The latest KHS card (semester 3) is still empty: a new KRS, nothing graded yet.
        $this->assertSame(['courses' => 0, 'sks' => 0], $this->calculator()->gradedKrsCourses($krsCourses, $this->studentSemesters()));

        // Without that card the latest is semester 2, the semester of this KRS:
        // each of its graded courses matches once; the one added late has no grade.
        $graded = array_values(array_filter($this->studentSemesters(), static fn (array $semester): bool => $semester['semester'] !== 3));
        $this->assertSame(['courses' => 4, 'sks' => 10], $this->calculator()->gradedKrsCourses($krsCourses, $graded));
        $this->assertSame(['courses' => 0, 'sks' => 0], $this->calculator()->gradedKrsCourses([], $graded));
    }

    public function test_a_course_retaken_in_a_new_krs_is_not_graded_by_the_previous_card(): void
    {
        $previous = $this->semester(5, [
            $this->course('IF501', 3, 'A', 4),
            $this->course('IF502', 3, 'E', 0),
            $this->course('IF503', 3, 'B', 3),
        ]);
        $newKrs = [
            ['code' => 'IF601', 'name' => 'Mata Kuliah Baru Satu', 'sks' => 3],
            ['code' => 'IF602', 'name' => 'Mata Kuliah Baru Dua', 'sks' => 3],
            ['code' => 'IF502', 'name' => 'Mata Kuliah Contoh', 'sks' => 3],
        ];

        // The new card is not out yet: the latest card is still semester 5, but this KRS is another semester.
        $this->assertSame(['courses' => 0, 'sks' => 0], $this->calculator()->gradedKrsCourses($newKrs, [$previous]));
        $this->assertSame(['courses' => 0, 'sks' => 0], $this->calculator()->gradedKrsCourses([$newKrs[2]], [$previous]));

        // Most of the previous card retaken at once is still a new semester.
        $retakes = [
            ['code' => 'IF501', 'sks' => 3],
            ['code' => 'IF502', 'sks' => 3],
            ['code' => 'IF503', 'sks' => 3],
            ['code' => 'IF601', 'sks' => 3],
            ['code' => 'IF602', 'sks' => 3],
        ];
        $this->assertSame(['courses' => 0, 'sks' => 0], $this->calculator()->gradedKrsCourses($retakes, [$previous]));

        // The KRS of semester 5 itself still shown after its grades are out.
        $sameKrs = [
            ['code' => 'IF501', 'sks' => 3],
            ['code' => 'IF502', 'sks' => 3],
            ['code' => 'IF503', 'sks' => 3],
        ];
        $this->assertSame(['courses' => 3, 'sks' => 9], $this->calculator()->gradedKrsCourses($sameKrs, [$previous]));
    }

    public function test_conversion_credits_without_courses_are_zero(): void
    {
        $this->assertSame(
            ['courses' => [], 'course_count' => 0, 'sks' => 0, 'already_graded_codes' => []],
            $this->calculator()->conversionCredits([], $this->studentSemesters())
        );
    }

    public function test_a_semester_flagged_non_regular_is_excluded_even_with_a_number(): void
    {
        $semester = $this->semester(4, [$this->course('MK4001', 3, 'A', 4)]);
        $semester['is_regular'] = false;

        $this->assertNull($this->calculator()->summarize([$semester])['ipk']);
    }

    public function test_an_empty_running_semester_is_ignored_and_the_latest_graded_ips_is_reported(): void
    {
        $summary = $this->calculator()->summarize($this->studentSemesters());

        $this->assertSame([1, 2], array_column($summary['ips_series'], 'semester'));
        $this->assertSame([
            'semester' => 2,
            'ips' => 3.25,
            'previous_semester' => 1,
            'previous_ips' => 2.3,
            'delta' => 0.95,
            'is_complete' => true,
        ], $summary['latest_ips']);
    }

    public function test_a_partly_graded_running_semester_is_not_counted_as_a_whole_semester(): void
    {
        $semesters = [];

        foreach ([1, 2, 3, 4] as $number) {
            $semesters[] = $this->semester($number, array_map(
                fn (int $index): array => $this->course(sprintf('MK%d%02d', $number, $index), 3, 'B', 3),
                range(1, 7)
            ));
        }

        $running = array_map(fn (int $index): array => $this->course(sprintf('MK5%02d', $index), 3, null, null), range(1, 7));
        $running[0] = $this->course('MK501', 3, 'A', 4);
        $semesters[] = $this->semester(5, $running);

        $summary = $this->calculator()->summarize($semesters);

        $this->assertSame(87, $summary['sks_passed']);
        $this->assertSame(5, $summary['graded_semester_count']);
        $this->assertFalse($summary['ips_series'][4]['is_complete']);
        // 84 SKS over the four whole semesters, not 87 over five.
        $this->assertSame(21.0, $summary['average_sks_per_semester']);
        $this->assertSame(3, $this->calculator()->progress(87, 144, $summary['average_sks_per_semester'])['projected_semesters_remaining']);
        // The latest IPS is that of the last whole semester, not one course of the running one.
        $this->assertSame([
            'semester' => 4,
            'ips' => 3.0,
            'previous_semester' => 3,
            'previous_ips' => 3.0,
            'delta' => 0.0,
            'is_complete' => true,
        ], $summary['latest_ips']);
    }

    public function test_a_partly_graded_first_semester_is_still_reported_but_marked_incomplete(): void
    {
        $summary = $this->calculator()->summarize([
            $this->semester(1, [$this->course('MK101', 3, 'A', 4), $this->course('MK102', 3, null, null)]),
        ]);

        $this->assertNull($summary['average_sks_per_semester']);
        $this->assertSame(1, $summary['latest_ips']['semester']);
        $this->assertSame(4.0, $summary['latest_ips']['ips']);
        $this->assertFalse($summary['latest_ips']['is_complete']);
        $this->assertNull($summary['latest_ips']['delta']);
    }

    public function test_an_incomplete_past_semester_still_counts_once_a_later_one_is_graded(): void
    {
        $summary = $this->calculator()->summarize([
            $this->semester(1, [$this->course('MK101', 3, 'B', 3), $this->course('MK102', 3, null, null)]),
            $this->semester(2, [$this->course('MK201', 3, 'A', 4), $this->course('MK202', 3, 'A', 4)]),
        ]);

        $this->assertSame(4.5, $summary['average_sks_per_semester']);
        $this->assertSame(2, $summary['latest_ips']['semester']);
        $this->assertSame(1, $summary['latest_ips']['previous_semester']);
    }

    public function test_rows_waiting_for_grades_are_ignored(): void
    {
        $pending = $this->course('MK3001', 3, null, null);
        $pending['is_graded'] = false;
        $placeholder = $this->course('MK3002', 2, 'T', 0);
        $placeholder['is_graded'] = false;

        $summary = $this->calculator()->summarize([
            $this->semester(3, [$this->course('MK3003', 2, 'A', 4), $pending, $placeholder]),
        ]);

        $this->assertSame(2, $summary['sks_total']);
        $this->assertSame(4.0, $summary['ipk']);
        $this->assertSame(2, $summary['sks_attempted']);
        $this->assertFalse($summary['ips_series'][0]['is_complete']);
        $this->assertSame(1, $summary['ips_series'][0]['graded_count']);
        $this->assertSame(3, $summary['ips_series'][0]['course_count']);
    }

    public function test_a_row_of_zero_sks_never_keeps_the_latest_semester_partial(): void
    {
        $summary = $this->calculator()->summarize([
            $this->semester(1, [$this->course('MK101', 3, 'B', 3), $this->course('MK102', 3, 'B', 3)]),
            $this->semester(2, [$this->course('MK201', 3, 'A', 4), $this->course('MK202', 3, 'A', 4), $this->course('MK299', 0, null, null, 'Kuliah Umum')]),
        ]);

        $this->assertTrue($summary['ips_series'][1]['is_complete']);
        $this->assertSame(3, $summary['ips_series'][1]['course_count']);
        $this->assertSame(6.0, $summary['average_sks_per_semester']);
        $this->assertSame(2, $summary['latest_ips']['semester']);
        $this->assertSame(4.0, $summary['latest_ips']['ips']);
        $this->assertTrue($summary['latest_ips']['is_complete']);
    }

    public function test_no_grades_at_all_gives_nulls_instead_of_zeros(): void
    {
        $summary = $this->calculator()->summarize([$this->semester(1, [], 0.0)]);

        $this->assertNull($summary['ipk']);
        $this->assertSame(0, $summary['sks_total']);
        $this->assertSame(0.0, $summary['quality_points_total']);
        $this->assertSame([], $summary['ips_series']);
        $this->assertNull($summary['latest_ips']);
        $this->assertNull($summary['average_sks_per_semester']);
        $this->assertNull($summary['predicate_estimate']);
    }

    public function test_ipk_and_ips_are_rounded_half_up_to_two_decimals(): void
    {
        $courses = [$this->course('MK0001', 3, 'A', 4), $this->course('MK0002', 4, 'A', 4)];

        for ($index = 3; $index <= 13; $index++) {
            $courses[] = $this->course(sprintf('MK%04d', $index), 3, 'B', 3);
        }

        $summary = $this->calculator()->summarize([$this->semester(1, $courses)]);

        $this->assertSame(40, $summary['sks_total']);
        $this->assertSame(127.0, $summary['quality_points_total']);
        $this->assertSame(3.18, $summary['ipk']);
        $this->assertSame(3.18, $summary['ips_series'][0]['ips_computed']);
    }

    public function test_the_portal_ips_is_kept_and_a_different_computed_ips_is_flagged(): void
    {
        $series = $this->calculator()->ipsSeries([
            $this->semester(1, [$this->course('MK1001', 3, 'A', 4), $this->course('MK1002', 3, 'B', 3)], 3.4),
        ]);

        $this->assertSame(3.4, $series[0]['ips']);
        $this->assertSame(3.4, $series[0]['ips_portal']);
        $this->assertSame(3.5, $series[0]['ips_computed']);
        $this->assertTrue($series[0]['ips_mismatch']);
    }

    public function test_semester_ips_is_null_without_graded_courses(): void
    {
        $calculator = $this->calculator();

        $this->assertNull($calculator->semesterIps($this->semester(3, [])));
        $this->assertSame(2.3, $calculator->semesterIps($this->studentSemesters()[2]));
    }

    public function test_progress_projects_the_remaining_semesters(): void
    {
        $this->assertSame([
            'target_sks' => 144,
            'earned_sks' => 20,
            'remaining_sks' => 124,
            'progress_percent' => 13.9,
            'average_sks_per_semester' => 10.0,
            'projected_semesters_remaining' => 13,
        ], $this->calculator()->progress(20, 144, 10.0, 124));
    }

    public function test_progress_handles_missing_averages_and_finished_studies(): void
    {
        $calculator = $this->calculator();

        $this->assertNull($calculator->progress(20, 144, null)['projected_semesters_remaining']);
        $this->assertNull($calculator->progress(20, 144, 0.0)['projected_semesters_remaining']);

        $finished = $calculator->progress(150, 144, 20.0);

        $this->assertSame(0, $finished['remaining_sks']);
        $this->assertSame(0, $finished['projected_semesters_remaining']);
        $this->assertSame(100.0, $finished['progress_percent']);
    }

    public function test_the_target_sks_falls_back_to_config_then_to_the_default(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(144, $calculator->progress(20)['target_sks']);
        $this->assertSame(124, $calculator->progress(20)['remaining_sks']);

        config(['portal.academic.target_sks' => 146]);
        $this->assertSame(146, $calculator->progress(20)['target_sks']);

        config(['portal.academic.target_sks' => 0]);
        $this->assertSame(144, $calculator->defaultTargetSks());
    }

    public function test_simulate_target_returns_the_minimum_ips_rounded_up(): void
    {
        $simulation = $this->calculator()->simulateTarget(3.2, 62.0, 20, 24);

        $this->assertSame([
            'target_ipk' => 3.2,
            'current_ipk' => 3.1,
            'additional_sks' => 24,
            'required_ips' => 3.29,
            'achievable' => true,
            'already_reached' => false,
        ], $simulation);
        $this->assertGreaterThanOrEqual(3.2, (62.0 + 3.29 * 24) / 44);
    }

    public function test_simulate_target_beyond_four_is_not_achievable(): void
    {
        $simulation = $this->calculator()->simulateTarget(4.0, 62.0, 20, 20);

        $this->assertSame(4.9, $simulation['required_ips']);
        $this->assertFalse($simulation['achievable']);
    }

    public function test_simulate_target_already_reached_or_without_more_sks(): void
    {
        $calculator = $this->calculator();

        $kept = $calculator->simulateTarget(3.0, 62.0, 20, 20);
        $this->assertSame(2.9, $kept['required_ips']);
        $this->assertTrue($kept['already_reached']);

        $this->assertSame(0.0, $calculator->simulateTarget(1.0, 62.0, 20, 2)['required_ips']);

        $none = $calculator->simulateTarget(3.5, 62.0, 20, 0);
        $this->assertNull($none['required_ips']);
        $this->assertFalse($none['achievable']);

        $fresh = $calculator->simulateTarget(3.0, 0.0, 0, 20);
        $this->assertNull($fresh['current_ipk']);
        $this->assertSame(3.0, $fresh['required_ips']);
    }

    public function test_simulate_target_rejects_impossible_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator()->simulateTarget(4.5, 62.0, 20, 20);
    }

    public function test_simulate_target_rejects_negative_sks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator()->simulateTarget(3.0, 62.0, 20, -3);
    }

    public function test_graduation_targets_cover_each_predicate_threshold(): void
    {
        $targets = $this->calculator()->graduationTargets(62.0, 20, 124);

        $this->assertSame(['Memuaskan', 'Sangat Memuaskan', 'Pujian'], array_column($targets, 'predicate'));
        $this->assertSame([2.71, 3.0, 3.58], array_column($targets, 'required_ips'));
        $this->assertSame([true, true, true], array_column($targets, 'achievable'));
    }

    public function test_project_ipk_clamps_the_assumed_ips(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(3.32, $calculator->projectIpk(62.0, 20, 3.5, 24));
        $this->assertSame($calculator->projectIpk(62.0, 20, 4.0, 24), $calculator->projectIpk(62.0, 20, 9.0, 24));
        $this->assertNull($calculator->projectIpk(0.0, 0, 3.0, 0));
    }

    public function test_predicate_estimate_thresholds(): void
    {
        $calculator = $this->calculator();

        $this->assertSame('Pujian', $calculator->predicateEstimate(3.51));
        $this->assertSame('Sangat Memuaskan', $calculator->predicateEstimate(3.5));
        $this->assertSame('Sangat Memuaskan', $calculator->predicateEstimate(3.01));
        $this->assertSame('Memuaskan', $calculator->predicateEstimate(3.0));
        $this->assertSame('Memuaskan', $calculator->predicateEstimate(2.76));
        $this->assertNull($calculator->predicateEstimate(2.75));
        $this->assertNull($calculator->predicateEstimate(null));
    }

    public function test_the_semesters_parsed_from_the_raw_score_page_match_its_own_footers(): void
    {
        $semesters = (new KhsExtractor(Mockery::mock(PortalSessionManager::class)))
            ->parseKhsHtml((string) file_get_contents(base_path('tests/Fixtures/portal/sipandai_score_raw.html')))['semesters'];

        $calculator = $this->calculator();
        $summary = $calculator->summarize($semesters);

        $this->assertSame(3.21, $summary['ipk']);
        $this->assertSame(42, $summary['sks_total']);
        $this->assertSame(42, $summary['sks_passed']);
        $this->assertSame(135.0, $summary['quality_points_total']);
        $this->assertSame(16, $summary['course_count']);
        $this->assertSame(['A' => 7, 'B' => 5, 'C' => 4, 'D' => 0, 'E' => 0], $summary['letter_counts']);
        $this->assertSame(['T'], $summary['excluded_semesters']);
        $this->assertSame(2, $summary['graded_semester_count']);
        $this->assertSame(21.0, $summary['average_sks_per_semester']);
        $this->assertSame([], $summary['repeated_courses']);
        $this->assertSame(['SIX033203', 'SXE052206', 'UCX0102101', 'UCX0106102'], array_column($summary['low_grade_courses'], 'code'));

        foreach ($summary['ips_series'] as $point) {
            $semester = array_values(array_filter($semesters, static fn (array $semester): bool => $semester['semester'] === $point['semester']))[0];

            $this->assertSame($semester['ips'], $point['ips_portal']);
            $this->assertSame($semester['ips'], $point['ips_computed']);
            $this->assertSame($semester['total_sks'], $point['sks']);
            $this->assertSame($semester['total_quality_points'], $point['quality_points']);
            $this->assertSame($semester['ips'], $calculator->semesterIps($semester));
        }

        $this->assertSame([1, 2], array_column($summary['ips_series'], 'semester'));
        $this->assertNull($calculator->semesterIps($semesters[0]));
        $this->assertNull($calculator->semesterIps($semesters[3]));
        $this->assertSame(5, $calculator->progress(42, 144, $summary['average_sks_per_semester'], 102)['projected_semesters_remaining']);
    }

    private function calculator(): AcademicProgressCalculator
    {
        return new AcademicProgressCalculator;
    }

    /**
     * Same synthetic student as the KHS fixtures, newest semester first.
     *
     * @return list<array<string, mixed>>
     */
    private function studentSemesters(): array
    {
        return [
            $this->semester(3, [], 0.0),
            $this->semester(2, [
                $this->course('MK2001', 3, 'A', 4, 'Mata Kuliah Lima'),
                $this->course('MK2002', 3, 'B', 3, 'Mata Kuliah Enam : Pengantar'),
                $this->course('MK2003', 2, 'C', 2, 'Mata Kuliah Tujuh'),
                $this->course('MK1003', 2, 'B', 3, 'Mata Kuliah Tiga'),
                $this->course('MK2004', 2, 'A', 4, 'Mata Kuliah Delapan & Praktikum'),
            ], 3.25),
            $this->semester(1, [
                $this->course('MK1001', 3, 'A', 4, 'Mata Kuliah Satu'),
                $this->course('MK1002', 3, 'B', 3, 'Mata Kuliah Dua'),
                $this->course('MK1003', 2, 'E', 0, 'Mata Kuliah Tiga'),
                $this->course('MK1004', 2, 'D', 1, 'Mata Kuliah Empat'),
            ], 2.3),
            $this->semester('T', [], 0.0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $courses
     * @return array<string, mixed>
     */
    private function semester(int|string $number, array $courses, ?float $ips = null): array
    {
        return [
            'label' => 'KHS Semester : '.$number,
            'semester' => $number,
            'is_regular' => is_int($number),
            'courses' => $courses,
            'ips' => $ips,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function course(string $code, int $sks, ?string $letter, int|float|null $weight, string $name = 'Mata Kuliah Contoh'): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'sks' => $sks,
            'components' => [],
            'score' => null,
            'letter' => $letter,
            'weight' => $weight === null ? null : (float) $weight,
            'quality_points' => $weight === null ? null : $sks * (float) $weight,
            'is_graded' => $letter !== null && $weight !== null,
        ];
    }
}
