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
}