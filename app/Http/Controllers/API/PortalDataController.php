<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\ClassDetailRequest;
use App\Http\Requests\PortalRefreshRequest;
use App\Http\Requests\SipandaiFlowRequest;
use App\Http\Requests\SipandaiMeetingDetailRequest;
use App\Jobs\RefreshPortalDataJob;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\FinancialHistoryExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('Portal Data', 'Endpoint pembacaan data profil, kelas, detail materi, dan alur data SIPANDAI.', 20)]
class PortalDataController extends CampusApiController
{
    private const DASHBOARD_RESPONSE = "array{status: 'success', message: string, data: array{user_info: array<string, mixed>, dashboard_overview: array<string, mixed>, recent_activity: list<array<string, mixed>>, classes_summary: array<string, mixed>, status: string, timestamp: string}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const PROFILE_RESPONSE = "array{status: 'success', message: string, user_info: array<string, mixed>, profile: array<string, mixed>, meta: array{timestamp: string, campus_session_valid: true}}";

    private const CLASSES_RESPONSE = "array{status: 'success', message: string, user_info: array<string, mixed>, classes: list<array<string, mixed>>, meta: array{timestamp: string, campus_session_valid: true}}";

    private const CLASS_DETAIL_RESPONSE = "array{status: 'success', message: string, class_detail: array<string, mixed>, meta: array{timestamp: string, campus_session_valid: true, parameters?: array<string, mixed>, meeting_id?: string, courses_id?: string, id?: string}}";

    private const CLASSES_WITH_SEMESTER_RESPONSE = "array{status: 'success', message: string, user_info: array<string, mixed>, classes: list<array<string, mixed>>, semester_info: array<string, mixed>, meta: array{timestamp: string, campus_session_valid: true, data_source: string}}";

    private const MERGED_CLASSES_RESPONSE = "array{status: 'success', message: string, user_info: array<string, mixed>, classes: list<array<string, mixed>>, semester_info: array<string, mixed>, data_sources: array<string, bool>, meta: array{timestamp: string, campus_session_valid: true, data_source: string}}";

    private const PAYMENT_HISTORY_RESPONSE = "array{status: 'success', message: string, data: array{student_info: array<string, string>, payment_history: list<array<string, mixed>>, summary: array<string, mixed>, extracted_at: string}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const FLOW_RESPONSE = "array{status: 'success', message: string, data: array{course_detail: array<string, mixed>, meeting_detail: array<string, mixed>|null, links: array<string, string>}, meta: array{timestamp: string, campus_session_valid: true, script_reference: list<int>}}";

    private ProfileExtractor $profileExtractor;

    private ClassesExtractor $classesExtractor;

    private DashboardExtractor $dashboardExtractor;

    private SipandaiClassesExtractor $sipandaiClassesExtractor;

    private StudentCourseExtractor $studentCourseExtractor;

    private FinancialHistoryExtractor $financialHistoryExtractor;

    private SipandaiSubmissionService $submissionService;

    private PortalSnapshotStore $snapshotStore;

    public function __construct(
        ProfileExtractor $profileExtractor,
        ClassesExtractor $classesExtractor,
        DashboardExtractor $dashboardExtractor,
        SipandaiClassesExtractor $sipandaiClassesExtractor,
        StudentCourseExtractor $studentCourseExtractor,
        FinancialHistoryExtractor $financialHistoryExtractor,
        SipandaiSubmissionService $submissionService,
        ?PortalSnapshotStore $snapshotStore = null
    ) {
        $this->profileExtractor = $profileExtractor;
        $this->classesExtractor = $classesExtractor;
        $this->dashboardExtractor = $dashboardExtractor;
        $this->sipandaiClassesExtractor = $sipandaiClassesExtractor;
        $this->studentCourseExtractor = $studentCourseExtractor;
        $this->financialHistoryExtractor = $financialHistoryExtractor;
        $this->submissionService = $submissionService;
        $this->snapshotStore = $snapshotStore ?? app(PortalSnapshotStore::class);
    }

    /**
     * Ambil data dashboard kampus terbaru.
     *
     * Menggabungkan ringkasan profil, kelas aktif, dan aktivitas terbaru dari DASHBOARD SIPANDAI.
     */
    #[Response(description: 'Ringkasan dashboard portal berhasil diambil.', type: self::DASHBOARD_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Data dashboard gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function dashboard(Request $request): JsonResponse
    {
        return $this->withCampusSession('Dashboard extraction failed', 'Failed to extract dashboard data', function (string $sessionToken) use ($request): JsonResponse {
            $forceFresh = $request->boolean('fresh');

            if (! $forceFresh) {
                $snapshot = $this->snapshotStore->get($sessionToken, 'dashboard');

                if ($snapshot !== null) {
                    $dashboardData = $snapshot['data'];
                    $syncMeta = $this->consumeSyncMeta($dashboardData, [
                        'data_origin' => 'snapshot',
                        'synced_at' => $snapshot['synced_at'] ?? now()->toISOString(),
                        'status' => $snapshot['status'] ?? 'synced',
                        'requested_fresh' => false,
                    ]);

                    if ($this->snapshotStore->isStale($sessionToken, 'dashboard', 600)) {
                        RefreshPortalDataJob::dispatch(
                            (string) session()->getId(),
                            $sessionToken,
                            ['dashboard']
                        );
                        $syncMeta['status'] = 'syncing';
                    }

                    return $this->statusSuccess('Dashboard data retrieved successfully', [
                        'data' => $dashboardData,
                    ], meta: $syncMeta, campusSessionValid: true);
                }
            }

            $dashboardData = $this->dashboardExtractor->extractDashboardData($sessionToken);
            $this->snapshotStore->put($sessionToken, 'dashboard', $dashboardData);
            if (! empty($dashboardData['user_info'])) {
                $currentUserInfo = (array) session('campus_user_info', []);
                if (empty($currentUserInfo['name']) && empty($currentUserInfo['nama'])) {
                    session(['campus_user_info' => array_merge($currentUserInfo, $dashboardData['user_info'])]);
                }
            }
            $syncMeta = $this->consumeSyncMeta($dashboardData, [
                'requested_fresh' => $forceFresh,
            ]);

            return $this->statusSuccess('Dashboard data retrieved successfully', [
                'data' => $dashboardData,
            ], meta: $syncMeta, campusSessionValid: true);
        });
    }

    /**
     * Ambil data profil mahasiswa langsung dari portal.
     *
     * Menyediakan data pribadi, kontak, pendidikan, dan metadata waktu ekstraksi.
     */
    #[Response(description: 'Profil mahasiswa berhasil diambil.', type: self::PROFILE_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Profil mahasiswa gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function profile(Request $request): JsonResponse
    {
        return $this->withCampusSession('Profile extraction failed', 'Failed to extract profile data', function (string $sessionToken) use ($request): JsonResponse {
            $profileData = $this->profileExtractor->extractProfileData($sessionToken);
            $syncMeta = $this->consumeSyncMeta($profileData, [
                'requested_fresh' => $request->boolean('fresh'),
            ]);

            return $this->statusSuccess('Profile data retrieved successfully', [
                'user_info' => $profileData['user_info'] ?? [],
                'profile' => $profileData,
            ], meta: $syncMeta, campusSessionValid: true);
        });
    }

    /**
     * Ambil daftar kelas dari LMS/SIPANDAI.
     */
    #[Response(description: 'Daftar kelas LMS/SIPANDAI berhasil diambil.', type: self::CLASSES_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Daftar kelas gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function classes(Request $request): JsonResponse
    {
        return $this->withCampusSession('Classes extraction failed', 'Failed to extract classes data', function (string $sessionToken) use ($request): JsonResponse {
            $classesData = $this->classesExtractor->extractClassesData($sessionToken);
            $syncMeta = $this->consumeSyncMeta($classesData, [
                'requested_fresh' => $request->boolean('fresh'),
            ]);

            return $this->statusSuccess('Classes data retrieved successfully', [
                'user_info' => $classesData['user_info'] ?? [],
                'classes' => $classesData['classes'] ?? [],
            ], meta: $syncMeta, campusSessionValid: true);
        });
    }

    /**
     * Ambil histori pembayaran keuangan mahasiswa dari portal mahasiswa.
     */
    #[Response(description: 'Histori pembayaran keuangan berhasil diambil.', type: self::PAYMENT_HISTORY_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Histori pembayaran gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function paymentHistory(Request $request): JsonResponse
    {
        return $this->withCampusSession('Payment history extraction failed', 'Failed to extract payment history', function (string $sessionToken) use ($request): JsonResponse {
            $paymentHistoryData = $this->financialHistoryExtractor->extractPaymentHistoryData($sessionToken);
            $syncMeta = $this->consumeSyncMeta($paymentHistoryData, [
                'requested_fresh' => $request->boolean('fresh'),
            ]);

            return $this->statusSuccess('Payment history retrieved successfully', [
                'data' => $paymentHistoryData,
            ], meta: $syncMeta, campusSessionValid: true);
        });
    }

    /**
     * Ambil detail kelas/SIPANDAI berdasarkan parameter eksplisit.
     *
     * Param `id`, `dosen_id`, dan `course_id` mengikuti struktur URL `act=download`.
     */
    #[PathParameter('id', 'Identifier `id` dari link kelas portal SIPANDAI.', type: 'integer', example: 2265)]
    #[Response(description: 'Detail kelas berhasil diambil.', type: self::CLASS_DETAIL_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(404, description: 'Identifier `id` tidak ditemukan pada daftar kelas aktif.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter detail kelas tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Detail kelas gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function classDetail(ClassDetailRequest $request): JsonResponse
    {
        return $this->withCampusSession('Class detail extraction failed', 'Failed to extract class detail', function (string $sessionToken) use ($request): JsonResponse {
            $validated = $request->validated();
            $resolvedParameters = $validated;

            if (empty($validated['dosen_id']) || empty($validated['course_id'])) {
                $resolvedParameters = $this->getClassParametersFromClassesList($sessionToken, (string) $validated['id']) ?? [];

                if ($resolvedParameters === []) {
                    return $this->statusError("Class ID {$validated['id']} not found in classes list", 404, campusSessionValid: true);
                }
            }

            Log::info('Class detail request validated', [
                'parameters' => $resolvedParameters,
            ]);

            $classDetailData = $this->classesExtractor->extractClassDetail($sessionToken, $resolvedParameters);
            $syncMeta = $this->consumeSyncMeta($classDetailData, [
                'requested_fresh' => $request->boolean('fresh'),
            ]);

            return $this->statusSuccess('Class detail retrieved successfully', [
                'class_detail' => $classDetailData,
            ], meta: [
                ...$syncMeta,
                'id' => (string) ($resolvedParameters['id'] ?? $validated['id']),
                'parameters' => $resolvedParameters,
            ], campusSessionValid: true);
        }, $request->query());
    }

    /**
     * Ambil detail pertemuan SIPANDAI berdasarkan query `meeting_id` + `courses_id`.
     */
    #[PathParameter('meeting_id', 'Identifier `meeting_id` dari link diskusi atau materi SIPANDAI.', type: 'integer', example: 445)]
    #[Response(description: 'Detail pertemuan berhasil diambil.', type: self::CLASS_DETAIL_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter detail pertemuan tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Detail pertemuan gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function meetingDetail(SipandaiMeetingDetailRequest $request): JsonResponse
    {
        return $this->withCampusSession('Meeting detail extraction failed', 'Failed to extract meeting detail', function (string $sessionToken) use ($request): JsonResponse {
            $meetingId = $request->meetingId();
            $coursesId = $request->coursesId();

            $meetingDetailData = $this->classesExtractor->extractClassDetail($sessionToken, [
                'meeting_id' => $meetingId,
                'courses_id' => $coursesId,
            ]);
            $meetingDetailData = $this->enrichMeetingDetailAssignmentInfo($sessionToken, $meetingDetailData, $coursesId);
            $syncMeta = $this->consumeSyncMeta($meetingDetailData, [
                'requested_fresh' => $request->boolean('fresh'),
            ]);

            return $this->statusSuccess('Meeting detail retrieved successfully', [
                'class_detail' => $meetingDetailData,
            ], meta: [
                ...$syncMeta,
                'meeting_id' => $meetingId,
                'courses_id' => $coursesId,
            ], campusSessionValid: true);
        }, $request->query());
    }

    /**
     * @param  array<string, mixed>  $meetingDetailData
     * @return array<string, mixed>
     */
    private function enrichMeetingDetailAssignmentInfo(string $sessionToken, array $meetingDetailData, string $coursesId): array
    {
        $assignmentInfo = is_array($meetingDetailData['assignment_info'] ?? null)
            ? $meetingDetailData['assignment_info']
            : [];
        $materials = is_array($meetingDetailData['materials'] ?? null)
            ? $meetingDetailData['materials']
            : [];

        $hasAssignmentContent = ! empty($materials['assignment_topic'])
            || ! empty($materials['assignment_instructions'])
            || ! empty($materials['assignment_material_iframe'])
            || trim((string) ($assignmentInfo['upload_link'] ?? '')) !== ''
            || trim((string) ($assignmentInfo['upload_text'] ?? '')) !== '';

        if (! $hasAssignmentContent) {
            $meetingDetailData['assignment_info'] = $assignmentInfo;

            return $meetingDetailData;
        }

        $meetingNumber = $this->inferMeetingNumberFromMeetingDetail($meetingDetailData);
        if ($meetingNumber === null) {
            $assignmentInfo['status'] = trim((string) ($assignmentInfo['upload_link'] ?? '')) !== ''
                ? 'not_uploaded'
                : 'unresolved';
            $meetingDetailData['assignment_info'] = $assignmentInfo;

            return $meetingDetailData;
        }

        $assignmentInfo['meeting_number'] = $meetingNumber;

        try {
            $classParameters = $this->getClassParametersFromCourseId($sessionToken, $coursesId);

            if ($classParameters !== null) {
                $classDetailData = $this->classesExtractor->extractClassDetail($sessionToken, $classParameters);

                $submission = collect($classDetailData['assignment_history']['assignments'] ?? [])
                    ->first(fn (mixed $item): bool => is_array($item) && (string) ($item['meeting'] ?? '') === $meetingNumber);

                if (is_array($submission)) {
                    $assignmentInfo['status'] = 'uploaded';
                    $assignmentInfo['submission'] = $submission;

                    $deletePayload = $this->extractDeletePayloadFromAssignmentHistory($submission);
                    if ($deletePayload !== null) {
                        $assignmentInfo['delete_payload'] = $deletePayload;
                    }

                    $assignmentPageState = $this->resolveAssignmentPageState(
                        $sessionToken,
                        $coursesId,
                        $meetingDetailData,
                        $assignmentInfo
                    );

                    if ($assignmentPageState !== null) {
                        if (($assignmentPageState['delete_payload'] ?? null) !== null) {
                            $assignmentInfo['delete_payload'] = $assignmentPageState['delete_payload'];
                        }

                        if (($assignmentPageState['file_info'] ?? null) !== null && empty($assignmentInfo['submission']['file_info'])) {
                            $assignmentInfo['submission']['file_info'] = $assignmentPageState['file_info'];
                        }
                    }

                    $meetingDetailData['assignment_info'] = $assignmentInfo;

                    return $meetingDetailData;
                }
            }
        } catch (\Throwable $throwable) {
            Log::warning('Failed to enrich meeting assignment info from class detail', [
                'courses_id' => $coursesId,
                'meeting_number' => $meetingNumber,
                'error' => $throwable->getMessage(),
            ]);
        }

        $assignmentInfo['status'] = trim((string) ($assignmentInfo['upload_link'] ?? '')) !== ''
            ? 'not_uploaded'
            : 'upload_unavailable';
        $meetingDetailData['assignment_info'] = $assignmentInfo;

        return $meetingDetailData;
    }

    /**
     * @param  array<string, mixed>  $meetingDetailData
     */
    private function inferMeetingNumberFromMeetingDetail(array $meetingDetailData): ?string
    {
        $candidates = [
            $meetingDetailData['materials']['assignment_topic'] ?? null,
            $meetingDetailData['materials']['main_topic'] ?? null,
            $meetingDetailData['conformity_info']['description'] ?? null,
            $meetingDetailData['meeting_info']['breadcrumb'] ?? null,
            $meetingDetailData['meeting_info']['title'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if (preg_match('/Pertemuan\s+(\d+)/i', $candidate, $matches) === 1) {
                return (string) $matches[1];
            }
        }

        return null;
    }

    private function getClassParametersFromCourseId(string $sessionToken, string $courseId): ?array
    {
        try {
            $cacheKey = 'class_parameters_mapping_'.md5($sessionToken);
            $cachedMapping = cache($cacheKey);

            if (is_array($cachedMapping) && isset($cachedMapping['by_course_id'][$courseId])) {
                return $cachedMapping['by_course_id'][$courseId];
            }

            $classesData = $this->classesExtractor->extractClassesData($sessionToken);
            $mapping = [
                'by_id' => [],
                'by_course_id' => [],
            ];

            foreach ($classesData['classes'] ?? [] as $class) {
                if (! isset($class['join_link']['params']) || ! is_array($class['join_link']['params'])) {
                    continue;
                }

                $params = $class['join_link']['params'];

                if (isset($params['id'])) {
                    $mapping['by_id'][(string) $params['id']] = $params;
                }

                if (isset($params['course_id'])) {
                    $mapping['by_course_id'][(string) $params['course_id']] = $params;
                }
            }

            cache([$cacheKey => $mapping], now()->addMinutes(30));

            return $mapping['by_course_id'][$courseId] ?? null;
        } catch (\Throwable $throwable) {
            Log::warning('Failed to resolve class parameters from course id', [
                'course_id' => $courseId,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $assignmentHistoryItem
     * @return array{id: string, idx: string, pertemuan: string}|null
     */
    private function extractDeletePayloadFromAssignmentHistory(array $assignmentHistoryItem): ?array
    {
        $deleteHref = $assignmentHistoryItem['delete_info']['href'] ?? null;

        if (! is_string($deleteHref) || trim($deleteHref) === '') {
            return null;
        }

        parse_str((string) parse_url($deleteHref, PHP_URL_QUERY), $query);

        $submissionId = $query['id'] ?? null;
        $discussionId = $query['idx'] ?? null;
        $meetingNumber = $query['pertemuan'] ?? null;

        if (! is_scalar($submissionId) || ! is_scalar($discussionId) || ! is_scalar($meetingNumber)) {
            return null;
        }

        return [
            'id' => (string) $submissionId,
            'idx' => (string) $discussionId,
            'pertemuan' => (string) $meetingNumber,
        ];
    }

    /**
     * @param  array<string, mixed>  $meetingDetailData
     * @param  array<string, mixed>  $assignmentInfo
     * @return array{
     *     status: string,
     *     delete_payload: array{id: string, idx: string, pertemuan: string}|null,
     *     file_info: array{href: string, text: string}|null
     * }|null
     */
    private function resolveAssignmentPageState(
        string $sessionToken,
        string $coursesId,
        array $meetingDetailData,
        array $assignmentInfo
    ): ?array {
        $meetingNumber = $assignmentInfo['meeting_number'] ?? null;

        if (! is_string($meetingNumber) || trim($meetingNumber) === '') {
            return null;
        }

        $discussionId = $this->extractDiscussionIdFromMeetingDetail($meetingDetailData);

        if ($discussionId === null) {
            return null;
        }

        try {
            return $this->submissionService->inspectAssignmentSubmissionState(
                $sessionToken,
                (int) $coursesId,
                (int) $discussionId,
                (int) $meetingNumber
            );
        } catch (\Throwable $throwable) {
            Log::warning('Failed to inspect assignment submission state from assignment page', [
                'courses_id' => $coursesId,
                'discussion_id' => $discussionId,
                'meeting_number' => $meetingNumber,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $meetingDetailData
     */
    private function extractDiscussionIdFromMeetingDetail(array $meetingDetailData): ?string
    {
        $requestUrl = $meetingDetailData['request_url'] ?? null;

        if (! is_string($requestUrl) || trim($requestUrl) === '') {
            return null;
        }

        parse_str((string) parse_url($requestUrl, PHP_URL_QUERY), $query);

        return is_scalar($query['id'] ?? null) ? (string) $query['id'] : null;
    }

    private function buildFlowLinks(array $params): array
    {
        $links = [];

        try {
            if (! empty($params['dosen_id'])) {
                $links['course_materials'] = PortalEndpointRegistry::url('course_materials', [
                    'id' => $params['id'],
                    'dosen_id' => $params['dosen_id'],
                    'course_id' => $params['course_id'],
                ]);
            }
        } catch (\Throwable $e) {
            // Optional link
        }

        try {
            $links['discussion'] = PortalEndpointRegistry::url('discussion', [
                'id' => $params['meeting_id'] ?? $params['id'],
                'courses_id' => $params['courses_id'] ?? $params['course_id'],
            ]);
        } catch (\Throwable $e) {
            //
        }

        try {
            $links['assignment_form'] = PortalEndpointRegistry::url('assignment_form', [
                'courses_id' => $params['courses_id'] ?? $params['course_id'],
                'id' => $params['id'],
                'pertemuan' => $params['pertemuan'],
            ]);
        } catch (\Throwable $e) {
            //
        }

        try {
            if (! empty($params['submission_id'])) {
                $links['assignment_delete'] = PortalEndpointRegistry::url('assignment_delete', [
                    'id' => $params['submission_id'],
                    'idx' => $params['id'],
                    'pertemuan' => $params['pertemuan'],
                ]);
            }
        } catch (\Throwable $e) {
            //
        }

        try {
            $links['qr_attendance'] = PortalEndpointRegistry::url('qr_attendance_page', [
                'course_id' => $params['course_id'],
                'id' => $params['meeting_id'] ?? $params['id'],
            ]);
        } catch (\Throwable $e) {
            //
        }

        return $links;
    }

    private function buildMergedClassesPayload(array $sipandaiData, array $studentCourseData): array
    {
        $sipandaiClasses = $sipandaiData['classes'] ?? [];
        $studentCourseClasses = $studentCourseData['classes'] ?? [];

        $studentCourseMap = [];
        foreach ($studentCourseClasses as $class) {
            $key = ($class['course_info']['code'] ?? '').'|'.($class['schedule']['day'] ?? '').'|'.($class['schedule']['time'] ?? ($class['schedule']['time_range'] ?? ''));
            $studentCourseMap[$key] = $class;
        }

        $mergedClasses = [];
        foreach ($sipandaiClasses as $sipandaiClass) {
            $key = ($sipandaiClass['course_info']['code'] ?? '').'|'.($sipandaiClass['schedule']['day'] ?? '').'|'.($sipandaiClass['schedule']['time'] ?? ($sipandaiClass['schedule']['time_range'] ?? ''));

            $mergedClass = $sipandaiClass;
            if (isset($studentCourseMap[$key])) {
                $studentClass = $studentCourseMap[$key];
                $mergedClass['class_room_info'] = $studentClass['class_room_info'] ?? null;
                $mergedClass['student_course_reference'] = $studentClass;
            }

            $mergedClasses[] = $mergedClass;
        }

        return [
            'student_info' => $sipandaiData['student_info'] ?? $studentCourseData['student_info'] ?? [],
            'classes' => $mergedClasses,
            'semester_info' => $sipandaiData['semester_info'] ?? $studentCourseData['semester_info'] ?? [],
            'data_sources' => [
                'sipandai_dashboard' => true,
                'student_course' => true,
            ],
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * Get the correct parameters for an upstream `id` from the classes list.
     */
    private function getClassParametersFromClassesList(string $sessionToken, string $id): ?array
    {
        try {
            // First, try to get from cache
            $cacheKey = 'class_parameters_mapping_'.md5($sessionToken);
            $cachedMapping = cache($cacheKey);

            if ($cachedMapping && isset($cachedMapping[$id])) {
                Log::info("Using cached parameters for class ID {$id}", [
                    'params' => $cachedMapping[$id],
                ]);

                return $cachedMapping[$id];
            }

            // Extract classes data to get parameter mapping
            $classesData = $this->classesExtractor->extractClassesData($sessionToken);
            $parametersMapping = [];

            foreach ($classesData['classes'] as $class) {
                if (isset($class['join_link']['params'])) {
                    $params = $class['join_link']['params'];

                    // Store mapping for different ID variations
                    if (isset($params['id'])) {
                        $parametersMapping[$params['id']] = $params;
                    }

                    Log::info('Mapped parameters for class', [
                        'class_no' => $class['no'] ?? null,
                        'params_id' => $params['id'] ?? null,
                        'params' => $params,
                    ]);
                }
            }

            // Cache the mapping for 30 minutes
            cache([$cacheKey => $parametersMapping], now()->addMinutes(30));

            // Return parameters for requested class ID
            return $parametersMapping[$id] ?? null;

        } catch (\Exception $e) {
            Log::error('Failed to get class parameters from classes list', [
                'error' => $e->getMessage(),
                'id' => $id,
            ]);

            return null;
        }
    }

    /**
     * Ambil jadwal perkuliahan dari portal mahasiswa.
     */
    #[Response(description: 'Jadwal perkuliahan mahasiswa berhasil diambil.', type: self::CLASSES_WITH_SEMESTER_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Jadwal mahasiswa gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function studentCourse(Request $request): JsonResponse
    {
        return $this->withCampusSession('Student course extraction failed', 'Failed to extract student course data', function (string $sessionToken) use ($request): JsonResponse {
            $classesData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);
            $syncMeta = $this->consumeSyncMeta($classesData, [
                'requested_fresh' => $request->boolean('fresh'),
            ]);

            return $this->statusSuccess('Student course data retrieved successfully', [
                'user_info' => $classesData['student_info'] ?? [],
                'classes' => $classesData['classes'] ?? [],
                'semester_info' => $classesData['semester_info'] ?? [],
            ], meta: [
                ...$syncMeta,
                'data_source' => 'student_course',
            ], campusSessionValid: true);
        });
    }

    /**
     * Ambil daftar kelas utama API.
     *
     * Data LMS/SIPANDAI dipakai untuk informasi materi dan tautan aksi, sedangkan
     * data jadwal mahasiswa melengkapi ruang kelas dan jadwal rinci.
     */
    #[Response(description: 'Daftar kelas utama API berhasil diambil.', type: self::MERGED_CLASSES_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Daftar kelas gabungan gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function mergedClasses(Request $request): JsonResponse
    {
        return $this->withCampusSession('Merged classes extraction failed', 'Failed to extract merged classes data', function (string $sessionToken) use ($request): JsonResponse {
            $sipandaiData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $sipandaiSyncMeta = $this->consumeSyncMeta($sipandaiData);
            $studentCourseData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);
            $studentCourseSyncMeta = $this->consumeSyncMeta($studentCourseData);
            $mergedData = $this->buildMergedClassesPayload($sipandaiData, $studentCourseData);

            return $this->statusSuccess('Merged classes data retrieved successfully', [
                'user_info' => $mergedData['student_info'] ?? [],
                'classes' => $mergedData['classes'] ?? [],
                'semester_info' => $mergedData['semester_info'] ?? [],
                'data_sources' => $mergedData['data_sources'] ?? [],
            ], meta: [
                'requested_fresh' => $request->boolean('fresh'),
                'data_origin' => in_array('server_cache', [
                    $sipandaiSyncMeta['data_origin'] ?? null,
                    $studentCourseSyncMeta['data_origin'] ?? null,
                ], true) ? 'server_cache' : 'upstream',
                'synced_at' => now()->toISOString(),
                'data_source' => 'merged',
            ], campusSessionValid: true);
        });
    }

    /**
     * Alur kerja LMS/SIPANDAI lengkap.
     *
     * Meniru Test 6-9 pada `test_all_endpoints.sh`.
     */
    #[Response(description: 'Alur kerja LMS/SIPANDAI berhasil dirangkai.', type: self::FLOW_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(422, description: 'Parameter alur SIPANDAI tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    #[Response(500, description: 'Alur data SIPANDAI gagal diproses.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function sipandaiFlow(SipandaiFlowRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->withCampusSession('SIPANDAI flow extraction failed', 'Failed to execute SIPANDAI flow', function (string $sessionToken) use ($params): JsonResponse {
            $courseDetailParams = [
                'id' => $params['id'],
                'course_id' => $params['course_id'],
            ];

            if (! empty($params['dosen_id'])) {
                $courseDetailParams['dosen_id'] = $params['dosen_id'];
            }

            $courseDetail = $this->classesExtractor->extractClassDetail($sessionToken, $courseDetailParams);

            $meetingDetail = null;
            if (! empty($params['meeting_id'])) {
                $meetingDetail = $this->classesExtractor->extractClassDetail($sessionToken, [
                    'meeting_id' => $params['meeting_id'],
                    'courses_id' => $params['courses_id'] ?? $params['course_id'],
                ]);
            }

            $links = $this->buildFlowLinks($params);

            return $this->statusSuccess('SIPANDAI flow data retrieved successfully', [
                'data' => [
                    'course_detail' => $courseDetail,
                    'meeting_detail' => $meetingDetail,
                    'links' => $links,
                ],
            ], meta: [
                'script_reference' => [6, 7, 8, 9],
            ], campusSessionValid: true);
        }, $params);
    }

    public function refresh(PortalRefreshRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        RefreshPortalDataJob::dispatch(
            (string) session()->getId(),
            $sessionToken,
            $request->domains()
        );

        return $this->statusSuccess('Refresh data berjalan di background.', [
            'data' => [
                'domains' => $request->domains(),
                'status' => 'queued',
            ],
        ], campusSessionValid: true, statusCode: 202);
    }
}
