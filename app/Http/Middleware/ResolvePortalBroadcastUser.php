<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PortalBroadcastActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Broadcast::auth() reads `$request->user()`: a session holding a campus
 * session answers as its own actor. The actor stays out of the auth guard,
 * whose id the database session handler would write into the integer
 * `sessions.user_id` column, which MySQL and PostgreSQL refuse.
 */
class ResolvePortalBroadcastUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('campus_session_token')) {
            $sessionId = (string) $request->session()->getId();
            $actor = new PortalBroadcastActor($sessionId);

            $request->setUserResolver(static fn (): PortalBroadcastActor => $actor);
        }

        return $next($request);
    }
}
