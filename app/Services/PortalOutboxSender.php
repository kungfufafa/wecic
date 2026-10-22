<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalOperationStage;
use App\Exceptions\PortalException;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Jobs\ProcessPortalExamUploadJob;
use App\Models\PortalOutboxItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends one outbox item to the portal, exactly as the online endpoints do:
 * uploads run through the same jobs (progress events, verification on the
 * assignment page, dataset invalidation, projection), an attendance code
 * through the same submission call and after-effects as
 * PortalActionController::markAttendance. Targets left unresolved while
 * offline (a meeting id without its upload ids, a class without today's
 * attendance link) are resolved now that the portal answers.
 */
class PortalOutboxSender
{
    public function __construct(
        private readonly SipandaiSubmissionService $submissions,
        private readonly AttendanceShortcutService $shortcuts,
        private readonly PortalOperationTracker $tracker,
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalActionProjector $projector,
        private readonly PortalBroadcastService $broadcast
    ) {}

    /**
     * @return array{0: bool, 1: string|null} Whether the portal took it, and the error otherwise.
     *
     * @throws \Throwable An expired campus session: the flush must stop, not mark the item failed.
     */
    public function send(PortalOutboxItem $item, string $sessionToken, ?string $sessionId): array
    {
        try {
            return match ($item->action) {
                PortalOutboxItem::ACTION_ASSIGNMENT_UPLOAD => $this->sendAssignment($item, $sessionToken, (string) $sessionId),
                PortalOutboxItem::ACTION_EXAM_UPLOAD => $this->sendExam($item, $sessionToken, (string) $sessionId),
                PortalOutboxItem::ACTION_ATTENDANCE => $this->sendAttendance($item, $sessionToken, $sessionId),
                default => [false, 'Jenis aksi tidak dikenal.'],
            };
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            return [false, $throwable->getMessage()];
        }
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function sendAssignment(PortalOutboxItem $item, string $sessionToken, string $sessionId): array
    {
        $payload = $item->payload;
        $courseId = (int) ($payload['courses_id'] ?? 0);
        $discussionId = is_numeric($payload['id'] ?? null) ? (int) $payload['id'] : null;
        $meetingNumber = is_numeric($payload['pertemuan'] ?? null) ? (int) $payload['pertemuan'] : null;

        if ($discussionId === null || $meetingNumber === null) {
            $resolved = is_numeric($payload['meeting_id'] ?? null)
                ? $this->shortcuts->resolveAssignmentUploadPayload($sessionToken, (int) $payload['meeting_id'], $courseId)
                : null;

            if ($resolved === null) {
                return [false, 'Target upload tugas belum bisa ditentukan untuk meeting ini.'];
            }

            $courseId = (int) $resolved['courses_id'];
            $discussionId = (int) $resolved['id'];
            $meetingNumber = (int) $resolved['pertemuan'];
        }

        if ($item->file_path === null || ! Storage::exists($item->file_path)) {
            return [false, 'File tugas tidak ditemukan lagi di MyCIC.'];
        }

        $operationId = (string) Str::uuid();
        $this->tracker->start($operationId, 'assignment_upload', $sessionId !== '' ? $sessionId : null, 'Mengirim tugas dari antrean offline.');

        $this->runUpload(fn () => ProcessPortalAssignmentUploadJob::dispatchSync(
            $operationId,
            $sessionId,
            $sessionToken,
            $courseId,
            $discussionId,
            $meetingNumber,
            $item->file_path,
            (string) ($payload['file_name'] ?? 'tugas.pdf'),
            (string) ($payload['mime_type'] ?? 'application/pdf'),
            is_array($payload['form_values'] ?? null) ? $payload['form_values'] : []
        ));

        return $this->operationOutcome($operationId, 'Upload tugas gagal diproses portal.');
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function sendExam(PortalOutboxItem $item, string $sessionToken, string $sessionId): array
    {
        $payload = $item->payload;

        if ($item->file_path === null || ! Storage::exists($item->file_path)) {
            return [false, 'File ujian tidak ditemukan lagi di MyCIC.'];
        }

        $operationId = (string) Str::uuid();
        $this->tracker->start($operationId, 'exam_upload', $sessionId !== '' ? $sessionId : null, 'Mengirim jawaban ujian dari antrean offline.');

        $this->runUpload(fn () => ProcessPortalExamUploadJob::dispatchSync(
            $operationId,
            $sessionId,
            $sessionToken,
            (int) ($payload['id'] ?? 0),
            $item->file_path,
            (string) ($payload['file_name'] ?? 'ujian.pdf'),
            (string) ($payload['mime_type'] ?? 'application/octet-stream'),
            is_array($payload['form_values'] ?? null) ? $payload['form_values'] : []
        ));

        return $this->operationOutcome($operationId, 'Upload ujian gagal diproses portal.');
    }

    /**
     * An upload job that throws has already recorded the failure (message
     * and detail) on its operation, in the student's words; only an expired
     * campus session is passed on, so the flush stops.
     */
    private function runUpload(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }
        }
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function sendAttendance(PortalOutboxItem $item, string $sessionToken, ?string $sessionId): array
    {
        $payload = $item->payload;
        $courseId = is_numeric($payload['courseid'] ?? null) ? (int) $payload['courseid'] : null;
        $discussionId = is_numeric($payload['modulesid'] ?? null) ? (int) $payload['modulesid'] : null;
        $meetingNumber = is_numeric($payload['pertemuan'] ?? null) ? (int) $payload['pertemuan'] : null;

        if ($courseId === null || $discussionId === null) {
            $context = is_array($payload['shortcut_context'] ?? null) ? $payload['shortcut_context'] : null;
            $resolved = $context !== null
                ? $this->shortcuts->resolveAttendanceShortcutForClass($sessionToken, [
                    'class_id' => (int) ($context['class_id'] ?? 0),
                    'dosen_id' => (int) ($context['dosen_id'] ?? 0),
                    'course_id' => (int) ($context['course_id'] ?? 0),
                ])
                : null;

            if ($resolved === null || empty($resolved['attendance_payload'])) {
                return [false, 'Target absensi terbaru belum bisa ditentukan untuk kelas ini.'];
            }

            $courseId = (int) $resolved['attendance_payload']['courseid'];
            $discussionId = (int) $resolved['attendance_payload']['modulesid'];
            $meetingNumber = isset($resolved['attendance_payload']['pertemuan']) ? (int) $resolved['attendance_payload']['pertemuan'] : null;
        }

        $overrides = [
            ...(is_array($payload['form_values'] ?? null) ? $payload['form_values'] : []),
            'kode' => (string) ($payload['kode'] ?? ''),
        ];

        $result = $this->submissions->submitAttendance($sessionToken, $courseId, $discussionId, $meetingNumber, $overrides);

        if (($result['success'] ?? false) !== true) {
            return [false, (string) ($result['message'] ?? 'Absensi ditolak portal.')];
        }

        $this->datasets->invalidate(
            $sessionToken,
            ['dashboard', 'assignments', 'class_detail', 'meeting_detail'],
            ['class_detail', 'meeting_detail']
        );
        $this->projector->recordAttendance($sessionToken, $courseId, $discussionId, $meetingNumber);

        if ($sessionId !== null && $sessionId !== '') {
            $this->broadcast->dataInvalidated(
                $sessionId,
                $sessionToken,
                ['/api/dashboard', '/api/attendance/today', '/api/assignment/pending'],
                ['dashboard-overview', 'dashboard-pending-assignments', 'meeting-show', 'class-show'],
                'Absensi dari antrean offline tercatat; jadwal hari ini diperbarui.'
            );
        }

        return [true, null];
    }

    /**
     * The upload jobs report through the operation tracker; `completed`
     * means the portal has the file (verified on its page for assignments).
     *
     * @return array{0: bool, 1: string|null}
     */
    private function operationOutcome(string $operationId, string $fallback): array
    {
        $operation = $this->tracker->get($operationId) ?? [];

        if (($operation['stage'] ?? null) === PortalOperationStage::Completed->value) {
            return [true, null];
        }

        $message = trim((string) ($operation['message'] ?? ''));
        $detail = trim((string) ($operation['detail'] ?? ''));

        return [false, trim(($message !== '' ? $message : $fallback).($detail !== '' ? ' '.$detail : ''))];
    }
}
