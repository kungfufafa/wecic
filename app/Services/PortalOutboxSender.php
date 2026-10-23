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
 * attendance link) are resolved now that the portal answers, with the same
 * checks as online.
 *
 * An upload takes the same per-target lock as the upload endpoints
 * (PortalUploadLocks), and its job gets a copy of the queued file: the job
 * deletes what it is given, while the queued file stays until the item is
 * settled, so a flush the portal session cuts short loses nothing.
 */
class PortalOutboxSender
{
    /**
     * Another upload of the same target is running: the item waits for a later flush.
     */
    public const DEFERRED = 'deferred';

    public function __construct(
        private readonly SipandaiSubmissionService $submissions,
        private readonly AttendanceShortcutService $shortcuts,
        private readonly PortalOperationTracker $tracker,
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalActionProjector $projector,
        private readonly PortalBroadcastService $broadcast,
        private readonly PortalUploadLocks $uploadLocks
    ) {}

    /**
     * @return array{0: string, 1: string|null} The outcome (`PortalOutboxItem::STATUS_SENT`,
     *                                          `STATUS_FAILED`, `STATUS_UNVERIFIED` or self::DEFERRED) and its message.
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
                default => [PortalOutboxItem::STATUS_FAILED, 'Jenis aksi tidak dikenal.'],
            };
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            return [PortalOutboxItem::STATUS_FAILED, $throwable->getMessage()];
        }
    }

    /**
     * @return array{0: string, 1: string|null}
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
                return [PortalOutboxItem::STATUS_FAILED, 'Target upload tugas belum bisa ditentukan untuk meeting ini.'];
            }

            $courseId = (int) $resolved['courses_id'];
            $discussionId = (int) $resolved['id'];
            $meetingNumber = (int) $resolved['pertemuan'];
        }

        if ($item->file_path === null || ! Storage::exists($item->file_path)) {
            return [PortalOutboxItem::STATUS_FAILED, 'File tugas tidak ditemukan lagi di MyCIC.'];
        }

        $operationId = (string) Str::uuid();
        $upload = $this->stage($item, $sessionToken, $sessionId, $operationId, 'assignment_upload', 'Mengirim tugas dari antrean offline.', PortalUploadLocks::assignmentTarget($courseId, $discussionId, $meetingNumber));

        if (! is_array($upload)) {
            return $upload === self::DEFERRED ? [self::DEFERRED, null] : [PortalOutboxItem::STATUS_FAILED, 'File tugas tidak bisa disiapkan untuk dikirim.'];
        }

        $this->runUpload(fn () => ProcessPortalAssignmentUploadJob::dispatchSync(
            $operationId,
            $sessionId,
            $sessionToken,
            $courseId,
            $discussionId,
            $meetingNumber,
            $upload['file'],
            (string) ($payload['file_name'] ?? 'tugas.pdf'),
            (string) ($payload['mime_type'] ?? 'application/pdf'),
            is_array($payload['form_values'] ?? null) ? $payload['form_values'] : [],
            $upload['lock_key'],
            $upload['lock_owner']
        ));

        return $this->operationOutcome($operationId, 'Upload tugas gagal diproses portal.');
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function sendExam(PortalOutboxItem $item, string $sessionToken, string $sessionId): array
    {
        $payload = $item->payload;
        $examId = (int) ($payload['id'] ?? 0);

        if ($item->file_path === null || ! Storage::exists($item->file_path)) {
            return [PortalOutboxItem::STATUS_FAILED, 'File ujian tidak ditemukan lagi di MyCIC.'];
        }

        $operationId = (string) Str::uuid();
        $upload = $this->stage($item, $sessionToken, $sessionId, $operationId, 'exam_upload', 'Mengirim jawaban ujian dari antrean offline.', PortalUploadLocks::examTarget($examId));

        if (! is_array($upload)) {
            return $upload === self::DEFERRED ? [self::DEFERRED, null] : [PortalOutboxItem::STATUS_FAILED, 'File ujian tidak bisa disiapkan untuk dikirim.'];
        }

        $this->runUpload(fn () => ProcessPortalExamUploadJob::dispatchSync(
            $operationId,
            $sessionId,
            $sessionToken,
            $examId,
            $upload['file'],
            (string) ($payload['file_name'] ?? 'ujian.pdf'),
            (string) ($payload['mime_type'] ?? 'application/octet-stream'),
            is_array($payload['form_values'] ?? null) ? $payload['form_values'] : [],
            $upload['lock_key'],
            $upload['lock_owner']
        ));

        return $this->operationOutcome($operationId, 'Upload ujian gagal diproses portal.');
    }

    /**
     * Takes the target's upload lock and registers the upload with a copy
     * of the queued file, as the upload endpoints do (stageUpload), so
     * `portal:recover-uploads` settles it if the process dies mid-way.
     *
     * @return array{file: string, lock_key: string, lock_owner: string}|string The upload, self::DEFERRED, or 'failed'.
     */
    private function stage(PortalOutboxItem $item, string $sessionToken, string $sessionId, string $operationId, string $type, string $message, string $target): array|string
    {
        $lock = $this->uploadLocks->acquire($sessionToken, $target, $operationId);

        if ($lock === null) {
            return self::DEFERRED;
        }

        $copy = $this->tracker->uploadFilePath($operationId, pathinfo((string) $item->file_path, PATHINFO_EXTENSION) ?: null);
        $upload = ['file' => $copy, 'lock_key' => $lock['key'], 'lock_owner' => $lock['owner']];
        $this->tracker->start($operationId, $type, $sessionId !== '' ? $sessionId : null, $message, $upload);

        try {
            $copied = Storage::copy((string) $item->file_path, $copy);
        } catch (\Throwable) {
            $copied = false;
        }

        if (! $copied) {
            Storage::delete($copy);
            $this->uploadLocks->release($lock);
            $this->tracker->progress($operationId, PortalOperationStage::Failed, 'File antrean tidak bisa disiapkan.', 100);

            return 'failed';
        }

        return $upload;
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
     * @return array{0: string, 1: string|null}
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
                return [PortalOutboxItem::STATUS_FAILED, 'Target absensi terbaru belum bisa ditentukan untuk kelas ini.'];
            }

            // As online: without today's material the link points at an
            // earlier meeting, which the portal would report as attended.
            if (($resolved['attendance_target_reliable'] ?? false) !== true) {
                return [PortalOutboxItem::STATUS_FAILED, 'Materi pertemuan hari ini belum diunggah dosen, jadi absensi belum bisa dikirim dari antrean. Buka pertemuannya dari halaman Kelas.'];
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
            return [PortalOutboxItem::STATUS_FAILED, (string) ($result['message'] ?? 'Absensi ditolak portal.')];
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

        return [PortalOutboxItem::STATUS_SENT, null];
    }

    /**
     * The upload jobs report through the operation tracker; `completed`
     * means the portal has the file (verified on its page for assignments),
     * `unverified` that it may have it (sending again could store it twice).
     * Anything else failed. Each keeps the job's wording, with its detail
     * and next step.
     *
     * @return array{0: string, 1: string|null}
     */
    private function operationOutcome(string $operationId, string $fallback): array
    {
        $operation = $this->tracker->get($operationId) ?? [];
        $stage = $operation['stage'] ?? null;

        if ($stage === PortalOperationStage::Completed->value) {
            return [PortalOutboxItem::STATUS_SENT, null];
        }

        $message = trim((string) ($operation['message'] ?? ''));
        $followUp = implode(' ', array_filter([
            trim((string) ($operation['detail'] ?? '')),
            trim((string) ($operation['next_step'] ?? '')),
        ], static fn (string $part): bool => $part !== ''));

        return [
            $stage === PortalOperationStage::Unverified->value ? PortalOutboxItem::STATUS_UNVERIFIED : PortalOutboxItem::STATUS_FAILED,
            trim(($message !== '' ? $message : $fallback).($followUp !== '' ? ' '.$followUp : '')),
        ];
    }
}
