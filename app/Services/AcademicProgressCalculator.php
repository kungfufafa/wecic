<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Every academic calculation of MyCIC (K8): IPS, IPK, SKS, grade
 * distribution, D/E lists, study progress and target simulations
 *
 * Pure: it only reads the KHS semesters it is given (the shape produced by
 * KhsExtractor) and never talks to the portal. Rules:
 * - Only regular (numeric) semesters count; semester `T` and any other
 *   non-numeric label is left out of every figure (K16). The conversion
 *   courses of a KRS `T` only add SKS, through conversionCredits().
 * - A course taken more than once counts once, with its best attempt
 *   (highest weight; the latest attempt wins a tie), as the transcript does.
 * - IPS is per semester over every graded course of that semester.
 * - Only courses with a letter and a weight count as graded; empty rows of
 *   the running semester (KRS not validated, grades not out) are ignored.
 *
 * @phpstan-type Attempt array{semester: int, letter: string, weight: float}
 * @phpstan-type TranscriptRow array{
 *     code: string,
 *     name: string,
 *     sks: int,
 *     letter: string,
 *     weight: float,
 *     quality_points: float,
 *     semester: int,
 *     attempt_count: int,
 *     attempts: list<Attempt>
 * }
 * @phpstan-type IpsPoint array{
 *     semester: int,
 *     label: string,
 *     ips: float|null,
 *     ips_portal: float|null,
 *     ips_computed: float|null,
 *     ips_mismatch: bool,
 *     sks: int,
 *     quality_points: float,
 *     graded_count: int,
 *     course_count: int,
 *     is_complete: bool
 * }
 * @phpstan-type TargetSimulation array{
 *     target_ipk: float,
 *     current_ipk: float|null,
 *     additional_sks: int,
 *     required_ips: float|null,
 *     achievable: bool,
 *     already_reached: bool
 * }
 */
class AcademicProgressCalculator
{
    public const MAX_GRADE_WEIGHT = 4.0;

    public const DEFAULT_TARGET_SKS = 144;

    /**
     * Predicate thresholds (minimum IPK) of an S1 graduate, highest first.
     *
     * @var array<string, float>
     */
    private const PREDICATE_THRESHOLDS = [
        'Pujian' => 3.51,
        'Sangat Memuaskan' => 3.01,
        'Memuaskan' => 2.76,
    ];

    /**
     * Graduation SKS when the portal gives no "Sisa SKS" (config `portal.academic.target_sks`)
     */
    public function defaultTargetSks(): int
    {
        $target = (int) config('portal.academic.target_sks', self::DEFAULT_TARGET_SKS);

        return $target > 0 ? $target : self::DEFAULT_TARGET_SKS;
    }

    /**
     * @param  list<array<string, mixed>>  $semesters
     * @return array{
     *     ipk: float|null,
     *     sks_total: int,
     *     sks_passed: int,
     *     sks_attempted: int,
     *     quality_points_total: float,
     *     course_count: int,
     *     letter_counts: array<string, int>,
     *     d_courses: list<TranscriptRow>,
     *     e_courses: list<TranscriptRow>,
     *     low_grade_courses: list<TranscriptRow>,
     *     repeated_courses: list<TranscriptRow>,
     *     ips_series: list<IpsPoint>,
     *     latest_ips: array{semester: int, ips: float, previous_semester: int|null, previous_ips: float|null, delta: float|null, is_complete: bool}|null,
     *     graded_semester_count: int,
     *     average_sks_per_semester: float|null,
     *     excluded_semesters: list<string>,
     *     predicate_estimate: string|null
     * }
     */
    public function summarize(array $semesters): array
    {
        $transcript = $this->transcript($semesters);
        $series = $this->ipsSeries($semesters);
        $settledSeries = $this->settledSeries($series);
        $runningSemester = count($settledSeries) < count($series) ? $series[count($series) - 1]['semester'] : null;

        $sksTotal = 0;
        $sksPassed = 0;
        $runningSksPassed = 0;
        $qualityPoints = 0.0;
        $letterCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];

        foreach ($transcript as $row) {
            $sksTotal += $row['sks'];
            $qualityPoints += $row['quality_points'];
            $letterCounts[$row['letter']] = ($letterCounts[$row['letter']] ?? 0) + 1;

            if (! $this->isFailing($row['letter'], $row['weight'])) {
                $sksPassed += $row['sks'];

                if ($row['semester'] === $runningSemester) {
                    $runningSksPassed += $row['sks'];
                }
            }
        }

        $sksAttempted = 0;
        foreach ($this->attempts($semesters) as $attempt) {
            $sksAttempted += $attempt['sks'];
        }

        $gradedSemesterCount = count($series);
        $settledSemesterCount = count($settledSeries);
        $ipk = $sksTotal > 0 ? round($qualityPoints / $sksTotal, 2) : null;

        return [
            'ipk' => $ipk,
            'sks_total' => $sksTotal,
            'sks_passed' => $sksPassed,
            'sks_attempted' => $sksAttempted,
            'quality_points_total' => round($qualityPoints, 2),
            'course_count' => count($transcript),
            'letter_counts' => $letterCounts,
            'd_courses' => array_values(array_filter($transcript, fn (array $row): bool => $this->baseLetter($row['letter']) === 'D' && ! $this->isFailing($row['letter'], $row['weight']))),
            'e_courses' => array_values(array_filter($transcript, fn (array $row): bool => $this->isFailing($row['letter'], $row['weight']))),
            'low_grade_courses' => array_values(array_filter($transcript, static fn (array $row): bool => $row['weight'] <= 2.0)),
            'repeated_courses' => array_values(array_filter($transcript, static fn (array $row): bool => $row['attempt_count'] > 1)),
            'ips_series' => $series,
            'latest_ips' => $this->latestIps($series),
            'graded_semester_count' => $gradedSemesterCount,
            'average_sks_per_semester' => $settledSemesterCount > 0 ? round(($sksPassed - $runningSksPassed) / $settledSemesterCount, 2) : null,
            'excluded_semesters' => $this->excludedSemesters($semesters),
            'predicate_estimate' => $this->predicateEstimate($ipk),
        ];
    }

    /**
     * Best attempt of every graded course, oldest semester first
     *
     * @param  list<array<string, mixed>>  $semesters
     * @return list<TranscriptRow>
     */
    public function transcript(array $semesters): array
    {
        $best = [];
        $history = [];

        foreach ($this->attempts($semesters) as $attempt) {
            $key = $attempt['key'];
            $history[$key][] = [
                'semester' => $attempt['semester'],
                'letter' => $attempt['letter'],
                'weight' => $attempt['weight'],
            ];

            if (! isset($best[$key]) || $attempt['weight'] >= $best[$key]['weight']) {
                $best[$key] = $attempt;
            }
        }

        $rows = [];

        foreach ($best as $key => $attempt) {
            $rows[] = [
                'code' => $attempt['code'],
                'name' => $attempt['name'],
                'sks' => $attempt['sks'],
                'letter' => $attempt['letter'],
                'weight' => $attempt['weight'],
                'quality_points' => round($attempt['sks'] * $attempt['weight'], 2),
                'semester' => $attempt['semester'],
                'attempt_count' => count($history[$key]),
                'attempts' => $history[$key],
                'order' => $attempt['order'],
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['semester'], $left['order']] <=> [$right['semester'], $right['order']]);

        return array_map(static function (array $row): array {
            unset($row['order']);

            return $row;
        }, $rows);
    }

    /**
     * IPS of every regular semester that has at least one grade, oldest first
     *
     * Semesters without any grade are left out, so a chart has no false zero.
     * `is_complete` says every row that can get a grade has one; a row of 0
     * SKS never gets one, so it never keeps a semester partial.
     *
     * @param  list<array<string, mixed>>  $semesters
     * @return list<IpsPoint>
     */
    public function ipsSeries(array $semesters): array
    {
        $series = [];

        foreach ($this->regularSemesters($semesters) as $semester) {
            $courses = $this->gradedCourses($semester);

            if ($courses === []) {
                continue;
            }

            $sks = 0;
            $qualityPoints = 0.0;

            foreach ($courses as $course) {
                $sks += $course['sks'];
                $qualityPoints += $course['sks'] * $course['weight'];
            }

            $computed = $sks > 0 ? round($qualityPoints / $sks, 2) : null;
            $portal = is_numeric($semester['ips'] ?? null) ? (float) $semester['ips'] : null;
            $rows = is_array($semester['courses'] ?? null) ? $semester['courses'] : [];
            $courseCount = count($rows);
            $gradableCount = count(array_filter($rows, static fn (mixed $row): bool => is_array($row) && is_numeric($row['sks'] ?? null) && (int) $row['sks'] > 0));

            $series[] = [
                'semester' => (int) $semester['semester'],
                'label' => (string) ($semester['label'] ?? ''),
                'ips' => $portal ?? $computed,
                'ips_portal' => $portal,
                'ips_computed' => $computed,
                'ips_mismatch' => $portal !== null && $computed !== null && abs($portal - $computed) > 0.005,
                'sks' => $sks,
                'quality_points' => round($qualityPoints, 2),
                'graded_count' => count($courses),
                'course_count' => $courseCount,
                'is_complete' => count($courses) >= $gradableCount,
            ];
        }

        return $series;
    }

    /**
     * Study progress towards graduation
     *
     * `remainingSks` is the portal's "Sisa SKS yang harus ditempuh" when
     * known; otherwise it is derived from the target. The projection is
     * `ceil(remaining / average SKS earned per semester)` (F16).
     *
     * @return array{
     *     target_sks: int,
     *     earned_sks: int,
     *     remaining_sks: int,
     *     progress_percent: float,
     *     average_sks_per_semester: float|null,
     *     projected_semesters_remaining: int|null
     * }
     */
    public function progress(int $earnedSks, ?int $targetSks = null, ?float $averageSksPerSemester = null, ?int $remainingSks = null): array
    {
        $target = $targetSks !== null && $targetSks > 0 ? $targetSks : $this->defaultTargetSks();
        $earned = max(0, $earnedSks);
        $remaining = $remainingSks !== null ? max(0, $remainingSks) : max(0, $target - $earned);

        $projected = match (true) {
            $remaining === 0 => 0,
            $averageSksPerSemester !== null && $averageSksPerSemester > 0 => (int) ceil(round($remaining / $averageSksPerSemester, 6)),
            default => null,
        };

        return [
            'target_sks' => $target,
            'earned_sks' => $earned,
            'remaining_sks' => $remaining,
            'progress_percent' => min(100.0, round($earned / $target * 100, 1)),
            'average_sks_per_semester' => $averageSksPerSemester,
            'projected_semesters_remaining' => $projected,
        ];
    }

    /**
     * SKS that the conversion courses of a KRS `T` (a D3 → S1 continuation)
     * add to the earned SKS
     *
     * The portal lists the recognized courses on the KRS `T` but leaves them
     * out of the KHS, "SKS Terkumpul" and "Sisa SKS" (K16). They carry no
     * grade, so they never touch the IPK. A course counts once: one listed
     * twice, or already graded in a KHS (its SKS are earned there), is not
     * added again.
     *
     * @param  list<array<string, mixed>>  $courses  course rows of KrsDetailExtractor
     * @param  list<array<string, mixed>>  $semesters  KHS semesters
     * @return array{courses: list<array{code: string, name: string|null, sks: int}>, course_count: int, sks: int, already_graded_codes: list<string>}
     */
    public function conversionCredits(array $courses, array $semesters): array
    {
        $graded = [];

        foreach ($semesters as $semester) {
            foreach ($this->gradedCourses($semester) as $course) {
                $graded[$this->courseKey($course['code'], $course['name'])] = true;
            }
        }

        $counted = [];
        $alreadyGraded = [];

        foreach ($courses as $course) {
            if (! is_array($course)) {
                continue;
            }

            $code = strtoupper(trim((string) ($course['code'] ?? '')));
            $name = trim((string) ($course['name'] ?? ''));
            $sks = is_numeric($course['sks'] ?? null) ? (int) $course['sks'] : 0;
            $key = $this->courseKey($code, $name);

            if (($code === '' && $name === '') || $sks <= 0 || isset($counted[$key])) {
                continue;
            }

            if (isset($graded[$key])) {
                $alreadyGraded[$key] = $code !== '' ? $code : $name;

                continue;
            }

            $counted[$key] = ['code' => $code, 'name' => $name !== '' ? $name : null, 'sks' => $sks];
        }

        return [
            'courses' => array_values($counted),
            'course_count' => count($counted),
            'sks' => array_sum(array_column($counted, 'sks')),
            'already_graded_codes' => array_values($alreadyGraded),
        ];
    }

    /**
     * Courses of the current KRS that already have a grade in the latest
     * regular KHS semester
     *
     * The portal keeps showing a semester's KRS after its grades are out,
     * until the next KRS opens; those courses are earned SKS by then and must
     * not also count as the load still being taken. Only a KRS of that very
     * semester is matched: the KRS and the latest KHS card list the same
     * courses (at least 80% of the longer list is on both). A KRS of a new
     * semester is not, even while the latest card is still the previous
     * semester: a course retaken now that was graded (D or E) there is still
     * being taken.
     *
     * @param  list<array<string, mixed>>  $krsCourses  course rows of KrsExtractor
     * @param  list<array<string, mixed>>  $semesters  KHS semesters
     * @return array{courses: int, sks: int}
     */
    public function gradedKrsCourses(array $krsCourses, array $semesters): array
    {
        $regular = $this->regularSemesters($semesters);
        $latest = $regular === [] ? null : $regular[count($regular) - 1];
        $krs = [];

        foreach ($krsCourses as $course) {
            if (! is_array($course)) {
                continue;
            }

            $code = strtoupper(trim((string) ($course['code'] ?? '')));
            $name = trim((string) ($course['name'] ?? ''));

            if ($code !== '' || $name !== '') {
                $krs[$this->courseKey($code, $name)] ??= is_numeric($course['sks'] ?? null) ? max(0, (int) $course['sks']) : 0;
            }
        }

        if ($latest === null || ! $this->listsTheSameCourses(array_keys($krs), $this->courseKeys($latest))) {
            return ['courses' => 0, 'sks' => 0];
        }

        $counted = [];

        foreach ($this->gradedCourses($latest) as $course) {
            $key = $this->courseKey($course['code'], $course['name']);

            if (isset($krs[$key])) {
                $counted[$key] = $krs[$key];
            }
        }

        return ['courses' => count($counted), 'sks' => array_sum($counted)];
    }

    /**
     * Minimum average (IPS) over the next `additionalSks` SKS to reach `targetIpk`
     *
     * The required value is rounded up, so reaching it guarantees the target.
     * Above 4.00 the target is not achievable within those SKS. Retaking a
     * course (which replaces an old grade) is not modelled.
     *
     * @return TargetSimulation
     */
    public function simulateTarget(float $targetIpk, float $qualityPoints, int $sks, int $additionalSks): array
    {
        if ($targetIpk < 0 || $targetIpk > self::MAX_GRADE_WEIGHT) {
            throw new InvalidArgumentException('Target IPK harus di antara 0 dan 4.');
        }

        if ($additionalSks < 0 || $sks < 0) {
            throw new InvalidArgumentException('Jumlah SKS tidak boleh negatif.');
        }

        $currentIpk = $sks > 0 ? round($qualityPoints / $sks, 2) : null;
        $alreadyReached = $currentIpk !== null && $currentIpk >= $targetIpk;

        if ($additionalSks === 0) {
            return [
                'target_ipk' => $targetIpk,
                'current_ipk' => $currentIpk,
                'additional_sks' => 0,
                'required_ips' => null,
                'achievable' => $alreadyReached,
                'already_reached' => $alreadyReached,
            ];
        }

        $needed = ($targetIpk * ($sks + $additionalSks) - $qualityPoints) / $additionalSks;
        $required = $needed <= 0 ? 0.0 : ceil(round($needed * 100, 6)) / 100;

        return [
            'target_ipk' => $targetIpk,
            'current_ipk' => $currentIpk,
            'additional_sks' => $additionalSks,
            'required_ips' => $required,
            'achievable' => $required <= self::MAX_GRADE_WEIGHT,
            'already_reached' => $alreadyReached,
        ];
    }

    /**
     * Average needed over every remaining SKS to graduate with each predicate threshold
     *
     * @return list<array{predicate: string, target_ipk: float, current_ipk: float|null, additional_sks: int, required_ips: float|null, achievable: bool, already_reached: bool}>
     */
    public function graduationTargets(float $qualityPoints, int $sks, int $remainingSks): array
    {
        $targets = [];

        foreach (array_reverse(self::PREDICATE_THRESHOLDS, true) as $predicate => $threshold) {
            $targets[] = ['predicate' => $predicate, ...$this->simulateTarget($threshold, $qualityPoints, $sks, max(0, $remainingSks))];
        }

        return $targets;
    }

    /**
     * Predicate the IPK would earn at graduation; always shown as an estimate
     * (the campus adds conditions such as study length)
     */
    public function predicateEstimate(?float $ipk): ?string
    {
        if ($ipk === null) {
            return null;
        }

        foreach (self::PREDICATE_THRESHOLDS as $predicate => $threshold) {
            if ($ipk >= $threshold) {
                return $predicate;
            }
        }

        return null;
    }

    /**
     * The series without the running semester while its grades are still
     * coming out: a trailing semester that is only partly graded is not a
     * whole semester yet, so it neither lowers the average SKS per semester
     * nor stands for the latest IPS on the strength of a single grade.
     *
     * @param  list<IpsPoint>  $series
     * @return list<IpsPoint>
     */
    private function settledSeries(array $series): array
    {
        $count = count($series);

        if ($count > 0 && ! $series[$count - 1]['is_complete']) {
            array_pop($series);
        }

        return $series;
    }

    /**
     * IPS of the latest fully graded semester; the running, partly graded
     * one only when no other semester has a grade (`is_complete` false).
     *
     * @param  list<IpsPoint>  $series
     * @return array{semester: int, ips: float, previous_semester: int|null, previous_ips: float|null, delta: float|null, is_complete: bool}|null
     */
    private function latestIps(array $series): ?array
    {
        $points = array_values(array_filter($series, static fn (array $point): bool => $point['ips'] !== null));
        $settled = $this->settledSeries($points);
        $points = $settled !== [] ? $settled : $points;
        $count = count($points);

        if ($count === 0) {
            return null;
        }

        $latest = $points[$count - 1];
        $previous = $points[$count - 2] ?? null;

        return [
            'semester' => $latest['semester'],
            'ips' => (float) $latest['ips'],
            'previous_semester' => $previous['semester'] ?? null,
            'previous_ips' => $previous === null ? null : (float) $previous['ips'],
            'delta' => $previous === null ? null : round((float) $latest['ips'] - (float) $previous['ips'], 2),
            'is_complete' => $latest['is_complete'],
        ];
    }

    /**
     * Graded attempts of the regular semesters, oldest semester first
     *
     * @param  list<array<string, mixed>>  $semesters
     * @return list<array{key: string, code: string, name: string, sks: int, letter: string, weight: float, semester: int, order: int}>
     */
    private function attempts(array $semesters): array
    {
        $attempts = [];
        $order = 0;

        foreach ($this->regularSemesters($semesters) as $semester) {
            foreach ($this->gradedCourses($semester) as $course) {
                $attempts[] = [
                    'key' => $this->courseKey($course['code'], $course['name']),
                    'code' => $course['code'],
                    'name' => $course['name'],
                    'sks' => $course['sks'],
                    'letter' => $course['letter'],
                    'weight' => $course['weight'],
                    'semester' => (int) $semester['semester'],
                    'order' => $order++,
                ];
            }
        }

        return $attempts;
    }

    /**
     * @param  list<array<string, mixed>>  $semesters
     * @return list<array<string, mixed>>
     */
    private function regularSemesters(array $semesters): array
    {
        $regular = array_values(array_filter($semesters, fn (array $semester): bool => $this->isRegular($semester)));

        usort($regular, static fn (array $left, array $right): int => (int) $left['semester'] <=> (int) $right['semester']);

        return $regular;
    }

    /**
     * @param  list<array<string, mixed>>  $semesters
     * @return list<string>
     */
    private function excludedSemesters(array $semesters): array
    {
        $excluded = [];

        foreach ($semesters as $semester) {
            if (! $this->isRegular($semester)) {
                $excluded[] = (string) ($semester['semester'] ?? $semester['label'] ?? '');
            }
        }

        return $excluded;
    }

    /**
     * @param  array<string, mixed>  $semester
     */
    private function isRegular(array $semester): bool
    {
        $number = $semester['semester'] ?? null;

        return is_int($number) && $number > 0 && ($semester['is_regular'] ?? true) === true;
    }

    /**
     * @param  array<string, mixed>  $semester
     * @return list<array{code: string, name: string, sks: int, letter: string, weight: float}>
     */
    private function gradedCourses(array $semester): array
    {
        $courses = [];

        foreach (is_array($semester['courses'] ?? null) ? $semester['courses'] : [] as $course) {
            if (! is_array($course)) {
                continue;
            }

            $letter = is_string($course['letter'] ?? null) ? trim($course['letter']) : '';
            $weight = is_numeric($course['weight'] ?? null) ? (float) $course['weight'] : null;
            $sks = is_numeric($course['sks'] ?? null) ? (int) $course['sks'] : 0;
            $isGraded = ($course['is_graded'] ?? true) === true;

            if (! $isGraded || $letter === '' || $weight === null || $sks <= 0) {
                continue;
            }

            $courses[] = [
                'code' => strtoupper(trim((string) ($course['code'] ?? ''))),
                'name' => trim((string) ($course['name'] ?? '')),
                'sks' => $sks,
                'letter' => strtoupper($letter),
                'weight' => $weight,
            ];
        }

        return $courses;
    }

    private function courseKey(string $code, string $name): string
    {
        return $code !== '' ? 'code:'.$code : 'name:'.mb_strtolower($name);
    }

    /**
     * Keys of every course row of a KHS card, graded or not
     *
     * @param  array<string, mixed>  $semester
     * @return list<string>
     */
    private function courseKeys(array $semester): array
    {
        $keys = [];

        foreach (is_array($semester['courses'] ?? null) ? $semester['courses'] : [] as $course) {
            if (! is_array($course)) {
                continue;
            }

            $code = strtoupper(trim((string) ($course['code'] ?? '')));
            $name = trim((string) ($course['name'] ?? ''));

            if ($code !== '' || $name !== '') {
                $keys[$this->courseKey($code, $name)] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Whether two course lists stand for the same semester: at least 80% of
     * the longer list is on both, so a course added or dropped late still
     * matches while a handful of retakes in a new semester does not.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function listsTheSameCourses(array $left, array $right): bool
    {
        $shared = count(array_intersect($left, $right));

        return $shared > 0 && $shared * 5 >= max(count($left), count($right)) * 4;
    }

    private function baseLetter(string $letter): string
    {
        return strtoupper(substr($letter, 0, 1));
    }

    private function isFailing(string $letter, float $weight): bool
    {
        return $this->baseLetter($letter) === 'E' || $weight <= 0.0;
    }
}
