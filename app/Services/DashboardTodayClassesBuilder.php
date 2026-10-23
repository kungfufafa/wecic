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
     * Re-evaluate `active_now`, and the order it drives, for a stored
     * schedule at read time. The dashboard snapshot is built once and served
     * for hours, so the flag it was built with goes stale: a class that was
     * in session at 21:00 must not still lead the list the next morning. A
     * schedule for another date has no class in session.
     *
     * @param  array<string, mixed>  $classesSummary  `classes_summary` of a dashboard snapshot.
     * @return array<string, mixed>
     */
    public function refreshActiveState(array $classesSummary, CarbonImmutable $reference): array
    {
        if (! is_array($classesSummary['today_classes'] ?? null)) {
            return $classesSummary;
        }

        $isToday = ($classesSummary['date'] ?? null) === $reference->toDateString();
        $classes = [];

        foreach ($classesSummary['today_classes'] as $class) {
            if (is_array($class)) {
                $class['active_now'] = $isToday && $this->isActiveNow(is_array($class['schedule'] ?? null) ? $class['schedule'] : [], $reference);
            }

            $classes[] = $class;
        }

        usort($classes, fn (mixed $firstClass, mixed $secondClass): int => $this->compareClasses(
            is_array($firstClass) ? $firstClass : [],
            is_array($secondClass) ? $secondClass : []
        ));

        $classesSummary['today_classes'] = $classes;

        return $classesSummary;
    }

    /**
     * Join today's attendance shortcuts onto the schedule's classes.
     *
     * The shortcuts come from their own snapshot (the class and meeting page
     * of every class today) and are joined only when they describe the
     * schedule's date. Until then every class gets an `attendance` whose
     * `state` says why it is empty: `checking` while that snapshot is being
     * built, `unavailable` when it could not be read. A class that already
     * carries its attendance (a snapshot built when both were one) keeps it.
     *
     * @param  array<string, mixed>  $classesSummary  `classes_summary` of the schedule.
     * @param  array<string, mixed>|null  $shortcuts  Data of the attendance snapshot, null when there is none.
     * @param  string  $status  Read status of the attendance snapshot (synced, syncing, pending, failed, offline, missing).
     * @return array<string, mixed>
     */
    public function attachAttendance(array $classesSummary, ?array $shortcuts, string $status, ?string $syncedAt = null): array
    {
        if (! is_array($classesSummary['today_classes'] ?? null)) {
            return $classesSummary;
        }

        $joinable = is_array($shortcuts)
            && is_array($shortcuts['classes'] ?? null)
            && isset($classesSummary['date'])
            && ($shortcuts['date'] ?? null) === $classesSummary['date'];
        $pendingState = in_array($status, ['failed', 'offline', 'missing'], true) ? 'unavailable' : 'checking';
        [$shortcutByClassId, $shortcutByScheduleKey] = $this->indexShortcuts($joinable ? $shortcuts['classes'] : []);
        $classes = [];

        foreach ($classesSummary['today_classes'] as $class) {
            if (! is_array($class)) {
                $classes[] = $class;

                continue;
            }

            if ($joinable) {
                $class['attendance'] = $this->attendanceFor($class, $this->findShortcut($class, $shortcutByClassId, $shortcutByScheduleKey), 'ready');
            } elseif (is_array($class['attendance'] ?? null)) {
                $class['attendance']['state'] ??= 'ready';
            } else {
                $class['attendance'] = $this->attendanceFor($class, null, $pendingState);
            }

            $classes[] = $class;
        }

        $classesSummary['today_classes'] = $classes;
        $classesSummary['actionable_attendance_count'] = count(array_filter(
            $classes,
            static fn (mixed $class): bool => is_array($class) && (bool) ($class['attendance']['actionable'] ?? false)
        ));
        $classesSummary['attendance_sync'] = [
            'status' => $status,
            'synced_at' => $syncedAt,
        ];

        return $classesSummary;
    }

    /**
     * @param  array<mixed>  $shortcuts
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function indexShortcuts(array $shortcuts): array
    {
        $shortcutByClassId = [];
        $shortcutByScheduleKey = [];

        foreach ($shortcuts as $shortcut) {
            if (! is_array($shortcut)) {
                continue;
            }

            $classId = trim((string) ($shortcut['class_id'] ?? ''));

            if ($classId !== '') {
                $shortcutByClassId[$classId] = $shortcut;
            }

            $shortcutByScheduleKey[$this->buildTodayClassKey([
                'course_code' => $shortcut['course_code'] ?? null,
                'schedule' => [
                    'day' => $shortcut['schedule']['day'] ?? null,
                    'start_time' => $shortcut['schedule']['start_time'] ?? null,
                    'time' => $shortcut['schedule']['time'] ?? null,
                ],
            ])] = $shortcut;
        }

        return [$shortcutByClassId, $shortcutByScheduleKey];
    }

    /**
     * @param  array<string, mixed>  $class
     * @param  array<string, array<string, mixed>>  $shortcutByClassId
     * @param  array<string, array<string, mixed>>  $shortcutByScheduleKey
     * @return array<string, mixed>|null
     */
    private function findShortcut(array $class, array $shortcutByClassId, array $shortcutByScheduleKey): ?array
    {
        $classId = trim((string) ($class['class_id'] ?? ''));

        if ($classId !== '' && isset($shortcutByClassId[$classId])) {
            return $shortcutByClassId[$classId];
        }

        return $shortcutByScheduleKey[$this->buildTodayClassKey($class)] ?? null;
    }

    /**
     * A class whose meeting page shows no attendance window after the class
     * has ended (`status.window` `closed`) is not offered the attendance
     * form: the portal no longer takes a code, and the student reads
     * "Tidak hadir" instead.
     *
     * @param  array<string, mixed>  $class
     * @param  array<string, mixed>|null  $shortcut
     * @return array<string, mixed>
     */
    private function attendanceFor(array $class, ?array $shortcut, string $state): array
    {
        $attendanceStatus = $shortcut['attendance_status'] ?? [
            'already_marked' => false,
            'status' => null,
            'label' => null,
            'time' => null,
        ];
        $windowClosed = ($attendanceStatus['window'] ?? null) === 'closed';
        $hasReliableAttendanceTarget = ($shortcut['attendance_target_reliable'] ?? true) === true;
        $classContext = [
            'class_id' => $class['class_id'] ?? null,
            'dosen_id' => $class['dosen_id'] ?? null,
            'course_id' => $class['course_id'] ?? null,
        ];
        $hasClassContext = ! empty($classContext['class_id'])
            && ! empty($classContext['dosen_id'])
            && ! empty($classContext['course_id']);

        $meetingUrl = null;
        if (! empty($shortcut['meeting_id']) && ! empty($shortcut['assignment_payload']['courses_id'])) {
            $meetingUrl = sprintf('/meeting/%s?courses_id=%s', $shortcut['meeting_id'], $shortcut['assignment_payload']['courses_id']);
        }

        return [
            'state' => $state,
            'available' => $shortcut !== null,
            'target_reliable' => $shortcut !== null && $hasReliableAttendanceTarget,
            'class_url' => $hasClassContext
                ? sprintf('/class/%s?dosen_id=%s&course_id=%s', $classContext['class_id'], $classContext['dosen_id'], $classContext['course_id'])
                : null,
            'actionable' => $shortcut !== null
                && $hasReliableAttendanceTarget
                && ! ($attendanceStatus['already_marked'] ?? false)
                && ! $windowClosed
                && (! empty($shortcut['attendance_payload']) || $hasClassContext),
            'meeting_id' => $shortcut['meeting_id'] ?? null,
            'meeting_number' => $shortcut['today_material']['meeting_number'] ?? ($shortcut['meeting_number'] ?? null),
            'today_material' => $shortcut['today_material'] ?? null,
            'status' => $attendanceStatus,
            'payload' => $hasReliableAttendanceTarget ? ($shortcut['attendance_payload'] ?? null) : null,
            'context' => $hasReliableAttendanceTarget && $hasClassContext ? $classContext : null,
            'meeting_url' => $meetingUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $class
     */
    private function buildTodayClassKey(array $class): string
    {
        $courseCode = strtolower(trim((string) ($class['course_code'] ?? '')));
        $day = strtolower(trim((string) ($class['schedule']['day'] ?? '')));
        $time = strtolower(trim((string) (($class['schedule']['start_time'] ?? '') ?: ($class['schedule']['time'] ?? ''))));

        return implode('|', [$courseCode, $day, $time]);
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

    private function minutesFromTime(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d{2}):(\d{2})/', $value, $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }

    /**
     * @param  array{
     *     active_now?: bool,
     *     schedule?: array{start_time?: string|null}
     * }  $firstClass
     * @param  array{
     *     active_now?: bool,
     *     schedule?: array{start_time?: string|null}
     * }  $secondClass
     */
    private function compareClasses(array $firstClass, array $secondClass): int
    {
        $firstActive = ($firstClass['active_now'] ?? false) === true;
        $secondActive = ($secondClass['active_now'] ?? false) === true;

        if ($firstActive !== $secondActive) {
            return $firstActive ? -1 : 1;
        }

        return ($this->minutesFromTime($firstClass['schedule']['start_time'] ?? null) ?? PHP_INT_MAX)
            <=> ($this->minutesFromTime($secondClass['schedule']['start_time'] ?? null) ?? PHP_INT_MAX);
    }
}
