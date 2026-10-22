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
use App\Services\PortalDatasetRepository;
use App\Services\PortalLogContext;
use App\Services\PortalOfflineAccess;
use App\Services\PortalPasswordService;
use App\Services\PortalSessionManager;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

#[Group('Autentikasi', 'Endpoint untuk membuat, memeriksa, memperbarui, dan mengakhiri sesi portal kampus.', 10)]
class PortalAuthController extends CampusApiController
{
    private const LOGIN_RESPONSE = "array{status: 'success', message: string, data: array{user_info: mixed, expires_at: string|null, offline: bool}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const CHECK_SESSION_RESPONSE = "array{status: 'success', message: string, data: array{user_info: mixed, offline: bool}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const REFRESH_SESSION_RESPONSE = "array{status: 'success', message: string, data: array{session_token: string, expires_at: string|null}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const LOGOUT_RESPONSE = "array{status: 'success', message: string, meta: array{timestamp: string, campus_session_valid: false}}";

    private const OFFLINE_ACTION_MESSAGE = 'Kamu sedang di mode offline. MyCIC menyambung lagi otomatis begitu portal kampus bisa diakses.';

    public function __construct(
        private readonly PortalSessionManager $sessionManager,
        private readonly PortalPasswordService $passwordService,
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalOfflineAccess $offlineAccess
    ) {}

    /**
     * Login ke sistem kampus.
     *
     * Kredensial kampus (NIM dan kata sandi) akan diverifikasi lalu dibuatkan
     * sesi baru via `PortalSessionManager`. Token hasil login disimpan di session
     * browser sehingga permintaan API lain bisa memakai `campus_session_token`.
     * Setelah login, data untuk keputusan (KRS, perwalian, nilai, tagihan, SKKM,
     * pengumuman, konteks portal) disinkronkan di background tanpa menahan respons.
     *
     * Saat portal tidak bisa dihubungi, mahasiswa yang pernah login online dalam
     * `portal.archive.offline_login_days` hari terakhir dengan password yang sama
     * masuk ke mode offline (`data.offline: true`): hanya membaca arsip MyCIC,
     * tanpa aksi ke portal. Sesi itu mencoba login lagi di background dan
     * tersambung otomatis begitu portal pulih (notice `offline_session_resumed`).
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
            $this->offlineAccess->remember($username, $password, is_array($sessionResult['user_info']) ? $sessionResult['user_info'] : null);

            session()->regenerate();
            session([
                'campus_session_token' => $sessionResult['session_token'],
                'campus_user_info' => $sessionResult['user_info'],
                'campus_offline' => false,
            ]);

            $this->sessionManager->rememberLaravelSessionId(
                $sessionResult['session_token'],
                (string) session()->getId()
            );

            ScanPortalNotificationsJob::dispatch(
                (string) session()->getId(),
                $sessionResult['session_token']
            );

            // KRS, grades, bills, SKKM and announcements sync in the background
            // right away, so those pages and the action center open instantly.
            $this->datasets->warmUp(
                $sessionResult['session_token'],
                (string) session()->getId(),
                (int) config('portal.sync.warmup_delay', 5)
            );

            Log::info('User logged in successfully', ['username_fingerprint' => PortalLogContext::fingerprint($username)]);

            return $this->statusSuccess('Login berhasil', [
                'data' => [
                    'user_info' => $sessionResult['user_info'],
                    'expires_at' => $sessionResult['expires_at'],
                    'offline' => false,
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

            $offlineLogin = $this->loginOffline((string) $request->validated('username'), (string) $request->validated('password'));

            if ($offlineLogin !== null) {
                return $offlineLogin;
            }

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
            return $this->statusError('Sesi kampus tidak ditemukan. Silakan masuk lagi.', 401, campusSessionValid: false);
        }

        try {
            $isValid = $this->sessionManager->validateSession($sessionToken);

            $payload = [
                'data' => $isValid ? [
                    'user_info' => $this->userInfo(),
                    'offline' => $this->offlineAccess->isOfflineSession($sessionToken),
                ] : null,
            ];

            return $isValid
                ? $this->statusSuccess('Session is valid', $payload, campusSessionValid: true)
                : $this->statusError('Sesi kampus sudah berakhir. Silakan masuk lagi.', 401, $payload, campusSessionValid: false);
        } catch (\Throwable $e) {
            Log::error('Session check failed', ['error' => $e->getMessage()]);

            return $this->statusError('Gagal memeriksa sesi kampus.', 500, campusSessionValid: false);
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
            return $this->statusError('Sesi kampus tidak ditemukan. Silakan masuk lagi.', 401, campusSessionValid: false);
        }

        if ($this->offlineAccess->isOfflineSession($sessionToken)) {
            return $this->statusError(self::OFFLINE_ACTION_MESSAGE, 409, campusSessionValid: true);
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

            return $this->statusError('Sesi gagal diperbarui. Silakan masuk lagi.', 401, campusSessionValid: false);
        }
    }

    /**
     * Logout dari sistem kampus.
     */
    #[Response(description: 'Sesi kampus dibersihkan dari browser saat ini.', type: self::LOGOUT_RESPONSE)]
    public function logout(): JsonResponse
    {
        $sessionToken = $this->sessionToken();
        $sessionId = (string) session()->getId();

        try {
            if ($sessionToken) {
                $this->sessionManager->destroySession($sessionToken);
                $this->offlineAccess->endOfflineSession($sessionToken);
            }
            session()->invalidate();
            session()->regenerateToken();

            // Other tabs follow to the login page. Notices are broadcast
            // instantly, so this must happen after the session is gone, or
            // a tab would reach /login while it still looks logged in.
            if ($sessionToken) {
                app(PortalBroadcastService::class)->sessionNotice(
                    $sessionId,
                    $sessionToken,
                    'logged_out',
                    'Akun keluar dari perangkat ini.',
                    'warning'
                );
            }

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

                return $this->statusError('Sesi kampus sudah berakhir. Silakan masuk lagi.', 401, campusSessionValid: false);
            }

            Log::error('Password form inspection failed', [
                'error' => $throwable->getMessage(),
            ]);

            return $this->statusError('Gagal memuat form ubah password.', 500, campusSessionValid: false);
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

            return $this->booleanResponse(false, 'Gagal mengubah password.', null, 500);
        }
    }

    /**
     * Open a read-only session from the archive when the password matches the
     * last online login; null when offline login is not possible.
     */
    private function loginOffline(string $username, string $password): ?JsonResponse
    {
        try {
            $userInfo = $this->offlineAccess->attempt($username, $password);

            if ($userInfo === null) {
                return null;
            }

            $sessionResult = $this->sessionManager->createOfflineSession($username, $userInfo, $password);
            $this->offlineAccess->markOfflineSession($sessionResult['session_token'], $this->sessionManager->sessionTtl());

            session()->regenerate();
            session([
                'campus_session_token' => $sessionResult['session_token'],
                'campus_user_info' => $sessionResult['user_info'],
                'campus_offline' => true,
            ]);

            $this->sessionManager->rememberLaravelSessionId($sessionResult['session_token'], (string) session()->getId());

            Log::info('User logged in offline from the archive', ['username_fingerprint' => PortalLogContext::fingerprint($username)]);

            return $this->statusSuccess('Portal kampus sedang tidak bisa diakses. Kamu masuk dalam mode offline: data diambil dari arsip MyCIC dan aksi ke portal belum tersedia. MyCIC menyambung lagi otomatis begitu portal pulih.', [
                'data' => [
                    'user_info' => $sessionResult['user_info'],
                    'expires_at' => $sessionResult['expires_at'],
                    'offline' => true,
                ],
            ], campusSessionValid: true);
        } catch (\Throwable $throwable) {
            Log::warning('Offline login failed', ['error' => $throwable->getMessage()]);

            return null;
        }
    }
}
