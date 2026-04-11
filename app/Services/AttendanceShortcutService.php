<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use Carbon\CarbonImmutable;

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

        $classes = collect($this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken)['classes'] ?? [])
            ->filter(fn (array $class): bool => $this->isScheduledToday($class, $reference))
            ->map(fn (array $class): array => $this->resolveCandidate($sessionToken, $class, $reference))
            ->filter(fn (array $candidate): bool => $candidate !== [])
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['active_now'] ?? false))
            ->values()
            ->all();

        return [
            'date' => $reference->toDateString(),
            'day' => ucfirst((string) $reference->locale('id')->isoFormat('dddd')),
            'current_time' => $reference->format('H:i'),
            'classes' => $classes,
        ];
    }

    /**
     * @param  array<string, mixed>  $class
     * @return array<string, mixed>
     */
    private function resolveCandidate(string $sessionToken, array $class, CarbonImmutable $reference): array
    {
        $params = $class['join_link']['params'] ?? [];
        $classId = $params['id'] ?? null;
        $dosenId = $params['dosen_id'] ?? null;
        $courseId = $params['course_id'] ?? null;

        if (! $classId || ! $dosenId || ! $courseId) {
            return [];
        }

        $detail = $this->classesExtractor->extractClassDetail($sessionToken, [
            'id' => $classId,
            'dosen_id' => $dosenId,
            'course_id' => $courseId,
        ]);

        $material = $this->findTodayMaterial($detail['course_materials'] ?? [], $reference);
        $fallbackMeetingNumber = $this->resolveFallbackMeetingNumber($detail);
        $meetingNumber = $material['meeting_number'] ?? $fallbackMeetingNumber;
        $meetingId = $this->extractQueryValue($material['join_link']['href'] ?? null, 'id')
            ?? $this->extractMeetingIdFromAttendancePayload($courseId, $detail);

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
