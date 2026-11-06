<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortalOfflineException;
use App\Http\Middleware\EnsurePortalOnline;
use App\Http\Requests\SipandaiAssignmentDeleteRequest;
use App\Http\Requests\SipandaiAssignmentUploadRequest;
use App\Http\Requests\SipandaiAttendanceRequest;
use App\Http\Requests\SipandaiExamDetailRequest;
use App\Http\Requests\SipandaiExamUploadRequest;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Jobs\ProcessPortalExamUploadJob;
use App\Models\PortalOutboxItem;
use App\Services\AttendanceShortcutService;
use App\Services\PortalActionProjector;
use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalFetchPolicy;
use App\Services\PortalOfflineAccess;
use App\Services\PortalOperationTracker;
use App\Services\PortalOutbox;
use App\Services\PortalUploadLocks;
use App\Services\SipandaiSubmissionService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Group('SIPANDAI - Aksi', 'Endpoint untuk mengirim aksi langsung ke portal SIPANDAI seperti unggah tugas, absensi, dan unggah ujian.', 30)]
class PortalActionController extends CampusApiController
{
    private const ACTION_RESULT_RESPONSE = "array{success: bool, status: 'success'|'error', message: string, data: array<string, mixed>|null, meta: array{timestamp: string}}";

    private const EXAM_LIST_RESPONSE = "array{success: bool, status: 'success', message: string, data: array{exams: list<array<string, mixed>>}, meta: array{timestamp: string}}";

    private const EXAM_DETAIL_RESPONSE = "array{success: bool, status: 'success', message: string, data: array{exam_detail: array<string, mixed>}, meta: array{timestamp: string}}";

    private const ATTENDANCE_SHORTCUT_RESPONSE = "array{success: bool, status: 'success', message: string, data: array{date: string, day: string, current_time: string, classes: list<array<string, mixed>>}, meta: array{timestamp: string}}";

    private const PENDING_ASSIGNMENTS_RESPONSE = "array{success: bool, status: 'success', message: string, data: array{assignments: list<array<string, mixed>>}, meta: array{timestamp: string}}";

    /**
     * Datasets and resources whose content changes when an assignment is uploaded or deleted.
     */
    private const ASSIGNMENT_DATASETS = ['assignments', 'dashboard', 'class_detail', 'meeting_detail'];

    private const ASSIGNMENT_RESOURCES = ['class_detail', 'meeting_detail'];

    private const QUEUED_OFFLINE_RESPONSE = "array{success: true, status: 'success', message: string, data: array{status: 'queued_offline', outbox_item: array<string, mixed>}, meta: array{timestamp: string}}";

    public function __construct(
        private readonly SipandaiSubmissionService $submissionService,
        private readonly AttendanceShortcutService $attendanceShortcutService,
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalFetchPolicy $fetchPolicy,
        private readonly PortalActionProjector $projector,
        private readonly PortalOperationTracker $tracker,
        private readonly PortalBroadcastService $broadcast,
        private readonly PortalOfflineAccess $offlineAccess,
        private readonly PortalOutbox $outbox,
        private readonly PortalUploadLocks $uploadLocks
    ) {}

    /**
     * Registers the upload before its file is written, with what
     * `portal:recover-uploads` needs when the process dies before the job
     * settles it (a killed PHP-FPM worker, a deploy): the file is named
     * after the operation, and the operation remembers its target lock.
     *
     * @param  array{key: string, owner: string}  $uploadLock
     * @return string the stored file's path
     */
    private function stageUpload(UploadedFile $attachment, string $operationId, string $type, string $message, array $uploadLock): string
    {
        $storedFilePath = $this->tracker->uploadFilePath($operationId, $attachment->extension());

        $this->tracker->start($operationId, $type, (string) session()->getId(), $message, [
            'file' => $storedFilePath,
            'lock_key' => $uploadLock['key'],
            'lock_owner' => $uploadLock['owner'],
        ]);

        try {
            $stored = $attachment->storeAs(PortalOperationTracker::UPLOAD_DIRECTORY, basename($storedFilePath));
        } catch (\Throwable $throwable) {
            Storage::delete($storedFilePath);

            throw $throwable;
        }

        if ($stored === false) {
            Storage::delete($storedFilePath);

            throw new \RuntimeException('The uploaded file could not be stored.');
        }

        return $storedFilePath;
    }

    /**
     * Frees the target and drops the stored file of an upload that failed
     * before its job could take them over.
     *
     * @param  array{key: string, owner: string}|null  $uploadLock
     */
    private function abandonUpload(?array $uploadLock, ?string $storedFilePath): void
    {
        $this->uploadLocks->release($uploadLock);

        if ($storedFilePath !== null) {
            Storage::delete($storedFilePath);
        }
    }

    private function uploadInProgressResponse(string $sessionToken, string $target, string $message): JsonResponse
    {
        return $this->booleanResponse(false, $message, [
            'operation_id' => $this->uploadLocks->runningOperationId($sessionToken, $target),
            'status' => 'in_progress',
        ], 409);
    }

    /**
     * An offline session reads the archive only; data that was never archived
     * is explained instead of reported as a server error.
     */
    private function offlineResponse(): JsonResponse
    {
        return $this->booleanResponse(
            false,
            'Data ini belum tersimpan di arsip WeCIC, jadi belum bisa dibuka selama mode offline.',
            null,
            503,
            meta: ['offline' => true]
        );
    }

    /**
     * The student's exam list, and the row of the given exam id in it.
     *
     * Exam ids are class-level and a course's class-fallback row carries the
     * same id with `can_upload=false`, so a row the portal opened for upload
     * wins over the others with that id.
     *
     * @return array{exams: list<mixed>, exam: array<string, mixed>|null}
     */
    private function listedExam(string $sessionToken, int $examId): array
    {
        $exams = $this->datasets->read($sessionToken, 'exams', broadcastSessionId: (string) session()->getId())['data']['exams'] ?? [];
        $exams = is_array($exams) ? array_values($exams) : [];
        $matches = collect($exams)->filter(fn (mixed $exam): bool => is_array($exam) && (string) ($exam['id'] ?? '') === (string) $examId);
        $exam = $matches->first(fn (array $exam): bool => ($exam['can_upload'] ?? true) !== false) ?? $matches->first();

        return ['exams' => $exams, 'exam' => $exam];
    }

    /**
     * An offline session cannot reach the portal: the validated action waits
     * in the outbox (PortalOutbox) and the answer says it is not sent yet.
     *
     * @param  \Closure(): PortalOutboxItem  $queue
     */
    private function queueOffline(string $sessionToken, \Closure $queue): JsonResponse
    {
        if (! $this->outbox->enabled()) {
            return EnsurePortalOnline::offlineResponse();
        }

        $item = $queue();
        $this->outbox->announce((string) session()->getId(), $sessionToken, $item->actionLabel().' masuk antrean offline; belum terkirim ke portal.');

        return $this->booleanResponse(true, $this->outbox->queuedMessage($item), [
            'status' => 'queued_offline',
            'outbox_item' => $item->toApiArray(),
        ], 202);
    }

    /**
     * Other open tabs re-read the views an assignment or attendance action changed.
     */
    private function notifyOtherTabs(string $sessionToken, string $message): void
    {
        $this->broadcast->dataInvalidated(
            (string) session()->getId(),
            $sessionToken,
            ['/api/dashboard', '/api/attendance/today', '/api/assignment/pending'],
            ['dashboard-overview', 'dashboard-pending-assignments', 'meeting-show', 'class-show'],
            $message
        );
    }

    /**
     * Upload tugas SIPANDAI.
     *
     * Endpoint ini mengambil struktur form dari SIPANDAI, menggabungkan field default
     * dengan override yang dikirim klien, lalu mengunggah berkas PDF secara multipart.
     * Wajib dipanggil dalam kondisi sudah login (memiliki `campus_session_token`).
     *
     * Opsi payload:
     * - `courses_id` + `id` + `pertemuan` → gunakan jika sudah punya URL upload tugas mentah.
     * - `courses_id` + `meeting_id` → gunakan jika hanya tahu detail meeting; backend akan mencari `id` dan `pertemuan` otomatis.
     *
     * Detail parameter:
     * - `courses_id` → ambil dari query `courses_id` pada URL upload atau URL meeting.
     * - `meeting_id` → ambil dari path `/meeting/{meeting_id}` bila ingin backend menyelesaikan target upload otomatis.
     * - `id` → ambil dari query `id` pada URL upload (contoh `id=30095`).
     * - `pertemuan` → nilai `pertemuan` pada URL upload (contoh `pertemuan=6`).
     * - `attachment` → upload file PDF yang akan dikirim sebagai `data[tugas]`.
     * - `form_values` → optional, untuk override field lain di form upload (misal `keterangan`, `cmd`, dsb.).
     * - `deadline` → optional, tenggat tugas bila klien mengetahuinya; dipakai saat sesi offline.
     *
     * Saat sesi sedang offline, tugas tidak ditolak: file dan datanya disimpan di
     * antrean MyCIC (`data.status: queued_offline`, belum terkirim ke portal) dan
     * dikirim otomatis begitu sesi tersambung lagi, selama tenggatnya belum lewat.
     */
    #[Response(description: 'Unggah tugas berhasil diproses oleh SIPANDAI.', type: self::ACTION_RESULT_RESPONSE)]
    #[Response(202, description: 'Diterima: sedang dikirim (`queued`), atau menunggu portal bisa diakses (`queued_offline`, belum terkirim).', type: self::QUEUED_OFFLINE_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Payload unggah tugas tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(409, description: 'Unggah tugas yang sama masih diproses; `data.operation_id` menunjuk operasi yang berjalan.', type: self::ACTION_RESULT_RESPONSE)]
    #[Response(500, description: 'Unggah tugas gagal diproses.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function uploadAssignment(SipandaiAssignmentUploadRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
            if ($this->offlineAccess->isOfflineSession($sessionToken)) {
                return $this->queueOffline($sessionToken, fn (): PortalOutboxItem => $this->outbox->queueAssignmentUpload(
                    $sessionToken,
                    $request->courseId(),
                    $request->hasDirectAssignmentPayload() ? $request->discussionId() : null,
                    $request->hasDirectAssignmentPayload() ? $request->meetingNumber() : null,
                    $request->hasMeetingContext() ? $request->meetingId() : null,
                    $request->attachment(),
                    $request->formValues(),
                    $request->deadline(),
                    $request->allowClosed()
                ));
            }

            $attachment = $request->attachment();
            $courseId = $request->courseId();
            $discussionId = $request->discussionId();
            $meetingNumber = $request->meetingNumber();

            if (! $request->hasDirectAssignmentPayload()) {
                $resolvedPayload = $this->attendanceShortcutService->resolveAssignmentUploadPayload(
                    $sessionToken,
                    $request->meetingId(),
                    $courseId
                );

                if ($resolvedPayload === null) {
                    return $this->booleanResponse(false, 'Target upload tugas belum bisa ditentukan untuk meeting ini.', null, 422);
                }

                $courseId = (int) $resolvedPayload['courses_id'];
                $discussionId = (int) $resolvedPayload['id'];
                $meetingNumber = (int) $resolvedPayload['pertemuan'];
            }

            $operationId = (string) Str::uuid();
            $uploadTarget = PortalUploadLocks::assignmentTarget($courseId, $discussionId, $meetingNumber);
            $uploadLock = $this->uploadLocks->acquire($sessionToken, $uploadTarget, $operationId);

            if ($uploadLock === null) {
                return $this->uploadInProgressResponse($sessionToken, $uploadTarget, 'Upload tugas ini masih diproses. Tunggu sampai selesai sebelum mengirim lagi.');
            }

            // A copy queued while offline is older than this file.
            $this->outbox->supersede($sessionToken, PortalOutboxItem::ACTION_ASSIGNMENT_UPLOAD, array_values(array_unique([
                'meeting:'.$discussionId,
                ...($request->hasMeetingContext() ? ['meeting:'.$request->meetingId()] : []),
            ])), (string) session()->getId());

            $storedFilePath = $this->stageUpload($attachment, $operationId, 'assignment_upload', 'Upload tugas diterima, menyiapkan pengiriman.', $uploadLock);

            // Runs right after the response is sent, so the upload starts
            // immediately instead of waiting for the next queue worker run.
            // A process that dies before the job settles the upload leaves
            // it to portal:recover-uploads.
            ProcessPortalAssignmentUploadJob::dispatchAfterResponse(
                $operationId,
                (string) session()->getId(),
                $sessionToken,
                $courseId,
                $discussionId,
                $meetingNumber,
                $storedFilePath,
                $attachment->getClientOriginalName(),
                $attachment->getMimeType() ?: 'application/pdf',
                $request->formValues(),
                $uploadLock['key'],
                $uploadLock['owner'],
                $request->allowClosed()
            );

            return $this->booleanResponse(true, 'Upload tugas diterima dan sedang diproses.', [
                'operation_id' => $operationId,
                'status' => 'queued',
            ], 202);

        } catch (\Throwable $e) {
            $this->abandonUpload($uploadLock ?? null, $storedFilePath ?? null);

            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to upload SIPANDAI assignment', [
                'error' => $e->getMessage(),
                'courses_id' => $request->input('courses_id'),
                'id' => $request->input('id'),
            ]);

            return $this->booleanResponse(false, 'Upload tugas gagal diproses.', null, 500);

        }
    }

    /**
     * Hapus tugas yang pernah diunggah di SIPANDAI.
     *
     * - `id` → ambil dari parameter `id` pada URL hapus (contoh: `...?act=jobdel&id=126663&idx=30095&pertemuan=6`).
     * - `idx` → nilai `idx` pada URL hapus; biasanya sama dengan `id` materi/diskusi.
     * - `pertemuan` → nilai `pertemuan` pada URL hapus.
     */
    #[Response(description: 'Penghapusan tugas berhasil diproses oleh SIPANDAI.', type: self::ACTION_RESULT_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Payload hapus tugas tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Penghapusan tugas gagal diproses.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function deleteAssignment(SipandaiAssignmentDeleteRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
            $result = $this->submissionService->deleteAssignment(
                $sessionToken,
                $request->submissionId(),
                $request->discussionId(),
                $request->meetingNumber()
            );

            if (($result['data']['status'] ?? null) === 'unknown' && $request->courseId() !== null) {
                $result = $this->verifyDeletion($sessionToken, $result, $request->courseId(), $request->discussionId(), $request->meetingNumber());
            }

            if (($result['success'] ?? false) === true) {
                $this->datasets->invalidate($sessionToken, self::ASSIGNMENT_DATASETS, self::ASSIGNMENT_RESOURCES);
                $this->projector->recordAssignmentDeleted(
                    $sessionToken,
                    $request->courseId(),
                    $request->discussionId(),
                    $request->meetingNumber()
                );
                $this->notifyOtherTabs($sessionToken, 'Tugas dihapus; daftar tugas diperbarui.');
            } elseif (($result['data']['status'] ?? null) === 'unknown') {
                // The outcome is unknown, so the upload recorded earlier must
                // not keep the file on screen; the next scrape decides.
                $this->datasets->invalidate($sessionToken, self::ASSIGNMENT_DATASETS, self::ASSIGNMENT_RESOURCES);
                $this->projector->forgetAssignment($sessionToken, $request->discussionId());
            }

            return $this->buildResponse($result, 'sipandai_assignment_delete');

        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to delete SIPANDAI assignment', [
                'error' => $e->getMessage(),
                'id' => $request->input('id'),
                'idx' => $request->input('idx'),
            ]);

            return $this->booleanResponse(false, 'Hapus tugas gagal diproses.', null, 500);

        }
    }

    /**
     * Tandai kehadiran pertemuan SIPANDAI.
     *
     * Mengisi form absensi langsung ke server SIPANDAI dengan kode QR/unix.
     * Endpoint ini mengirim POST request ke URL absensi dengan payload kode dan cmd=Simpan.
     *
     * URL yang dipanggil: `/portal/sipandai/?mod=sipandai&act=actabsen&modulesid={modulesid}&courseid={courseid}`
     *
     * - `courseid` → nilai `courseid` pada URL absensi QR (contoh `courseid=8833`).
     * - `modulesid` → nilai `modulesid` pada URL absensi (contoh `modulesid=30728`).
     * - `kode` → kode QR/unix untuk absensi (contoh `35533429`).
     * - `pertemuan` → opsional; nomor pertemuan untuk referensi.
     * - `deadline` → opsional; batas waktu absensi bila klien mengetahuinya (dipakai saat sesi offline).
     *
     * Tanpa `courseid` dan `modulesid`, target diambil dari `shortcut_context` dan hanya dipakai
     * bila materi pertemuan hari ini sudah ada: tanpa itu targetnya pertemuan sebelumnya, dan kode
     * yang dikirim ke sana ditolak portal saat kelas berlangsung.
     *
     * Saat sesi sedang offline, kode absen disimpan di antrean MyCIC (`data.status:
     * queued_offline`, belum terkirim ke portal) dan dikirim otomatis begitu sesi
     * tersambung lagi, selama harinya belum berganti.
     */
    #[Response(description: 'Absensi berhasil dikirim ke SIPANDAI.', type: self::ACTION_RESULT_RESPONSE)]
    #[Response(202, description: 'Sesi offline: kode absen menunggu portal bisa diakses (belum terkirim).', type: self::QUEUED_OFFLINE_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Payload absensi tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Absensi gagal diproses.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function markAttendance(SipandaiAttendanceRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        $overrides = array_merge($request->formValues(), [
            'kode' => (string) $request->validated('kode'),
        ]);

        try {
            if ($this->offlineAccess->isOfflineSession($sessionToken)) {
                return $this->queueOffline($sessionToken, fn (): PortalOutboxItem => $this->outbox->queueAttendance(
                    $sessionToken,
                    $request->hasDirectAttendancePayload() ? $request->courseId() : null,
                    $request->hasDirectAttendancePayload() ? $request->discussionId() : null,
                    $request->meetingNumber(),
                    $request->shortcutContext(),
                    (string) $request->validated('kode'),
                    $request->formValues(),
                    $request->deadline()
                ));
            }

            $courseId = $request->courseId();
            $discussionId = $request->discussionId();
            $meetingNumber = $request->meetingNumber();

            if (! $request->hasDirectAttendancePayload()) {
                $shortcutContext = $request->shortcutContext();
                $resolvedShortcut = $shortcutContext !== null
                    ? $this->attendanceShortcutService->resolveAttendanceShortcutForClass($sessionToken, $shortcutContext)
                    : null;

                if ($resolvedShortcut === null || empty($resolvedShortcut['attendance_payload'])) {
                    return $this->booleanResponse(false, 'Target absensi terbaru belum bisa ditentukan untuk kelas ini.', null, 422);
                }

                if (($resolvedShortcut['attendance_target_reliable'] ?? false) !== true) {
                    return $this->booleanResponse(false, 'Materi pertemuan hari ini belum diunggah dosen, jadi absensi belum bisa dikirim dari sini. Buka pertemuannya dari halaman Kelas.', null, 422);
                }

                $resolvedPayload = $resolvedShortcut['attendance_payload'];
                $courseId = (int) $resolvedPayload['courseid'];
                $discussionId = (int) $resolvedPayload['modulesid'];
                $meetingNumber = isset($resolvedPayload['pertemuan']) ? (int) $resolvedPayload['pertemuan'] : null;
            }

            $result = $this->submissionService->submitAttendance(
                $sessionToken,
                $courseId,
                $discussionId,
                $meetingNumber,
                $overrides
            );

            if (($result['success'] ?? false) === true) {
                $this->datasets->invalidate(
                    $sessionToken,
                    ['dashboard', 'assignments', 'class_detail', 'meeting_detail'],
                    ['class_detail', 'meeting_detail']
                );

                // Show the student "attended" right away; the background
                // re-sync of the invalidated snapshots confirms it later.
                $result['data'] = [
                    ...($result['data'] ?? []),
                    'attendance' => $this->projector->recordAttendance($sessionToken, $courseId, $discussionId, $meetingNumber),
                ];
                $this->notifyOtherTabs($sessionToken, 'Absensi tercatat; jadwal hari ini diperbarui.');
            }

            return $this->buildResponse($result, 'sipandai_attendance');

        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to submit SIPANDAI attendance', [
                'error' => $e->getMessage(),
                'courseid' => $request->input('courseid'),
                'modulesid' => $request->input('modulesid'),
            ]);

            return $this->booleanResponse(false, 'Absensi gagal diproses.', null, 500);

        }
    }

    /**
     * Ambil kandidat shortcut absensi dan tugas untuk jadwal hari ini.
     */
    #[Response(description: 'Shortcut absensi hari ini berhasil diambil.', type: self::ATTENDANCE_SHORTCUT_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Shortcut absensi hari ini gagal diambil.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function todayAttendanceShortcuts(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        try {
            $shortcuts = $this->fetchPolicy->runFresh(
                fn (): array => $this->attendanceShortcutService->getTodayShortcuts($sessionToken),
                request()->boolean('fresh')
            );

            return $this->booleanResponse(true, 'Attendance shortcuts retrieved successfully.', $shortcuts);
        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to resolve attendance shortcuts', [
                'error' => $e->getMessage(),
            ]);

            return $this->booleanResponse(false, 'Gagal memuat absensi hari ini.', null, 500);
        }
    }

    /**
     * Ambil daftar tugas yang belum dikumpulkan.
     */
    #[Response(description: 'Daftar tugas pending berhasil diambil.', type: self::PENDING_ASSIGNMENTS_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(503, description: 'Sesi offline dan daftar tugas belum tersimpan di arsip.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    #[Response(500, description: 'Daftar tugas pending gagal diambil.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function pendingAssignments(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        try {
            $pendingAssignments = $this->datasets->read(
                $sessionToken,
                'assignments',
                forceFresh: request()->boolean('fresh'),
                broadcastSessionId: (string) session()->getId()
            );

            return $this->booleanResponse(true, 'Pending assignments retrieved successfully.', [
                'assignments' => $pendingAssignments['data']['assignments'] ?? [],
            ], meta: $pendingAssignments['meta']);
        } catch (\Throwable $e) {
            if ($e instanceof PortalOfflineException) {
                return $this->offlineResponse();
            }

            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to resolve pending assignments', [
                'error' => $e->getMessage(),
            ]);

            return $this->booleanResponse(false, 'Gagal memuat daftar tugas.', null, 500);
        }
    }

    /**
     * Ambil daftar ujian UTS/UAS yang tersedia di SIPANDAI.
     */
    #[Response(description: 'Daftar ujian SIPANDAI berhasil diambil.', type: self::EXAM_LIST_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(503, description: 'Sesi offline dan daftar ujian belum tersimpan di arsip.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    #[Response(500, description: 'Daftar ujian gagal diambil.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function listExams(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
            $exams = $this->datasets->read(
                $sessionToken,
                'exams',
                forceFresh: request()->boolean('fresh'),
                broadcastSessionId: (string) session()->getId()
            );

            $message = ($exams['data']['source'] ?? null) === 'class_schedule_fallback'
                ? 'Exam list retrieved from class schedule fallback.'
                : 'Exam list retrieved successfully.';

            return $this->booleanResponse(true, $message, [
                'exams' => $exams['data']['exams'] ?? [],
            ], meta: $exams['meta']);
        } catch (\Throwable $e) {
            if ($e instanceof PortalOfflineException) {
                return $this->offlineResponse();
            }

            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to fetch SIPANDAI exam list', [
                'error' => $e->getMessage(),
            ]);

            return $this->booleanResponse(false, 'Gagal memuat daftar ujian.', null, 500);
        }
    }

    /**
     * Ambil detail/form upload ujian UTS/UAS dari halaman `sendujian`.
     */
    #[PathParameter('id', 'Identifier ujian dari daftar `/exams` atau parameter `id` pada URL halaman unggah ujian SIPANDAI.', type: 'integer', example: 8833)]
    #[Response(description: 'Detail dan metadata form unggah ujian berhasil diambil.', type: self::EXAM_DETAIL_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Identifier ujian tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Detail ujian gagal diambil.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function examDetail(SipandaiExamDetailRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
            // The upload page itself carries no course name; the exam list does,
            // and it also tells whether this id is one of the student's exams.
            ['exams' => $exams, 'exam' => $course] = $this->listedExam($sessionToken, $request->examId());

            if ($course === null && $exams !== []) {
                return $this->booleanResponse(false, 'Ujian tidak ditemukan di daftar ujian kamu.', null, 404);
            }

            $examDetail = $this->submissionService->getExamDetail($sessionToken, $request->examId());

            // The id of a row the portal gave no upload link for is a guess.
            if (($course['can_upload'] ?? true) === false) {
                $examDetail['form']['available'] = false;
            }

            $examDetail['course'] = $course === null ? null : [
                'code' => $course['course_code'] ?? null,
                'name' => $course['course_name'] ?? null,
                'schedule' => $course['schedule'] ?: null,
                'lecturer' => $course['lecturer'] ?? null,
                'status' => $course['status'] ?? null,
            ];

            return $this->booleanResponse(true, 'Exam detail retrieved successfully.', [
                'exam_detail' => $examDetail,
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof PortalOfflineException) {
                return $this->offlineResponse();
            }

            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to fetch SIPANDAI exam detail', [
                'error' => $e->getMessage(),
                'id' => $request->input('id'),
            ]);

            return $this->booleanResponse(false, 'Gagal memuat detail ujian.', null, 500);
        }
    }

    /**
     * Upload berkas UTS/UAS ke SIPANDAI.
     *
     * - `id` → ambil dari parameter `id` pada URL upload ujian (`act=sendujian`).
     * - `attachment` → file ujian yang akan diunggah.
     * - `form_values` → optional, override field tambahan dari form upload ujian.
     *   Field tersembunyi milik portal (`data[id]`, `data[tipe]`, `data[kelas]`) tidak bisa di-override.
     * - `deadline` → optional, batas akhir ujian bila klien mengetahuinya (dipakai saat sesi offline).
     *
     * Hanya ujian yang ada di daftar ujian mahasiswa dan dibuka portal untuk upload yang diterima:
     * id yang dipinjam dari daftar kelas hanya tebakan dan bisa menunjuk ujian mata kuliah lain.
     * Daftar ujian yang tidak bisa dibaca tidak memblokir upload; halaman `sendujian` tetap menentukan.
     * Jawaban tidak dikirim bila halaman `sendujian` tidak menampilkan form upload.
     *
     * Saat sesi sedang offline, jawaban disimpan di antrean MyCIC (`data.status:
     * queued_offline`, belum terkirim ke portal) dan dikirim otomatis begitu sesi
     * tersambung lagi; portal yang menentukan bila waktunya sudah habis.
     */
    #[Response(description: 'Unggah ujian berhasil diproses oleh SIPANDAI.', type: self::ACTION_RESULT_RESPONSE)]
    #[Response(202, description: 'Diterima: sedang dikirim (`queued`), atau menunggu portal bisa diakses (`queued_offline`, belum terkirim).', type: self::QUEUED_OFFLINE_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(404, description: 'Ujian tidak ada di daftar ujian mahasiswa.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    #[Response(422, description: 'Payload unggah ujian tidak valid, atau portal belum membuka upload ujian ini.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(409, description: 'Unggah ujian yang sama masih diproses; `data.operation_id` menunjuk operasi yang berjalan.', type: self::ACTION_RESULT_RESPONSE)]
    #[Response(500, description: 'Unggah ujian gagal diproses.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function uploadExam(SipandaiExamUploadRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
            if ($this->offlineAccess->isOfflineSession($sessionToken)) {
                return $this->queueOffline($sessionToken, fn (): PortalOutboxItem => $this->outbox->queueExamUpload(
                    $sessionToken,
                    $request->examId(),
                    $request->attachment(),
                    $request->formValues(),
                    $request->deadline()
                ));
            }

            try {
                ['exams' => $exams, 'exam' => $exam] = $this->listedExam($sessionToken, $request->examId());
            } catch (\Throwable $listError) {
                if ($this->isCampusSessionExpiredException($listError)) {
                    throw $listError;
                }

                Log::warning('Exam list unavailable while checking an exam upload', [
                    'id' => $request->examId(),
                    'error' => $listError->getMessage(),
                ]);
                [$exams, $exam] = [[], null];
            }

            if ($exam === null && $exams !== []) {
                return $this->booleanResponse(false, 'Ujian tidak ditemukan di daftar ujian kamu.', null, 404);
            }

            if ($exam !== null && ($exam['can_upload'] ?? true) === false) {
                return $this->booleanResponse(false, 'Portal belum membuka upload jawaban untuk ujian ini.', ['status' => 'upload_unavailable'], 422);
            }

            $attachment = $request->attachment();
            $operationId = (string) Str::uuid();
            $uploadTarget = PortalUploadLocks::examTarget($request->examId());
            $uploadLock = $this->uploadLocks->acquire($sessionToken, $uploadTarget, $operationId);

            if ($uploadLock === null) {
                return $this->uploadInProgressResponse($sessionToken, $uploadTarget, 'Upload jawaban ujian ini masih diproses. Tunggu sampai selesai sebelum mengirim lagi.');
            }

            // A copy queued while offline is older than this file.
            $this->outbox->supersede($sessionToken, PortalOutboxItem::ACTION_EXAM_UPLOAD, ['exam:'.$request->examId()], (string) session()->getId());

            $storedFilePath = $this->stageUpload($attachment, $operationId, 'exam_upload', 'Upload ujian diterima, menyiapkan pengiriman.', $uploadLock);

            ProcessPortalExamUploadJob::dispatchAfterResponse(
                $operationId,
                (string) session()->getId(),
                $sessionToken,
                $request->examId(),
                $storedFilePath,
                $attachment->getClientOriginalName(),
                $attachment->getMimeType() ?: 'application/octet-stream',
                $request->formValues(),
                $uploadLock['key'],
                $uploadLock['owner']
            );

            return $this->booleanResponse(true, 'Upload ujian diterima dan sedang diproses.', [
                'operation_id' => $operationId,
                'status' => 'queued',
            ], 202);
        } catch (\Throwable $e) {
            $this->abandonUpload($uploadLock ?? null, $storedFilePath ?? null);

            if ($e instanceof PortalOfflineException) {
                return $this->offlineResponse();
            }

            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to upload SIPANDAI exam', [
                'error' => $e->getMessage(),
                'id' => $request->input('id'),
            ]);

            return $this->booleanResponse(false, 'Upload ujian gagal diproses.', null, 500);
        }
    }

    /**
     * The portal's reply to a delete does not always say what happened; the
     * assignment page does: it offers the upload form again once the file is gone.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function verifyDeletion(string $sessionToken, array $result, int $courseId, int $discussionId, int $meetingNumber): array
    {
        try {
            $state = $this->submissionService->inspectAssignmentSubmissionState($sessionToken, $courseId, $discussionId, $meetingNumber);
        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                throw $e;
            }

            Log::warning('Failed to verify SIPANDAI assignment deletion', [
                'courses_id' => $courseId,
                'id' => $discussionId,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }

        if (($state['status'] ?? null) !== 'not_uploaded') {
            return $result;
        }

        return [
            'success' => true,
            'message' => 'Tugas berhasil dihapus.',
            'data' => [...($result['data'] ?? []), 'status' => 'deleted', 'verified' => true],
        ];
    }

    /**
     * Helper to build consistent JSON responses.
     */
    private function buildResponse(array $result, string $source): JsonResponse
    {
        $statusCode = $result['success'] ? 200 : 422;

        return $this->booleanResponse($result['success'], $result['message'], $result['data'] ?? null, $statusCode);
    }
}
