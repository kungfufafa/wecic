<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PortalLogContext;
use App\Services\PortalSessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PortalSessionMiddleware
{
    /**
     * Sent by requests the app makes on its own (re-reads triggered by a
     * realtime event or a timer). They check the session without counting
     * as the student's activity, or an unattended open tab would keep the
     * session from ever reaching the idle timeout.
     */
    public const BACKGROUND_HEADER = 'X-MyCIC-Background';

    public function __construct(private readonly PortalSessionManager $sessionManager) {}

    /**
     * Handle an incoming request.
     *
     * `$mode` 'page' is for a route the browser opens as a page (a download
     * link): a request that does not ask for JSON and has no usable session
     * is sent to the login page instead of getting a JSON 401.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $sessionToken = session('campus_session_token');
        $isPage = $mode === 'page' && ! $request->expectsJson();

        if (! $sessionToken) {
            if ($isPage) {
                return redirect()->route('login');
            }

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
            $isValid = $request->header(self::BACKGROUND_HEADER) === '1'
                ? $this->sessionManager->isSessionUsable($sessionToken)
                : $this->sessionManager->validateSession($sessionToken);

            if (! $isValid) {
                session()->forget(['campus_session_token', 'campus_user_info']);

                if ($isPage) {
                    return redirect()->route('login');
                }

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
                'session_token_fingerprint' => PortalLogContext::fingerprint((string) $sessionToken),
                'error' => $e->getMessage(),
            ]);

            session()->forget(['campus_session_token', 'campus_user_info']);

            if ($isPage) {
                return redirect()->route('login');
            }

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
