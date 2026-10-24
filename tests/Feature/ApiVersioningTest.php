<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExpirePortalSessionJob;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;
use Tests\Traits\LogsInWithMobileToken;

/**
 * Version 1 of the API (`/api/v1/*`, the mobile app) serves exactly the
 * routes of the unversioned `/api/*`, which the web app keeps using.
 */
class ApiVersioningTest extends TestCase
{
    use LogsInWithMobileToken, RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_every_api_route_is_served_under_v1_with_the_same_stack(): void
    {
        $unversioned = $this->apiRoutes(fn (string $uri): bool => ! str_starts_with($uri, 'api/v1/'), 'api/');
        $v1 = $this->apiRoutes(fn (string $uri): bool => str_starts_with($uri, 'api/v1/'), 'api/v1/');

        $this->assertNotEmpty($v1);
        $this->assertSame($unversioned, $v1);
        $this->assertArrayHasKey('POST auth/token', $v1);
        $this->assertArrayHasKey('POST broadcasting/auth', $v1);
    }

    public function test_the_mobile_app_logs_in_and_out_through_v1(): void
    {
        $this->portalAcceptsLogin();
        Bus::fake([RefreshPortalSessionJob::class, ExpirePortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class]);

        $token = (string) $this->postJson('/api/v1/auth/token', [
            'username' => '20990000501',
            'password' => 'rahasia-mobile',
            'device_name' => 'Pixel 8',
        ])->assertOk()->assertJsonPath('data.token_type', 'Bearer')->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('data.user_info.name', 'Mahasiswa Mobile');

        // The unversioned routes are the same API: the token works there too.
        $this->withToken($token)->getJson('/api/auth/session')->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($token)->getJson('/api/v1/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_the_api_docs_describe_v1_once(): void
    {
        $exportPath = tempnam(sys_get_temp_dir(), 'scramble-v1-docs-');

        if ($exportPath === false) {
            $this->fail('Failed to allocate a temporary file for OpenAPI export.');
        }

        try {
            Artisan::call('scramble:export', ['--path' => $exportPath]);
            $spec = json_decode((string) file_get_contents($exportPath), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($exportPath);
        }

        $this->assertStringEndsWith('/api/v1', (string) $spec['servers'][0]['url']);
        $this->assertArrayHasKey('/auth/token', $spec['paths']);
        $this->assertSame([], array_values(array_filter(
            array_keys($spec['paths']),
            fn (string $path): bool => str_starts_with($path, '/v1/') || str_starts_with($path, '/api/'),
        )));
    }

    /**
     * The API routes whose URI passes `$filter`, keyed by method and URI
     * without `$prefix`, with what serves them.
     *
     * @param  \Closure(string): bool  $filter
     * @return array<string, array{action: string, middleware: list<string>, wheres: array<string, string>}>
     */
    private function apiRoutes(\Closure $filter, string $prefix): array
    {
        $routes = [];

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! $filter($route->uri())) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[$method.' '.substr($route->uri(), strlen($prefix))] = [
                    'action' => $route->getActionName(),
                    'middleware' => $route->gatherMiddleware(),
                    'wheres' => $route->wheres,
                ];
            }
        }

        ksort($routes);

        return $routes;
    }
}
