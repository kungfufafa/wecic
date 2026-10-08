<?php

declare(strict_types=1);

namespace App\Services;

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
            default => $data,
        };
    }

    /**
     * Patch the snapshots that exist right now, keeping their sync state so
     * the pending invalidation still schedules a confirming re-sync.
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
                ['status' => 'Hadir', 'time' => $attendanceEntry['time'] ?? null]
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
     * Mirrors how the meeting page decides that attendance is recorded.
     */
    private function meetingAttendanceRecorded(mixed $attendanceInfo): bool
    {
        if (! is_array($attendanceInfo)) {
            return false;
        }

        if (trim((string) ($attendanceInfo['time'] ?? '')) !== '') {
            return true;
        }

        $status = strtolower(trim((string) ($attendanceInfo['status'] ?? '')));

        return preg_match('/hadir|terlambat|izin|sakit/', $status) === 1
            && preg_match('/belum|silakan|scan|qr/', $status) !== 1;
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
