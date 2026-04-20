<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalScrapingException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

abstract class CampusApiController extends Controller
{
    public const SCRAMBLE_STATUS_ERROR_RESPONSE = "array{status: 'error', message: string, meta?: array<string, mixed>, errors?: array<string, list<string>>}";

    public const SCRAMBLE_SESSION_ERROR_RESPONSE = "array{status: 'error', message: string, meta: array{timestamp: string, campus_session_valid: false}}";

    public const SCRAMBLE_VALIDATION_ERROR_RESPONSE = "array{status: 'error', message: string, errors: array<string, list<string>>}";

    public const SCRAMBLE_BOOLEAN_RESPONSE = 'array{success: bool, message: string, data: mixed}';

    protected function sessionToken(): ?string
    {
        return session('campus_session_token');
    }

    protected function userInfo(): mixed
    {
        return session('campus_user_info');
    }

    protected function campusMeta(bool $campusSessionValid, array $meta = []): array
    {
        return array_merge([
            'timestamp' => now()->toISOString(),
            'campus_session_valid' => $campusSessionValid,
        ], $meta);
    }

    protected function statusSuccess(
        string $message,
        array $payload = [],
        array $meta = [],
        ?bool $campusSessionValid = null,
        int $statusCode = 200
    ): JsonResponse {
        if ($campusSessionValid !== null) {
            $payload['meta'] = $this->campusMeta($campusSessionValid, $meta);
        } elseif ($meta !== []) {
            $payload['meta'] = array_merge([
                'timestamp' => now()->toISOString(),
            ], $meta);
        }

        return response()->json(array_merge([
            'status' => 'success',
            'message' => $message,
        ], $payload), $statusCode);
    }

    protected function statusError(
        string $message,
        int $statusCode,
        array $payload = [],
        array $meta = [],
        ?bool $campusSessionValid = null
    ): JsonResponse {
        if ($campusSessionValid !== null) {
            $payload['meta'] = $this->campusMeta($campusSessionValid, $meta);
        } elseif ($meta !== []) {
            $payload['meta'] = array_merge([
                'timestamp' => now()->toISOString(),
            ], $meta);
        }

        return response()->json(array_merge([
            'status' => 'error',
            'message' => $message,
        ], $payload), $statusCode);
    }

    protected function booleanResponse(bool $success, string $message, mixed $data = null, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    protected function missingSessionStatusResponse(): JsonResponse
    {
        return $this->statusError('No active campus session found', 401, campusSessionValid: false);
    }

    protected function missingSessionBooleanResponse(): JsonResponse
    {
        return $this->booleanResponse(false, 'No active session. Please login first.', null, 401);
    }

    protected function expiredSessionBooleanResponse(): JsonResponse
    {
        session()->forget(['campus_session_token', 'campus_user_info']);

        return $this->booleanResponse(false, 'Campus session has expired. Please login again.', null, 401);
    }

    protected function isCampusSessionExpiredException(\Throwable $throwable): bool
    {
        return $throwable instanceof PortalAuthenticationException
            || ($throwable instanceof PortalScrapingException && $throwable->getCode() === 403);
    }

    protected function withCampusSession(
        string $failureLogMessage,
        string $clientErrorPrefix,
        callable $callback,
        array $logContext = []
    ): JsonResponse {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        try {
            return $callback($sessionToken);
        } catch (\Throwable $throwable) {
            if ($this->isCampusSessionExpiredException($throwable)) {
                session()->forget(['campus_session_token', 'campus_user_info']);

                return $this->statusError('Campus session has expired. Please login again.', 401, campusSessionValid: false);
            }

            Log::error($failureLogMessage, array_merge([
                'error' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ], $logContext));

            return $this->statusError(
                $clientErrorPrefix.': '.$throwable->getMessage(),
                500,
                campusSessionValid: false,
            );
        }
    }
}
