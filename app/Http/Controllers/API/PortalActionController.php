<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\SipandaiAssignmentDeleteRequest;
use App\Http\Requests\SipandaiAssignmentUploadRequest;
use App\Http\Requests\SipandaiAttendanceRequest;
use App\Http\Requests\SipandaiExamDetailRequest;
use App\Http\Requests\SipandaiExamUploadRequest;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Jobs\ProcessPortalExamUploadJob;
use App\Jobs\RefreshPortalDataJob;
use App\Services\AttendanceShortcutService;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        private readonly AttendanceShortcutService $attendanceShortcutService,
        private readonly ClassesExtractor $classesExtractor,
        private readonly SipandaiClassesExtractor $sipandaiClassesExtractor,
        private ?PortalSnapshotStore $snapshotStore = null
    ) {
        $this->snapshotStore = $snapshotStore ?? app(PortalSnapshotStore::class);
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

            $operationId = (string) Str::uuid();
            $storedFilePath = $attachment->store('portal-uploads');

            ProcessPortalAssignmentUploadJob::dispatch(
                $operationId,
                (string) session()->getId(),
                $sessionToken,
                $courseId,
                $discussionId,
                $meetingNumber,
                $storedFilePath,
                $attachment->getClientOriginalName(),
                $attachment->getMimeType() ?: 'application/pdf',
                $request->formValues()
            );

            return response()->json([
                'success' => true,
                'message' => 'Upload tugas diterima dan sedang diproses.',
                'data' => [
                    'operation_id' => $operationId,
                    'status' => 'queued',
                ],
            ], 202);

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

            if (($result['success'] ?? false) === true && $courseId > 0 && $discussionId > 0) {
                $this->classesExtractor->invalidateMeetingDetailCache(
                    $sessionToken,
                    (string) $discussionId,
                    (string) $courseId
                );
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
            $forceFresh = request()->boolean('fresh');

            if (! $forceFresh) {
                $snapshot = $this->snapshotStore->get($sessionToken, 'assignments');

                if ($snapshot !== null) {
                    $pendingAssignmentsData = $snapshot['data'];
                    $syncMeta = $this->consumeSyncMeta($pendingAssignmentsData, [
                        'data_origin' => 'snapshot',
                        'synced_at' => $snapshot['synced_at'] ?? now()->toISOString(),
                        'status' => $snapshot['status'] ?? 'synced',
                        'requested_fresh' => false,
                    ]);

                    if ($this->snapshotStore->isStale($sessionToken, 'assignments', 600)) {
                        RefreshPortalDataJob::dispatch(
                            (string) session()->getId(),
                            $sessionToken,
                            ['assignments']
                        );
                        $syncMeta['status'] = 'syncing';
                    }

                    return $this->booleanResponse(true, 'Pending assignments retrieved successfully.', [
                        'assignments' => $pendingAssignmentsData['assignments'] ?? [],
                    ], meta: $syncMeta);
                }
            }

            $pendingAssignmentsData = $this->attendanceShortcutService->getPendingAssignmentsSnapshot($sessionToken);
            $this->snapshotStore->put($sessionToken, 'assignments', $pendingAssignmentsData);
            $syncMeta = $this->consumeSyncMeta($pendingAssignmentsData, [
                'requested_fresh' => $forceFresh,
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
            $exams = $this->resolveExamListFromClasses($sessionToken, $exams);

            return $this->booleanResponse(true, 'Exam list retrieved successfully.', [
                'exams' => $exams,
            ]);
        } catch (\Throwable $e) {
            if ($this->isCampusSessionExpiredException($e)) {
                return $this->expiredSessionBooleanResponse();
            }

            $fallbackExams = $this->resolveExamListFromClasses($sessionToken, []);

            if ($fallbackExams !== []) {
                Log::warning('SIPANDAI exam list failed, using class schedule fallback', [
                    'error' => $e->getMessage(),
                ]);

                return $this->booleanResponse(true, 'Exam list retrieved from class schedule fallback.', [
                    'exams' => $fallbackExams,
                ]);
            }

            Log::error('Failed to fetch SIPANDAI exam list', [
                'error' => $e->getMessage(),
            ]);

            return $this->booleanResponse(false, 'Failed to fetch exam list.', null, 500);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $exams
     * @return list<array<string, mixed>>
     */
    private function resolveExamListFromClasses(string $sessionToken, array $exams): array
    {
        if ($exams !== [] && ! $this->hasExamRowsWithoutIds($exams)) {
            return $exams;
        }

        try {
            $classesData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $classExams = $this->buildExamListFromClasses($classesData['classes'] ?? []);

            if ($classExams === []) {
                return $exams;
            }

            if ($exams === []) {
                return $classExams;
            }

            return $this->mergeExamRowsWithClassFallbacks($exams, $classExams);
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve exam list from class fallback', [
                'error' => $e->getMessage(),
            ]);

            return $exams;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $exams
     */
    private function hasExamRowsWithoutIds(array $exams): bool
    {
        foreach ($exams as $exam) {
            if (empty($exam['id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     * @return list<array<string, mixed>>
     */
    private function buildExamListFromClasses(array $classes): array
    {
        $exams = [];

        foreach ($classes as $class) {
            $courseId = $this->classCourseId($class);
            $courseCode = (string) ($class['course_info']['code'] ?? '');
            $courseName = (string) ($class['course_info']['name'] ?? '');

            if ($courseId === null || $courseName === '') {
                continue;
            }

            $exams[] = [
                'id' => $courseId,
                'course_code' => $courseCode,
                'course_name' => $courseName,
                'schedule' => $this->formatClassSchedule($class['schedule'] ?? []),
                'status' => 'Terjadwal',
                'upload_url' => PortalEndpointRegistry::url('exam_upload_form', ['id' => $courseId]),
                'source' => 'class_schedule',
                'class_reference' => [
                    'id' => $class['join_link']['params']['id'] ?? null,
                    'course_id' => $courseId,
                    'dosen_id' => $class['join_link']['params']['dosen_id'] ?? null,
                ],
            ];
        }

        return $exams;
    }

    /**
     * @param  array<string, mixed>  $class
     */
    private function classCourseId(array $class): ?int
    {
        $courseId = $class['join_link']['params']['course_id'] ?? null;

        if (is_numeric($courseId)) {
            return (int) $courseId;
        }

        $classId = $class['join_link']['params']['id'] ?? null;

        return is_numeric($classId) ? (int) $classId : null;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function formatClassSchedule(array $schedule): string
    {
        $day = trim((string) ($schedule['day'] ?? ''));
        $time = trim((string) ($schedule['formatted_time'] ?? ($schedule['time_range'] ?? ($schedule['time'] ?? ''))));

        return trim($day.' '.$time);
    }

    /**
     * @param  list<array<string, mixed>>  $exams
     * @param  list<array<string, mixed>>  $classExams
     * @return list<array<string, mixed>>
     */
    private function mergeExamRowsWithClassFallbacks(array $exams, array $classExams): array
    {
        $classExamMap = [];

        foreach ($classExams as $classExam) {
            foreach ($this->examMatchKeys($classExam) as $key) {
                $classExamMap[$key] = $classExam;
            }
        }

        return array_map(function (array $exam) use ($classExamMap): array {
            if (! empty($exam['id'])) {
                return $exam;
            }

            foreach ($this->examMatchKeys($exam) as $key) {
                if (! isset($classExamMap[$key])) {
                    continue;
                }

                return [
                    ...$exam,
                    'id' => $classExamMap[$key]['id'],
                    'upload_url' => $exam['upload_url'] ?? $classExamMap[$key]['upload_url'],
                    'source' => $exam['source'] ?? 'exam_list_with_class_id',
                    'class_reference' => $classExamMap[$key]['class_reference'] ?? null,
                ];
            }

            return $exam;
        }, $exams);
    }

    /**
     * @param  array<string, mixed>  $exam
     * @return list<string>
     */
    private function examMatchKeys(array $exam): array
    {
        $keys = [];
        $courseIdentity = $this->extractExamCourseIdentity($exam);
        $courseCode = $this->normalizeExamCodeMatchValue($courseIdentity['code']);
        $courseName = $this->normalizeExamMatchValue($courseIdentity['name']);

        if ($courseCode !== '') {
            $keys[] = 'code:'.$courseCode;
        }

        if ($courseName !== '') {
            $keys[] = 'name:'.$courseName;
        }

        foreach ([$exam['course_code'] ?? null, $exam['course_name'] ?? null] as $candidate) {
            $candidateCode = $this->extractExamCodeFromValue((string) $candidate);

            if ($candidateCode !== '') {
                $keys[] = 'code:'.$candidateCode;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $exam
     * @return array{code: string, name: string}
     */
    private function extractExamCourseIdentity(array $exam): array
    {
        $code = (string) ($exam['course_code'] ?? '');
        $name = (string) ($exam['course_name'] ?? '');

        foreach ([$code, $name] as $candidate) {
            $parsed = $this->parseExamCourseIdentity($candidate);

            if ($parsed['code'] !== '') {
                return $parsed;
            }
        }

        return [
            'code' => $code,
            'name' => $name,
        ];
    }

    /**
     * @return array{code: string, name: string}
     */
    private function parseExamCourseIdentity(string $value): array
    {
        $normalizedValue = trim((string) preg_replace('/\s+/', ' ', $value));

        if (preg_match('/\b([A-Z]{1,4}\d{4,})(.+)$/u', $normalizedValue, $matches) !== 1) {
            return [
                'code' => '',
                'name' => '',
            ];
        }

        return [
            'code' => $matches[1],
            'name' => trim($matches[2]),
        ];
    }

    private function normalizeExamMatchValue(mixed $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));
    }

    private function normalizeExamCodeMatchValue(mixed $value): string
    {
        return strtolower(preg_replace('/[^A-Z0-9]/i', '', (string) $value) ?? '');
    }

    private function extractExamCodeFromValue(string $value): string
    {
        if (preg_match('/\b([A-Z]{1,4}\d{4,})/u', $value, $matches) !== 1) {
            return '';
        }

        return $this->normalizeExamCodeMatchValue($matches[1]);
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
            $attachment = $request->attachment();
            $operationId = (string) Str::uuid();
            $storedFilePath = $attachment->store('portal-uploads');

            ProcessPortalExamUploadJob::dispatch(
                $operationId,
                (string) session()->getId(),
                $sessionToken,
                $request->examId(),
                $storedFilePath,
                $attachment->getClientOriginalName(),
                $attachment->getMimeType() ?: 'application/octet-stream',
                $request->formValues()
            );

            return response()->json([
                'success' => true,
                'message' => 'Upload ujian diterima dan sedang diproses.',
                'data' => [
                    'operation_id' => $operationId,
                    'status' => 'queued',
                ],
            ], 202);
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
