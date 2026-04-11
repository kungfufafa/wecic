<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfTokenMiddleware extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/*',
        'api/campus/*',
        'api/campus/login',
        'api/campus/logout',
        'api/campus/refresh-session',
        'api/campus/check-session',
        'api/campus/assignments/*',
        'api/campus/attendance/*',
    ];
}
