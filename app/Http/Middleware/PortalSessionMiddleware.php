<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PortalSessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PortalSessionMiddleware
{
    private PortalSessionManager $sessionManager;

    public function __construct(PortalSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionToken = session('campus_session_token');

        if (! $sessionToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesi kampus tidak ditemukan. Silakan masuk lagi.',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 401);
        }

        try {
            $isValid = $this->sessionManager->validateSession($sessionToken);

            if (! $isValid) {
                session()->forget(['campus_session_token', 'campus_user_info']);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Sesi kampus sudah berakhir. Silakan masuk lagi.',
                    'meta' => [
                        'timestamp' => now()->toISOString(),
                        'campus_session_valid' => false,
                    ],
                ], 401);
            }

            return $next($request);
        } catch (\Exception $e) {
            Log::error('Campus session validation failed', [
                'session_token' => $sessionToken,
                'error' => $e->getMessage(),
            ]);

            session()->forget(['campus_session_token', 'campus_user_info']);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memeriksa sesi kampus.',
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'campus_session_valid' => false,
                ],
            ], 500);
        }
    }
}
