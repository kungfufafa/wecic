<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PortalOfflineAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes that send something to the portal (attendance, uploads, password,
 * refresh) are closed to an offline session, which only reads the archive.
 */
class EnsurePortalOnline
{
    public function __construct(private readonly PortalOfflineAccess $offlineAccess) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->offlineAccess->isOfflineSession(session('campus_session_token'))) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Kamu sedang di mode offline, jadi aksi ke portal kampus belum bisa dijalankan. MyCIC menyambung lagi otomatis begitu portal bisa diakses.',
            'meta' => [
                'timestamp' => now()->toISOString(),
                'campus_session_valid' => true,
                'offline' => true,
            ],
        ], 503);
    }
}
