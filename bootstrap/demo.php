<?php

declare(strict_types=1);

use App\Services\PortalDemoFixtures;
use App\Services\PortalHttpClientOptions;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Application;

/**
 * The demo mode of a local checkout without a CIC account (APP_ENV=local
 * with PORTAL_DEMO=true, read as config portal.demo), set up while the
 * application boots: bootstrap/app.php calls this in that case only, before
 * any route file loads, so a cached route list cannot skip it.
 * routes/demo.php adds the demo sign-in.
 *
 * - Every portal client answers from the fixture pages of
 *   tests/Fixtures/portal (PortalDemoFixtures): a page without a fixture is
 *   refused like a host that cannot be reached, unless the test suite bound
 *   its own guard first (tests/TestCase.php).
 * - There is no portal to spare: no pause between requests, and a refused
 *   page fails at once instead of being retried.
 */
return static function (Application $app): void {
    if (! $app->bound(PortalHttpClientOptions::HANDLER_BINDING)) {
        $app->bind(PortalHttpClientOptions::HANDLER_BINDING, static fn (): HandlerStack => HandlerStack::create(
            $app->make(PortalDemoFixtures::class)
        ));
    }

    $app->make('config')->set([
        'portal.request_delay' => 0,
        'portal.scraping.max_retries' => 1,
        'portal.scraping.retry_delay' => 0,
    ]);
};
