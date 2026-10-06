<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AttendanceShortcutService;
use App\Services\PortalBroadcastService;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ScanPortalNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $sessionId,
        private readonly string $campusSessionToken
    ) {
        $this->onQueue('campus-refresh');
    }

    public function handle(
        AttendanceShortcutService $attendanceShortcutService,
        PortalBroadcastService $broadcast,
        SipandaiSubmissionService $submissionService,
        ?PortalSnapshotStore $snapshotStore = null
    ): void {
        $snapshotStore ??= app(PortalSnapshotStore::class);
        $lock = Cache::lock(
            'portal_notification_scan_'.$this->campusSessionToken,
            (int) config('portal.realtime.notifications.scan_lock_ttl', 300)
        );

        if (! $lock->get()) {
            return;
        }

        try {
            $pending = $attendanceShortcutService->getPendingAssignmentsSnapshot($this->campusSessionToken);
            $snapshotStore->put($this->campusSessionToken, 'assignments', $pending);
            $shortcuts = $attendanceShortcutService->getTodayShortcuts($this->campusSessionToken);

            foreach (($pending['assignments'] ?? []) as $assignment) {
                $title = (string) ($assignment['title'] ?? 'Tugas baru');
                $urgency = $this->urgencyForDeadline($assignment['deadline_at'] ?? null);
                $dedupeKey = 'portal_notification_'.md5($this->campusSessionToken.$title.$urgency);

                if (Cache::add($dedupeKey, true, now()->addHours(6))) {
                    $broadcast->notification($this->sessionId, $this->campusSessionToken, [
                        'type' => 'assignment',
                        'title' => $title,
                        'message' => $urgency === 'critical' ? 'Deadline tugas semakin dekat.' : 'Tugas tersedia untuk dikerjakan.',
                        'urgency' => $urgency,
                        'cache_keys' => ['/api/assignment/pending'],
                        'sync_keys' => ['dashboard-pending-assignments'],
                    ]);
                }
            }

            foreach (($shortcuts['classes'] ?? []) as $class) {
                if (empty($class['attendance_payload'])) {
                    continue;
                }

                $dedupeKey = 'portal_notification_attendance_'.md5($this->campusSessionToken.($class['course_name'] ?? 'class'));

                if (! Cache::add($dedupeKey, true, now()->addHours(2))) {
                    continue;
                }

                $broadcast->notification($this->sessionId, $this->campusSessionToken, [
                    'type' => 'attendance',
                    'title' => 'Shortcut absensi tersedia',
                    'message' => 'Absensi untuk kelas saat ini dapat dikirim dari myCIC.',
                    'urgency' => 'warning',
                    'cache_keys' => ['/api/attendance/today'],
                    'sync_keys' => ['dashboard-overview'],
                ]);
            }

            try {
                $exams = $submissionService->getExamList($this->campusSessionToken);

                foreach ($exams as $exam) {
                    $status = strtolower((string) ($exam['status'] ?? ''));
                    if (! str_contains($status, 'aktif') && ! str_contains($status, 'active')) {
                        continue;
                    }

                    $title = (string) ($exam['course_name'] ?? 'Ujian aktif');
                    $dedupeKey = 'portal_notification_exam_'.md5($this->campusSessionToken.$title);

                    if (! Cache::add($dedupeKey, true, now()->addHours(6))) {
                        continue;
                    }

                    $broadcast->notification($this->sessionId, $this->campusSessionToken, [
                        'type' => 'exam',
                        'title' => $title,
                        'message' => 'Ujian telah aktif. Siapkan berkas jawaban Anda.',
                        'urgency' => 'critical',
                        'cache_keys' => ['/api/exam'],
                        'sync_keys' => ['exam-index'],
                    ]);
                }
            } catch (\Throwable) {
                // Exam list is optional for notification scan.
            }
        } finally {
            $lock->release();
        }
    }

    private function urgencyForDeadline(mixed $deadline): string
    {
        if (! is_string($deadline) || $deadline === '') {
            return 'info';
        }

        try {
            $deadlineAt = Carbon::parse($deadline);
        } catch (\Throwable) {
            return 'info';
        }

        $hours = now()->diffInHours($deadlineAt, false);

        if ($hours <= (int) config('portal.realtime.notifications.deadline_urgent_hours', 24)) {
            return 'critical';
        }

        if ($hours <= (int) config('portal.realtime.notifications.deadline_warning_hours', 72)) {
            return 'warning';
        }

        return 'info';
    }
}
