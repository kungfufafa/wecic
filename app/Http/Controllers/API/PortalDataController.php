<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\ClassDetailRequest;
use App\Http\Requests\PortalRefreshRequest;
use App\Http\Requests\SipandaiFlowRequest;
use App\Http\Requests\SipandaiMeetingDetailRequest;
use App\Jobs\RefreshPortalDataJob;
use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalClassCatalog;
use App\Services\PortalDatasetRepository;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalFetchPolicy;
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

    public function __construct(
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalClassCatalog $classCatalog,
        private readonly ClassesExtractor $classesExtractor,
        private readonly PortalFetchPolicy $fetchPolicy
    ) {}

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
        return $this->withCampusSession('Dashboard extraction failed', 'Gagal memuat dashboard', function (string $sessionToken) use ($request): JsonResponse {
            $dashboard = $this->readDataset($request, $sessionToken, 'dashboard');

            if (! empty($dashboard['data']['user_info'])) {
                $currentUserInfo = (array) session('campus_user_info', []);
                if (empty($currentUserInfo['name']) && empty($currentUserInfo['nama'])) {
                    session(['campus_user_info' => array_merge($currentUserInfo, $dashboard['data']['user_info'])]);
                }
            }

            return $this->statusSuccess('Dashboard data retrieved successfully', [
                'data' => $dashboard['data'],
            ], meta: $dashboard['meta'], campusSessionValid: true);
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
        return $this->withCampusSession('Profile extraction failed', 'Gagal memuat profil', function (string $sessionToken) use ($request): JsonResponse {
            $profile = $this->readDataset($request, $sessionToken, 'profile');

            return $this->statusSuccess('Profile data retrieved successfully', [
                'user_info' => $profile['data']['user_info'] ?? [],
                'profile' => $profile['data'],
            ], meta: $profile['meta'], campusSessionValid: true);
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
        return $this->withCampusSession('Classes extraction failed', 'Gagal memuat daftar kelas', function (string $sessionToken) use ($request): JsonResponse {
            $classes = $this->readDataset($request, $sessionToken, 'lms_classes');

            return $this->statusSuccess('Classes data retrieved successfully', [
                'user_info' => $classes['data']['user_info'] ?? [],
                'classes' => $classes['data']['classes'] ?? [],
            ], meta: $classes['meta'], campusSessionValid: true);
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
        return $this->withCampusSession('Payment history extraction failed', 'Gagal memuat riwayat pembayaran', function (string $sessionToken) use ($request): JsonResponse {
            $payments = $this->readDataset($request, $sessionToken, 'payments');

            return $this->statusSuccess('Payment history retrieved successfully', [
                'data' => $payments['data'],
            ], meta: $payments['meta'], campusSessionValid: true);
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
        return $this->withCampusSession('Class detail extraction failed', 'Gagal memuat detail kelas', function (string $sessionToken) use ($request): JsonResponse {
            $validated = $request->validated();
            $resolvedParameters = $validated;

            if (empty($validated['dosen_id']) || empty($validated['course_id'])) {
                $resolvedParameters = $this->findClassParameters($sessionToken, (string) $validated['id']) ?? [];

                if ($resolvedParameters === []) {
                    return $this->statusError('Kelas tidak ditemukan di daftar kelas kamu.', 404, campusSessionValid: true);
                }
            }

            $classDetail = $this->readDataset($request, $sessionToken, 'class_detail', $resolvedParameters);

            return $this->statusSuccess('Class detail retrieved successfully', [
                'class_detail' => $classDetail['data'],
            ], meta: [
                ...$classDetail['meta'],
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
        return $this->withCampusSession('Meeting detail extraction failed', 'Gagal memuat detail pertemuan', function (string $sessionToken) use ($request): JsonResponse {
            $meetingId = $request->meetingId();
            $coursesId = $request->coursesId();

            $meetingDetail = $this->readDataset($request, $sessionToken, 'meeting_detail', [
                'meeting_id' => $meetingId,
                'courses_id' => $coursesId,
            ]);

            if (($meetingDetail['data']['found'] ?? true) === false) {
                return $this->statusError('Pertemuan tidak ditemukan. Periksa kembali link yang kamu buka.', 404, campusSessionValid: true);
            }

            return $this->statusSuccess('Meeting detail retrieved successfully', [
                'class_detail' => $meetingDetail['data'],
            ], meta: [
                ...$meetingDetail['meta'],
                'meeting_id' => $meetingId,
                'courses_id' => $coursesId,
            ], campusSessionValid: true);
        }, $request->query());
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

    /**
     * Resolve the upstream `dosen_id`/`course_id` for a class `id` from the class list.
     *
     * @return array<string, mixed>|null
     */
    private function findClassParameters(string $sessionToken, string $id): ?array
    {
        try {
            return $this->classCatalog->findById($sessionToken, $id);
        } catch (\Throwable $throwable) {
            Log::error('Failed to get class parameters from classes list', [
                'error' => $throwable->getMessage(),
                'id' => $id,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function readDataset(Request $request, string $sessionToken, string $dataset, array $params = []): array
    {
        return $this->datasets->read(
            $sessionToken,
            $dataset,
            $params,
            $request->boolean('fresh'),
            (string) session()->getId()
        );
    }

    /**
     * Ambil jadwal perkuliahan dari portal mahasiswa.
     */
    #[Response(description: 'Jadwal perkuliahan mahasiswa berhasil diambil.', type: self::CLASSES_WITH_SEMESTER_RESPONSE)]
    #[Response(401, description: 'Endpoint ini membutuhkan sesi kampus yang aktif.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Jadwal mahasiswa gagal diambil.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function studentCourse(Request $request): JsonResponse
    {
        return $this->withCampusSession('Student course extraction failed', 'Gagal memuat jadwal kuliah', function (string $sessionToken) use ($request): JsonResponse {
            $schedule = $this->readDataset($request, $sessionToken, 'schedule');

            return $this->statusSuccess('Student course data retrieved successfully', [
                'user_info' => $schedule['data']['student_info'] ?? [],
                'classes' => $schedule['data']['classes'] ?? [],
                'semester_info' => $schedule['data']['semester_info'] ?? [],
            ], meta: [
                ...$schedule['meta'],
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
        return $this->withCampusSession('Merged classes extraction failed', 'Gagal memuat daftar kelas', function (string $sessionToken) use ($request): JsonResponse {
            $classes = $this->readDataset($request, $sessionToken, 'classes');

            return $this->statusSuccess('Merged classes data retrieved successfully', [
                'user_info' => $classes['data']['student_info'] ?? [],
                'classes' => $classes['data']['classes'] ?? [],
                'semester_info' => $classes['data']['semester_info'] ?? [],
                'data_sources' => $classes['data']['data_sources'] ?? [],
            ], meta: [
                ...$classes['meta'],
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

        return $this->withCampusSession('SIPANDAI flow extraction failed', 'Gagal menjalankan alur SIPANDAI', fn (string $sessionToken): JsonResponse => $this->fetchPolicy->runFresh(function () use ($sessionToken, $params): JsonResponse {
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
        }, $request->boolean('fresh')), $params);
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
