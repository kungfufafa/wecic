<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PortalMobileSession;
use App\Services\PortalMobileSessions;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts the Laravel session of an API request: from the session cookie
 * (the web app) or, for the mobile app, from the bearer token
 * (`Authorization: Bearer`), which names a server-side session
 * (PortalMobileSession). Whatever reads the session (the campus token, rate
 * limits, the realtime channel) then works the same for both.
 *
 * A request with a bearer token never reads or sets the session cookie, so
 * neither stands in for the other; in `token` mode (the token login) a
 * request without a token starts an empty session too. Such a session is
 * saved only while a token still names it: the one a logout ended or a
 * login replaced is left behind. Nothing of it stays in memory after the
 * request, for the next request a long-lived process (or a test) serves. A
 * token whose session no longer holds a campus session (it idled out or
 * expired upstream) is revoked on its next use.
 */
class StartPortalSession extends StartSession
{
    /**
     * Request attribute: the request is authenticated by bearer token (or
     * asks for one), never by cookie.
     */
    public const TOKEN_REQUEST = 'portal_token_request';

    /**
     * Request attribute: the PortalMobileSession whose session the request
     * runs in, while its token is valid.
     */
    public const MOBILE_SESSION = 'portal_mobile_session';

    public static function isTokenRequest(Request $request): bool
    {
        return $request->attributes->getBoolean(self::TOKEN_REQUEST);
    }

    public static function mobileSession(Request $request): ?PortalMobileSession
    {
        $mobileSession = $request->attributes->get(self::MOBILE_SESSION);

        return $mobileSession instanceof PortalMobileSession ? $mobileSession : null;
    }

    /**
     * Names the session the current request saves at its end (a login's
     * new token), or none (a logout). Always on the application's request:
     * a form request is a copy, whose attributes this middleware never sees.
     */
    public static function bindMobileSession(?PortalMobileSession $mobileSession): void
    {
        if ($mobileSession === null) {
            request()->attributes->remove(self::MOBILE_SESSION);

            return;
        }

        request()->attributes->set(self::MOBILE_SESSION, $mobileSession);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle($request, Closure $next, ?string $mode = null)
    {
        if ($mode === 'token' || $request->bearerToken() !== null) {
            $request->attributes->set(self::TOKEN_REQUEST, true);
        }

        return parent::handle($request, $next);
    }

    public function getSession(Request $request)
    {
        if (! self::isTokenRequest($request)) {
            return parent::getSession($request);
        }

        $mobileSession = app(PortalMobileSessions::class)->resolve($request->bearerToken());
        $request->attributes->set(self::MOBILE_SESSION, $mobileSession);

        return tap($this->manager->driver(), function (Session $session) use ($mobileSession): void {
            // Only what the store holds for this session, never what an
            // earlier request of a long-lived process left in memory.
            $session->flush();
            $session->setId($mobileSession?->session_id);
        });
    }

    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        if (! self::isTokenRequest($request)) {
            return parent::handleStatefulRequest($request, $session, $next);
        }

        $request->setLaravelSession($this->startSession($request, $session));

        $this->collectGarbage($session);

        $response = $next($request);

        if (self::mobileSession($request)?->session_id === $session->getId()) {
            $this->saveSession($request);
        }

        $session->flush();

        return $response;
    }

    protected function startSession(Request $request, $session)
    {
        $session = parent::startSession($request, $session);
        $mobileSession = self::mobileSession($request);

        if ($mobileSession !== null && ! $session->has('campus_session_token')) {
            app(PortalMobileSessions::class)->revoke($mobileSession);
            $request->attributes->remove(self::MOBILE_SESSION);
        }

        return $session;
    }
}
