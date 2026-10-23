<?php

declare(strict_types=1);

use App\Services\PortalHttpClientOptions;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;
use Psr\Http\Message\RequestInterface;

/**
 * The e2e environment of the browser tests (tests/e2e, APP_ENV=e2e, which
 * reads .env.e2e), set up while the application boots: bootstrap/app.php
 * calls this in that environment only, before any route file loads, so a
 * cached route list cannot skip it. routes/e2e.php adds the fake sign-in.
 *
 * - Every portal client refuses to send (a ConnectException, as for a portal
 *   that cannot be reached), unless the test suite bound its own guard first
 *   (tests/TestCase.php).
 * - Pages load the built bundle (public/build) even while a Vite dev server
 *   runs for the development site (public/hot): the specs let no other origin
 *   than the e2e server through, and check that build before they run.
 */
return static function (Application $app): void {
    if (! $app->bound(PortalHttpClientOptions::HANDLER_BINDING)) {
        $app->bind(PortalHttpClientOptions::HANDLER_BINDING, static fn (): HandlerStack => HandlerStack::create(
            static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
                new ConnectException('The e2e environment never reaches the campus portal.', $request)
            )
        ));
    }

    $app->make(Vite::class)->useHotFile($app->storagePath('framework/testing/e2e/vite-dev-server-is-not-used'));
};
