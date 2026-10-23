<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalWebSession
{
    public function __construct(
        private readonly PortalSessionManager $sessionManager,
        private readonly PortalOfflineAccess $offlineAccess
    ) {}

    /**
     * Pages read from the app session whether the campus session is offline
     * and how it reconnects (PortalSessionManager::offlineReconnectMode()):
     * an offline session can go online in the background
     * (ResumeOfflineSessionJob) or learn that the portal is back, so both
     * are refreshed on every page.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionToken = $request->session()->get('campus_session_token');

        if (! is_string($sessionToken) || $sessionToken === '') {
            return $this->redirectToLogin($request);
        }

        try {
            if (! $this->sessionManager->validateSession($sessionToken)) {
                return $this->redirectToLogin($request);
            }

            $isOffline = $this->offlineAccess->isOfflineSession($sessionToken);
            $request->session()->put('campus_offline', $isOffline);
            $request->session()->put('campus_offline_reconnect', $isOffline ? $this->sessionManager->offlineReconnectMode($sessionToken) : null);
        } catch (\Throwable) {
            return $this->redirectToLogin($request);
        }

        return $next($request);
    }

    private function redirectToLogin(Request $request): RedirectResponse
    {
        $request->session()->forget(['campus_session_token', 'campus_user_info']);

        return redirect()->route('login');
    }
}
