<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassDetailRequest;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use App\Services\PortalEndpointRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PortalDataController extends Controller
{
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
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $dashboardData = $this->dashboardExtractor->extractDashboardData($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard data retrieved successfully',
                'data' => $dashboardData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Dashboard extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract dashboard data: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Ambil data profil mahasiswa langsung dari portal.
     *
     * Menyediakan data pribadi, kontak, pendidikan, dan metadata waktu ekstraksi.
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $profileData = $this->profileExtractor->extractProfileData($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Profile data retrieved successfully',
                'user_info' => $profileData['user_info'] ?? [],
                'profile' => $profileData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Profile extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract profile data: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Ambil daftar kelas versi SIPANDAI lengkap dengan info akademik.
     */
    public function classes(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $classesData = $this->classesExtractor->extractClassesData($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Classes data retrieved successfully',
                'user_info' => $classesData['user_info'] ?? [],
                'classes' => $classesData['classes'] ?? [],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Classes extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract classes data: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Ambil detail kelas/SIPANDAI berdasarkan parameter eksplisit.
     *
     * Param `id`, `dosen_id`, dan `course_id` mengikuti struktur URL `act=download`.
     */
    public function classDetail(ClassDetailRequest $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            // Validate input using form request
            $validated = $request->validated();

            Log::info('Class detail request validated', [
                'parameters' => $validated,
            ]);

            $classDetailData = $this->classesExtractor->extractClassDetail($sessionToken, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Class detail retrieved successfully',
                'class_detail' => $classDetailData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'parameters' => $validated,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Class detail extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $request->query(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract class detail: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Ambil detail pertemuan SIPANDAI berdasarkan query `meeting_id` + `courses_id`.
     */
    public function meetingDetail(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $meetingId = $request->query('meeting_id');
            $courseId = $request->query('courses_id');

            if (! $meetingId || ! $courseId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing required parameters: meeting_id, courses_id',
                ], 400);
            }

            $meetingDetailData = $this->classesExtractor->extractClassDetail($sessionToken, [
                'meeting_id' => $meetingId,
                'courses_id' => $courseId,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Meeting detail retrieved successfully',
                'class_detail' => $meetingDetailData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'meeting_id' => $meetingId,
                    'course_id' => $courseId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Meeting detail extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $request->query(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract meeting detail: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Ambil detail kelas berdasarkan `class_id` dari daftar SIPANDAI.
     *
     * Endpoint ini otomatis mencari parameter `id/dosen_id/course_id` dari daftar kelas terbaru sebelum memanggil extractor.
     */
    public function classDetailById(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $classId = $request->query('class_id');
            if (! $classId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing required parameter: class_id',
                ], 400);
            }

            // Get the correct parameters from classes list
            $params = $this->getClassParametersFromClassesList($sessionToken, $classId);

            if (! $params) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Class ID {$classId} not found in classes list",
                ], 404);
            }

            Log::info("Using parameters from classes list for class ID {$classId}", [
                'params' => $params,
            ]);

            $classDetailData = $this->classesExtractor->extractClassDetail($sessionToken, $params);

            return response()->json([
                'status' => 'success',
                'message' => 'Class detail retrieved successfully',
                'class_detail' => $classDetailData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'class_id' => $classId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Class detail by ID extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $request->query(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract class detail: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Menentukan apakah hasil ekstraksi kelas memuat konten yang relevan.
     */
    private function isValidClassDetail(array $data): bool
    {
        // Verifikasi apakah ada informasi mata kuliah yang berarti
        $hasCourseInfo = ! empty($data['course_info']['name']) || ! empty($data['course_info']['code']);

        // Verifikasi apakah ada konten tambahan (absensi, tugas, materi, dll.)
        $hasContent = false;
        if (! empty($data['attendance_info']['meetings'])) {
            $hasContent = true;
        }
        if (! empty($data['assignment_history']['assignments'])) {
            $hasContent = true;
        }
        if (! empty($data['course_materials'])) {
            $hasContent = true;
        }
        if (! empty($data['students_list'])) {
            $hasContent = true;
        }

        // If we have course info OR any content, consider it valid
        return $hasCourseInfo || $hasContent;
    }

    private function currentSessionToken(): ?string
    {
        return session('campus_session_token');
    }

    private function missingSessionResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'No active campus session found',
        ], 401);
    }

    private function buildFlowLinks(array $params): array
    {
        $links = [];

        try {
            if (! empty($params['dosen_id'])) {
                $links['course_materials'] = PortalEndpointRegistry::url('course_materials', [
                    'id' => $params['discussion_id'],
                    'dosen_id' => $params['dosen_id'],
                    'course_id' => $params['course_id'],
                ]);
            }
        } catch (\Throwable $e) {
            // Optional link
        }

        try {
            $links['discussion'] = PortalEndpointRegistry::url('discussion', [
                'id' => $params['meeting_id'] ?? $params['discussion_id'],
                'courses_id' => $params['course_id'],
            ]);
        } catch (\Throwable $e) {
            //
        }

        try {
            $links['assignment_form'] = PortalEndpointRegistry::url('assignment_form', [
                'courses_id' => $params['course_id'],
                'id' => $params['discussion_id'],
                'pertemuan' => $params['meeting_number'],
            ]);
        } catch (\Throwable $e) {
            //
        }

        try {
            if (! empty($params['submission_id'])) {
                $links['assignment_delete'] = PortalEndpointRegistry::url('assignment_delete', [
                    'id' => $params['submission_id'],
                    'idx' => $params['discussion_id'],
                    'pertemuan' => $params['meeting_number'],
                ]);
            }
        } catch (\Throwable $e) {
            //
        }

        try {
            $links['qr_attendance'] = PortalEndpointRegistry::url('qr_attendance_page', [
                'course_id' => $params['course_id'],
                'id' => $params['meeting_id'] ?? $params['discussion_id'],
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
     * Ambil detail pertemuan berdasarkan identifier `meeting_id`.
     */
    public function meetingDetailById(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $meetingId = $request->query('meeting_id');
            if (! $meetingId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing required parameter: meeting_id',
                ], 400);
            }

            // Get courses_id from session or use default
            $courseId = session('current_class_course_id', '8861');

            $meetingDetailData = $this->classesExtractor->extractClassDetail($sessionToken, [
                'meeting_id' => $meetingId,
                'courses_id' => $courseId,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Meeting detail retrieved successfully',
                'class_detail' => $meetingDetailData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'meeting_id' => $meetingId,
                    'course_id' => $courseId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Meeting detail by ID extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $request->query(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract meeting detail: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get the correct parameters for a class ID from the classes list
     */
    private function getClassParametersFromClassesList(string $sessionToken, string $classId): ?array
    {
        try {
            // First, try to get from cache
            $cacheKey = 'class_parameters_mapping_'.md5($sessionToken);
            $cachedMapping = cache($cacheKey);

            if ($cachedMapping && isset($cachedMapping[$classId])) {
                Log::info("Using cached parameters for class ID {$classId}", [
                    'params' => $cachedMapping[$classId],
                ]);

                return $cachedMapping[$classId];
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

                    // Also store by class_no if different
                    if (isset($class['no'])) {
                        $parametersMapping[$class['no']] = $params;
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
            return $parametersMapping[$classId] ?? null;

        } catch (\Exception $e) {
            Log::error('Failed to get class parameters from classes list', [
                'error' => $e->getMessage(),
                'class_id' => $classId,
            ]);

            return null;
        }
    }

    /**
     * Get SIPANDAI dashboard classes data
     */
    public function sipandaiClasses(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $classesData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'SIPANDAI classes data retrieved successfully',
                'user_info' => $classesData['student_info'] ?? [],
                'classes' => $classesData['classes'] ?? [],
                'semester_info' => $classesData['semester_info'] ?? [],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'data_source' => 'sipandai_dashboard',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('SIPANDAI classes extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract SIPANDAI classes data: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Ambil jadwal portal mahasiswa beserta informasi ruang kelas.
     */
    public function studentCourse(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $classesData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Student course data retrieved successfully',
                'user_info' => $classesData['student_info'] ?? [],
                'classes' => $classesData['classes'] ?? [],
                'semester_info' => $classesData['semester_info'] ?? [],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'data_source' => 'student_course',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Student course extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract student course data: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Ambil daftar kelas gabungan (SIPANDAI + portal mahasiswa).
     *
     * Data SIPANDAI dipakai untuk informasi join/link materi, sedangkan data
     * `student_course` melengkapi info ruangan, kelas, dan jadwal rinci. Hasil akhirnya
     * adalah satu struktur kelas dengan atribut `class_room_info` sudah terisi.
     */
    public function mergedClasses(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $sipandaiData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $studentCourseData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);
            $mergedData = $this->buildMergedClassesPayload($sipandaiData, $studentCourseData);

            return response()->json([
                'status' => 'success',
                'message' => 'Merged classes data retrieved successfully',
                'user_info' => $mergedData['student_info'] ?? [],
                'classes' => $mergedData['classes'] ?? [],
                'semester_info' => $mergedData['semester_info'] ?? [],
                'data_sources' => $mergedData['data_sources'] ?? [],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'data_source' => 'merged',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Merged classes extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract merged classes data: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Ringkas daftar kelas berdasarkan skrip test_all_endpoints (Test 4 & 5).
     */
    public function classesOverview(Request $request): JsonResponse
    {
        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $sipandai = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $studentCourse = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);
            $merged = $this->buildMergedClassesPayload($sipandai, $studentCourse);

            return response()->json([
                'status' => 'success',
                'message' => 'Classes overview retrieved successfully',
                'data' => [
                    'sipandai' => $sipandai,
                    'student_course' => $studentCourse,
                    'merged' => $merged,
                ],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'script_reference' => [4, 5],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Classes overview extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to build classes overview: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Alur data SIPANDAI lengkap (materi, diskusi, form tugas, dan QR absensi).
     *
     * Meniru Test 6-9 pada `test_all_endpoints.sh`.
     */
    public function sipandaiFlow(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'course_id' => ['required', 'integer', 'min:1'],
            'discussion_id' => ['required', 'integer', 'min:1'],
            'meeting_number' => ['required', 'integer', 'min:1'],
            'meeting_id' => ['sometimes', 'integer', 'min:1'],
            'submission_id' => ['sometimes', 'integer', 'min:1'],
            'dosen_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid parameters',
                'errors' => $validator->errors(),
            ], 422);
        }

        $params = $validator->validated();

        try {
            $sessionToken = $this->currentSessionToken();

            if (! $sessionToken) {
                return $this->missingSessionResponse();
            }

            $courseDetailParams = [
                'id' => $params['discussion_id'],
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
                    'courses_id' => $params['course_id'],
                ]);
            }

            $links = $this->buildFlowLinks($params);

            return response()->json([
                'status' => 'success',
                'message' => 'SIPANDAI flow data retrieved successfully',
                'data' => [
                    'course_detail' => $courseDetail,
                    'meeting_detail' => $meetingDetail,
                    'links' => $links,
                ],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'script_reference' => [6, 7, 8, 9],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('SIPANDAI flow extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $params,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to execute SIPANDAI flow: '.$e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }
}
