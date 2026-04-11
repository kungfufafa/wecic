<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class AttendanceShortcutService
{
    public function __construct(
        private readonly ClassesExtractor $classesExtractor,
        private readonly SipandaiClassesExtractor $sipandaiClassesExtractor
    ) {}

    /**
     * @return array{
     *     date: string,
     *     day: string,
     *     current_time: string,
     *     classes: list<array<string, mixed>>
     * }
     */
    public function getTodayShortcuts(string $sessionToken, ?CarbonImmutable $reference = null): array
    {
        $reference ??= CarbonImmutable::now(config('app.timezone'));

        $attendanceClasses = [];
        $classes = collect($this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken)['classes'] ?? [])
            ->filter(fn (array $class): bool => $this->isScheduledToday($class, $reference))
            ->values()
            ->all();

        foreach ($classes as $class) {
            $detail = $this->resolveClassDetail($sessionToken, $class);

            if ($detail === null) {
                continue;
            }

            $candidate = $this->resolveAttendanceCandidate($class, $detail, $reference);

            if ($candidate !== []) {
                $attendanceClasses[] = $candidate;
            }
        }

        $attendanceClasses = collect($attendanceClasses)
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['active_now'] ?? false))
            ->values()
            ->all();

        return [
            'date' => $reference->toDateString(),
            'day' => ucfirst((string) $reference->locale('id')->isoFormat('dddd')),
            'current_time' => $reference->format('H:i'),
            'classes' => $attendanceClasses,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPendingAssignments(string $sessionToken, int $limit = 4): array
    {
        $originalDelay = (int) config('portal.request_delay', 1000);
        config(['portal.request_delay' => min($originalDelay, 250)]);

        try {
            $pendingAssignments = [];
            $classes = collect($this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken)['classes'] ?? [])
                ->sortBy(fn (array $class): int => (int) (($class['join_link']['params']['course_id'] ?? 0)))
                ->values()
                ->all();
            $classScanCount = 0;
            $classScanLimit = 4;

            foreach ($classes as $class) {
                if ($classScanCount >= $classScanLimit || count($pendingAssignments) >= $limit) {
                    break;
                }

                $detail = $this->resolveClassDetail($sessionToken, $class);

                if ($detail === null) {
                    continue;
                }

                $classScanCount++;

                foreach ($this->resolvePendingAssignmentCandidates($class, $detail) as $candidate) {
                    if (count($pendingAssignments) >= $limit) {
                        break 2;
                    }

                    $meetingDetail = $this->resolveMeetingDetail($sessionToken, $candidate['meeting_id'], $candidate['courses_id']);

                    if ($meetingDetail === null) {
                        continue;
                    }

                    $assignment = $this->buildPendingAssignment($class, $candidate, $meetingDetail);

                    if ($assignment !== null) {
                        $pendingAssignments[] = $assignment;
                        break;
                    }
                }
            }

            return $pendingAssignments;
        } finally {
            config(['portal.request_delay' => $originalDelay]);
        }
    }

    /**
     * @param  array<string, mixed>  $class
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function resolveAttendanceCandidate(array $class, array $detail, CarbonImmutable $reference): array
    {
        $params = $class['join_link']['params'] ?? [];
        $classId = $params['id'] ?? null;
        $dosenId = $params['dosen_id'] ?? null;
        $courseId = $params['course_id'] ?? null;

        if (! $classId || ! $dosenId || ! $courseId) {
            return [];
        }

        $material = $this->findTodayMaterial($detail['course_materials'] ?? [], $reference);
        $fallbackMeetingNumber = $this->resolveFallbackMeetingNumber($detail);
        $meetingNumber = $material['meeting_number'] ?? $fallbackMeetingNumber;
        $meetingId = $this->extractQueryValue($material['join_link']['href'] ?? null, 'id')
            ?? $this->extractMeetingIdFromAttendancePayload($courseId, $detail);
        $attendanceStatus = $this->resolveAttendanceStatus($detail['attendance_info']['meetings'] ?? [], $meetingNumber);

        if (! $meetingId || $meetingNumber === null) {
            return [];
        }

        return [
            'class_id' => (string) $classId,
            'meeting_id' => (string) $meetingId,
            'meeting_number' => $meetingNumber,
            'course_code' => $class['course_info']['code'] ?? null,
            'course_name' => $class['course_info']['name'] ?? null,
            'lecturer_name' => $class['lecturer_info']['name'] ?? null,
            'schedule' => [
                'day' => $class['schedule']['day'] ?? null,
                'time' => $class['schedule']['formatted_time'] ?? ($class['schedule']['time_range'] ?? ($class['schedule']['time'] ?? null)),
                'start_time' => $class['schedule']['start_time'] ?? null,
                'end_time' => $class['schedule']['end_time'] ?? null,
            ],
            'today_material' => [
                'title' => $material['title'] ?? 'Pertemuan terdekat belum terdeteksi dari materi.',
                'date' => $material['date'] ?? null,
                'time' => $material['time'] ?? null,
            ],
            'active_now' => $this->isActiveNow($class, $reference),
            'attendance_status' => $attendanceStatus,
            'attendance_payload' => [
                'courseid' => (string) $courseId,
                'modulesid' => (string) $meetingId,
                'pertemuan' => $meetingNumber,
            ],
            'assignment_payload' => [
                'courses_id' => (string) $courseId,
                'id' => (string) $meetingId,
                'pertemuan' => $meetingNumber,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $class
     * @param  array<string, mixed>  $detail
     * @return list<array<string, mixed>>
     */
    private function resolvePendingAssignmentCandidates(array $class, array $detail): array
    {
        $submittedMeetings = collect($detail['assignment_history']['assignments'] ?? [])
            ->map(fn (array $assignment): int => (int) ($assignment['meeting'] ?? 0))
            ->filter(fn (int $meetingNumber): bool => $meetingNumber > 0)
            ->values()
            ->all();

        $pendingAssignments = [];

        foreach ($detail['course_materials'] ?? [] as $index => $material) {
            $meetingNumber = (int) ($material['meeting_number'] ?? ($index + 1));

            if ($meetingNumber <= 0 || in_array($meetingNumber, $submittedMeetings, true)) {
                continue;
            }

            $meetingId = $this->extractQueryValue($material['join_link']['href'] ?? null, 'id');
            $coursesId = $this->extractQueryValue($material['join_link']['href'] ?? null, 'courses_id')
                ?? $this->extractQueryValue($material['join_link']['href'] ?? null, 'course_id');

            if (! $meetingId || ! $coursesId) {
                continue;
            }

            $pendingAssignments[] = [
                'meeting_id' => (string) $meetingId,
                'courses_id' => (string) $coursesId,
                'meeting_number' => $meetingNumber,
                'material_title' => $material['title'] ?? sprintf('Pertemuan %s', $meetingNumber),
                'material_date' => $material['date'] ?? null,
            ];
        }

        usort($pendingAssignments, fn (array $left, array $right): int => $right['meeting_number'] <=> $left['meeting_number']);

        return array_slice($pendingAssignments, 0, 1);
    }

    /**
     * @param  array<string, mixed>  $class
     * @return array<string, mixed>|null
     */
    private function resolveClassDetail(string $sessionToken, array $class): ?array
    {
        $params = $class['join_link']['params'] ?? [];
        $classId = $params['id'] ?? null;
        $dosenId = $params['dosen_id'] ?? null;
        $courseId = $params['course_id'] ?? null;

        if (! $classId || ! $dosenId || ! $courseId) {
            return null;
        }

        try {
            return $this->classesExtractor->extractClassDetail($sessionToken, [
                'id' => $classId,
                'dosen_id' => $dosenId,
                'course_id' => $courseId,
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('Skipping class while resolving attendance shortcuts', [
                'class_id' => $classId,
                'course_id' => $courseId,
                'dosen_id' => $dosenId,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveMeetingDetail(string $sessionToken, string $meetingId, string $coursesId): ?array
    {
        try {
            return $this->classesExtractor->extractMeetingDetail($sessionToken, [
                'meeting_id' => $meetingId,
                'courses_id' => $coursesId,
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('Skipping meeting while resolving pending assignments', [
                'meeting_id' => $meetingId,
                'courses_id' => $coursesId,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $class
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $meetingDetail
     * @return array<string, mixed>|null
     */
    private function buildPendingAssignment(array $class, array $candidate, array $meetingDetail): ?array
    {
        $assignmentInfo = $meetingDetail['assignment_info'] ?? [];
        $materials = $meetingDetail['materials'] ?? [];
        $deadline = trim((string) ($assignmentInfo['deadline'] ?? ''));
        $deadline = $deadline === ':' || $deadline === '' ? (string) ($materials['assignment_instructions'] ?? '') : $deadline;

        $hasAssignmentContent = ! empty($materials['assignment_topic'])
            || ! empty($materials['assignment_instructions'])
            || ! empty($materials['assignment_material_iframe'])
            || ! empty($assignmentInfo['upload_text'])
            || trim((string) ($assignmentInfo['upload_link'] ?? '')) !== '';

        if (! $hasAssignmentContent) {
            return null;
        }

        return [
            'meeting_id' => $candidate['meeting_id'],
            'meeting_number' => $candidate['meeting_number'],
            'course_code' => $class['course_info']['code'] ?? null,
            'course_name' => $class['course_info']['name'] ?? null,
            'lecturer_name' => $class['lecturer_info']['name'] ?? null,
            'material_title' => $materials['assignment_topic']
                ?? $candidate['material_title']
                ?? sprintf('Pertemuan %s', $candidate['meeting_number']),
            'material_date' => $candidate['material_date'] ?? null,
            'deadline' => $deadline !== '' ? $deadline : null,
            'meeting_url' => sprintf('/meeting/%s?courses_id=%s', $candidate['meeting_id'], $candidate['courses_id']),
            'assignment_file_url' => ! empty($materials['assignment_material_iframe']) ? (string) $materials['assignment_material_iframe'] : null,
            'can_submit_directly' => true,
            'assignment_payload' => [
                'courses_id' => $candidate['courses_id'],
                'id' => $candidate['meeting_id'],
                'pertemuan' => $candidate['meeting_number'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $class
     */
    private function isScheduledToday(array $class, CarbonImmutable $reference): bool
    {
        $day = strtolower((string) ($class['schedule']['day'] ?? ''));

        if ($day === '') {
            return false;
        }

        $today = strtolower((string) $reference->locale('id')->isoFormat('dddd'));
        $aliases = [
            'minggu' => 'minggu',
            'senin' => 'senin',
            'selasa' => 'selasa',
            'rabu' => 'rabu',
            'kamis' => 'kamis',
            'jumat' => 'jumat',
            "jum'at" => 'jumat',
            'sabtu' => 'sabtu',
            'sunday' => 'minggu',
            'monday' => 'senin',
            'tuesday' => 'selasa',
            'wednesday' => 'rabu',
            'thursday' => 'kamis',
            'friday' => 'jumat',
            'saturday' => 'sabtu',
        ];

        return ($aliases[$day] ?? $day) === ($aliases[$today] ?? $today);
    }

    /**
     * @param  array<int, array<string, mixed>>  $materials
     * @return array<string, mixed>|null
     */
    private function findTodayMaterial(array $materials, CarbonImmutable $reference): ?array
    {
        foreach (array_values($materials) as $index => $material) {
            if ($this->parsePortalDate($material['date'] ?? null)?->isSameDay($reference) !== true) {
                continue;
            }

            $material['meeting_number'] = $index + 1;

            return $material;
        }

        return null;
    }

    private function parsePortalDate(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (! preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/u', $value, $matches)) {
            return null;
        }

        $months = [
            'januari' => 1,
            'februari' => 2,
            'maret' => 3,
            'april' => 4,
            'mei' => 5,
            'juni' => 6,
            'juli' => 7,
            'agustus' => 8,
            'september' => 9,
            'oktober' => 10,
            'november' => 11,
            'desember' => 12,
        ];

        $month = $months[strtolower($matches[2])] ?? null;

        if ($month === null) {
            return null;
        }

        return CarbonImmutable::create(
            (int) $matches[3],
            $month,
            (int) $matches[1],
            0,
            0,
            0,
            config('app.timezone'),
        );
    }

    /**
     * @param  array<string, mixed>  $class
     */
    private function isActiveNow(array $class, CarbonImmutable $reference): bool
    {
        $start = $class['schedule']['start_time'] ?? null;
        $end = $class['schedule']['end_time'] ?? null;

        if (! is_string($start) || ! is_string($end)) {
            return false;
        }

        $currentMinutes = ((int) $reference->format('H')) * 60 + (int) $reference->format('i');
        $startMinutes = $this->minutesFromTime($start);
        $endMinutes = $this->minutesFromTime($end);

        if ($startMinutes === null || $endMinutes === null) {
            return false;
        }

        return $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;
    }

    private function minutesFromTime(string $value): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})/', $value, $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }

    private function extractQueryValue(?string $href, string $key): ?string
    {
        if (! is_string($href) || trim($href) === '') {
            return null;
        }

        $query = str_contains($href, '?') ? explode('?', $href, 2)[1] : $href;
        parse_str($query, $params);

        return isset($params[$key]) && $params[$key] !== '' ? (string) $params[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function resolveFallbackMeetingNumber(array $detail): ?int
    {
        $summary = $detail['attendance_info']['summary'] ?? null;

        if (! is_array($summary)) {
            return null;
        }

        $totalMeetings = (int) ($summary['total_meetings'] ?? 0);

        if ($totalMeetings <= 0) {
            return null;
        }

        return $totalMeetings + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $meetings
     * @return array{already_marked: bool, status: string|null, label: string|null, time: string|null}
     */
    private function resolveAttendanceStatus(array $meetings, ?int $meetingNumber): array
    {
        if ($meetingNumber === null) {
            return [
                'already_marked' => false,
                'status' => null,
                'label' => null,
                'time' => null,
            ];
        }

        foreach ($meetings as $meeting) {
            if ((int) ($meeting['meeting_number'] ?? 0) !== $meetingNumber) {
                continue;
            }

            $status = strtolower((string) ($meeting['status'] ?? ''));
            $details = is_array($meeting['details'] ?? null) ? $meeting['details'] : [];
            $attendanceTime = isset($details['attendance_time']) ? (string) $details['attendance_time'] : null;

            $alreadyMarked = $attendanceTime !== null
                || ($details['attendance_date'] ?? null) !== null
                || ($details['button_text'] ?? null) !== null
                || in_array($status, ['present', 'late', 'permit', 'sick'], true);

            return [
                'already_marked' => $alreadyMarked,
                'status' => $status !== '' ? $status : null,
                'label' => $this->attendanceLabel($status),
                'time' => $attendanceTime,
            ];
        }

        return [
            'already_marked' => false,
            'status' => null,
            'label' => null,
            'time' => null,
        ];
    }

    private function attendanceLabel(string $status): ?string
    {
        return match ($status) {
            'present' => 'Hadir',
            'late' => 'Terlambat',
            'permit' => 'Izin',
            'sick' => 'Sakit',
            'absent' => 'Absen',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function extractMeetingIdFromAttendancePayload(string $courseId, array $detail): ?string
    {
        $materials = $detail['course_materials'] ?? [];

        foreach ($materials as $material) {
            $href = $material['join_link']['href'] ?? null;
            $meetingId = $this->extractQueryValue($href, 'id');
            $coursesId = $this->extractQueryValue($href, 'courses_id')
                ?? $this->extractQueryValue($href, 'course_id');

            if ($meetingId && $coursesId === (string) $courseId) {
                return $meetingId;
            }
        }

        return null;
    }
}
