<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Http\Middleware\PortalSessionMiddleware;
use App\Jobs\ExpirePortalSessionJob;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Models\PortalMobileSession;
use App\Models\PortalPushToken;
use App\Models\User;
use App\Services\PortalAuthService;
use App\Services\PortalMobileSessions;
use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Tests\Traits\LogsInWithMobileToken;

/**
 * The mobile app authenticates with a bearer token instead of the session
 * cookie: the token names a server-side Laravel session that holds the
 * campus session, so every API route works the same for both.
 */
class PortalMobileTokenAuthTest extends TestCase
{
    use LogsInWithMobileToken, RefreshDatabase;

    private const USERNAME = '20990000501';

    private const PASSWORD = 'rahasia-mobile';

    private const PUSH_TOKEN = 'ExponentPushToken[mobileAuthTest0000001]';

    protected function setUp(): void
    {
        parent::setUp();

        // Bound before anything builds the session manager: nothing here may reach the portal.
        $this->portalAcceptsLogin();
        Bus::fake([RefreshPortalSessionJob::class, ExpirePortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_token_login_returns_a_bearer_token_and_no_cookie(): void
    {
        $response = $this->tokenLogin();

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.offline', false)
            ->assertJsonPath('data.user_info.name', 'Mahasiswa Mobile')
            ->assertJsonMissingPath('data.session_token');

        $token = (string) $response->json('data.token');
        $this->assertMatchesRegularExpression('/^\d+\|mycic_[A-Za-z0-9]{40}[0-9a-f]{8}$/', $token);
        $this->assertSame([], $response->headers->getCookies(), 'A token login must not set the session or XSRF cookie.');

        $mobileSession = PortalMobileSession::query()->sole();
        $accessToken = PersonalAccessToken::query()->sole();
        $this->assertTrue($accessToken->tokenable->is($mobileSession));
        $this->assertSame(hash('sha256', Str::after($token, '|')), $accessToken->token, 'Only a hash of the token is stored.');
        $this->assertSame('Pixel 8', $mobileSession->device_name);
        $this->assertSame('portal.session.'.$mobileSession->session_id, $response->json('data.channel'));
        $this->assertSame($mobileSession->session_id, app(PortalSessionManager::class)->laravelSessionId($this->campusTokenOf($token)));
    }

    public function test_the_token_login_needs_no_csrf_token_but_the_cookie_login_still_does(): void
    {
        $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD])->assertStatus(419);

        $this->tokenLogin()->assertOk();
    }

    public function test_the_cookie_login_gives_no_token(): void
    {
        $this->withSession(['_token' => 'known-csrf-token'])
            ->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => self::PASSWORD], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertOk()
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.channel');

        $this->assertSame(0, PortalMobileSession::query()->count());
    }

    public function test_the_token_login_ignores_a_cookie_session_it_carries(): void
    {
        $this->withSession(['campus_session_token' => 'campus-token-browser', '_token' => 'known-csrf-token']);
        $browserSessionId = session()->getId();

        $token = $this->loginForToken();

        $this->assertNotSame($browserSessionId, PortalMobileSession::query()->sole()->session_id);
        $this->assertNotSame('campus-token-browser', $this->campusTokenOf($token));
    }

    public function test_a_failed_token_login_issues_nothing(): void
    {
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andThrow(new \App\Exceptions\PortalAuthenticationException('Invalid campus credentials provided'));
        }));
        $this->app->forgetInstance(PortalSessionManager::class);

        $this->tokenLogin()->assertStatus(401)->assertJsonMissingPath('data.token');

        $this->assertSame(0, PortalMobileSession::query()->count());
    }

    public function test_the_token_login_validates_its_fields(): void
    {
        $this->postJson('/api/auth/token', ['username' => self::USERNAME, 'password' => self::PASSWORD, 'device_name' => str_repeat('x', 101)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nama perangkat maksimal 100 karakter.');

        $this->postJson('/api/auth/token', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Username dan password harus diisi');
    }

    public function test_an_offline_token_login_issues_a_token_too(): void
    {
        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Mobile', 'student_id' => self::USERNAME]);
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andThrow(PortalConnectionException::networkError('https://portal.invalid/portal/', 'Connection timed out'));
        }));
        $this->app->forgetInstance(PortalSessionManager::class);

        $response = $this->tokenLogin();

        $response->assertOk()
            ->assertJsonPath('data.offline', true)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->withToken((string) $response->json('data.token'))
            ->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('data.offline', true);
    }

    public function test_the_session_check_works_with_the_token(): void
    {
        $token = $this->loginForToken();

        $this->withToken($token)
            ->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('data.user_info.name', 'Mahasiswa Mobile')
            ->assertJsonPath('meta.campus_session_valid', true);

        $this->assertSame([], $this->withToken($token)->getJson('/api/auth/session')->headers->getCookies());
    }

    public function test_the_session_refresh_works_with_the_token_and_without_csrf(): void
    {
        $token = $this->loginForToken();

        $this->withToken($token)
            ->postJson('/api/auth/session/refresh')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonMissingPath('data.session_token');

        $this->assertNotNull(PortalMobileSession::query()->sole()->fresh());
    }

    public function test_a_request_without_a_token_or_with_an_unknown_one_is_refused(): void
    {
        $this->loginForToken();

        $this->getJson('/api/notifications/unread-count')
            ->assertUnauthorized()
            ->assertJsonPath('meta.campus_session_valid', false);

        $this->withToken('mycic_'.Str::random(48))
            ->getJson('/api/notifications/unread-count')
            ->assertUnauthorized();

        $this->withToken('mycic_'.Str::random(48))
            ->postJson('/api/notifications/read', ['all' => true])
            ->assertUnauthorized();
    }

    public function test_a_bearer_header_never_falls_back_to_the_cookie_session(): void
    {
        $this->withSession(['campus_session_token' => 'campus-token-browser', '_token' => 'known-csrf-token'])
            ->withToken('mycic_not-a-real-token')
            ->getJson('/api/notifications/unread-count')
            ->assertUnauthorized();
    }

    public function test_the_cookie_session_still_needs_csrf_on_writes(): void
    {
        $this->withSession(['campus_session_token' => 'campus-token-browser', '_token' => 'known-csrf-token'])
            ->postJson('/api/notifications/read', ['all' => true])
            ->assertStatus(419);
    }

    public function test_a_write_with_the_token_needs_no_csrf(): void
    {
        $token = $this->loginForToken();

        $this->withToken($token)
            ->postJson('/api/notifications/read', ['all' => true])
            ->assertOk()
            ->assertJsonPath('data.updated', 0);
    }

    public function test_logout_revokes_the_token_and_stops_its_pushes(): void
    {
        $token = $this->loginForToken();
        $campusToken = $this->campusTokenOf($token);
        $this->withToken($token)->postJson('/api/devices/push-token', ['token' => self::PUSH_TOKEN, 'platform' => 'android'])->assertOk();
        $this->assertSame(1, PortalPushToken::query()->count());

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('meta.campus_session_valid', false);

        $this->assertSame(0, PortalMobileSession::query()->count());
        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame(0, PortalPushToken::query()->count());
        $this->assertNull(app(PortalSessionManager::class)->getSession($campusToken));
        $this->withToken($token)->getJson('/api/auth/session')->assertUnauthorized();
    }

    public function test_logout_forgets_the_push_token_the_app_names_even_after_the_session_ended(): void
    {
        $token = $this->loginForToken();
        $this->withToken($token)->postJson('/api/devices/push-token', ['token' => self::PUSH_TOKEN])->assertOk();
        app(PortalSessionManager::class)->destroySession($this->campusTokenOf($token));
        $this->withToken($token)->getJson('/api/auth/session')->assertUnauthorized();

        $this->withToken($token)
            ->postJson('/api/auth/logout', ['push_token' => self::PUSH_TOKEN])
            ->assertOk();

        $this->assertSame(0, PortalPushToken::query()->count());
    }

    public function test_a_token_whose_campus_session_ended_is_revoked_but_its_pushes_stay(): void
    {
        $token = $this->loginForToken();
        $this->withToken($token)->postJson('/api/devices/push-token', ['token' => self::PUSH_TOKEN])->assertOk();

        // The campus session idles out: the next request finds it unusable.
        app(PortalSessionManager::class)->invalidateSession($this->campusTokenOf($token), 'idle');

        $this->withToken($token)->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->assertSame(1, PortalMobileSession::query()->count(), 'The request that finds the campus session gone only drops it from the session.');

        $this->withToken($token)->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->assertSame(0, PortalMobileSession::query()->count());

        $pushToken = PortalPushToken::query()->sole();
        $this->assertNull($pushToken->portal_mobile_session_id, 'The install keeps getting the student\'s pushes until it logs out.');
    }

    public function test_a_failed_session_check_keeps_the_token(): void
    {
        $token = $this->loginForToken();
        $sessions = app(PortalSessionManager::class);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andThrow(new \RuntimeException('database is locked'));
        }));

        $this->withToken($token)->getJson('/api/notifications/unread-count')
            ->assertStatus(503)
            ->assertJsonPath('meta.campus_session_valid', true);

        $this->instance(PortalSessionManager::class, $sessions);

        $this->withToken($token)->getJson('/api/notifications/unread-count')->assertOk();
        $this->withToken($token)->getJson('/api/notifications/unread-count')->assertOk();
        $this->assertSame(1, PortalMobileSession::query()->count());
    }

    public function test_a_failed_refresh_or_session_check_keeps_the_token(): void
    {
        $token = $this->loginForToken();
        $sessions = app(PortalSessionManager::class);
        $offlineAccess = app(PortalOfflineAccess::class);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshSession')->andThrow(new \RuntimeException('Campus session mutation timed out'));
            $mock->shouldReceive('validateSession')->andThrow(new \RuntimeException('database is locked'));
        }));
        $this->instance(PortalOfflineAccess::class, Mockery::mock(PortalOfflineAccess::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isOfflineSession')->andReturnFalse();
        }));

        $this->withToken($token)->postJson('/api/auth/session/refresh')
            ->assertStatus(503)
            ->assertJsonPath('meta.campus_session_valid', true);
        $this->withToken($token)->getJson('/api/auth/session')
            ->assertStatus(503)
            ->assertJsonPath('meta.campus_session_valid', true);

        // (The router keeps the controller it built with the failing manager.)
        $this->instance(PortalSessionManager::class, $sessions);
        $this->instance(PortalOfflineAccess::class, $offlineAccess);

        $this->withToken($token)->getJson('/api/notifications/unread-count')->assertOk();
        $this->assertSame(1, PortalMobileSession::query()->count());
    }

    public function test_a_refresh_the_portal_ends_still_ends_the_login(): void
    {
        $token = $this->loginForToken();
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshSession')->andThrow(PortalAuthenticationException::sessionExpired());
        }));
        $this->instance(PortalOfflineAccess::class, Mockery::mock(PortalOfflineAccess::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isOfflineSession')->andReturnFalse();
        }));

        $this->withToken($token)->postJson('/api/auth/session/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('meta.campus_session_valid', false);
        $this->assertSame('', $this->campusTokenOf($token), 'The ended campus session is dropped from the token\'s session.');
    }

    public function test_logging_in_again_with_a_valid_token_replaces_it(): void
    {
        $oldToken = $this->loginForToken();
        $this->withToken($oldToken)->postJson('/api/devices/push-token', ['token' => self::PUSH_TOKEN])->assertOk();

        $response = $this->withToken($oldToken)->postJson('/api/auth/token', [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ])->assertOk();
        $newToken = (string) $response->json('data.token');

        $this->assertNotSame($oldToken, $newToken);
        $this->assertTrue(PersonalAccessToken::findToken($newToken)?->tokenable?->is(PortalMobileSession::query()->sole()));
        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->assertSame(PortalMobileSession::query()->sole()->id, PortalPushToken::query()->sole()->portal_mobile_session_id);
        $this->withToken($oldToken)->getJson('/api/auth/session')->assertUnauthorized();
        $this->withToken($newToken)->getJson('/api/auth/session')->assertOk();
    }

    public function test_tokens_stay_apart(): void
    {
        $first = $this->loginForToken();
        $second = $this->loginForToken();

        $this->assertNotSame($this->campusTokenOf($first), $this->campusTokenOf($second));
        $this->assertSame(2, PortalMobileSession::query()->count());

        $this->withToken($first)->postJson('/api/auth/logout')->assertOk();

        $this->withToken($first)->getJson('/api/auth/session')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/auth/session')->assertOk();
    }

    public function test_every_protected_route_is_reached_with_a_token_and_refused_without(): void
    {
        $token = $this->loginForToken();
        $routes = $this->protectedApiRoutes();
        $this->assertGreaterThan(60, count($routes));
        // Dozens of requests from one address in a row; rate limits are not what this is about.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ($routes as [$method, $uri]) {
            $this->assertContains(
                $this->json($method, $uri)->status(),
                [401, 419],
                "{$method} {$uri} answered a request without a token or cookie."
            );
            $this->withToken('mycic_'.Str::random(48))->json($method, $uri)->assertStatus(401);
        }

        // Past the campus session check, the route answers instead of running
        // its controller (which would read portal data).
        $this->app['router']->aliasMiddleware('portal.session', ReachedPortalSessionMiddleware::class);

        foreach ($routes as [$method, $uri]) {
            $this->withToken($token)
                ->json($method, $uri)
                ->assertStatus(299)
                ->assertHeader('X-Campus-Session', $this->campusTokenOf($token));
        }
    }

    public function test_tokens_unused_for_the_retention_period_are_pruned(): void
    {
        $stale = PortalMobileSession::factory()->withTokenUsedAt(now()->subDays(31))->create();
        $fresh = PortalMobileSession::factory()->withTokenUsedAt(now()->subDays(29))->create();
        $tokenless = PortalMobileSession::factory()->create();
        $pushToken = PortalPushToken::factory()->create(['portal_mobile_session_id' => $stale->id]);

        $this->artisan('model:prune', ['--model' => [PortalMobileSession::class]])->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelMissing($tokenless);
        $this->assertModelExists($fresh);
        $this->assertSame([$fresh->id], PersonalAccessToken::query()->pluck('tokenable_id')->all());
        $this->assertNull($pushToken->fresh()->portal_mobile_session_id);
    }

    public function test_only_a_valid_token_of_a_mobile_login_resolves(): void
    {
        $mobileSessions = app(PortalMobileSessions::class);
        $issued = $mobileSessions->issue(Str::random(40), 'Pixel 8');
        $accessToken = PersonalAccessToken::query()->sole();

        $this->assertTrue($issued['mobile_session']->is($mobileSessions->resolve($issued['token'])));
        $this->assertNull($mobileSessions->resolve($accessToken->token), 'The stored hash is not a token.');
        $this->assertNull($mobileSessions->resolve($accessToken->id.'|mycic_'.Str::random(48)));
        $this->assertNull($mobileSessions->resolve(''));
        $this->assertNull($mobileSessions->resolve(null));

        $accessToken->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertNull($mobileSessions->resolve($issued['token']), 'An expired Sanctum token is refused.');

        $accessToken->forceFill(['expires_at' => null])->save();
        config(['sanctum.expiration' => 60]);
        $this->travel(61)->minutes();
        $this->assertNull($mobileSessions->resolve($issued['token']), 'sanctum.expiration applies too.');
    }

    public function test_a_token_of_another_model_is_never_a_mobile_login(): void
    {
        $user = User::factory()->create();
        $foreignToken = PersonalAccessToken::query()->forceCreate([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'other',
            'token' => hash('sha256', 'mycic_foreign-token'),
            'abilities' => ['*'],
        ]);

        $this->assertNull(app(PortalMobileSessions::class)->resolve($foreignToken->id.'|mycic_foreign-token'));
    }

    public function test_the_auth_and_device_endpoints_document_what_they_answer(): void
    {
        $exportPath = tempnam(sys_get_temp_dir(), 'scramble-mobile-docs-');

        if ($exportPath === false) {
            $this->fail('Failed to allocate a temporary file for OpenAPI export.');
        }

        try {
            Artisan::call('scramble:export', ['--path' => $exportPath]);
            $spec = json_decode((string) file_get_contents($exportPath), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($exportPath);
        }

        $dataKeys = static fn (string $path, string $method): array => array_keys(
            $spec['paths'][$path][$method]['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'] ?? []
        );

        // The refresh answers the new expiry only; the campus token never leaves the server.
        $this->assertSame(['expires_at'], $dataKeys('/auth/session/refresh', 'post'));
        $this->assertSame(['user_info', 'expires_at', 'offline', 'offline_reconnect', 'token', 'token_type', 'channel'], $dataKeys('/auth/token', 'post'));
        $this->assertNotContains('token', $dataKeys('/auth/login', 'post'));
        $this->assertSame(['platform', 'registered_at'], $dataKeys('/devices/push-token', 'post'));
        $this->assertSame(['removed'], $dataKeys('/devices/push-token', 'delete'));
        $this->assertArrayHasKey('403', $spec['paths']['/broadcasting/auth']['post']['responses']);
    }

    /**
     * API routes behind the campus session check, with a value for every
     * route parameter that its constraint accepts.
     *
     * @return list<array{string, string}>
     */
    private function protectedApiRoutes(): array
    {
        $routes = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $isProtected = collect($route->gatherMiddleware())->contains(
                static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'portal.session')
            );

            if (! str_starts_with($route->uri(), 'api/') || ! $isProtected) {
                continue;
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', static function (array $matches) use ($route): string {
                $pattern = $route->wheres[$matches[1]] ?? '[^/]+';

                foreach (['1', 'krs', str_repeat('a', 40), (string) Str::uuid()] as $candidate) {
                    if (preg_match('#^'.$pattern.'$#', $candidate) === 1) {
                        return $candidate;
                    }
                }

                return '1';
            }, '/'.$route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, (string) $uri];
            }
        }

        return $routes;
    }
}

/**
 * The campus session check, answering 299 once it passes.
 */
class ReachedPortalSessionMiddleware extends PortalSessionMiddleware
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        return parent::handle($request, static fn (Request $request): Response => response('', 299, [
            'X-Campus-Session' => (string) $request->session()->get('campus_session_token'),
        ]), $mode);
    }
}
