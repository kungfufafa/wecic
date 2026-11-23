<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Routes that make the portal build a page for any id, or that start
 * notebook work, are throttled per campus session.
 */
class PortalRouteThrottleTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function throttledRoutes(): array
    {
        return [
            'class detail' => ['GET', '/api/class/91', 'throttle:portal-read'],
            'meeting detail' => ['GET', '/api/meeting/123', 'throttle:portal-read'],
            'sipandai flow' => ['GET', '/api/lms/flow', 'throttle:portal-read'],
            'notebook meeting' => ['POST', '/api/notebooks/meetings', 'throttle:notebook-sources'],
            'notebook source' => ['POST', '/api/notebooks/5/sources', 'throttle:notebook-sources'],
            'notebook source retry' => ['POST', '/api/notebooks/5/sources/9/retry', 'throttle:notebook-sources'],
            'notebook note to source' => ['POST', '/api/notebooks/5/notes/9/source', 'throttle:notebook-sources'],
        ];
    }

    #[DataProvider('throttledRoutes')]
    public function test_the_route_is_throttled(string $method, string $uri, string $throttle): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertContains($throttle, $route->gatherMiddleware());
    }

    public function test_portal_reads_are_limited_per_campus_session(): void
    {
        config(['portal.security.read_rate_limit_attempts' => 7]);

        $limit = $this->limitFor('portal-read', 'campus-token-read');

        $this->assertSame(7, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('campus-token-read', $limit->key);
    }

    public function test_notebook_sources_are_limited_per_campus_session(): void
    {
        $limit = $this->limitFor('notebook-sources', 'campus-token-notebook');

        $this->assertSame(20, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('campus-token-notebook', $limit->key);
    }

    private function limitFor(string $limiter, string $sessionToken): Limit
    {
        $request = Request::create('/api/class/91');
        $session = new Store('test', app('session')->driver()->getHandler());
        $session->put('campus_session_token', $sessionToken);
        $request->setLaravelSession($session);

        $limit = RateLimiter::limiter($limiter)($request);
        $this->assertInstanceOf(Limit::class, $limit);

        return $limit;
    }
}
