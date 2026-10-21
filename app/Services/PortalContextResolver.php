<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
use App\Services\Extractors\PortalContextExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Builds the `portal_context` dataset: the dashboard context plus the
 * student's current semester number (K2).
 *
 * The semester is read from the portal, never calculated: the schedule page
 * ("Semester : <n>") first, then the SIPANDAI class list. Other portal
 * sources (exam page, KRS print, KRS history) can be passed to compose().
 * A count from the intake year is only a last resort and is labelled as an
 * estimate, because it is wrong for transfer students, leave semesters and
 * non-regular ("T") KRS.
 */
class PortalContextResolver
{
    /**
     * Portal sources of the semester number, most trusted first (K2).
     *
     * @var list<string>
     */
    public const SEMESTER_SOURCES = ['student_course', 'sipandai_classes', 'exam_page', 'krs_print', 'krs_history'];

    public const ESTIMATE_SOURCE = 'intake_year_estimate';

    private const MAX_SEMESTER = 20;

    public function __construct(
        private readonly PortalContextExtractor $portalContextExtractor,
        private readonly StudentCourseExtractor $studentCourseExtractor,
        private readonly SipandaiClassesExtractor $sipandaiClassesExtractor,
        private readonly ProfileExtractor $profileExtractor
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $sessionToken): array
    {
        $portalContext = $this->portalContextExtractor->extractPortalContextData($sessionToken);
        $dataOrigins = [$portalContext['_sync']['data_origin'] ?? null];
        unset($portalContext['_sync']);

        $semesterSources = [];
        $hasPortalSemester = false;
        $semesterPages = [
            'student_course' => fn (): array => $this->studentCourseExtractor->extractStudentCourseData($sessionToken),
            'sipandai_classes' => fn (): array => $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken),
        ];

        foreach ($semesterPages as $source => $fetch) {
            $pageData = $this->attemptSource($source, $fetch);

            if ($pageData === null) {
                continue;
            }

            $semesterSources[$source] = $pageData['semester_info']['semester'] ?? null;
            $dataOrigins[] = $pageData['_sync']['data_origin'] ?? null;

            if ($this->regularSemester($semesterSources[$source]) !== null) {
                $hasPortalSemester = true;

                break;
            }
        }

        if (! $hasPortalSemester) {
            $profile = $this->attemptSource('profile', fn (): array => $this->profileExtractor->extractProfileData($sessionToken));

            if ($profile !== null) {
                $semesterSources['intake_year'] = $profile['personal_data']['angkatan'] ?? null;
                $dataOrigins[] = $profile['_sync']['data_origin'] ?? null;
            }
        }

        $context = $this->compose($portalContext, $semesterSources);
        $context['_sync'] = [
            'data_origin' => in_array('server_cache', $dataOrigins, true) ? 'server_cache' : 'upstream',
        ];

        return $context;
    }

    /**
     * Add the semester number to an extracted portal context
     *
     * Each semester source is the raw value the portal printed ("3",
     * "Semester : 3", "T"); `krs_history` is the list of semesters in the
     * KRS history, of which the highest regular one counts. `intake_year`
     * (angkatan) is only used when no portal source has a number.
     *
     * @param  array<string, mixed>  $portalContext
     * @param  array{student_course?: mixed, sipandai_classes?: mixed, exam_page?: mixed, krs_print?: mixed, krs_history?: list<mixed>, intake_year?: mixed}  $semesterSources
     * @param  array{text: string, dates: list<string>, is_future: bool, kind: string}|null  $fallbackNotice  marquee from another page (announcements, SIPANDAI) when the dashboard has none
     * @return array<string, mixed>
     */
    public function compose(array $portalContext, array $semesterSources, ?array $fallbackNotice = null): array
    {
        $candidates = [];

        foreach (self::SEMESTER_SOURCES as $source) {
            $number = $source === 'krs_history'
                ? $this->highestRegularSemester($semesterSources['krs_history'] ?? [])
                : $this->regularSemester($semesterSources[$source] ?? null);

            if ($number !== null) {
                $candidates[$source] = $number;
            }
        }

        if (count(array_unique($candidates)) > 1) {
            Log::info('Portal semester sources disagree', ['candidates' => $candidates]);
        }

        $semesterSource = array_key_first($candidates);
        $semesterNumber = $semesterSource !== null ? $candidates[$semesterSource] : null;
        $isEstimate = false;
        $academicPeriod = is_array($portalContext['academic_period'] ?? null) ? $portalContext['academic_period'] : null;

        if ($semesterNumber === null) {
            $semesterNumber = $this->estimateSemesterFromIntakeYear($academicPeriod, $semesterSources['intake_year'] ?? null);

            if ($semesterNumber !== null) {
                $semesterSource = self::ESTIMATE_SOURCE;
                $isEstimate = true;
            }
        }

        $semesterLabel = $semesterNumber !== null
            ? 'Semester '.$semesterNumber.($isEstimate ? ' (perkiraan)' : '')
            : null;

        $notice = $portalContext['notice'] ?? null;

        if (! is_array($notice) && is_array($fallbackNotice) && is_string($fallbackNotice['text'] ?? null)) {
            $notice = $fallbackNotice;
        }

        return [
            ...$portalContext,
            'notice' => is_array($notice) ? $notice : null,
            'semester_number' => $semesterNumber,
            'semester_source' => $semesterSource,
            'semester_is_estimate' => $isEstimate,
            'semester_label' => $semesterLabel,
            'summary_label' => $this->summaryLabel($academicPeriod, $semesterLabel),
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $fetch
     * @return array<string, mixed>|null
     */
    private function attemptSource(string $source, callable $fetch): ?array
    {
        try {
            return $fetch();
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            Log::warning('Portal context semester source failed', [
                'source' => $source,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A regular semester number, or null for "T" (non-regular, K16), blanks
     * and values outside a plausible study length
     */
    private function regularSemester(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= self::MAX_SEMESTER ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^(?:semester\s*(?:ke)?\s*[:\-]?\s*)?(\d{1,2})$/iu', trim($value), $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];

        return $number >= 1 && $number <= self::MAX_SEMESTER ? $number : null;
    }

    /**
     * Highest regular semester in the KRS history; not the most recent row,
     * which can be a non-regular "T" KRS
     *
     * @param  list<mixed>|mixed  $semesters
     */
    private function highestRegularSemester(mixed $semesters): ?int
    {
        if (! is_array($semesters)) {
            return null;
        }

        $numbers = array_filter(array_map(fn (mixed $semester): ?int => $this->regularSemester($semester), $semesters));

        return $numbers === [] ? null : max($numbers);
    }

    /**
     * Semesters since the intake year, assuming the student started in the
     * odd (ganjil) term of that year and never skipped one
     *
     * @param  array<string, mixed>|null  $academicPeriod
     */
    private function estimateSemesterFromIntakeYear(?array $academicPeriod, mixed $intakeYear): ?int
    {
        $term = $academicPeriod['term'] ?? null;

        if (! in_array($term, ['ganjil', 'genap'], true)) {
            return null;
        }

        if (preg_match('/^(\d{4})\//', (string) ($academicPeriod['academic_year'] ?? ''), $periodMatches) !== 1) {
            return null;
        }

        if (! is_int($intakeYear) && ! is_string($intakeYear)) {
            return null;
        }

        if (preg_match('/(?<!\d)(\d{4})(?!\d)/', (string) $intakeYear, $intakeMatches) !== 1) {
            return null;
        }

        $estimate = ((int) $periodMatches[1] - (int) $intakeMatches[1]) * 2 + ($term === 'ganjil' ? 1 : 2);

        return $estimate >= 1 && $estimate <= self::MAX_SEMESTER ? $estimate : null;
    }

    /**
     * Hero chip text: "T.A 2026/2027 · Ganjil · Semester 3"
     *
     * @param  array<string, mixed>|null  $academicPeriod
     */
    private function summaryLabel(?array $academicPeriod, ?string $semesterLabel): ?string
    {
        if (! is_string($academicPeriod['academic_year'] ?? null)) {
            return null;
        }

        $parts = ['T.A '.$academicPeriod['academic_year']];

        if (is_string($academicPeriod['term'] ?? null)) {
            $parts[] = ucfirst($academicPeriod['term']);
        }

        if ($semesterLabel !== null) {
            $parts[] = $semesterLabel;
        }

        return implode(' · ', $parts);
    }
}
