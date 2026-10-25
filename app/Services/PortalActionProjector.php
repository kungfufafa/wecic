<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingAttendanceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Projects confirmed portal actions onto the snapshots the API serves.
 *
 * Scraped pages present data differently from the actions that change it, so
 * after the portal accepts an action the student would otherwise keep seeing
 * the old state until a full re-scrape finishes. Instead, each confirmed
 * action is applied to the existing snapshots right away and kept in a short
 * ledger that is re-applied to every rebuild, so a sync that started before
 * the action cannot revert it. The overlay only ever moves a record forward
 * (e.g. "not attended" → "attended"); scraped data that already reflects the
 * action is left untouched and remains the source of truth.
 */
class PortalActionProjector
{
    public const ATTENDANCE_MARKED = 'attendance_marked';

    public const ASSIGNMENT_UPLOADED = 'assignment_uploaded';

    public const ASSIGNMENT_DELETED = 'assignment_deleted';

    /**
     * Class page attendance statuses that already count as a recorded attendance.
     */
    private const RECORDED_CLASS_ATTENDANCE = ['present', 'late', 'permit', 'sick'];

    public function __construct(
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * @return array{already_marked: bool, status: string, label: string, time: string}
     */
    public function recordAttendance(string $sessionToken, int $courseId, int $moduleId, ?int $meetingNumber = null): array
    {
        $entry = $this->remember($sessionToken, 'attendance', [
            'type' => self::ATTENDANCE_MARKED,
            'course_id' => (string) $courseId,
            'module_id' => (string) $moduleId,
            'meeting_number' => $meetingNumber,
            'time' => now()->format('H:i'),
        ]);

        $this->project($sessionToken, (string) $courseId, (string) $moduleId);

        return $this->attendanceStatus($entry);
    }

    /**
     * @param  array{href?: string, text?: string}|null  $fileInfo
     * @param  array<string, string>|null  $deletePayload
     */
    public function recordAssignmentUploaded(
        string $sessionToken,
        int $courseId,
        int $discussionId,
        int $meetingNumber,
        ?array $fileInfo = null,
        ?array $deletePayload = null
    ): void {
        $this->remember($sessionToken, 'assignment', [
            'type' => self::ASSIGNMENT_UPLOADED,
            'course_id' => (string) $courseId,
            'module_id' => (string) $discussionId,
            'meeting_number' => $meetingNumber,
            'file_info' => $fileInfo,
            'delete_payload' => $deletePayload,
            'date' => now()->locale('id')->translatedFormat('l, d F Y'),
            // Kept so a later delete can put the task back on the dashboard
            // without waiting for the next crawl of every class.
            'pending_item' => $this->findPendingItem($sessionToken, (string) $discussionId),
        ]);

        $this->project($sessionToken, (string) $courseId, (string) $discussionId);
    }

    public function recordAssignmentDeleted(string $sessionToken, ?int $courseId, int $discussionId, int $meetingNumber): void
    {
        $previous = $this->entries($sessionToken)['assignment:'.$discussionId] ?? null;

        $this->remember($sessionToken, 'assignment', [
            'type' => self::ASSIGNMENT_DELETED,
            'course_id' => $courseId !== null ? (string) $courseId : null,
            'module_id' => (string) $discussionId,
            'meeting_number' => $meetingNumber,
            'pending_item' => $previous['pending_item'] ?? null,
        ]);

        $this->project($sessionToken, $courseId !== null ? (string) $courseId : null, (string) $discussionId);
    }

    /**
     * The dashboard entry of an assignment, as it is listed right now.
     *
     * @return array<string, mixed>|null
     */
    private function findPendingItem(string $sessionToken, string $discussionId): ?array
    {
        $assignments = $this->snapshots->get($sessionToken, 'assignments')['data']['assignments'] ?? [];

        foreach ($assignments as $assignment) {
            if (is_array($assignment) && (string) ($assignment['assignment_payload']['id'] ?? ($assignment['meeting_id'] ?? '')) === $discussionId) {
                return $assignment;
            }
        }

        return null;
    }

    /**
     * Stop vouching for an assignment's state, e.g. when a delete could not
     * be confirmed: the next scrape is then shown as is.
     */
    public function forgetAssignment(string $sessionToken, int $discussionId): void
    {
        $entries = $this->entries($sessionToken);
        unset($entries['assignment:'.$discussionId]);

        Cache::put($this->ledgerKey($sessionToken), $entries, $this->ttl());
    }

    /**
     * Overlay the remembered actions onto freshly built (or stored) dataset data.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function apply(string $sessionToken, string $dataset, array $params, array $data): array
    {
        $entries = $this->entries($sessionToken);

        if ($entries === []) {
            return $data;
        }

        return match ($dataset) {
            'dashboard' => $this->applyToDashboard($data, $entries),
            'assignments' => $this->applyToAssignments($data, $entries),
            'meeting_detail' => $this->applyToMeetingDetail($data, $params, $entries),
            'class_detail' => $this->applyToClassDetail($data, $params, $entries),
            default => $data,
        };
    }

    /**
     * Patch the snapshots that exist right now, keeping their sync state so
     * the pending invalidation still schedules a confirming re-sync.
     *
     * Class pages are not patched here: their snapshots are keyed by the
     * class's own ids (id, dosen_id, course_id), which an action on one of
     * its meetings does not carry. PortalDatasetRepository::read() overlays
     * the recorded actions on a class page as it is served instead.
     */
    private function project(string $sessionToken, ?string $courseId, string $moduleId): void
    {
        $targets = [
            ['dashboard', []],
            ['assignments', []],
        ];

        if ($courseId !== null && $courseId !== '') {
            $targets[] = ['meeting_detail', ['meeting_id' => $moduleId, 'courses_id' => $courseId]];
        }

        foreach ($targets as [$dataset, $params]) {
            $snapshot = $this->snapshots->get($sessionToken, $dataset, $params);

            if ($snapshot === null || ! is_array($snapshot['data'] ?? null)) {
                continue;
            }

            $patched = $this->apply($sessionToken, $dataset, $params, $snapshot['data']);

            if ($patched !== $snapshot['data']) {
                $this->snapshots->replaceData($sessionToken, $dataset, $patched, $params);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function applyToDashboard(array $data, array $entries): array
    {
        $todayClasses = $data['classes_summary']['today_classes'] ?? null;

        if (! is_array($todayClasses)) {
            return $data;
        }

        $changed = false;

        foreach ($todayClasses as $index => $class) {
            $attendance = is_array($class['attendance'] ?? null) ? $class['attendance'] : null;

            if ($attendance === null || ($attendance['status']['already_marked'] ?? false) === true) {
                continue;
            }

            $moduleId = (string) ($attendance['meeting_id'] ?? ($attendance['payload']['modulesid'] ?? ''));
            $entry = $entries['attendance:'.$moduleId] ?? null;

            if ($moduleId === '' || $entry === null) {
                continue;
            }

            $attendance['status'] = $this->attendanceStatus($entry);
            $attendance['actionable'] = false;
            $todayClasses[$index]['attendance'] = $attendance;
            $changed = true;
        }

        if (! $changed) {
            return $data;
        }

        $data['classes_summary']['today_classes'] = $todayClasses;
        $data['classes_summary']['actionable_attendance_count'] = count(array_filter(
            $todayClasses,
            static fn (mixed $class): bool => is_array($class) && (bool) ($class['attendance']['actionable'] ?? false)
        ));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function applyToAssignments(array $data, array $entries): array
    {
        if (! is_array($data['assignments'] ?? null)) {
            return $data;
        }

        $itemId = static fn (array $assignment): string => (string) ($assignment['assignment_payload']['id'] ?? ($assignment['meeting_id'] ?? ''));

        $assignments = array_values(array_filter(
            $data['assignments'],
            function (mixed $assignment) use ($entries, $itemId): bool {
                if (! is_array($assignment)) {
                    return true;
                }

                $entry = $entries['assignment:'.$itemId($assignment)] ?? null;

                return $itemId($assignment) === '' || ($entry['type'] ?? null) !== self::ASSIGNMENT_UPLOADED;
            }
        ));

        // A deleted upload makes the task pending again; the snapshot lost the
        // entry when the upload was recorded, so restore it from the ledger.
        $listed = array_map($itemId, array_filter($assignments, 'is_array'));
        $restored = false;
        foreach ($entries as $entry) {
            $item = $entry['pending_item'] ?? null;
            if (($entry['type'] ?? null) === self::ASSIGNMENT_DELETED && is_array($item) && ! in_array((string) $entry['module_id'], $listed, true)) {
                $assignments[] = $item;
                $restored = true;
            }
        }

        if ($restored) {
            usort($assignments, static function (mixed $a, mixed $b): int {
                $dateA = is_array($a) ? ($a['deadline_date'] ?? null) : null;
                $dateB = is_array($b) ? ($b['deadline_date'] ?? null) : null;

                if ($dateA === $dateB) {
                    return 0;
                }

                if ($dateA === null || $dateB === null) {
                    return $dateA === null ? 1 : -1;
                }

                return strcmp($dateA, $dateB);
            });
        }

        $data['assignments'] = $assignments;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $params
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function applyToMeetingDetail(array $data, array $params, array $entries): array
    {
        $moduleId = (string) ($params['meeting_id'] ?? '');

        if ($moduleId === '') {
            return $data;
        }

        $attendanceEntry = $entries['attendance:'.$moduleId] ?? null;

        if ($attendanceEntry !== null && ! $this->meetingAttendanceRecorded($data['attendance_info'] ?? [])) {
            $data['attendance_info'] = array_merge(
                is_array($data['attendance_info'] ?? null) ? $data['attendance_info'] : [],
                ['status' => 'Hadir', 'time' => $attendanceEntry['time'] ?? null, 'state' => MeetingAttendanceState::Present->value]
            );
        }

        $assignmentEntry = $entries['assignment:'.$moduleId] ?? null;
        $assignmentInfo = is_array($data['assignment_info'] ?? null) ? $data['assignment_info'] : [];
        $uploaded = ($assignmentInfo['status'] ?? null) === 'uploaded';

        if (($assignmentEntry['type'] ?? null) === self::ASSIGNMENT_UPLOADED && ! $uploaded) {
            $assignmentInfo['status'] = 'uploaded';
            $assignmentInfo['submission'] = array_filter([
                'meeting' => $assignmentEntry['meeting_number'] ?? null,
                'date' => $assignmentEntry['date'] ?? null,
                'file_info' => $assignmentEntry['file_info'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);

            if (is_array($assignmentEntry['delete_payload'] ?? null)) {
                $assignmentInfo['delete_payload'] = $assignmentEntry['delete_payload'];
            }

            $data['assignment_info'] = $assignmentInfo;
        }

        if (($assignmentEntry['type'] ?? null) === self::ASSIGNMENT_DELETED && $uploaded) {
            unset($assignmentInfo['submission'], $assignmentInfo['delete_payload']);
            $assignmentInfo['status'] = trim((string) ($assignmentInfo['upload_link'] ?? '')) !== ''
                ? 'not_uploaded'
                : 'upload_unavailable';
            $data['assignment_info'] = $assignmentInfo;
        }

        return $data;
    }

    /**
     * The class page's attendance table and upload history. Their rows carry
     * meeting numbers only; the class's materials link each meeting's
     * discussion id, which is what an action records. An action on a meeting
     * the materials do not link counts when it names this class's course.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $params
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function applyToClassDetail(array $data, array $params, array $entries): array
    {
        $linkedMeetings = $this->classMeetingNumbers($data);
        $courseId = (string) ($params['course_id'] ?? '');

        foreach ($entries as $entry) {
            $meetingNumber = $linkedMeetings[(string) ($entry['module_id'] ?? '')] ?? null;

            if ($meetingNumber === null && $courseId !== '' && (string) ($entry['course_id'] ?? '') === $courseId && (int) ($entry['meeting_number'] ?? 0) > 0) {
                $meetingNumber = (int) $entry['meeting_number'];
            }

            if ($meetingNumber === null) {
                continue;
            }

            $data = match ($entry['type'] ?? null) {
                self::ATTENDANCE_MARKED => $this->markClassMeetingAttended($data, $meetingNumber, $entry),
                self::ASSIGNMENT_UPLOADED => $this->addClassUpload($data, $meetingNumber, $entry),
                self::ASSIGNMENT_DELETED => $this->removeClassUpload($data, $meetingNumber),
                default => $data,
            };
        }

        return $data;
    }

    /**
     * The meeting number of every discussion the class's materials link to.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int> Discussion id => meeting number.
     */
    private function classMeetingNumbers(array $data): array
    {
        $materials = is_array($data['course_materials'] ?? null) ? $data['course_materials'] : [];
        $numbers = [];

        foreach ($materials as $material) {
            $href = is_array($material) ? ($material['join_link']['href'] ?? null) : null;
            $meetingNumber = is_array($material) ? (int) ($material['meeting_number'] ?? 0) : 0;

            if (! is_string($href) || $meetingNumber < 1) {
                continue;
            }

            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $discussionId = $query['id'] ?? null;

            if (is_string($discussionId) && $discussionId !== '') {
                $numbers[$discussionId] = $meetingNumber;
            }
        }

        return $numbers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function markClassMeetingAttended(array $data, int $meetingNumber, array $entry): array
    {
        $meetings = $data['attendance_info']['meetings'] ?? null;

        if (! is_array($meetings)) {
            return $data;
        }

        foreach ($meetings as $index => $meeting) {
            if (! is_array($meeting) || (int) ($meeting['meeting_number'] ?? 0) !== $meetingNumber) {
                continue;
            }

            if (in_array($meeting['status'] ?? null, self::RECORDED_CLASS_ATTENDANCE, true)) {
                return $data;
            }

            $recordedAt = CarbonImmutable::parse((string) $entry['recorded_at'])->setTimezone((string) config('app.timezone'));
            $meetings[$index]['status'] = 'present';
            $meetings[$index]['details'] = [
                ...(is_array($meeting['details'] ?? null) ? $meeting['details'] : []),
                'attendance_date' => $recordedAt->locale('id')->translatedFormat('d F Y'),
                'attendance_time' => (string) ($entry['time'] ?? $recordedAt->format('H:i')),
            ];

            $data['attendance_info']['meetings'] = $meetings;
            $data['attendance_info']['summary'] = $this->recountClassAttendance($data['attendance_info']['summary'] ?? null, $meetings);

            return $data;
        }

        return $data;
    }

    /**
     * The summary ClassesExtractor computes, counted again from the rows.
     *
     * @param  array<int|string, mixed>  $meetings
     */
    private function recountClassAttendance(mixed $summary, array $meetings): mixed
    {
        if (! is_array($summary) || $summary === []) {
            return $summary;
        }

        $statuses = array_map(static fn (mixed $meeting): mixed => is_array($meeting) ? ($meeting['status'] ?? null) : null, $meetings);
        $count = static fn (string ...$wanted): int => count(array_filter($statuses, static fn (mixed $status): bool => in_array($status, $wanted, true)));

        $attended = $count('present', 'late');
        $upcoming = $count('upcoming');
        $held = max(0, (int) ($summary['total_meetings'] ?? count($meetings)) - $upcoming);

        return [
            ...$summary,
            'present' => $attended,
            'late' => $count('late'),
            'absent' => $count('absent'),
            'permit' => $count('permit'),
            'sick' => $count('sick'),
            'upcoming' => $upcoming,
            'held_meetings' => $held,
            'attendance_rate' => $held > 0 ? round(($attended / $held) * 100, 1) : 0,
        ];
    }

    /**
     * Add the upload to the history the way the portal lists a fresh one:
     * dated today and scored 0 until the lecturer grades it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function addClassUpload(array $data, int $meetingNumber, array $entry): array
    {
        $history = is_array($data['assignment_history'] ?? null) ? $data['assignment_history'] : [];
        $uploads = is_array($history['assignments'] ?? null) ? $history['assignments'] : [];

        foreach ($uploads as $upload) {
            if (is_array($upload) && $this->uploadMeetingNumber($upload) === $meetingNumber) {
                return $data;
            }
        }

        $uploads[] = [
            'no' => (count($uploads) + 1).'.',
            'date' => $entry['date'] ?? null,
            'meeting' => (string) $meetingNumber,
            'file_info' => $entry['file_info'] ?? null,
            'score' => '0',
        ];
        $history['assignments'] = $uploads;
        $data['assignment_history'] = $history;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function removeClassUpload(array $data, int $meetingNumber): array
    {
        $uploads = $data['assignment_history']['assignments'] ?? null;

        if (! is_array($uploads)) {
            return $data;
        }

        $kept = array_values(array_filter(
            $uploads,
            fn (mixed $upload): bool => ! is_array($upload) || $this->uploadMeetingNumber($upload) !== $meetingNumber
        ));

        if (count($kept) !== count($uploads)) {
            $data['assignment_history']['assignments'] = $kept;
        }

        return $data;
    }

    /**
     * The meeting an upload history row belongs to (the portal writes "3").
     *
     * @param  array<string, mixed>  $upload
     */
    private function uploadMeetingNumber(array $upload): ?int
    {
        return preg_match('/\d+/', (string) ($upload['meeting'] ?? ''), $matches) === 1 ? (int) $matches[0] : null;
    }

    /**
     * Whether the meeting page already shows the attendance recorded, by
     * the same reading the meeting page uses (MeetingAttendanceState).
     */
    private function meetingAttendanceRecorded(mixed $attendanceInfo): bool
    {
        return is_array($attendanceInfo) && MeetingAttendanceState::fromAttendanceInfo($attendanceInfo)->isRecorded();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{already_marked: bool, status: string, label: string, time: string}
     */
    private function attendanceStatus(array $entry): array
    {
        return [
            'already_marked' => true,
            'status' => 'present',
            'label' => 'Hadir',
            'time' => (string) ($entry['time'] ?? now()->format('H:i')),
        ];
    }

    /**
     * Store an action; a later action on the same target replaces the earlier
     * one (a delete cancels an upload).
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function remember(string $sessionToken, string $group, array $entry): array
    {
        $entry['recorded_at'] = now()->toISOString();
        $entries = $this->entries($sessionToken);
        $entries[$group.':'.$entry['module_id']] = $entry;

        Cache::put($this->ledgerKey($sessionToken), $entries, $this->ttl());

        return $entry;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function entries(string $sessionToken): array
    {
        $entries = Cache::get($this->ledgerKey($sessionToken));

        if (! is_array($entries)) {
            return [];
        }

        $cutoff = CarbonImmutable::now()->subSeconds($this->ttl());

        return array_filter(
            $entries,
            static fn (mixed $entry): bool => is_array($entry)
                && isset($entry['recorded_at'])
                && CarbonImmutable::parse($entry['recorded_at'])->greaterThan($cutoff)
        );
    }

    private function ttl(): int
    {
        return max(60, (int) config('portal.actions.ledger_ttl', 1800));
    }

    private function ledgerKey(string $sessionToken): string
    {
        return 'portal_action_ledger_'.md5($this->owner->key($sessionToken));
    }
}
