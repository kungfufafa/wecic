<?php

use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            'portal.session' => \App\Http\Middleware\PortalSessionMiddleware::class,
        ]);

        // Apply CSRF protection to web routes only
        $middleware->web(VerifyCsrfTokenMiddleware::class);

        // Apply api middleware to api routes (without CSRF)
        $middleware->api([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Remove CSRF from all routes and add back only to web
        $middleware->remove(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
        $middleware->web(VerifyCsrfTokenMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
