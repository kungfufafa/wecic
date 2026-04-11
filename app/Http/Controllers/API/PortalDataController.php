<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\ClassDetailRequest;
use App\Http\Requests\SipandaiFlowRequest;
use App\Http\Requests\SipandaiMeetingDetailRequest;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use App\Services\PortalEndpointRegistry;
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

    private const FLOW_RESPONSE = "array{status: 'success', message: string, data: array{course_detail: array<string, mixed>, meeting_detail: array<string, mixed>|null, links: array<string, string>}, meta: array{timestamp: string, campus_session_valid: true, script_reference: list<int>}}";

    private ProfileExtractor $profileExtractor;

    private ClassesExtractor $classesExtractor;

    private DashboardExtractor $dashboardExtractor;

    private SipandaiClassesExtractor $sipandaiClassesExtractor;

    private StudentCourseExtractor $studentCourseExtractor;

    public function __construct(
        ProfileExtractor $profileExtractor,
        ClassesExtractor $classesExtractor,
        DashboardExtractor $dashboardExtractor,
        SipandaiClassesExtractor $sipandaiClassesExtractor,
        StudentCourseExtractor $studentCourseExtractor
    ) {
        $this->profileExtractor = $profileExtractor;
        $this->classesExtractor = $classesExtractor;
        $this->dashboardExtractor = $dashboardExtractor;
        $this->sipandaiClassesExtractor = $sipandaiClassesExtractor;
        $this->studentCourseExtractor = $studentCourseExtractor;
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
        return $this->withCampusSession('Dashboard extraction failed', 'Failed to extract dashboard data', function (string $sessionToken): JsonResponse {
            $dashboardData = $this->dashboardExtractor->extractDashboardData($sessionToken);

            return $this->statusSuccess('Dashboard data retrieved successfully', [
                'data' => $dashboardData,
            ], campusSessionValid: true);
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
        return $this->withCampusSession('Profile extraction failed', 'Failed to extract profile data', function (string $sessionToken): JsonResponse {
            $profileData = $this->profileExtractor->extractProfileData($sessionToken);

            return $this->statusSuccess('Profile data retrieved successfully', [
                'user_info' => $profileData['user_info'] ?? [],
                'profile' => $profileData,
            ], campusSessionValid: true);
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
        return $this->withCampusSession('Classes extraction failed', 'Failed to extract classes data', function (string $sessionToken): JsonResponse {
            $classesData = $this->classesExtractor->extractClassesData($sessionToken);

            return $this->statusSuccess('Classes data retrieved successfully', [
                'user_info' => $classesData['user_info'] ?? [],
                'classes' => $classesData['classes'] ?? [],
            ], campusSessionValid: true);
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

            return $this->statusSuccess('Class detail retrieved successfully', [
                'class_detail' => $classDetailData,
            ], meta: [
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

            return $this->statusSuccess('Meeting detail retrieved successfully', [
                'class_detail' => $meetingDetailData,
            ], meta: [
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
        return $this->withCampusSession('Student course extraction failed', 'Failed to extract student course data', function (string $sessionToken): JsonResponse {
            $classesData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);

            return $this->statusSuccess('Student course data retrieved successfully', [
                'user_info' => $classesData['student_info'] ?? [],
                'classes' => $classesData['classes'] ?? [],
                'semester_info' => $classesData['semester_info'] ?? [],
            ], meta: [
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
        return $this->withCampusSession('Merged classes extraction failed', 'Failed to extract merged classes data', function (string $sessionToken): JsonResponse {
            $sipandaiData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $studentCourseData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);
            $mergedData = $this->buildMergedClassesPayload($sipandaiData, $studentCourseData);

            return $this->statusSuccess('Merged classes data retrieved successfully', [
                'user_info' => $mergedData['student_info'] ?? [],
                'classes' => $mergedData['classes'] ?? [],
                'semester_info' => $mergedData['semester_info'] ?? [],
                'data_sources' => $mergedData['data_sources'] ?? [],
            ], meta: [
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
}
