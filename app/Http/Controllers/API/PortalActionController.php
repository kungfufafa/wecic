<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\SipandaiAssignmentDeleteRequest;
use App\Http\Requests\SipandaiAssignmentUploadRequest;
use App\Http\Requests\SipandaiAttendanceRequest;
use App\Http\Requests\SipandaiExamDetailRequest;
use App\Http\Requests\SipandaiExamUploadRequest;
use App\Services\AttendanceShortcutService;
use App\Services\SipandaiSubmissionService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

#[Group('SIPANDAI - Aksi', 'Endpoint untuk mengirim aksi langsung ke portal SIPANDAI seperti unggah tugas, absensi, dan unggah ujian.', 30)]
class PortalActionController extends CampusApiController
{
    private const ACTION_RESULT_RESPONSE = 'array{success: bool, message: string, data: array<string, mixed>|null}';

    private const EXAM_LIST_RESPONSE = 'array{success: bool, message: string, data: array{exams: list<array<string, mixed>>}}';

    private const EXAM_DETAIL_RESPONSE = 'array{success: bool, message: string, data: array{exam_detail: array<string, mixed>}}';

    private const ATTENDANCE_SHORTCUT_RESPONSE = 'array{success: bool, message: string, data: array{date: string, day: string, current_time: string, classes: list<array<string, mixed>>}}';

    private const PENDING_ASSIGNMENTS_RESPONSE = 'array{success: bool, message: string, data: array{assignments: list<array<string, mixed>>}}';

    public function __construct(
        private readonly SipandaiSubmissionService $submissionService,
        private readonly AttendanceShortcutService $attendanceShortcutService
    ) {}

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
     */
    #[Response(description: 'Unggah tugas berhasil diproses oleh SIPANDAI.', type: self::ACTION_RESULT_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Payload unggah tugas tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Unggah tugas gagal diproses.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function uploadAssignment(SipandaiAssignmentUploadRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
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

            $result = $this->submissionService->submitAssignment(
                $sessionToken,
                $courseId,
                $discussionId,
                $meetingNumber,
                $attachment,
                $request->formValues()
            );

            return $this->buildResponse($result, 'sipandai_assignment_upload');

        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to upload SIPANDAI assignment', [
                'error' => $e->getMessage(),
                'courses_id' => $request->input('courses_id'),
                'id' => $request->input('id'),
            ]);

            return $this->booleanResponse(false, 'Failed to upload assignment.', null, 500);

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

            return $this->booleanResponse(false, 'Failed to delete assignment.', null, 500);

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
     */
    #[Response(description: 'Absensi berhasil dikirim ke SIPANDAI.', type: self::ACTION_RESULT_RESPONSE)]
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

            return $this->booleanResponse(false, 'Failed to mark attendance.', null, 500);

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
            $shortcuts = $this->attendanceShortcutService->getTodayShortcuts($sessionToken);

            return $this->booleanResponse(true, 'Attendance shortcuts retrieved successfully.', $shortcuts);
        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to resolve attendance shortcuts', [
                'error' => $e->getMessage(),
            ]);

            return $this->booleanResponse(false, 'Failed to resolve attendance shortcuts.', null, 500);
        }
    }

    /**
     * Ambil daftar tugas yang belum dikumpulkan.
     */
    #[Response(description: 'Daftar tugas pending berhasil diambil.', type: self::PENDING_ASSIGNMENTS_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Daftar tugas pending gagal diambil.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function pendingAssignments(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        try {
            $pendingAssignmentsData = $this->attendanceShortcutService->getPendingAssignmentsSnapshot($sessionToken);
            $syncMeta = $this->consumeSyncMeta($pendingAssignmentsData, [
                'requested_fresh' => request()->boolean('fresh'),
            ]);

            return $this->booleanResponse(true, 'Pending assignments retrieved successfully.', [
                'assignments' => $pendingAssignmentsData['assignments'] ?? [],
            ], meta: $syncMeta);
        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to resolve pending assignments', [
                'error' => $e->getMessage(),
            ]);

            return $this->booleanResponse(false, 'Failed to resolve pending assignments.', null, 500);
        }
    }

    /**
     * Ambil daftar ujian UTS/UAS yang tersedia di SIPANDAI.
     */
    #[Response(description: 'Daftar ujian SIPANDAI berhasil diambil.', type: self::EXAM_LIST_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Daftar ujian gagal diambil.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function listExams(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
            $exams = $this->submissionService->getExamList($sessionToken);

            return $this->booleanResponse(true, 'Exam list retrieved successfully.', [
                'exams' => $exams,
            ]);
        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to fetch SIPANDAI exam list', [
                'error' => $e->getMessage(),
            ]);

            return $this->booleanResponse(false, 'Failed to fetch exam list.', null, 500);
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
            $examDetail = $this->submissionService->getExamDetail($sessionToken, $request->examId());

            return $this->booleanResponse(true, 'Exam detail retrieved successfully.', [
                'exam_detail' => $examDetail,
            ]);
        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to fetch SIPANDAI exam detail', [
                'error' => $e->getMessage(),
                'id' => $request->input('id'),
            ]);

            return $this->booleanResponse(false, 'Failed to fetch exam detail.', null, 500);
        }
    }

    /**
     * Upload berkas UTS/UAS ke SIPANDAI.
     *
     * - `id` → ambil dari parameter `id` pada URL upload ujian (`act=sendujian`).
     * - `attachment` → file ujian yang akan diunggah.
     * - `form_values` → optional, override field tambahan dari form upload ujian.
     */
    #[Response(description: 'Unggah ujian berhasil diproses oleh SIPANDAI.', type: self::ACTION_RESULT_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Payload unggah ujian tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Unggah ujian gagal diproses.', type: CampusApiController::SCRAMBLE_BOOLEAN_RESPONSE)]
    public function uploadExam(SipandaiExamUploadRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
            $result = $this->submissionService->submitExam(
                $sessionToken,
                $request->examId(),
                $request->attachment(),
                $request->formValues()
            );

            return $this->buildResponse($result, 'sipandai_exam_upload');
        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Failed to upload SIPANDAI exam', [
                'error' => $e->getMessage(),
                'id' => $request->input('id'),
            ]);

            return $this->booleanResponse(false, 'Failed to upload exam.', null, 500);
        }
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
