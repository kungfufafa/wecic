<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCsrfTokenMiddleware extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [];

    /**
     * A request authenticated by bearer token (StartPortalSession) is not
     * checked: a browser never adds the token on its own, and such a request
     * never runs in the cookie session.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     */
    public function handle($request, Closure $next)
    {
        if (StartPortalSession::isTokenRequest($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * Enforce CSRF during feature tests when middleware is not explicitly disabled.
     */
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
