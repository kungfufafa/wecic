<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Extractors\FinanceExtractor;
use App\Services\Extractors\GradesExtractor;
use App\Services\Extractors\ScheduleExtractor;
use App\Services\Extractors\SipandaiExtractor;
use App\Services\Extractors\StudentDashboardExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CampusDataController extends Controller
{
    private StudentDashboardExtractor $dashboardExtractor;
    private GradesExtractor $gradesExtractor;
    private ScheduleExtractor $scheduleExtractor;
    private SipandaiExtractor $sipandaiExtractor;
    private FinanceExtractor $financeExtractor;

    public function __construct(
        StudentDashboardExtractor $dashboardExtractor,
        GradesExtractor $gradesExtractor,
        ScheduleExtractor $scheduleExtractor,
        SipandaiExtractor $sipandaiExtractor,
        FinanceExtractor $financeExtractor
    ) {
        $this->dashboardExtractor = $dashboardExtractor;
        $this->gradesExtractor = $gradesExtractor;
        $this->scheduleExtractor = $scheduleExtractor;
        $this->sipandaiExtractor = $sipandaiExtractor;
        $this->financeExtractor = $financeExtractor;
    }

    /**
     * Get dashboard data
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $data = $this->dashboardExtractor->extractDashboardData($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard data retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get dashboard data', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dashboard data: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get dashboard overview (lighter version)
     */
    public function dashboardOverview(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $data = $this->dashboardExtractor->getDashboardOverview($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard overview retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get dashboard overview', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dashboard overview: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get grades data
     */
    public function grades(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $options = $request->only(['semester', 'academic_year']);
            $data = $this->gradesExtractor->extractGrades($sessionToken, $options);

            return response()->json([
                'status' => 'success',
                'message' => 'Grades data retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get grades data', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve grades data: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get grades summary
     */
    public function gradesSummary(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $data = $this->gradesExtractor->getGradesSummary($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Grades summary retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get grades summary', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve grades summary: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get schedule data
     */
    public function schedule(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $options = $request->only(['semester', 'academic_year']);
            $data = $this->scheduleExtractor->extractSchedule($sessionToken, $options);

            return response()->json([
                'status' => 'success',
                'message' => 'Schedule data retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get schedule data', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve schedule data: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get schedule summary
     */
    public function scheduleSummary(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $data = $this->scheduleExtractor->getScheduleSummary($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Schedule summary retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get schedule summary', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve schedule summary: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get SIPANDAI data
     */
    public function sipandai(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $options = $request->only(['section']);
            $data = $this->sipandaiExtractor->extractSipandaiData($sessionToken, $options);

            return response()->json([
                'status' => 'success',
                'message' => 'SIPANDAI data retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get SIPANDAI data', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve SIPANDAI data: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get SIPANDAI overview
     */
    public function sipandaiOverview(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $data = $this->sipandaiExtractor->getSipandaiOverview($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'SIPANDAI overview retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get SIPANDAI overview', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve SIPANDAI overview: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get finance data
     */
    public function finance(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $options = $request->only(['semester', 'academic_year']);
            $data = $this->financeExtractor->extractFinanceData($sessionToken, $options);

            return response()->json([
                'status' => 'success',
                'message' => 'Finance data retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get finance data', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve finance data: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Get finance summary
     */
    public function financeSummary(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (!$sessionToken) {
                return $this->unauthorizedResponse();
            }

            $data = $this->financeExtractor->getFinanceSummary($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Finance summary retrieved successfully',
                'data' => $data,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get finance summary', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve finance summary: ' . $e->getMessage(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Standard unauthorized response
     */
    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized - Please login first',
            'meta' => [
                'timestamp' => now()->toISOString(),
                'campus_session_valid' => false,
            ],
        ], 401);
    }
}