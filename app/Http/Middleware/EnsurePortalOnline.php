<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\API\CampusApiController;
use App\Services\PortalOfflineAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes that send something to the portal (password, refresh, deletes,
 * archive syncs) are closed to an offline session, which only reads the
 * archive. Attendance and uploads are not behind this: they go to the
 * outbox instead (PortalOutbox), because their windows close.
 */
class EnsurePortalOnline
{
    public const OFFLINE_MESSAGE = 'Kamu sedang di mode offline, jadi aksi ke portal kampus belum bisa dijalankan. MyCIC menyambung lagi otomatis begitu portal bisa diakses.';

    public function __construct(private readonly PortalOfflineAccess $offlineAccess) {}

    /**
     * `$mode` 'page' is for a route the browser opens as a page (a download
     * link): a request that does not ask for JSON gets a short error page.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! $this->offlineAccess->isOfflineSession(session('campus_session_token'))) {
            return $next($request);
        }

        if ($mode === 'page' && ! $request->expectsJson()) {
            return CampusApiController::navigationErrorPage('Belum tersedia di mode offline', self::OFFLINE_MESSAGE, 503);
        }

        return self::offlineResponse();
    }

    /**
     * Also the answer of the outbox endpoints when the outbox is switched off.
     */
    public static function offlineResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => self::OFFLINE_MESSAGE,
            'meta' => [
                'timestamp' => now()->toISOString(),
                'campus_session_valid' => true,
                'offline' => true,
            ],
        ], 503);
    }
}
