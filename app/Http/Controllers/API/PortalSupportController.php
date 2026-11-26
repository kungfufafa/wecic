<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortalScrapingException;
use App\Http\Requests\PortalFileDownloadRequest;
use App\Http\Requests\PortalParameterValidationRequest;
use App\Services\PortalEndpointRegistry;
use App\Services\SipandaiSubmissionService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Support & Metadata', 'Endpoint bantuan parameter, status sesi, dan metadata utilitas untuk integrasi portal.', 40)]
class PortalSupportController extends CampusApiController
{
    private const ENDPOINTS_RESPONSE = "array{status: 'success', message: string, data: array<string, mixed>|list<array<string, mixed>>, meta: array{grouped: bool, total_endpoints: int}}";

    private const PARAMETER_HELP_RESPONSE = "array{status: 'success', message: string, parameter_guide: array<string, mixed>}";

    private const VALID_PARAMETER_RESPONSE = "array{status: 'success', message: string, validation_result: array{valid: true, validated_params: array<string, mixed>, suggested_api_url: string}, debug_info: array<string, mixed>}";

    private const INVALID_PARAMETER_RESPONSE = "array{status: 'error', message: 'Parameter validation failed', validation_result: array{valid: false, errors: array<string, list<string>>, provided_params: array{id: mixed, dosen_id: mixed, course_id: mixed}}, debug_info: array{suggestion: string, help_url: string, example_url: string}}";

    /**
     * Bytes copied to the browser per write while streaming a campus file
     */
    private const DOWNLOAD_CHUNK_BYTES = 8192;

    public function __construct(
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
            if (! is_string($key) || ! in_array($key, PortalEndpointRegistry::keys(), true)) {
                return $this->statusError('Endpoint key tidak dikenal.', 404, meta: [
                    'available_keys' => PortalEndpointRegistry::keys(),
                ]);
            }

            return $this->statusSuccess("Endpoint metadata for {$key}", [
                'data' => PortalEndpointRegistry::describe($key),
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
                        'format' => 'positive integer (at most 10 digits)',
                        'range' => '1 or greater',
                        'description' => 'Class identifier from CIC portal',
                        'example' => 2265,
                    ],
                    'dosen_id' => [
                        'parameter_name' => 'dosen_id (lecturer_id)',
                        'format' => 'positive integer',
                        'range' => '1 or greater',
                        'description' => 'Lecturer identifier from CIC portal',
                        'example' => 5240,
                    ],
                    'course_id' => [
                        'parameter_name' => 'course_id',
                        'format' => 'positive integer (at most 10 digits)',
                        'range' => '1 or greater',
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
                        'mistake' => 'Non-numeric identifier',
                        'wrong_url' => '?id=SI-EN-1&dosen_id=5240&course_id=SIE053004',
                        'problem' => 'Using the class name or course code instead of the numeric portal identifiers',
                        'correct_url' => '?id=2265&dosen_id=5240&course_id=8810',
                        'explanation' => 'id, dosen_id and course_id are the positive integers in the portal act=download URL',
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

    /**
     * Stream a campus file (materi, soal, tugas, dokumen) to the browser.
     *
     * The file is copied in chunks as it arrives, so a large module is never
     * held in memory. A failure answers JSON to a fetch, and a small HTML page
     * (or the login page) to a link the student opened directly.
     */
    public function downloadFile(PortalFileDownloadRequest $request): SymfonyResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $request->expectsJson() ? $this->missingSessionStatusResponse() : redirect()->route('login');
        }

        try {
            $download = $this->submissionService->streamCampusFile(
                $sessionToken,
                $request->campusPath(),
                $request->downloadName()
            );
        } catch (\Throwable $throwable) {
            return $this->downloadFailure($request, $throwable);
        }

        return $this->streamDownload($download);
    }

    /**
     * The file as it arrives, in DOWNLOAD_CHUNK_BYTES writes. A file whose
     * size the portal did not announce stops at the download cap the
     * service applies to announced sizes.
     *
     * @param  array{filename: string, content_type: string, size: int|null, stream: StreamInterface}  $download
     */
    private function streamDownload(array $download): StreamedResponse
    {
        $stream = $download['stream'];
        $size = $download['size'];
        $maxBytes = (int) config('portal.scraping.download_max_bytes', 50 * 1024 * 1024);
        $limit = $size ?? ($maxBytes > 0 ? $maxBytes : null);
        $headers = [
            'Content-Type' => $download['content_type'],
            'Content-Disposition' => sprintf('attachment; filename="%s"', addslashes($download['filename'])),
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
        ];

        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        return response()->stream(static function () use ($stream, $size, $limit): void {
            $sent = 0;

            try {
                while (! $stream->eof() && ($limit === null || $sent < $limit)) {
                    $chunk = $stream->read($limit === null ? self::DOWNLOAD_CHUNK_BYTES : min(self::DOWNLOAD_CHUNK_BYTES, $limit - $sent));

                    if ($chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $sent += strlen($chunk);

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();

                    if (connection_aborted() === 1) {
                        return;
                    }
                }

                if ($size === null && $limit !== null && $sent >= $limit && ! $stream->eof()) {
                    Log::warning('Campus file download stopped at the download limit', ['max_bytes' => $limit]);
                }
            } finally {
                $stream->close();
            }
        }, 200, $headers);
    }

    /**
     * The answer to a download that could not start
     */
    private function downloadFailure(PortalFileDownloadRequest $request, \Throwable $throwable): SymfonyResponse
    {
        if ($this->isCampusSessionExpiredException($throwable)) {
            return $request->expectsJson()
                ? $this->statusError('Sesi kampus sudah berakhir. Silakan masuk lagi.', 401, campusSessionValid: false)
                : redirect()->route('login');
        }

        // A file the portal no longer serves fails the same way on every retry.
        $upstreamStatus = $throwable instanceof RequestException ? $throwable->getResponse()?->getStatusCode() : null;

        if (in_array($upstreamStatus, [403, 404, 410], true)) {
            return $this->downloadError($request, 'File tidak ditemukan', 'File tidak ditemukan di portal kampus. Mungkin sudah dihapus atau dipindahkan dosen.', 404, campusSessionValid: true);
        }

        if ($throwable instanceof PortalScrapingException && str_contains($throwable->getMessage(), 'byte download limit')) {
            return $this->downloadError($request, 'File terlalu besar', 'File terlalu besar untuk diunduh lewat WeCIC. Buka langsung di portal kampus.', 422, campusSessionValid: true);
        }

        Log::warning('Campus file download failed', ['error' => $throwable->getMessage()]);

        return $this->downloadError($request, 'Unduhan gagal', 'Unduhan gagal. Coba lagi beberapa saat.', 500, campusSessionValid: false);
    }

    /**
     * JSON for a fetch; a minimal page with a way back for a link opened in a tab
     */
    private function downloadError(PortalFileDownloadRequest $request, string $title, string $message, int $status, bool $campusSessionValid): SymfonyResponse
    {
        return $request->expectsJson()
            ? $this->statusError($message, $status, campusSessionValid: $campusSessionValid)
            : self::navigationErrorPage($title, $message, $status);
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
