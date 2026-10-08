<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortalException;
use App\Http\Controllers\Controller;
use App\Services\PortalBroadcastService;
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

    protected function booleanResponse(bool $success, string $message, mixed $data = null, int $statusCode = 200, array $meta = []): JsonResponse
    {
        $payload = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = array_merge([
                'timestamp' => now()->toISOString(),
            ], $meta);
        }

        return response()->json($payload, $statusCode);
    }

    protected function consumeSyncMeta(array &$payload, array $meta = []): array
    {
        $syncMeta = is_array($payload['_sync'] ?? null) ? $payload['_sync'] : [];

        unset($payload['_sync']);

        return array_merge($syncMeta, $meta);
    }

    protected function missingSessionStatusResponse(): JsonResponse
    {
        return $this->statusError('Sesi kampus tidak ditemukan. Silakan masuk lagi.', 401, campusSessionValid: false);
    }

    protected function missingSessionBooleanResponse(): JsonResponse
    {
        return $this->booleanResponse(false, 'Sesi kampus tidak ditemukan. Silakan masuk lagi.', null, 401);
    }

    protected function expiredSessionBooleanResponse(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if ($sessionToken) {
            app(PortalBroadcastService::class)->sessionNotice(
                (string) session()->getId(),
                $sessionToken,
                'manual_relogin_required',
                'Sesi portal kedaluwarsa. Silakan login ulang.',
                'error'
            );
        }

        session()->forget(['campus_session_token', 'campus_user_info']);

        return $this->booleanResponse(false, 'Sesi kampus sudah berakhir. Silakan masuk lagi.', null, 401);
    }

    protected function isCampusSessionExpiredException(\Throwable $throwable): bool
    {
        return PortalException::indicatesExpiredSession($throwable);
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

                return $this->statusError('Sesi kampus sudah berakhir. Silakan masuk lagi.', 401, campusSessionValid: false);
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
