<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\ProfileExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * @group Debug & Testing
 *
 * API endpoints for debugging and testing scraping functionality
 */
class DebugController extends Controller
{
    /**
     * Test Dashboard Scraping
     *
     * Test dashboard data extraction and show raw scraping results.
     * This endpoint helps debug what data is being extracted from the CIC portal.
     *
     * @authenticated
     *
     * @response {
     *   "status": "success",
     *   "message": "Dashboard scraping test completed",
     *   "raw_data": {
     *     "user_info": {...},
     *     "dashboard_overview": {...},
     *     "recent_activity": [...],
     *     "classes_summary": {...}
     *   },
     *   "debug_info": {
     *     "session_valid": true,
     *     "scraping_time": "2025-10-11T01:30:34.000Z",
     *     "data_sources": ["dashboard_page", "profile_data", "classes_data"]
     *   }
     * }
     */
    public function testDashboard(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active session found. Please login first.',
                    'debug_info' => [
                        'session_valid' => false,
                        'suggestion' => 'Call POST /login first',
                    ],
                ], 401);
            }

            $startTime = microtime(true);
            $extractor = new DashboardExtractor(app(\App\Services\PortalSessionManager::class));
            $data = $extractor->extractDashboardData($sessionToken);
            $endTime = microtime(true);

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard scraping test completed',
                'raw_data' => $data,
                'debug_info' => [
                    'session_valid' => true,
                    'scraping_time' => now()->toISOString(),
                    'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
                    'data_sources' => ['dashboard_page', 'profile_data', 'classes_data'],
                    'session_token_preview' => substr($sessionToken, 0, 20).'...',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dashboard scraping failed: '.$e->getMessage(),
                'debug_info' => [
                    'error_type' => get_class($e),
                    'error_details' => $e->getTraceAsString(),
                    'session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Test Profile Scraping
     *
     * Test profile data extraction and show raw scraping results.
     *
     * @authenticated
     *
     * @response {
     *   "status": "success",
     *   "message": "Profile scraping test completed",
     *   "raw_data": {
     *     "user_info": {...},
     *     "profile": {
     *       "personal_data": {...},
     *       "contact_data": {...},
     *       "education_data": {...}
     *     }
     *   },
     *   "debug_info": {
     *     "session_valid": true,
     *     "scraping_time": "2025-10-11T01:30:34.000Z"
     *   }
     * }
     */
    public function testProfile(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active session found. Please login first.',
                    'debug_info' => [
                        'session_valid' => false,
                        'suggestion' => 'Call POST /login first',
                    ],
                ], 401);
            }

            $startTime = microtime(true);
            $extractor = new ProfileExtractor(app(\App\Services\PortalSessionManager::class));
            $data = $extractor->extractProfileData($sessionToken);
            $endTime = microtime(true);

            return response()->json([
                'status' => 'success',
                'message' => 'Profile scraping test completed',
                'raw_data' => $data,
                'debug_info' => [
                    'session_valid' => true,
                    'scraping_time' => now()->toISOString(),
                    'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
                    'data_source' => 'profile_page',
                    'session_token_preview' => substr($sessionToken, 0, 20).'...',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Profile scraping failed: '.$e->getMessage(),
                'debug_info' => [
                    'error_type' => get_class($e),
                    'error_details' => $e->getTraceAsString(),
                    'session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Test Classes Scraping
     *
     * Test classes data extraction and show raw scraping results.
     *
     * @authenticated
     *
     * @response {
     *   "status": "success",
     *   "message": "Classes scraping test completed",
     *   "raw_data": {
     *     "student_info": {...},
     *     "classes": [...],
     *     "semester_info": {...}
     *   },
     *   "debug_info": {
     *     "session_valid": true,
     *     "scraping_time": "2025-10-11T01:30:34.000Z",
     *     "total_classes": 6
     *   }
     * }
     */
    public function testClasses(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active session found. Please login first.',
                    'debug_info' => [
                        'session_valid' => false,
                        'suggestion' => 'Call POST /login first',
                    ],
                ], 401);
            }

            $startTime = microtime(true);
            $extractor = new ClassesExtractor(app(\App\Services\PortalSessionManager::class));
            $data = $extractor->extractClassesData($sessionToken);
            $endTime = microtime(true);

            return response()->json([
                'status' => 'success',
                'message' => 'Classes scraping test completed',
                'raw_data' => $data,
                'debug_info' => [
                    'session_valid' => true,
                    'scraping_time' => now()->toISOString(),
                    'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
                    'data_source' => 'sipandai_dashboard',
                    'total_classes' => count($data['classes'] ?? []),
                    'session_token_preview' => substr($sessionToken, 0, 20).'...',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Classes scraping failed: '.$e->getMessage(),
                'debug_info' => [
                    'error_type' => get_class($e),
                    'error_details' => $e->getTraceAsString(),
                    'session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Test Class Detail Scraping
     *
     * Test specific class detail extraction with provided parameters.
     * This helps debug class-specific data extraction.
     *
     * @authenticated
     *
     * @queryParam class_id integer required Class ID (4 digits). Example: 3142
     * @queryParam dosen_id integer required Lecturer ID (4 digits). Example: 1863
     * @queryParam course_id integer required Course ID (4 digits). Example: 8861
     *
     * @response {
     *   "status": "success",
     *   "message": "Class detail scraping test completed",
     *   "raw_data": {
     *     "request_url": "...",
     *     "content_type": "class_detail",
     *     "course_info": {...},
     *     "lecturer_info": {...},
     *     "attendance_info": {...}
     *   },
     *   "debug_info": {
     *     "session_valid": true,
     *     "parameters_used": {...},
     *     "full_url": "..."
     *   }
     * }
     */
    public function testClassDetail(Request $request): JsonResponse
    {
        try {
            $sessionToken = session('campus_session_token');

            if (! $sessionToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active session found. Please login first.',
                    'debug_info' => [
                        'session_valid' => false,
                        'suggestion' => 'Call POST /login first',
                    ],
                ], 401);
            }

            $params = [
                'id' => $request->query('class_id'),
                'dosen_id' => $request->query('dosen_id'),
                'course_id' => $request->query('course_id'),
            ];

            // Validate parameters
            if (! $params['id'] || ! $params['dosen_id'] || ! $params['course_id']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing required parameters: class_id, dosen_id, course_id',
                    'debug_info' => [
                        'provided_params' => $params,
                        'required_params' => ['class_id', 'dosen_id', 'course_id'],
                        'example_url' => '/api/debug/class-detail?class_id=3142&dosen_id=1863&course_id=8861',
                    ],
                ], 400);
            }

            $startTime = microtime(true);
            $extractor = new ClassesExtractor(app(\App\Services\PortalSessionManager::class));
            $data = $extractor->extractClassDetail($sessionToken, $params);
            $endTime = microtime(true);

            return response()->json([
                'status' => 'success',
                'message' => 'Class detail scraping test completed',
                'raw_data' => $data,
                'debug_info' => [
                    'session_valid' => true,
                    'scraping_time' => now()->toISOString(),
                    'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
                    'parameters_used' => $params,
                    'full_url' => $data['request_url'] ?? 'N/A',
                    'content_type_detected' => $data['content_type'] ?? 'unknown',
                    'session_token_preview' => substr($sessionToken, 0, 20).'...',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Class detail scraping failed: '.$e->getMessage(),
                'debug_info' => [
                    'error_type' => get_class($e),
                    'error_details' => $e->getTraceAsString(),
                    'parameters_used' => $request->query->all(),
                    'session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Session Status
     *
     * Get detailed information about current session status and debugging info.
     *
     * @response {
     *   "status": "success",
     *   "session_info": {
     *     "session_valid": true,
     *     "session_token_preview": "abc123...",
     *     "user_info": {...}
     *   },
     *   "debug_info": {
     *     "session_id": "...",
     *     "session_data": {...},
     *     "campus_session_valid": true
     *   }
     * }
     */
    public function sessionStatus(Request $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');
        $userInfo = session('campus_user_info');

        $debugInfo = [
            'session_id' => session()->getId(),
            'session_data' => session()->all(),
            'campus_session_valid' => false,
        ];

        if ($sessionToken) {
            try {
                $sessionManager = app(\App\Services\PortalSessionManager::class);
                $debugInfo['campus_session_valid'] = $sessionManager->validateSession($sessionToken);
            } catch (\Exception $e) {
                $debugInfo['validation_error'] = $e->getMessage();
            }
        }

        return response()->json([
            'status' => 'success',
            'session_info' => [
                'session_valid' => ! empty($sessionToken),
                'session_token_preview' => $sessionToken ? substr($sessionToken, 0, 20).'...' : null,
                'user_info' => $userInfo,
                'has_user_info' => ! empty($userInfo),
            ],
            'debug_info' => $debugInfo,
        ]);
    }

    /**
     * Test Parameter Validation
     *
     * Test parameter validation without requiring session.
     * This endpoint helps validate parameters before making actual scraping requests.
     *
     * @queryParam id integer required Class ID (parameter "id", 4 digits). Example: 2265
     * @queryParam dosen_id integer required Lecturer ID (parameter "dosen_id", 4 digits). Example: 5240
     * @queryParam course_id integer required Course ID (parameter "course_id", 4 digits, 8000-9999). Example: 8810
     *
     * @response {
     *   "status": "success",
     *   "message": "Parameters are valid",
     *   "validation_result": {...},
     *   "debug_info": {...}
     * }
     */
    public function testParameterValidation(Request $request): JsonResponse
    {
        $params = [
            'id' => $request->query('id'),
            'dosen_id' => $request->query('dosen_id'),
            'course_id' => $request->query('course_id'),
        ];

        // Validate using same rules as ClassDetailRequest
        $rules = [
            'id' => 'required|integer|regex:/^\d{4}$/|between:1000,9999',
            'dosen_id' => 'required|integer|regex:/^\d{4}$/|between:1000,9999',
            'course_id' => 'required|integer|regex:/^\d{4}$/|between:8000,9999',
        ];

        $messages = [
            'id.required' => 'Class ID (parameter "id") is required',
            'id.integer' => 'Class ID must be a number',
            'id.regex' => 'Class ID must be a 4-digit number (1000-9999)',
            'id.between' => 'Class ID must be between 1000 and 9999',
            'dosen_id.required' => 'Lecturer ID (parameter "dosen_id") is required',
            'dosen_id.integer' => 'Lecturer ID must be a number',
            'dosen_id.regex' => 'Lecturer ID must be a 4-digit number (1000-9999)',
            'dosen_id.between' => 'Lecturer ID must be between 1000 and 9999',
            'course_id.required' => 'Course ID (parameter "course_id") is required',
            'course_id.integer' => 'Course ID must be a number',
            'course_id.regex' => 'Course ID must be a 4-digit number (8000-9999)',
            'course_id.between' => 'Course ID must be between 8000 and 9999. Example: course_id=8810 or course_id=8861',
        ];

        $validator = validator($params, $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parameter validation failed',
                'validation_result' => [
                    'valid' => false,
                    'errors' => $validator->errors()->toArray(),
                    'provided_params' => $params,
                ],
                'debug_info' => [
                    'suggestion' => 'Check parameter mapping: id=class_id, course_id=course_id',
                    'help_url' => '/api/debug/parameter-help',
                    'example_url' => '/api/debug/test-validation?id=2265&dosen_id=5240&course_id=8810',
                ],
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Parameters are valid',
            'validation_result' => [
                'valid' => true,
                'validated_params' => $validator->validated(),
                'suggested_api_url' => '/api/campus/class-detail?'.http_build_query($params),
                'suggested_debug_url' => '/api/debug/class-detail?'.http_build_query($params),
            ],
            'debug_info' => [
                'next_steps' => [
                    '1. Login via POST /api/campus/login',
                    '2. Use validated parameters in scraping requests',
                ],
            ],
        ]);
    }

    /**
     * Get Parameter Help & Examples
     *
     * Get detailed information about required parameters and valid examples.
     * This endpoint helps understand the correct parameter mapping from CIC portal URLs.
     *
     * @response {
     *   "status": "success",
     *   "message": "Parameter help retrieved successfully",
     *   "parameter_guide": {
     *     "mapping_explanation": "...",
     *     "validation_rules": {...},
     *     "real_examples": [...],
     *     "common_mistakes": [...],
     *     "url_examples": [...]
     *   }
     * }
     */
    public function parameterHelp(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Parameter help retrieved successfully',
            'parameter_guide' => [
                'mapping_explanation' => [
                    'Portal URL: /portal/sipandai/?mod=sipandai&act=download&id=2265&dosen_id=5240&course_id=8810',
                    'API Mapping: ?id={class_id}&dosen_id={lecturer_id}&course_id={course_id}',
                    'Important: id parameter = class_id (NOT course_id!)',
                ],
                'validation_rules' => [
                    'id' => [
                        'parameter_name' => 'id (class_id)',
                        'format' => '4-digit integer',
                        'range' => '1000-9999',
                        'description' => 'Class identifier from CIC portal',
                        'example' => 2265,
                    ],
                    'dosen_id' => [
                        'parameter_name' => 'dosen_id (lecturer_id)',
                        'format' => '4-digit integer',
                        'range' => '1000-9999',
                        'description' => 'Lecturer identifier from CIC portal',
                        'example' => 5240,
                    ],
                    'course_id' => [
                        'parameter_name' => 'course_id',
                        'format' => '4-digit integer',
                        'range' => '8000-9999',
                        'description' => 'Course identifier from CIC portal',
                        'example' => 8810,
                    ],
                ],
                'real_examples' => [
                    [
                        'source' => 'CIC Portal URL 1',
                        'portal_url' => '/portal/sipandai/?mod=sipandai&act=download&id=2265&dosen_id=5240&course_id=8810',
                        'api_url' => '/api/campus/class-detail?id=2265&dosen_id=5240&course_id=8810',
                        'parameters' => [
                            'id' => 2265,
                            'dosen_id' => 5240,
                            'course_id' => 8810,
                        ],
                    ],
                    [
                        'source' => 'CIC Portal URL 2',
                        'portal_url' => '/portal/sipandai/?mod=sipandai&act=download&id=3142&dosen_id=1863&course_id=8861',
                        'api_url' => '/api/campus/class-detail?id=3142&dosen_id=1863&course_id=8861',
                        'parameters' => [
                            'id' => 3142,
                            'dosen_id' => 1863,
                            'course_id' => 8861,
                        ],
                    ],
                ],
                'common_mistakes' => [
                    [
                        'mistake' => 'Wrong parameter mapping',
                        'wrong_url' => '?id=8810&dosen_id=5240&course_id=2265',
                        'problem' => 'Using course_id value as id parameter',
                        'correct_url' => '?id=2265&dosen_id=5240&course_id=8810',
                        'explanation' => 'id parameter should be the class_id from portal URL, not course_id',
                    ],
                    [
                        'mistake' => 'Invalid course_id range',
                        'wrong_url' => '?id=2265&dosen_id=5240&course_id=2265',
                        'problem' => 'course_id=2265 is outside valid range (8000-9999)',
                        'correct_url' => '?id=2265&dosen_id=5240&course_id=8810',
                        'explanation' => 'course_id must be between 8000-9999',
                    ],
                ],
                'url_examples' => [
                    [
                        'method' => 'curl',
                        'command' => 'curl "http://localhost:8000/api/campus/class-detail?id=2265&dosen_id=5240&course_id=8810"',
                    ],
                    [
                        'method' => 'debug',
                        'command' => 'curl "http://localhost:8000/api/debug/class-detail?id=2265&dosen_id=5240&course_id=8810"',
                    ],
                    [
                        'method' => 'parameter_help',
                        'command' => 'curl "http://localhost:8000/api/debug/parameter-help"',
                    ],
                ],
            ],
        ]);
    }
}
