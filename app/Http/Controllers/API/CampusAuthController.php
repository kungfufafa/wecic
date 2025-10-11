<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\CampusSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * @group Authentication
 *
 * API endpoints for managing campus system authentication
 */
class CampusAuthController extends Controller
{
    private CampusSessionManager $sessionManager;

    public function __construct(CampusSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    /**
     * Login to campus system
     *
     * Authenticate user with CIC portal credentials and create session.
     *
     * @bodyParam username string required Student NIM/username. Example: 20251030030
     * @bodyParam password string required Student password. Example: Kanjutz123@
     *
     * @response {
     *   "status": "success",
     *   "message": "Login berhasil",
     *   "data": {
     *     "session_token": "abc123...",
     *     "user_info": [],
     *     "expires_at": "2025-10-10T18:48:21.158943Z"
     *   },
     *   "meta": {
     *     "timestamp": "2025-10-10T18:28:21.197134Z",
     *     "campus_session_valid": true
     *   }
     * }
     * @response 422 {
     *   "status": "error",
     *   "message": "Username dan password harus diisi",
     *   "errors": {
     *     "username": ["The username field is required."]
     *   }
     * }
     * @response 401 {
     *   "status": "error",
     *   "message": "Login gagal. Periksa kembali username dan password Anda.",
     *   "meta": {
     *     "timestamp": "2025-10-10T18:28:21.197134Z",
     *     "campus_session_valid": false
     *   }
     * }
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username dan password harus diisi',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $username = $request->input('username');
            $password = $request->input('password');

            // Create campus session
            $sessionResult = $this->sessionManager->createSession($username, $password);

            // Store session token in client session
            session(['campus_session_token' => $sessionResult['session_token']]);
            session(['campus_user_info' => $sessionResult['user_info']]);

            Log::info('User logged in successfully', ['username' => $username]);

            return response()->json([
                'status' => 'success',
                'message' => 'Login berhasil',
                'data' => [
                    'session_token' => $sessionResult['session_token'],
                    'user_info' => $sessionResult['user_info'],
                    'expires_at' => $sessionResult['expires_at'],
                ],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Login failed', [
                'username' => $request->input('username'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Login gagal. Periksa kembali username dan password Anda.',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }
    }

    /**
     * Check if current session is valid
     */
    public function checkSession(Request $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');

        if (! $sessionToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active session found',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }

        try {
            $isValid = $this->sessionManager->validateSession($sessionToken);
            $userInfo = session('campus_user_info');

            return response()->json([
                'status' => $isValid ? 'success' : 'error',
                'message' => $isValid ? 'Session is valid' : 'Session has expired',
                'data' => $isValid ? [
                    'user_info' => $userInfo,
                ] : null,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => $isValid,
                ],
            ], $isValid ? 200 : 401);

        } catch (\Exception $e) {
            Log::error('Session check failed', ['error' => $e->getMessage()]);

            // Use ToastService for session expired notification
            \App\Services\ToastService::sessionExpired();

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to validate session',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }

    /**
     * Refresh campus session
     */
    public function refreshSession(Request $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');

        if (! $sessionToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active session found',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }

        try {
            $refreshResult = $this->sessionManager->refreshSession($sessionToken);

            return response()->json([
                'status' => 'success',
                'message' => 'Session refreshed successfully',
                'data' => [
                    'session_token' => $sessionToken,
                    'expires_at' => $refreshResult['expires_at'] ?? null,
                ],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => true,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Session refresh failed', ['error' => $e->getMessage()]);

            // Clear invalid session
            session()->forget(['campus_session_token', 'campus_user_info']);

            // Use ToastService for session expired notification
            \App\Services\ToastService::sessionExpired('Session refresh failed. Please login again.');

            return response()->json([
                'status' => 'error',
                'message' => 'Session refresh failed. Please login again.',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }
    }

    /**
     * Logout from campus system
     */
    public function logout(Request $request): JsonResponse
    {
        $sessionToken = session('campus_session_token');

        try {
            if ($sessionToken) {
                $this->sessionManager->destroySession($sessionToken);
            }

            // Clear client session
            session()->forget(['campus_session_token', 'campus_user_info']);

            Log::info('User logged out successfully');

            return response()->json([
                'status' => 'success',
                'message' => 'Logout berhasil',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Logout failed', ['error' => $e->getMessage()]);

            // Still clear client session even if campus logout fails
            session()->forget(['campus_session_token', 'campus_user_info']);

            return response()->json([
                'status' => 'success',
                'message' => 'Logout berhasil (session cleared)',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ]);
        }
    }
}
