<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SipandaiAssignmentUploadRequest;
use App\Http\Requests\SipandaiAttendanceRequest;
use App\Services\SipandaiSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PortalActionController extends Controller
{
    public function __construct(private readonly SipandaiSubmissionService $submissionService) {}

    /**
     * Upload tugas SIPANDAI melalui API proxy.
     */
    public function uploadAssignment(SipandaiAssignmentUploadRequest $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');

        if (! $sessionToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active campus session found',
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }

        $validated = $request->validated();
        $overrides = $this->stringifyOverrides($validated['form_values'] ?? []);

        try {
            $attachment = $request->attachment();

            $result = $this->submissionService->submitAssignment(
                $sessionToken,
                (int) $validated['course_id'],
                (int) $validated['discussion_id'],
                (int) $validated['meeting_number'],
                $attachment,
                $overrides
            );

            return $this->buildResponse($result, 'sipandai_assignment_upload');

        } catch (\Throwable $e) {
            Log::error('Failed to upload SIPANDAI assignment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'course_id' => $validated['course_id'],
                'discussion_id' => $validated['discussion_id'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to upload assignment: '.$e->getMessage(),
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                    'source' => 'sipandai_assignment_upload',
                ],
            ], 500);
        }
    }

    /**
     * Tandai kehadiran pertemuan SIPANDAI.
     */
    public function markAttendance(SipandaiAttendanceRequest $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');

        if (! $sessionToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active campus session found',
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }

        $validated = $request->validated();
        $overrides = $this->stringifyOverrides($validated['form_values'] ?? []);

        try {
            $result = $this->submissionService->submitAttendance(
                $sessionToken,
                (int) $validated['course_id'],
                (int) $validated['discussion_id'],
                isset($validated['meeting_number']) ? (int) $validated['meeting_number'] : null,
                $overrides
            );

            return $this->buildResponse($result, 'sipandai_attendance');

        } catch (\Throwable $e) {
            Log::error('Failed to submit SIPANDAI attendance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'course_id' => $validated['course_id'],
                'discussion_id' => $validated['discussion_id'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit attendance: '.$e->getMessage(),
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                    'source' => 'sipandai_attendance',
                ],
            ], 500);
        }
    }

    /**
     * Helper to build consistent JSON responses.
     */
    private function buildResponse(array $result, string $source): JsonResponse
    {
        $statusCode = $result['success'] ? 200 : 422;

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
            'meta' => [
                'timestamp' => now()->toISOString(),
                'campus_session_valid' => $result['success'],
                'source' => $source,
            ],
        ], $statusCode);
    }

    /**
     * Normalise override values to strings.
     */
    private function stringifyOverrides(array $overrides): array
    {
        return array_map(static function ($value) {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_array($value)) {
                return json_encode($value, JSON_THROW_ON_ERROR);
            }

            return (string) $value;
        }, $overrides);
    }
}
