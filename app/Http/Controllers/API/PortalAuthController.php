<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalScrapingException;
use App\Http\Requests\PortalLoginRequest;
use App\Http\Requests\PortalPasswordChangeRequest;
use App\Jobs\ScanPortalNotificationsJob;
use App\Services\PortalBroadcastService;
use App\Services\PortalLogContext;
use App\Services\PortalPasswordService;
use App\Services\PortalSessionManager;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

#[Group('Autentikasi', 'Endpoint untuk membuat, memeriksa, memperbarui, dan mengakhiri sesi portal kampus.', 10)]
class PortalAuthController extends CampusApiController
{
    private const LOGIN_RESPONSE = "array{status: 'success', message: string, data: array{session_token: string, user_info: mixed, expires_at: string|null}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const CHECK_SESSION_RESPONSE = "array{status: 'success', message: string, data: array{user_info: mixed}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const REFRESH_SESSION_RESPONSE = "array{status: 'success', message: string, data: array{session_token: string, expires_at: string|null}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const LOGOUT_RESPONSE = "array{status: 'success', message: string, meta: array{timestamp: string, campus_session_valid: false}}";

    public function __construct(
        private readonly PortalSessionManager $sessionManager,
        private readonly PortalPasswordService $passwordService
    ) {}

    /**
     * Login ke sistem kampus.
     *
     * Kredensial kampus (NIM dan kata sandi) akan diverifikasi lalu dibuatkan
     * sesi baru via `PortalSessionManager`. Token hasil login disimpan di session
     * browser sehingga permintaan API lain bisa memakai `campus_session_token`.
     */
    #[Response(description: 'Login berhasil dan sesi kampus aktif.', type: self::LOGIN_RESPONSE)]
    #[Response(401, description: 'Kredensial portal tidak valid atau sesi gagal dibuat.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(503, description: 'Portal kampus sedang tidak dapat diakses atau timeout.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(422, description: 'Payload login tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function login(PortalLoginRequest $request): JsonResponse
    {
        try {
            $username = (string) $request->validated('username');
            $password = (string) $request->validated('password');

            $sessionResult = $this->sessionManager->createSession($username, $password);

            session()->regenerate();
            session([
                'campus_session_token' => $sessionResult['session_token'],
                'campus_user_info' => $sessionResult['user_info'],
            ]);

            $this->sessionManager->rememberLaravelSessionId(
                $sessionResult['session_token'],
                (string) session()->getId()
            );

            ScanPortalNotificationsJob::dispatch(
                (string) session()->getId(),
                $sessionResult['session_token']
            );

            Log::info('User logged in successfully', ['username_fingerprint' => PortalLogContext::fingerprint($username)]);

            return $this->statusSuccess('Login berhasil', [
                'data' => [
                    'user_info' => $sessionResult['user_info'],
                    'expires_at' => $sessionResult['expires_at'],
                ],
            ], campusSessionValid: true);
        } catch (PortalAuthenticationException $exception) {
            Log::warning('Campus authentication rejected', [
                'username' => $request->input('username'),
                'error' => $exception->getMessage(),
            ]);

            return $this->statusError('Login gagal. Periksa kembali username dan password Anda.', 401, campusSessionValid: false);
        } catch (PortalConnectionException|PortalScrapingException $exception) {
            Log::error('Campus portal is unreachable during login', [
                'username' => $request->input('username'),
                'error' => $exception->getMessage(),
            ]);

            return $this->statusError('Portal kampus sedang tidak dapat diakses. Coba lagi beberapa saat.', 503, campusSessionValid: false);
        } catch (\Throwable $e) {
            Log::error('Login failed', [
                'username' => $request->input('username'),
                'error' => $e->getMessage(),
            ]);

            return $this->statusError('Login gagal. Periksa kembali username dan password Anda.', 401, campusSessionValid: false);
        }
    }

    /**
     * Cek status sesi kampus yang sedang aktif.
     */
    #[Response(description: 'Sesi kampus masih aktif dan data sesi dapat digunakan.', type: self::CHECK_SESSION_RESPONSE)]
    #[Response(401, description: 'Tidak ada sesi aktif atau sesi kampus sudah kedaluwarsa.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Validasi sesi kampus gagal diproses.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function checkSession(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->statusError('No active session found', 401, campusSessionValid: false);
        }

        try {
            $isValid = $this->sessionManager->validateSession($sessionToken);

            $payload = [
                'data' => $isValid ? [
                    'user_info' => $this->userInfo(),
                ] : null,
            ];

            return $isValid
                ? $this->statusSuccess('Session is valid', $payload, campusSessionValid: true)
                : $this->statusError('Session has expired', 401, $payload, campusSessionValid: false);
        } catch (\Throwable $e) {
            Log::error('Session check failed', ['error' => $e->getMessage()]);

            return $this->statusError('Failed to validate session', 500, campusSessionValid: false);
        }
    }

    /**
     * Refresh sesi kampus supaya tidak kedaluwarsa.
     */
    #[Response(description: 'Sesi kampus berhasil diperbarui.', type: self::REFRESH_SESSION_RESPONSE)]
    #[Response(401, description: 'Sesi tidak ditemukan atau refresh sesi gagal.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    public function refreshSession(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->statusError('No active session found', 401, campusSessionValid: false);
        }

        try {
            $refreshResult = $this->sessionManager->refreshSession($sessionToken);

            $this->sessionManager->rememberLaravelSessionId($sessionToken, (string) session()->getId());

            app(PortalBroadcastService::class)->sessionNotice(
                (string) session()->getId(),
                $sessionToken,
                'session_refreshed',
                'Sesi portal berhasil diperbarui.',
                'success'
            );

            ScanPortalNotificationsJob::dispatch((string) session()->getId(), $sessionToken);

            return $this->statusSuccess('Session refreshed successfully', [
                'data' => [
                    'expires_at' => $refreshResult['expires_at'] ?? null,
                ],
            ], campusSessionValid: true);
        } catch (PortalConnectionException $e) {
            // The portal is unreachable; the session may well still be valid.
            Log::warning('Session refresh could not reach the campus portal', ['error' => $e->getMessage()]);

            return $this->statusError('Portal kampus sedang tidak dapat diakses. Coba lagi beberapa saat.', 503, campusSessionValid: true);
        } catch (\Throwable $e) {
            Log::error('Session refresh failed', ['error' => $e->getMessage()]);
            session()->forget(['campus_session_token', 'campus_user_info']);

            return $this->statusError('Session refresh failed. Please login again.', 401, campusSessionValid: false);
        }
    }

    /**
     * Logout dari sistem kampus.
     */
    #[Response(description: 'Sesi kampus dibersihkan dari browser saat ini.', type: self::LOGOUT_RESPONSE)]
    public function logout(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        try {
            if ($sessionToken) {
                app(PortalBroadcastService::class)->sessionNotice(
                    (string) session()->getId(),
                    $sessionToken,
                    'logged_out',
                    'Akun keluar dari perangkat ini.',
                    'warning'
                );

                $this->sessionManager->destroySession($sessionToken);
            }
            session()->invalidate();
            session()->regenerateToken();

            Log::info('User logged out successfully');

            return $this->statusSuccess('Logout berhasil', campusSessionValid: false);
        } catch (\Throwable $e) {
            Log::error('Logout failed', ['error' => $e->getMessage()]);

            session()->invalidate();
            session()->regenerateToken();

            return $this->statusSuccess('Logout berhasil (session cleared)', campusSessionValid: false);
        }
    }

    /**
     * Ambil metadata form ubah password dari portal kampus.
     */
    public function passwordForm(): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionStatusResponse();
        }

        try {
            return $this->statusSuccess('Password form metadata retrieved successfully', [
                'data' => $this->passwordService->inspectPasswordForm($sessionToken),
            ], campusSessionValid: true);
        } catch (\Throwable $throwable) {
            if ($this->isCampusSessionExpiredException($throwable)) {
                session()->forget(['campus_session_token', 'campus_user_info']);

                return $this->statusError('Campus session has expired. Please login again.', 401, campusSessionValid: false);
            }

            Log::error('Password form inspection failed', [
                'error' => $throwable->getMessage(),
            ]);

            return $this->statusError('Failed to inspect password form.', 500, campusSessionValid: false);
        }
    }

    /**
     * Kirim perubahan password ke portal kampus.
     */
    public function changePassword(PortalPasswordChangeRequest $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->missingSessionBooleanResponse();
        }

        try {
            $result = $this->passwordService->changePassword(
                $sessionToken,
                $request->currentPassword(),
                $request->newPassword(),
                $request->newPasswordConfirmation()
            );

            if (($result['success'] ?? false) === true) {
                $this->sessionManager->forgetStoredCredentials($sessionToken);
            }

            return $this->booleanResponse(
                (bool) $result['success'],
                (string) $result['message'],
                $result['data'] ?? null,
                $result['success'] ? 200 : 422
            );
        } catch (\Throwable $throwable) {
            if ($this->isCampusSessionExpiredException($throwable)) {
                return $this->expiredSessionBooleanResponse();
            }

            Log::error('Password change failed', [
                'error' => $throwable->getMessage(),
            ]);

            return $this->booleanResponse(false, 'Failed to change password.', null, 500);
        }
    }
}
