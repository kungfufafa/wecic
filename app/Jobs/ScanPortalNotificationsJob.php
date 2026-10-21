<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PortalException;
use App\Services\ActionCenterBuilder;
use App\Services\AttendanceShortcutService;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetRepository;
use App\Services\PortalNotificationCenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Time-based reminders: deadlines coming up and a class whose attendance is
 * open right now. Changes in portal data (new assignment, exam opened, new
 * grade, ...) are not decided here: re-syncing the datasets lets their
 * `notify` rules raise those (PortalSnapshotDiffNotifier).
 *
 * Reminders are keyed by student and record, never by login, so logging in
 * again does not repeat them.
 */
class ScanPortalNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Datasets whose changes matter within hours, re-synced on every scan.
     */
    private const TIME_SENSITIVE_DATASETS = ['assignments', 'exams'];

    private const REMINDER_SEVERITIES = ['critical', 'warning'];

    public function __construct(
        private readonly string $sessionId,
        private readonly string $campusSessionToken
    ) {
        // Lower priority than data refreshes: a slow scan must not delay the
        // dashboard the student is waiting for after login.
        $this->onQueue('campus-notifications');
    }

    public function handle(
        PortalDatasetRepository $datasets,
        ActionCenterBuilder $actionCenter,
        AttendanceShortcutService $attendanceShortcutService,
        PortalNotificationCenter $notifications,
        PortalDataOwner $owner
    ): void {
        $lock = Cache::lock(
            'portal_notification_scan_'.$owner->key($this->campusSessionToken),
            (int) config('portal.realtime.notifications.scan_lock_ttl', 300)
        );

        if (! $lock->get()) {
            return;
        }

        try {
            foreach (self::TIME_SENSITIVE_DATASETS as $dataset) {
                try {
                    $datasets->ensureFresh($this->campusSessionToken, $dataset);
                } catch (\Throwable $throwable) {
                    if (PortalException::indicatesExpiredSession($throwable)) {
                        return;
                    }

                    // The last snapshot still feeds the reminders below.
                    Log::info('Notification scan could not refresh a dataset', [
                        'dataset' => $dataset,
                        'error' => $throwable->getMessage(),
                    ]);
                }
            }

            $notifications->publish(
                $this->campusSessionToken,
                $this->deadlineReminders($actionCenter->build($this->campusSessionToken, $this->sessionId)['items']),
                $this->sessionId
            );

            $notifications->publish(
                $this->campusSessionToken,
                $this->attendanceReminders($attendanceShortcutService->getTodayShortcuts($this->campusSessionToken)['classes'] ?? []),
                $this->sessionId
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Action center items with a due date that turned warning or critical:
     * at most one reminder per item, due date and severity.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{type: string, dataset: string|null, record_key: string, dedupe_key: string, title: string, body: string|null, url: string|null, urgency: string}>
     */
    private function deadlineReminders(array $items): array
    {
        $reminders = [];

        foreach ($items as $item) {
            $dueAt = $item['due_at'] ?? null;
            $severity = $item['severity'] ?? null;

            if (! is_string($dueAt) || $dueAt === '' || ! in_array($severity, self::REMINDER_SEVERITIES, true)) {
                continue;
            }

            $id = (string) ($item['id'] ?? '');

            $reminders[] = [
                'type' => 'deadline_reminder',
                'dataset' => is_string($item['source'] ?? null) ? $item['source'] : null,
                'record_key' => $id,
                'dedupe_key' => hash('sha256', implode("\0", ['reminder', $id, $dueAt, $severity])),
                'title' => (string) ($item['title'] ?? 'Tenggat mendekat'),
                'body' => is_string($item['description'] ?? null) ? $item['description'] : null,
                'url' => ($item['cta_external'] ?? false) === true ? '/dashboard' : (string) ($item['cta_url'] ?? '/dashboard'),
                'urgency' => $severity,
            ];
        }

        return $reminders;
    }

    /**
     * Classes in session now whose attendance has not been sent yet.
     *
     * @param  list<array<string, mixed>>  $classes
     * @return list<array{type: string, dataset: null, record_key: string, dedupe_key: string, title: string, body: string, url: string, urgency: string}>
     */
    private function attendanceReminders(array $classes): array
    {
        $reminders = [];

        foreach ($classes as $class) {
            $meetingId = (string) ($class['meeting_id'] ?? '');

            if ($meetingId === ''
                || empty($class['attendance_payload'])
                || ($class['active_now'] ?? false) !== true
                || ($class['attendance_status']['already_marked'] ?? false) === true) {
                continue;
            }

            $courseName = trim((string) ($class['course_name'] ?? ''));
            $coursesId = (string) ($class['attendance_payload']['courseid'] ?? '');

            $reminders[] = [
                'type' => 'attendance_open',
                'dataset' => null,
                'record_key' => $meetingId,
                'dedupe_key' => hash('sha256', implode("\0", ['attendance', $meetingId])),
                'title' => $courseName !== '' ? "Absen {$courseName} sekarang" : 'Absen kelas yang sedang berlangsung',
                'body' => 'Kelas sedang berlangsung dan absensimu belum tercatat. Kirim dari MyCIC.',
                'url' => '/meeting/'.rawurlencode($meetingId).($coursesId !== '' ? '?courses_id='.rawurlencode($coursesId) : ''),
                'urgency' => 'warning',
            ];
        }

        return $reminders;
    }
}
