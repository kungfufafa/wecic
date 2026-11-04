<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Services\PortalDemoFixtures;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (Application $app): void {
            // Version 1 of the API (the mobile app): the same routes as the
            // unversioned /api/*, which the web app keeps using.
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../routes/api.php');

            // Browser-test helpers (fake sign-in), in the e2e environment only.
            if ($app->environment('e2e')) {
                Route::middleware('web')->group(__DIR__.'/../routes/e2e.php');
            }

            // The demo sign-in of a checkout without a CIC account, in the
            // local environment with PORTAL_DEMO=true only.
            if (PortalDemoFixtures::enabledIn($app)) {
                Route::middleware('web')->group(__DIR__.'/../routes/demo.php');
            }
        },
    )
    // The browser's channel sign-in (/broadcasting/auth), throttled like the API's.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'throttle:portal-channel-auth']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            'portal.session' => \App\Http\Middleware\PortalSessionMiddleware::class,
            'portal.web' => \App\Http\Middleware\EnsurePortalWebSession::class,
            'portal.online' => \App\Http\Middleware\EnsurePortalOnline::class,
        ]);

        // Web routes check CSRF with our middleware, which also enforces it in
        // feature tests (the web group lists ValidateCsrfToken since Laravel 11).
        $middleware->web(replace: [
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => \App\Http\Middleware\VerifyCsrfTokenMiddleware::class,
        ]);

        // Apply api middleware to api routes (without CSRF)
        $middleware->api([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\ResolvePortalBroadcastUser::class,
        ]);

        // Proxies to trust come from portal.security.trusted_proxies (none by default).
        $middleware->replace(
            \Illuminate\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\TrustConfiguredProxies::class
        );

        // Passwords reach the portal exactly as typed, like the login password
        // (already excluded by the framework): a trimmed new password would
        // differ from what the student types at the next login.
        $middleware->trimStrings(except: [
            'new_password',
            'new_password_confirmation',
            // Aliases PortalPasswordChangeRequest accepts.
            'old_password',
            'passwordold',
            'konfirm',
            'data.passwordold',
            'data.password',
            'data.konfirm',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* error is JSON in the API's envelope, whatever the client's Accept header.
        $exceptions->shouldRenderJsonWhen(ApiExceptionRenderer::rendersJson(...));
        $exceptions->respond(ApiExceptionRenderer::respond(...));

        // Reported exceptions also go to Sentry when SENTRY_LARAVEL_DSN is set.
        Integration::handles($exceptions);
    })
    // Browser-test guards (no portal, no Vite dev server), in the e2e
    // environment only, and the demo mode's fixture portal (local with
    // PORTAL_DEMO=true only); set while booting, so a cached route list
    // keeps them.
    ->booting(function (Application $app): void {
        if ($app->environment('e2e')) {
            (require __DIR__.'/e2e.php')($app);
        }

        if (PortalDemoFixtures::enabledIn($app)) {
            (require __DIR__.'/demo.php')($app);
        }
    })
    ->create();
