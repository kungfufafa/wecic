<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

class DashboardTodayClassesBuilder
{
    /**
     * @param  list<array<string, mixed>>  $sipandaiClasses
     * @param  list<array<string, mixed>>  $studentCourseClasses
     * @return array{
     *     date: string,
     *     day: string,
     *     classes: list<array{
     *         class_id: string|null,
     *         dosen_id: string|null,
     *         course_id: string|null,
     *         course_code: string|null,
     *         course_name: string|null,
     *         lecturer_name: string|null,
     *         class_name: string|null,
     *         room: string|null,
     *         schedule: array{
     *             day: string|null,
     *             time: string|null,
     *             start_time: string|null,
     *             end_time: string|null
     *         },
     *         active_now: bool
     *     }>
     * }
     */
    public function build(array $sipandaiClasses, array $studentCourseClasses, CarbonImmutable $reference): array
    {
        $studentCourseMap = [];

        foreach ($studentCourseClasses as $studentCourseClass) {
            $studentCourseMap[$this->buildCourseKey($studentCourseClass)] = $studentCourseClass;
        }

        $todayClasses = [];

        foreach ($sipandaiClasses as $sipandaiClass) {
            if (! $this->isScheduledToday($sipandaiClass, $reference)) {
                continue;
            }

            $studentCourseClass = $studentCourseMap[$this->buildCourseKey($sipandaiClass)] ?? null;

            $todayClasses[] = [
                'class_id' => isset($sipandaiClass['join_link']['params']['id']) ? (string) $sipandaiClass['join_link']['params']['id'] : null,
                'dosen_id' => isset($sipandaiClass['join_link']['params']['dosen_id']) ? (string) $sipandaiClass['join_link']['params']['dosen_id'] : null,
                'course_id' => isset($sipandaiClass['join_link']['params']['course_id']) ? (string) $sipandaiClass['join_link']['params']['course_id'] : null,
                'course_code' => $sipandaiClass['course_info']['code'] ?? null,
                'course_name' => $sipandaiClass['course_info']['name'] ?? null,
                'lecturer_name' => $sipandaiClass['lecturer_info']['name'] ?? null,
                'class_name' => $studentCourseClass['class_room_info']['class_name'] ?? null,
                'room' => $studentCourseClass['class_room_info']['room'] ?? null,
                'schedule' => [
                    'day' => $sipandaiClass['schedule']['day'] ?? null,
                    'time' => $sipandaiClass['schedule']['formatted_time'] ?? ($sipandaiClass['schedule']['time_range'] ?? null),
                    'start_time' => $sipandaiClass['schedule']['start_time'] ?? null,
                    'end_time' => $sipandaiClass['schedule']['end_time'] ?? null,
                ],
                'active_now' => $this->isActiveNow($sipandaiClass['schedule'] ?? [], $reference),
            ];
        }

        usort($todayClasses, fn (array $firstClass, array $secondClass): int => $this->compareClasses($firstClass, $secondClass));

        return [
            'date' => $reference->toDateString(),
            'day' => ucfirst((string) $reference->locale('id')->isoFormat('dddd')),
            'classes' => $todayClasses,
        ];
    }

    /**
     * @param  array<string, mixed>  $class
     */
    private function buildCourseKey(array $class): string
    {
        $courseCode = strtolower(trim((string) ($class['course_info']['code'] ?? '')));
        $day = $this->normalizeDayName($class['schedule']['day'] ?? null);
        $timeKey = $this->extractTimeKey($class['schedule'] ?? []);

        return implode('|', [$courseCode, $day, $timeKey]);
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function extractTimeKey(array $schedule): string
    {
        $timeRange = $schedule['time_range'] ?? null;

        if (is_string($timeRange) && trim($timeRange) !== '') {
            return strtolower(trim($timeRange));
        }

        $startTime = $schedule['start_time'] ?? null;
        $endTime = $schedule['end_time'] ?? null;

        if (is_string($startTime) && is_string($endTime) && $startTime !== '' && $endTime !== '') {
            return strtolower("{$startTime}-{$endTime}");
        }

        return strtolower(trim((string) ($schedule['formatted_time'] ?? '')));
    }

    private function normalizeDayName(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        $day = strtolower(trim($value));

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

        return $aliases[$day] ?? $day;
    }

    /**
     * @param  array<string, mixed>  $class
     */
    private function isScheduledToday(array $class, CarbonImmutable $reference): bool
    {
        return $this->normalizeDayName($class['schedule']['day'] ?? null)
            === $this->normalizeDayName($reference->locale('id')->isoFormat('dddd'));
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function isActiveNow(array $schedule, CarbonImmutable $reference): bool
    {
        $startMinutes = $this->minutesFromTime($schedule['start_time'] ?? null);
        $endMinutes = $this->minutesFromTime($schedule['end_time'] ?? null);

        if ($startMinutes === null || $endMinutes === null) {
            return false;
        }

        $currentMinutes = ((int) $reference->format('H')) * 60 + (int) $reference->format('i');

        return $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;
    }

    private function minutesFromTime(?string $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d{2}):(\d{2})/', $value, $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }

    /**
     * @param  array{
     *     active_now: bool,
     *     schedule: array{start_time: string|null}
     * }  $firstClass
     * @param  array{
     *     active_now: bool,
     *     schedule: array{start_time: string|null}
     * }  $secondClass
     */
    private function compareClasses(array $firstClass, array $secondClass): int
    {
        if ($firstClass['active_now'] !== $secondClass['active_now']) {
            return $firstClass['active_now'] ? -1 : 1;
        }

        return ($this->minutesFromTime($firstClass['schedule']['start_time']) ?? PHP_INT_MAX)
            <=> ($this->minutesFromTime($secondClass['schedule']['start_time']) ?? PHP_INT_MAX);
    }
}
