<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\PortalFileDownloadRequest;
use App\Http\Requests\PortalParameterValidationRequest;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

#[Group('Support & Metadata', 'Endpoint bantuan parameter, status sesi, dan metadata utilitas untuk integrasi portal.', 40)]
class PortalSupportController extends CampusApiController
{
    private const ENDPOINTS_RESPONSE = "array{status: 'success', message: string, data: array<string, mixed>|list<array<string, mixed>>, meta: array{grouped: bool, total_endpoints: int}}";

    private const PARAMETER_HELP_RESPONSE = "array{status: 'success', message: string, parameter_guide: array<string, mixed>}";

    private const VALID_PARAMETER_RESPONSE = "array{status: 'success', message: string, validation_result: array{valid: true, validated_params: array<string, mixed>, suggested_api_url: string}, debug_info: array<string, mixed>}";

    private const INVALID_PARAMETER_RESPONSE = "array{status: 'error', message: 'Parameter validation failed', validation_result: array{valid: false, errors: array<string, list<string>>, provided_params: array{id: mixed, dosen_id: mixed, course_id: mixed}}, debug_info: array{suggestion: string, help_url: string, example_url: string}}";

    public function __construct(
        private readonly PortalSessionManager $sessionManager,
        private readonly SipandaiSubmissionService $submissionService
    ) {}

    #[QueryParameter('grouped', 'Jika `true`, metadata endpoint akan dikelompokkan per kategori.', type: 'boolean', example: true)]
    #[QueryParameter('key', 'Jika diisi, hanya metadata untuk endpoint key tertentu yang dikembalikan.', type: 'string', example: 'assignment_form')]
    #[Response(description: 'Metadata endpoint portal berhasil diambil.', type: self::ENDPOINTS_RESPONSE)]
    #[Response(404, description: 'Key endpoint tidak ditemukan pada registry.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function endpoints(Request $request): JsonResponse
    {
        $grouped = $request->boolean('grouped', true);

        if ($key = $request->query('key')) {
            try {
                $endpoint = PortalEndpointRegistry::describe($key);
            } catch (\Throwable $e) {
                return $this->statusError($e->getMessage(), 404, meta: [
                    'available_keys' => PortalEndpointRegistry::keys(),
                ]);
            }

            return $this->statusSuccess("Endpoint metadata for {$key}", [
                'data' => $endpoint,
            ], meta: [
                'grouped' => false,
            ]);
        }

        $payload = $grouped
            ? PortalEndpointRegistry::groupedByCategory()
            : PortalEndpointRegistry::all();

        return $this->statusSuccess($grouped ? 'Grouped portal endpoints' : 'Portal endpoints list', [
            'data' => $payload,
        ], meta: [
            'grouped' => $grouped,
            'total_endpoints' => count(PortalEndpointRegistry::keys()),
        ]);
    }

    #[Response(description: 'Panduan pemetaan parameter portal berhasil diambil.', type: self::PARAMETER_HELP_RESPONSE)]
    public function parameterHelp(): JsonResponse
    {
        $helpBase = '/api/support/parameter-help';

        return $this->statusSuccess('Parameter help retrieved successfully', [
            'parameter_guide' => [
                'mapping_explanation' => [
                    'Portal URL: /portal/sipandai/?mod=sipandai&act=download&id=2265&dosen_id=5240&course_id=8810',
                    'API Mapping: /api/class/{id}?dosen_id={dosen_id}&course_id={course_id}',
                    'Important: gunakan nama parameter yang sama seperti URL portal sumber.',
                ],
                'validation_rules' => [
                    'id' => [
                        'parameter_name' => 'id',
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
                        'api_url' => '/api/class/2265?dosen_id=5240&course_id=8810',
                        'parameters' => [
                            'id' => 2265,
                            'dosen_id' => 5240,
                            'course_id' => 8810,
                        ],
                    ],
                    [
                        'source' => 'CIC Portal URL 2',
                        'portal_url' => '/portal/sipandai/?mod=sipandai&act=download&id=3142&dosen_id=1863&course_id=8861',
                        'api_url' => '/api/class/3142?dosen_id=1863&course_id=8861',
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
                        'explanation' => 'id parameter should use the same id value from the portal URL, not course_id',
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
                        'command' => 'curl "http://localhost:8000/api/class/2265?dosen_id=5240&course_id=8810"',
                    ],
                    [
                        'method' => 'parameter_help',
                        'command' => "curl \"http://localhost:8000{$helpBase}\"",
                    ],
                    [
                        'method' => 'validate_parameters',
                        'command' => 'curl "http://localhost:8000/api/support/validate-parameters?id=2265&dosen_id=5240&course_id=8810"',
                    ],
                ],
            ],
        ]);
    }

    public function downloadFile(PortalFileDownloadRequest $request): SymfonyResponse|JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        try {
            $download = $this->submissionService->downloadCampusFile(
                $sessionToken,
                $request->campusPath(),
                $request->downloadName()
            );

            return response($download['contents'], 200, [
                'Content-Type' => $download['content_type'],
                'Content-Disposition' => sprintf('attachment; filename="%s"', addslashes($download['filename'])),
            ]);
        } catch (\Throwable $throwable) {
            if ($this->isCampusSessionExpiredException($throwable)) {
                return $this->statusError('Sesi kampus sudah berakhir. Silakan masuk lagi.', 401, campusSessionValid: false);
            }

            Log::warning('Campus file download failed', ['error' => $throwable->getMessage()]);

            return $this->statusError('Unduhan gagal. Coba lagi beberapa saat.', 500, campusSessionValid: false);
        }
    }

    #[Response(description: 'Parameter portal valid dan siap digunakan.', type: self::VALID_PARAMETER_RESPONSE)]
    #[Response(422, description: 'Parameter portal tidak valid.', type: self::INVALID_PARAMETER_RESPONSE)]
    public function validateParameters(PortalParameterValidationRequest $request): JsonResponse
    {
        $params = $request->validated();
        $id = (string) $params['id'];
        $query = http_build_query([
            'dosen_id' => $params['dosen_id'],
            'course_id' => $params['course_id'],
        ]);

        return $this->statusSuccess('Parameters are valid', [
            'validation_result' => [
                'valid' => true,
                'validated_params' => $params,
                'suggested_api_url' => "/api/class/{$id}?{$query}",
            ],
            'debug_info' => [
                'next_steps' => [
                    '1. Login via POST /api/auth/login',
                    '2. Gunakan parameter tervalidasi untuk request scraping',
                ],
            ],
        ]);
    }
}
