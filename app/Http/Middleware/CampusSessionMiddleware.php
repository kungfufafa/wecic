<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CampusSessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CampusSessionMiddleware
{
    private CampusSessionManager $sessionManager;

    public function __construct(CampusSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionToken = session('campus_session_token');

        // If no campus session token, redirect to login
        if (! $sessionToken) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active campus session found',
                    'meta' => [
                        'timestamp' => now()->toISOString(),
                        'campus_session_valid' => false,
                    ],
                ], 401);
            }

            return redirect()->route('login')->with('error', 'Please login to access this page');
        }

        try {
            // Validate campus session
            $isValid = $this->sessionManager->validateSession($sessionToken);

            if (! $isValid) {
                // Clear invalid session
                session()->forget(['campus_session_token', 'campus_user_info']);

                if ($request->expectsJson()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Campus session has expired',
                        'meta' => [
                            'timestamp' => now()->toISOString(),
                            'campus_session_valid' => false,
                        ],
                    ], 401);
                }

                return redirect()->route('login')->with('error', 'Your campus session has expired. Please login again.')->with('session_expired', true);
            }

            // Session is valid, continue
            return $next($request);

        } catch (\Exception $e) {
            Log::error('Campus session validation failed', [
                'session_token' => $sessionToken,
                'error' => $e->getMessage(),
            ]);

            // Clear potentially corrupted session
            session()->forget(['campus_session_token', 'campus_user_info']);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to validate campus session',
                    'meta' => [
                        'timestamp' => now()->toISOString(),
                        'campus_session_valid' => false,
                    ],
                ], 500);
            }

            return redirect()->route('login')->with('error', 'Session validation failed. Please login again.');
        }
    }
}
