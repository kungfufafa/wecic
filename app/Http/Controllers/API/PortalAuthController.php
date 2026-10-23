<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalScrapingException;
use App\Http\Middleware\PortalSessionMiddleware;
use App\Http\Requests\PortalLoginRequest;
use App\Http\Requests\PortalPasswordChangeRequest;
use App\Jobs\ScanPortalNotificationsJob;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetRepository;
use App\Services\PortalLogContext;
use App\Services\PortalOfflineAccess;
use App\Services\PortalPasswordService;
use App\Services\PortalSessionManager;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('Autentikasi', 'Endpoint untuk membuat, memeriksa, memperbarui, dan mengakhiri sesi portal kampus.', 10)]
class PortalAuthController extends CampusApiController
{
    private const LOGIN_RESPONSE = "array{status: 'success', message: string, data: array{user_info: mixed, expires_at: string|null, offline: bool, offline_reconnect: 'automatic'|'notify'|'portal_back'|'manual'|null}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const CHECK_SESSION_RESPONSE = "array{status: 'success', message: string, data: array{user_info: mixed, offline: bool, offline_reconnect: 'automatic'|'notify'|'portal_back'|'manual'|null}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const REFRESH_SESSION_RESPONSE = "array{status: 'success', message: string, data: array{session_token: string, expires_at: string|null}, meta: array{timestamp: string, campus_session_valid: true}}";

    private const LOGOUT_RESPONSE = "array{status: 'success', message: string, meta: array{timestamp: string, campus_session_valid: false}}";

    /**
     * Request attribute set on a login whose password was checked and found
     * wrong without the response saying so (the offline check while the
     * portal is down answers 503). The per-address failed-login limit
     * (AppServiceProvider) counts these like the portal's 401s.
     */
    public const FAILED_LOGIN_ATTRIBUTE = 'portal_login_failed';

    public function __construct(
        private readonly PortalSessionManager $sessionManager,
        private readonly PortalPasswordService $passwordService,
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalOfflineAccess $offlineAccess,
        private readonly PortalDataOwner $dataOwner
    ) {}

    /**
     * What an offline student is told about getting back online, for the
     * mode PortalSessionManager::offlineReconnectMode() reports.
     */
    public static function offlineReconnectMessage(?string $mode): string
    {
        return match ($mode) {
            'notify' => 'Sesi ini tidak menyimpan password, jadi tidak tersambung otomatis. MyCIC memberi tahu begitu portal kampus bisa diakses; setelah itu keluar lalu masuk lagi.',
            'portal_back' => 'Portal kampus sudah bisa diakses lagi. Keluar lalu masuk lagi untuk menyambungkan sesi.',
            'manual' => 'Keluar lalu masuk lagi saat portal kampus sudah bisa diakses.',
            default => 'MyCIC menyambung lagi otomatis begitu portal kampus bisa diakses.',
        };
    }

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
     * tersambung otomatis begitu portal pulih (notice `offline_session_resumed`,
     * `data.offline_reconnect: automatic`). Mahasiswa yang mematikan
     * sinkronisasi latar belakang tidak disimpan password-nya, termasuk di sesi
     * offline (`offline_reconnect: notify`): sesi itu tidak login sendiri, hanya
     * memberi tahu begitu portal bisa diakses lagi (notice
     * `offline_resume_unavailable`) supaya mahasiswa keluar lalu masuk lagi.
     * Sesi offline yang sebelumnya terbuka di browser ini diakhiri.
     */
    #[Response(description: 'Login berhasil dan sesi kampus aktif.', type: self::LOGIN_RESPONSE)]
    #[Response(401, description: 'Kredensial portal tidak valid atau sesi gagal dibuat.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(503, description: 'Portal kampus sedang tidak dapat diakses atau timeout.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    #[Response(422, description: 'Payload login tidak valid.', type: CampusApiController::SCRAMBLE_VALIDATION_ERROR_RESPONSE)]
    public function login(PortalLoginRequest $request): JsonResponse
    {
        $previousSessionToken = $this->sessionToken();

        try {
            $username = (string) $request->validated('username');
            $password = (string) $request->validated('password');

            $sessionResult = $this->sessionManager->createSession($username, $password);
            $this->offlineAccess->remember($username, $password, is_array($sessionResult['user_info']) ? $sessionResult['user_info'] : null);
            $this->endReplacedOfflineSession($previousSessionToken, $sessionResult['session_token']);

            session()->regenerate();
            session([
                'campus_session_token' => $sessionResult['session_token'],
                'campus_user_info' => $sessionResult['user_info'],
                'campus_offline' => false,
                'campus_offline_reconnect' => null,
            ]);

            $this->sessionManager->rememberLaravelSessionId(
                $sessionResult['session_token'],
                (string) session()->getId()
            );

            $this->scheduleFirstNotificationScan($sessionResult['session_token']);

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
                    'offline_reconnect' => null,
                ],
            ], campusSessionValid: true);
        } catch (PortalAuthenticationException $exception) {
            Log::warning('Campus authentication rejected', [
                'username_fingerprint' => PortalLogContext::fingerprint((string) $request->input('username')),
                'error' => $exception->getMessage(),
            ]);

            return $this->statusError('Login gagal. Periksa kembali username dan password Anda.', 401, campusSessionValid: false);
        } catch (PortalConnectionException|PortalScrapingException $exception) {
            Log::error('Campus portal is unreachable during login', [
                'username_fingerprint' => PortalLogContext::fingerprint((string) $request->input('username')),
                'error' => $exception->getMessage(),
            ]);

            $offlineLogin = $this->loginOffline((string) $request->validated('username'), (string) $request->validated('password'), $previousSessionToken);

            if ($offlineLogin !== null) {
                return $offlineLogin;
            }

            return $this->statusError('Portal kampus sedang tidak dapat diakses. Coba lagi beberapa saat.', 503, campusSessionValid: false);
        } catch (\Throwable $e) {
            Log::error('Login failed', [
                'username_fingerprint' => PortalLogContext::fingerprint((string) $request->input('username')),
                'error' => $e->getMessage(),
            ]);

            return $this->statusError('Login gagal. Periksa kembali username dan password Anda.', 401, campusSessionValid: false);
        }
    }

    /**
     * Cek status sesi kampus yang sedang aktif.
     *
     * Dengan header `X-MyCIC-Background: 1` (pengecekan otomatis dari aplikasi,
     * bukan aksi mahasiswa) sesi hanya diperiksa, tanpa dihitung sebagai
     * aktivitas yang memperpanjang batas waktu idle.
     */
    #[Response(description: 'Sesi kampus masih aktif dan data sesi dapat digunakan.', type: self::CHECK_SESSION_RESPONSE)]
    #[Response(401, description: 'Tidak ada sesi aktif atau sesi kampus sudah kedaluwarsa.', type: CampusApiController::SCRAMBLE_SESSION_ERROR_RESPONSE)]
    #[Response(500, description: 'Validasi sesi kampus gagal diproses.', type: CampusApiController::SCRAMBLE_STATUS_ERROR_RESPONSE)]
    public function checkSession(Request $request): JsonResponse
    {
        $sessionToken = $this->sessionToken();

        if (! $sessionToken) {
            return $this->statusError('Sesi kampus tidak ditemukan. Silakan masuk lagi.', 401, campusSessionValid: false);
        }

        try {
            $isValid = $request->header(PortalSessionMiddleware::BACKGROUND_HEADER) === '1'
                ? $this->sessionManager->isSessionUsable($sessionToken)
                : $this->sessionManager->validateSession($sessionToken);

            $payload = [
                'data' => $isValid ? [
                    'user_info' => $this->userInfo(),
                    'offline' => $this->offlineAccess->isOfflineSession($sessionToken),
                    'offline_reconnect' => $this->sessionManager->offlineReconnectMode($sessionToken),
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
            return $this->statusError('Kamu sedang di mode offline. '.self::offlineReconnectMessage($this->sessionManager->offlineReconnectMode($sessionToken)), 409, campusSessionValid: true);
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
                $this->forgetOldPasswordCopies($sessionToken);
                $this->rememberNewPassword($sessionToken, $request->newPassword());
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
     * The first scan of a login crawls every class and meeting page, so it
     * is delayed like the warm-up: today's schedule re-sync is not queued
     * behind it.
     */
    private function scheduleFirstNotificationScan(string $sessionToken): void
    {
        ScanPortalNotificationsJob::dispatch((string) session()->getId(), $sessionToken)
            ->delay(now()->addSeconds((int) config('portal.sync.warmup_delay', 5)));
    }

    /**
     * The copies sessions keep of the old password are useless now (a
     * relogin with one would only be refused by the portal): this session
     * and every other session of the student drop theirs. Best-effort: the
     * password did change, so a busy session lock never turns the answer
     * into an error, and a copy left behind is refused at its session's next
     * relogin, which ends that session.
     */
    private function forgetOldPasswordCopies(string $sessionToken): void
    {
        try {
            $this->sessionManager->forgetStoredCredentials($sessionToken);
        } catch (\Throwable $throwable) {
            Log::warning('The password copy of the session could not be dropped after a password change', ['error' => $throwable->getMessage()]);
        }

        $username = $this->sessionManager->getSession($sessionToken)['username'] ?? null;

        if (! is_string($username) || $username === '') {
            return;
        }

        try {
            if (! $this->sessionManager->forgetStoredCredentialsForOwner($this->dataOwner->keyForUsername($username))) {
                Log::warning('Some password copies of the student could not be dropped after a password change');
            }
        } catch (\Throwable $throwable) {
            Log::warning('The password copies of the student could not be dropped after a password change', ['error' => $throwable->getMessage()]);
        }
    }

    /**
     * The portal accepted the new password: the old one must stop opening
     * MyCIC offline at once, and stored credentials follow the new one.
     */
    private function rememberNewPassword(string $sessionToken, string $newPassword): void
    {
        $session = $this->sessionManager->getSession($sessionToken) ?? [];
        $username = $session['username'] ?? null;

        if (! is_string($username) || $username === '') {
            Log::warning('Offline access could not follow a password change: the session has no username');

            return;
        }

        $this->offlineAccess->remember($username, $newPassword, is_array($session['user_info'] ?? null) ? $session['user_info'] : null);
    }

    /**
     * A new login in this browser replaces its offline session, if it had
     * one: that session, its stored password and its resume chain end here
     * instead of lingering until the idle timeout (and logging in by
     * themselves once the portal is back). An online session is left to its
     * keep-alive, which logs it out of the portal when it idles out.
     */
    private function endReplacedOfflineSession(?string $previousSessionToken, string $sessionToken): void
    {
        if ($previousSessionToken === null || $previousSessionToken === '' || $previousSessionToken === $sessionToken) {
            return;
        }

        try {
            if ($this->sessionManager->offlineReconnectMode($previousSessionToken) !== null) {
                $this->sessionManager->destroySession($previousSessionToken);
                $this->offlineAccess->endOfflineSession($previousSessionToken);
            }
        } catch (\Throwable $throwable) {
            Log::warning('The offline session a new login replaced could not be ended', ['error' => $throwable->getMessage()]);
        }
    }

    /**
     * Open a read-only session from the archive when the password matches the
     * last online login; null when offline login is not possible.
     */
    private function loginOffline(string $username, string $password, ?string $previousSessionToken): ?JsonResponse
    {
        try {
            ['user_info' => $userInfo, 'wrong_password' => $wrongPassword] = $this->offlineAccess->verify($username, $password);

            if ($wrongPassword) {
                request()->attributes->set(self::FAILED_LOGIN_ATTRIBUTE, true);
            }

            if ($userInfo === null) {
                return null;
            }

            $sessionResult = $this->sessionManager->createOfflineSession($username, $userInfo, $password);
            $this->offlineAccess->markOfflineSession($sessionResult['session_token'], $this->sessionManager->sessionTtl());
            $this->endReplacedOfflineSession($previousSessionToken, $sessionResult['session_token']);

            session()->regenerate();
            session([
                'campus_session_token' => $sessionResult['session_token'],
                'campus_user_info' => $sessionResult['user_info'],
                'campus_offline' => true,
                'campus_offline_reconnect' => $sessionResult['offline_reconnect'],
            ]);

            $this->sessionManager->rememberLaravelSessionId($sessionResult['session_token'], (string) session()->getId());

            Log::info('User logged in offline from the archive', ['username_fingerprint' => PortalLogContext::fingerprint($username)]);

            return $this->statusSuccess('Portal kampus sedang tidak bisa diakses. Kamu masuk dalam mode offline: data diambil dari arsip MyCIC dan aksi ke portal belum tersedia. '.self::offlineReconnectMessage($sessionResult['offline_reconnect']), [
                'data' => [
                    'user_info' => $sessionResult['user_info'],
                    'expires_at' => $sessionResult['expires_at'],
                    'offline' => true,
                    'offline_reconnect' => $sessionResult['offline_reconnect'],
                ],
            ], campusSessionValid: true);
        } catch (\Throwable $throwable) {
            Log::warning('Offline login failed', ['error' => $throwable->getMessage()]);

            return null;
        }
    }
}
