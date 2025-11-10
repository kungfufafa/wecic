<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalSupportController extends Controller
{
    public function __construct(private readonly PortalSessionManager $sessionManager) {}

    public function endpoints(Request $request): JsonResponse
    {
        $grouped = $request->boolean('grouped', true);

        if ($key = $request->query('key')) {
            try {
                $endpoint = PortalEndpointRegistry::describe($key);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'meta' => [
                        'timestamp' => now()->toISOString(),
                        'available_keys' => PortalEndpointRegistry::keys(),
                    ],
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => "Endpoint metadata for {$key}",
                'data' => $endpoint,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'grouped' => false,
                ],
            ]);
        }

        $payload = $grouped
            ? PortalEndpointRegistry::groupedByCategory()
            : PortalEndpointRegistry::all();

        return response()->json([
            'status' => 'success',
            'message' => $grouped ? 'Grouped portal endpoints' : 'Portal endpoints list',
            'data' => $payload,
            'meta' => [
                'timestamp' => now()->toISOString(),
                'grouped' => $grouped,
                'total_endpoints' => count(PortalEndpointRegistry::keys()),
            ],
        ]);
    }

    public function parameterHelp(): JsonResponse
    {
        $helpBase = '/api/campus/support/parameter-help';

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
                        'method' => 'parameter_help',
                        'command' => "curl \"http://localhost:8000{$helpBase}\"",
                    ],
                    [
                        'method' => 'validate_parameters',
                        'command' => 'curl "http://localhost:8000/api/campus/support/validate-parameters?id=2265&dosen_id=5240&course_id=8810"',
                    ],
                ],
            ],
        ]);
    }

    public function validateParameters(Request $request): JsonResponse
    {
        $params = [
            'id' => $request->query('id'),
            'dosen_id' => $request->query('dosen_id'),
            'course_id' => $request->query('course_id'),
        ];

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
                    'help_url' => '/api/campus/support/parameter-help',
                    'example_url' => '/api/campus/support/validate-parameters?id=2265&dosen_id=5240&course_id=8810',
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
            ],
            'debug_info' => [
                'next_steps' => [
                    '1. Login via POST /api/campus/login',
                    '2. Gunakan parameter tervalidasi untuk request scraping',
                ],
            ],
        ]);
    }

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
                $debugInfo['campus_session_valid'] = $this->sessionManager->validateSession($sessionToken);
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
}
