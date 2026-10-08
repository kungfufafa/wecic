<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
use App\Services\Extractors\SipandaiClassesExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Builds the exam (UTS/UAS) list: the SIPANDAI exam page is authoritative,
 * and the class list fills in missing exam ids or replaces the page entirely
 * when it is empty or unavailable.
 */
class PortalExamListResolver
{
    public function __construct(
        private readonly SipandaiSubmissionService $submissionService,
        private readonly SipandaiClassesExtractor $sipandaiClassesExtractor
    ) {}

    /**
     * @return array{exams: list<array<string, mixed>>, source: 'exam_page'|'class_schedule_fallback'}
     */
    public function resolve(string $sessionToken): array
    {
        try {
            $exams = $this->submissionService->getExamList($sessionToken);
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            $fallbackExams = $this->resolveExamListFromClasses($sessionToken, []);

            if ($fallbackExams === []) {
                throw $throwable;
            }

            Log::warning('SIPANDAI exam list failed, using class schedule fallback', [
                'error' => $throwable->getMessage(),
            ]);

            return [
                'exams' => $fallbackExams,
                'source' => 'class_schedule_fallback',
            ];
        }

        return [
            'exams' => $this->resolveExamListFromClasses($sessionToken, $exams),
            'source' => 'exam_page',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $exams
     * @return list<array<string, mixed>>
     */
    private function resolveExamListFromClasses(string $sessionToken, array $exams): array
    {
        if ($exams !== [] && ! $this->hasExamRowsWithoutIds($exams)) {
            return $exams;
        }

        try {
            $classesData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $classExams = $this->buildExamListFromClasses($classesData['classes'] ?? []);

            if ($classExams === []) {
                return $exams;
            }

            if ($exams === []) {
                return $classExams;
            }

            return $this->mergeExamRowsWithClassFallbacks($exams, $classExams);
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve exam list from class fallback', [
                'error' => $e->getMessage(),
            ]);

            return $exams;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $exams
     */
    private function hasExamRowsWithoutIds(array $exams): bool
    {
        foreach ($exams as $exam) {
            if (empty($exam['id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     * @return list<array<string, mixed>>
     */
    private function buildExamListFromClasses(array $classes): array
    {
        $exams = [];

        foreach ($classes as $class) {
            $courseId = $this->classCourseId($class);
            $courseCode = (string) ($class['course_info']['code'] ?? '');
            $courseName = (string) ($class['course_info']['name'] ?? '');

            if ($courseId === null || $courseName === '') {
                continue;
            }

            $exams[] = [
                'id' => $courseId,
                'course_code' => $courseCode,
                'course_name' => $courseName,
                'schedule' => $this->formatClassSchedule($class['schedule'] ?? []),
                'status' => 'Terjadwal',
                'upload_url' => PortalEndpointRegistry::url('exam_upload_form', ['id' => $courseId]),
                'source' => 'class_schedule',
                'class_reference' => [
                    'id' => $class['join_link']['params']['id'] ?? null,
                    'course_id' => $courseId,
                    'dosen_id' => $class['join_link']['params']['dosen_id'] ?? null,
                ],
            ];
        }

        return $exams;
    }

    /**
     * @param  array<string, mixed>  $class
     */
    private function classCourseId(array $class): ?int
    {
        $courseId = $class['join_link']['params']['course_id'] ?? null;

        if (is_numeric($courseId)) {
            return (int) $courseId;
        }

        $classId = $class['join_link']['params']['id'] ?? null;

        return is_numeric($classId) ? (int) $classId : null;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function formatClassSchedule(array $schedule): string
    {
        $day = trim((string) ($schedule['day'] ?? ''));
        $time = trim((string) ($schedule['formatted_time'] ?? ($schedule['time_range'] ?? ($schedule['time'] ?? ''))));

        return trim($day.' '.$time);
    }

    /**
     * @param  list<array<string, mixed>>  $exams
     * @param  list<array<string, mixed>>  $classExams
     * @return list<array<string, mixed>>
     */
    private function mergeExamRowsWithClassFallbacks(array $exams, array $classExams): array
    {
        $classExamMap = [];

        foreach ($classExams as $classExam) {
            foreach ($this->examMatchKeys($classExam) as $key) {
                $classExamMap[$key] = $classExam;
            }
        }

        return array_map(function (array $exam) use ($classExamMap): array {
            if (! empty($exam['id'])) {
                return $exam;
            }

            foreach ($this->examMatchKeys($exam) as $key) {
                if (! isset($classExamMap[$key])) {
                    continue;
                }

                return [
                    ...$exam,
                    'id' => $classExamMap[$key]['id'],
                    'upload_url' => $exam['upload_url'] ?? $classExamMap[$key]['upload_url'],
                    'source' => $exam['source'] ?? 'exam_list_with_class_id',
                    'class_reference' => $classExamMap[$key]['class_reference'] ?? null,
                ];
            }

            return $exam;
        }, $exams);
    }

    /**
     * @param  array<string, mixed>  $exam
     * @return list<string>
     */
    private function examMatchKeys(array $exam): array
    {
        $keys = [];
        $courseIdentity = $this->extractExamCourseIdentity($exam);
        $courseCode = $this->normalizeExamCodeMatchValue($courseIdentity['code']);
        $courseName = $this->normalizeExamMatchValue($courseIdentity['name']);

        if ($courseCode !== '') {
            $keys[] = 'code:'.$courseCode;
        }

        if ($courseName !== '') {
            $keys[] = 'name:'.$courseName;
        }

        foreach ([$exam['course_code'] ?? null, $exam['course_name'] ?? null] as $candidate) {
            $candidateCode = $this->extractExamCodeFromValue((string) $candidate);

            if ($candidateCode !== '') {
                $keys[] = 'code:'.$candidateCode;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $exam
     * @return array{code: string, name: string}
     */
    private function extractExamCourseIdentity(array $exam): array
    {
        $code = (string) ($exam['course_code'] ?? '');
        $name = (string) ($exam['course_name'] ?? '');

        foreach ([$code, $name] as $candidate) {
            $parsed = $this->parseExamCourseIdentity($candidate);

            if ($parsed['code'] !== '') {
                return $parsed;
            }
        }

        return [
            'code' => $code,
            'name' => $name,
        ];
    }

    /**
     * @return array{code: string, name: string}
     */
    private function parseExamCourseIdentity(string $value): array
    {
        $normalizedValue = trim((string) preg_replace('/\s+/', ' ', $value));

        if (preg_match('/\b([A-Z]{1,4}\d{4,})(.+)$/u', $normalizedValue, $matches) !== 1) {
            return [
                'code' => '',
                'name' => '',
            ];
        }

        return [
            'code' => $matches[1],
            'name' => trim($matches[2]),
        ];
    }

    private function normalizeExamMatchValue(mixed $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));
    }

    private function normalizeExamCodeMatchValue(mixed $value): string
    {
        return strtolower(preg_replace('/[^A-Z0-9]/i', '', (string) $value) ?? '');
    }

    private function extractExamCodeFromValue(string $value): string
    {
        if (preg_match('/\b([A-Z]{1,4}\d{4,})/u', $value, $matches) !== 1) {
            return '';
        }

        return $this->normalizeExamCodeMatchValue($matches[1]);
    }
}
