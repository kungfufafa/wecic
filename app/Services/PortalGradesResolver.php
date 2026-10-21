<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\AdvisingExtractor;
use App\Services\Extractors\KhsExtractor;
use App\Services\Extractors\KrsDetailExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Builds the `grades` dataset (F15/F16): KHS per semester, the official
 * cumulative summary, MyCIC's own computation and the transcript
 *
 * SIPANDAI `act=score` is the canonical source of grade rows (it has the
 * ABSEN/TUGAS components). Portal Mahasiswa `act=viewkrs` of the latest KRS
 * (from the student's own KRS history) adds the official summary, is used to
 * cross-check the rows, and replaces them when the score page fails
 * ("data sebagian": no components). The KRS of semester `T` (conversion of
 * a D3 → S1 continuation) adds its courses to the earned SKS (K16).
 */
class PortalGradesResolver
{
    /**
     * Largest difference between the official and the computed IPK still
     * treated as equal (both are printed with two decimals).
     */
    private const IPK_TOLERANCE = 0.005;

    /**
     * Semester of the advising history whose KRS lists the conversion courses
     */
    private const CONVERSION_SEMESTER = 'T';

    public function __construct(
        private readonly KhsExtractor $khsExtractor,
        private readonly AdvisingExtractor $advisingExtractor,
        private readonly KrsDetailExtractor $krsDetailExtractor,
        private readonly AcademicProgressCalculator $calculator
    ) {}

    /**
     * @return array{
     *     found: bool,
     *     semesters: list<array<string, mixed>>,
     *     summary: array{
     *         official: array<string, mixed>|null,
     *         computed: array<string, mixed>,
     *         mismatch: bool,
     *         mismatch_fields: list<string>,
     *         ipk: float|null,
     *         sks_passed: int,
     *         conversion_sks: int,
     *         quality_points_total: float,
     *         source: 'official'|'computed',
     *         progress: array<string, mixed>
     *     },
     *     transcript: list<array<string, mixed>>,
     *     transcript_print_url: string|null,
     *     conversion: array{status: 'ok'|'none'|'failed'|'skipped', krs_ids: list<int>, courses: list<array{code: string, name: string|null, sks: int}>, course_count: int, sks: int, already_graded_codes: list<string>},
     *     sources: array{
     *         khs: 'sipandai_score'|'student_krs_detail',
     *         is_partial: bool,
     *         score_page: 'ok'|'empty'|'failed',
     *         krs_detail: 'ok'|'empty'|'failed'|'skipped',
     *         krs_id: string|null,
     *         cross_check: array{compared: int, mismatched: int}|null
     *     },
     *     extracted_at: string,
     *     _sync: array{data_origin: 'server_cache'|'upstream'}
     * }
     */
    public function resolve(string $sessionToken): array
    {
        [$scoreData, $scoreStatus, $scoreError] = $this->fetchScorePage($sessionToken);
        $advising = $this->fetchAdvising($sessionToken);
        [$krsData, $krsStatus, $krsId] = $this->fetchKrsDetailGrades($sessionToken, $advising);

        $primarySource = match (true) {
            $scoreStatus === 'ok' => KhsExtractor::SOURCE_SCORE,
            $krsStatus === 'ok' => KhsExtractor::SOURCE_KRS_DETAIL,
            default => null,
        };

        // The score page was read and has no KHS yet (a first-semester
        // student), and the KRS detail page did not fail either: that is a
        // real "no grades yet", stored like any other result. Throwing here
        // would leave no snapshot, so the IPK tile and the action center
        // would wait for grades forever.
        $noKhsYet = $primarySource === null
            && $scoreStatus === 'empty'
            && in_array($krsStatus, ['empty', 'skipped'], true);

        if ($primarySource === null && ! $noKhsYet) {
            if ($scoreError !== null) {
                throw $scoreError;
            }

            throw PortalScrapingException::dataExtractionFailed('grades', 'Kartu Hasil Studi tidak ditemukan di portal.');
        }

        $primarySource ??= KhsExtractor::SOURCE_SCORE;
        $primary = $primarySource === KhsExtractor::SOURCE_SCORE ? $scoreData : $krsData;

        if ($primarySource === KhsExtractor::SOURCE_KRS_DETAIL) {
            Log::warning('SIPANDAI score page unavailable, grades built from the KRS detail page', [
                'score_page' => $scoreStatus,
            ]);
        }

        /** @var list<array<string, mixed>> $semesters */
        $semesters = $noKhsYet ? [] : $primary['semesters'];
        $official = $krsData['cumulative'] ?? $scoreData['cumulative'] ?? null;
        $computed = $this->calculator->summarize($semesters);
        $mismatchFields = $official === null ? [] : $this->mismatchFields($official, $computed);
        $conversion = $this->conversion($sessionToken, $advising, $semesters);

        if ($mismatchFields !== []) {
            Log::warning('Official cumulative summary differs from the computed one', [
                'fields' => $mismatchFields,
            ]);
        }

        return [
            'found' => ! $noKhsYet,
            'semesters' => $semesters,
            'summary' => $this->buildSummary($official, $computed, $mismatchFields, $conversion['sks']),
            'transcript' => $this->calculator->transcript($semesters),
            'transcript_print_url' => $scoreData['transcript_print_url'] ?? null,
            'conversion' => $conversion,
            'sources' => [
                'khs' => $primarySource,
                'is_partial' => $primarySource !== KhsExtractor::SOURCE_SCORE,
                'score_page' => $scoreStatus,
                'krs_detail' => $krsStatus,
                'krs_id' => $krsId,
                'cross_check' => $scoreStatus === 'ok' && $krsStatus === 'ok'
                    ? $this->crossCheck($scoreData['semesters'], $krsData['semesters'])
                    : null,
            ],
            'extracted_at' => now()->toISOString(),
            '_sync' => [
                'data_origin' => $this->dataOrigin([$scoreData, $krsData]),
            ],
        ];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: 'ok'|'empty'|'failed', 2: \Throwable|null}
     */
    private function fetchScorePage(string $sessionToken): array
    {
        try {
            $data = $this->khsExtractor->extractKhsData($sessionToken);

            return [$data, ($data['found'] ?? false) === true ? 'ok' : 'empty', null];
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            Log::warning('SIPANDAI score page failed', ['error' => $throwable->getMessage()]);

            return [null, 'failed', $throwable];
        }
    }

    /**
     * Advising page (KRS history), or null when it fails: only the official
     * summary and the conversion SKS depend on it
     *
     * @return array<string, mixed>|null
     */
    private function fetchAdvising(string $sessionToken): ?array
    {
        try {
            return $this->advisingExtractor->extractAdvisingData($sessionToken);
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            Log::warning('Advising page failed, official grade summary skipped', ['error' => $throwable->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $advising
     * @return array{0: array<string, mixed>|null, 1: 'ok'|'empty'|'failed'|'skipped', 2: string|null}
     */
    private function fetchKrsDetailGrades(string $sessionToken, ?array $advising): array
    {
        $krsId = $advising === null ? null : $this->gradesKrsId($advising);

        if ($krsId === null) {
            return [null, 'skipped', null];
        }

        try {
            $data = $this->khsExtractor->extractKrsGradesData($sessionToken, $krsId);

            return [$data, ($data['found'] ?? false) === true ? 'ok' : 'empty', $krsId];
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            Log::warning('KRS detail grades failed', ['error' => $throwable->getMessage()]);

            return [null, 'failed', $krsId];
        }
    }

    /**
     * Conversion credits from every KRS `T` of the advising history (K16)
     *
     * A failed KRS `T` page adds no SKS at all rather than part of them, so
     * the remaining SKS never drops on a half-read list.
     *
     * @param  array<string, mixed>|null  $advising
     * @param  list<array<string, mixed>>  $semesters
     * @return array{status: 'ok'|'none'|'failed'|'skipped', krs_ids: list<int>, courses: list<array{code: string, name: string|null, sks: int}>, course_count: int, sks: int, already_graded_codes: list<string>}
     */
    private function conversion(string $sessionToken, ?array $advising, array $semesters): array
    {
        $krsIds = $advising === null ? [] : $this->conversionKrsIds($advising);
        $status = match (true) {
            $advising === null => 'skipped',
            $krsIds === [] => 'none',
            default => 'ok',
        };
        $courses = [];

        try {
            foreach ($krsIds as $krsId) {
                $detail = $this->krsDetailExtractor->extractKrsDetailData($sessionToken, $krsId);
                array_push($courses, ...array_values(is_array($detail['courses'] ?? null) ? $detail['courses'] : []));
            }
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            Log::warning('Conversion KRS failed, conversion SKS left out', ['error' => $throwable->getMessage()]);

            [$status, $courses] = ['failed', []];
        }

        return [
            'status' => $status,
            'krs_ids' => $krsIds,
            ...$this->calculator->conversionCredits($courses, $semesters),
        ];
    }

    /**
     * @param  array<string, mixed>  $advising
     * @return list<int>
     */
    private function conversionKrsIds(array $advising): array
    {
        $krsIds = [];

        foreach (is_array($advising['krs_history'] ?? null) ? $advising['krs_history'] : [] as $entry) {
            if (! is_array($entry) || ($entry['semester'] ?? null) !== self::CONVERSION_SEMESTER) {
                continue;
            }

            $krsId = $this->numericKrsId($entry['krs_id'] ?? null);

            if ($krsId !== null) {
                $krsIds[] = (int) $krsId;
            }
        }

        return array_values(array_unique($krsIds));
    }

    /**
     * KRS id whose viewkrs page is read for the official summary
     *
     * The advising page's `latest_krs_id` (an int) comes first. When it has
     * none (only "T" rows, or an unreadable semester column) any id of the
     * history is used: every viewkrs page shows the same KHS and summary.
     *
     * @param  array<string, mixed>  $advising
     */
    private function gradesKrsId(array $advising): ?string
    {
        $latest = $this->numericKrsId($advising['latest_krs_id'] ?? null);

        if ($latest !== null) {
            return $latest;
        }

        foreach (is_array($advising['krs_history'] ?? null) ? $advising['krs_history'] : [] as $entry) {
            $krsId = is_array($entry) ? $this->numericKrsId($entry['krs_id'] ?? null) : null;

            if ($krsId !== null) {
                return $krsId;
            }
        }

        return null;
    }

    private function numericKrsId(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1 && (int) trim($value) > 0) {
            return trim($value);
        }

        return null;
    }

    /**
     * Conversion SKS are earned but ungraded: they raise the earned SKS and
     * lower the portal's remaining SKS, and stay out of the IPK figures.
     *
     * @param  array<string, mixed>|null  $official
     * @param  array<string, mixed>  $computed
     * @param  list<string>  $mismatchFields
     * @return array<string, mixed>
     */
    private function buildSummary(?array $official, array $computed, array $mismatchFields, int $conversionSks): array
    {
        $useOfficial = $official !== null && $official['ipk'] !== null;
        $earnedSks = $conversionSks + ($useOfficial && $official['sks_total'] !== null ? (int) $official['sks_total'] : (int) $computed['sks_passed']);
        $countedSks = $useOfficial && $official['sks_total'] !== null ? (int) $official['sks_total'] : (int) $computed['sks_total'];
        $qualityPoints = $useOfficial && $official['quality_points_total'] !== null
            ? (float) $official['quality_points_total']
            : (float) $computed['quality_points_total'];
        $ipk = $useOfficial ? (float) $official['ipk'] : $computed['ipk'];
        $targetSks = $official['target_sks'] ?? null;

        $progress = $this->calculator->progress(
            $earnedSks,
            $targetSks,
            $computed['average_sks_per_semester'],
            $useOfficial && $official['sks_remaining'] !== null ? (int) $official['sks_remaining'] - $conversionSks : null
        );

        return [
            'official' => $official,
            'computed' => $computed,
            'mismatch' => $mismatchFields !== [],
            'mismatch_fields' => $mismatchFields,
            'ipk' => $ipk,
            'sks_passed' => $earnedSks,
            'conversion_sks' => $conversionSks,
            'quality_points_total' => round($qualityPoints, 2),
            'source' => $useOfficial ? 'official' : 'computed',
            'progress' => [
                ...$progress,
                'target_sks_source' => $targetSks !== null ? 'official' : 'config',
                'graduation_targets' => $countedSks > 0
                    ? $this->calculator->graduationTargets($qualityPoints, $countedSks, $progress['remaining_sks'])
                    : [],
            ],
            'predicate_estimate' => $this->calculator->predicateEstimate($ipk),
        ];
    }

    /**
     * @param  array<string, mixed>  $official
     * @param  array<string, mixed>  $computed
     * @return list<string>
     */
    private function mismatchFields(array $official, array $computed): array
    {
        $fields = [];

        $officialIpk = $official['ipk'];
        $computedIpk = $computed['ipk'];

        if (($officialIpk === null) !== ($computedIpk === null)
            || ($officialIpk !== null && $computedIpk !== null && abs((float) $officialIpk - (float) $computedIpk) > self::IPK_TOLERANCE)) {
            $fields[] = 'ipk';
        }

        if ($official['sks_total'] !== null && (int) $official['sks_total'] !== (int) $computed['sks_total']) {
            $fields[] = 'sks_total';
        }

        if ($official['quality_points_total'] !== null && abs((float) $official['quality_points_total'] - (float) $computed['quality_points_total']) > 0.01) {
            $fields[] = 'quality_points_total';
        }

        return $fields;
    }

    /**
     * Compare letter and weight per semester and course code between both pages
     *
     * @param  list<array<string, mixed>>  $scoreSemesters
     * @param  list<array<string, mixed>>  $krsSemesters
     * @return array{compared: int, mismatched: int}
     */
    private function crossCheck(array $scoreSemesters, array $krsSemesters): array
    {
        $reference = [];

        foreach ($krsSemesters as $semester) {
            foreach ($semester['courses'] ?? [] as $course) {
                $reference[$semester['semester'].'|'.$course['code']] = $course;
            }
        }

        $compared = 0;
        $mismatched = 0;

        foreach ($scoreSemesters as $semester) {
            foreach ($semester['courses'] ?? [] as $course) {
                $other = $reference[$semester['semester'].'|'.$course['code']] ?? null;

                if ($other === null) {
                    continue;
                }

                $compared++;

                if ($other['letter'] !== $course['letter'] || $other['weight'] !== $course['weight'] || $other['sks'] !== $course['sks']) {
                    $mismatched++;
                }
            }
        }

        if ($mismatched > 0) {
            Log::warning('KHS rows differ between the score page and the KRS detail page', [
                'compared' => $compared,
                'mismatched' => $mismatched,
            ]);
        }

        return ['compared' => $compared, 'mismatched' => $mismatched];
    }

    /**
     * @param  list<array<string, mixed>|null>  $payloads
     * @return 'server_cache'|'upstream'
     */
    private function dataOrigin(array $payloads): string
    {
        foreach ($payloads as $payload) {
            if (($payload['_sync']['data_origin'] ?? null) === 'server_cache') {
                return 'server_cache';
            }
        }

        return 'upstream';
    }
}
