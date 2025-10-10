<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\ProfileExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CampusDataController extends Controller
{
    private ProfileExtractor $profileExtractor;
    private ClassesExtractor $classesExtractor;

    public function __construct(
        ProfileExtractor $profileExtractor,
        ClassesExtractor $classesExtractor
    ) {
        $this->profileExtractor = $profileExtractor;
        $this->classesExtractor = $classesExtractor;
    }

    /**
     * Get profile data
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found'
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
                    'campus_session_valid' => true
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Profile extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract profile data: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false
                ]
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

            if (!$sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found'
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
                    'campus_session_valid' => true
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Classes extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract classes data: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false
                ]
            ], 500);
        }
    }

    /**
     * Get class detail data
     */
    public function classDetail(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found'
                ], 401);
            }

            $classId = $request->query('id');
            $dosenId = $request->query('dosen_id');
            $courseId = $request->query('course_id');

            if (!$classId || !$dosenId || !$courseId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing required parameters: id, dosen_id, course_id'
                ], 400);
            }

            $classDetailData = $this->classesExtractor->extractClassDetail($sessionToken, [
                'id' => $classId,
                'dosen_id' => $dosenId,
                'course_id' => $courseId
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Class detail retrieved successfully',
                'class_detail' => $classDetailData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'class_id' => $classId,
                    'course_id' => $courseId,
                    'dosen_id' => $dosenId
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Class detail extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $request->query()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract class detail: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false
                ]
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

            if (!$sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found'
                ], 401);
            }

            $meetingId = $request->query('meeting_id');
            $courseId = $request->query('courses_id');

            if (!$meetingId || !$courseId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing required parameters: meeting_id, courses_id'
                ], 400);
            }

            $meetingDetailData = $this->classesExtractor->extractClassDetail($sessionToken, [
                'meeting_id' => $meetingId,
                'courses_id' => $courseId
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Meeting detail retrieved successfully',
                'class_detail' => $meetingDetailData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'meeting_id' => $meetingId,
                    'course_id' => $courseId
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Meeting detail extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $request->query()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract meeting detail: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false
                ]
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

            if (!$sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found'
                ], 401);
            }

            $classId = $request->query('class_id');
            if (!$classId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing required parameter: class_id'
                ], 400);
            }

            // Extract classId, dosenId, courseId from session storage or use defaults
            $params = [
                'id' => $classId,
                'dosen_id' => session('current_class_dosen_id', '5240'),
                'course_id' => session('current_class_course_id', '8810'),
            ];

            $classDetailData = $this->classesExtractor->extractClassDetail($sessionToken, $params);

            return response()->json([
                'status' => 'success',
                'message' => 'Class detail retrieved successfully',
                'class_detail' => $classDetailData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'class_id' => $classId
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Class detail by ID extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $request->query()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract class detail: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false
                ]
            ], 500);
        }
    }

    /**
     * Get meeting detail data by ID
     */
    public function meetingDetailById(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found'
                ], 401);
            }

            $meetingId = $request->query('meeting_id');
            if (!$meetingId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing required parameter: meeting_id'
                ], 400);
            }

            // Get courses_id from session or use default
            $courseId = session('current_class_course_id', '8861');

            $meetingDetailData = $this->classesExtractor->extractClassDetail($sessionToken, [
                'meeting_id' => $meetingId,
                'courses_id' => $courseId
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Meeting detail retrieved successfully',
                'class_detail' => $meetingDetailData,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                    'meeting_id' => $meetingId,
                    'course_id' => $courseId
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Meeting detail by ID extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $request->query()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to extract meeting detail: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false
                ]
            ], 500);
        }
    }
}