<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PortalSessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WebLoginController extends Controller
{
    private PortalSessionManager $sessionManager;

    public function __construct(PortalSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    /**
     * Handle login request for both web and API
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            $response = [
                'status' => 'error',
                'message' => 'Username dan password harus diisi',
                'errors' => $validator->errors(),
            ];

            // Return JSON if request expects JSON, otherwise redirect back with error
            if ($request->expectsJson()) {
                return response()->json($response, 422);
            }

            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Username dan password harus diisi');
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

            $response = [
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
            ];

            // Return JSON if request expects JSON, otherwise redirect to dashboard
            if ($request->expectsJson()) {
                return response()->json($response);
            }

            return redirect()
                ->route('dashboard')
                ->with('success', 'Login berhasil! Selamat datang '.($sessionResult['user_info']['name'] ?? $username));

        } catch (\Exception $e) {
            Log::error('Login failed', [
                'username' => $request->input('username'),
                'error' => $e->getMessage(),
            ]);

            $response = [
                'status' => 'error',
                'message' => 'Login gagal. Periksa kembali username dan password Anda.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ];

            // Return JSON if request expects JSON, otherwise redirect back with error
            if ($request->expectsJson()) {
                return response()->json($response, 401);
            }

            return back()
                ->withInput()
                ->with('error', 'Login gagal. Periksa kembali username dan password Anda.');
        }
    }
}
