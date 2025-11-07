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
     * Get dashboard data
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
     * Get profile data
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
     * Get classes data
     */
    public function classes(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
     * Get class detail data
     */
    public function classDetail(ClassDetailRequest $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
     * Get meeting detail data
     */
    public function meetingDetail(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
     * Get class detail data by ID
     */
    public function classDetailById(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
     * Check if the extracted class detail contains meaningful data
     */
    private function isValidClassDetail(array $data): bool
    {
        // Check if we have at least some meaningful course information
        $hasCourseInfo = ! empty($data['course_info']['name']) || ! empty($data['course_info']['code']);

        // Check if we have any content (attendance, assignments, materials, etc.)
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

    /**
     * Get meeting detail data by ID
     */
    public function meetingDetailById(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
     * Get student course data with room information
     */
    public function studentCourse(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
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
     * Get merged classes data from both endpoints with room information
     */
    public function mergedClasses(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                ], 401);
            }

            // Get data from both extractors
            $sipandaiData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $studentCourseData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);

            // Merge the data, prioritizing room info from student course
            $mergedClasses = [];
            $sipandaiClasses = $sipandaiData['classes'] ?? [];
            $studentCourseClasses = $studentCourseData['classes'] ?? [];

            // Create a lookup map for student course classes by course code and schedule
            $studentCourseMap = [];
            foreach ($studentCourseClasses as $class) {
                $key = ($class['course_info']['code'] ?? '').'|'.($class['schedule']['day'] ?? '').'|'.($class['schedule']['time'] ?? '');
                $studentCourseMap[$key] = $class;
            }

            // Merge data, adding room info from student course data
            foreach ($sipandaiClasses as $sipandaiClass) {
                $key = ($sipandaiClass['course_info']['code'] ?? '').'|'.($sipandaiClass['schedule']['day'] ?? '').'|'.($sipandaiClass['schedule']['time'] ?? '');

                $mergedClass = $sipandaiClass;

                // Add room information if available
                if (isset($studentCourseMap[$key])) {
                    $studentClass = $studentCourseMap[$key];
                    $mergedClass['class_room_info'] = $studentClass['class_room_info'] ?? null;
                }

                $mergedClasses[] = $mergedClass;
            }

            $mergedData = [
                'student_info' => $sipandaiData['student_info'] ?? $studentCourseData['student_info'] ?? [],
                'classes' => $mergedClasses,
                'semester_info' => $sipandaiData['semester_info'] ?? $studentCourseData['semester_info'] ?? [],
                'data_sources' => [
                    'sipandai_dashboard' => true,
                    'student_course' => true,
                ],
                'extracted_at' => now()->toISOString(),
            ];

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
}
