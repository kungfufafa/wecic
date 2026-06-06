<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PortalBroadcastActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePortalBroadcastUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('campus_session_token')) {
            $sessionId = (string) $request->session()->getId();
            $actor = new PortalBroadcastActor($sessionId);

            $request->setUserResolver(static fn (): PortalBroadcastActor => $actor);
            auth()->setUser($actor);
        }

        return $next($request);
    }
}
