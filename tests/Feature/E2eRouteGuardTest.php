<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalConnectionException;
use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalAuthService;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalHttpClientOptions;
use App\Services\PortalReadOnlyFetcher;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * routes/e2e.php signs a browser in without the portal, for the Playwright
 * specs (tests/e2e). It must exist in the e2e environment alone, answer only
 * this machine there, and never reach the portal. bootstrap/e2e.php keeps
 * every portal client of the e2e server from sending, and its pages on the
 * built bundle even while a Vite dev server runs.
 */
class E2eRouteGuardTest extends TestCase
{
    /**
     * Whether a portal handler was bound when the application started to
     * boot, before any route file loaded (createApplication()).
     *
     * @var array{bound: bool, routes_loaded: bool}|null
     */
    private ?array $portalHandlerBeforeTheRoutes = null;

    /**
     * Boots as the environment the data set names (the e2e one otherwise).
     * The environment is set once the configuration is loaded, before the
     * providers boot and bootstrap/app.php decides which route files to load,
     * without touching the process environment the other tests read.
     *
     * Every test but the one about the e2e server's own portal guard runs
     * with the test suite's guard; what is bound when booting starts, before
     * any route file loads, is noted for both.
     */
    public function createApplication(): Application
    {
        $environment = $this->providedData()[0] ?? 'e2e';
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        if ($this->name() !== 'test_every_portal_client_of_the_e2e_server_refuses_to_send') {
            $this->guardPortalRequests($app);
        }

        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($environment): void {
            $app->detectEnvironment(static fn (): string => $environment);
        });
        $app->booting(function (Application $app): void {
            $this->portalHandlerBeforeTheRoutes = [
                'bound' => $app->bound(PortalHttpClientOptions::HANDLER_BINDING),
                'routes_loaded' => $app->make('router')->getRoutes()->count() > 0,
            ];
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function environmentsWithoutTheE2eRoutes(): array
    {
        return [
            'local' => ['local'],
            'testing' => ['testing'],
            'staging' => ['staging'],
            'production' => ['production'],
        ];
    }

    #[DataProvider('environmentsWithoutTheE2eRoutes')]
    public function test_the_e2e_routes_do_not_exist_outside_the_e2e_environment(string $environment): void
    {
        $this->assertSame($environment, $this->app->environment());
        $this->assertFalse(Route::has('e2e.session.start'));
        $this->assertFalse(Route::has('e2e.session.end'));

        $this->postJson('/__e2e/session')->assertNotFound();
        $this->deleteJson('/__e2e/session')->assertNotFound();
        $this->assertNull(session('campus_session_token'));
    }

    public function test_the_e2e_environment_has_the_routes_for_this_machine_only(): void
    {
        $this->assertSame('e2e', $this->app->environment());
        $this->assertTrue(Route::has('e2e.session.start'));
        $this->assertTrue(Route::has('e2e.session.end'));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/__e2e/session')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->deleteJson('/__e2e/session')->assertNotFound();
        $this->assertNull(session('campus_session_token'));
    }

    public function test_the_routes_answer_404_once_the_environment_is_no_longer_e2e(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->postJson('/__e2e/session')->assertNotFound();
        $this->deleteJson('/__e2e/session')->assertNotFound();
        $this->assertNull(session('campus_session_token'));
    }

    public function test_starting_a_session_signs_the_browser_in_without_the_portal(): void
    {
        Queue::fake();

        $response = $this->postJson('/__e2e/session');

        $response->assertCreated()
            ->assertJsonPath('session_id', session()->getId())
            ->assertJsonPath('user_info.name', 'Mahasiswa E2E')
            ->assertJsonPath('user_info.nim', 'e2e-mahasiswa');

        $token = session('campus_session_token');
        $sessions = app(PortalSessionManager::class);

        $this->assertIsString($token);
        $this->assertTrue($sessions->validateSession($token));
        $this->assertSame('e2e-mahasiswa', $sessions->getSession($token)['username']);
        $this->assertSame([], $sessions->getSession($token)['cookies']);
        $this->assertArrayNotHasKey('offline', $sessions->getSession($token));
        $this->assertSame(session()->getId(), $sessions->laravelSessionId($token));
        $this->assertFalse(session('campus_offline'));
        Queue::assertNotPushed(RefreshPortalSessionJob::class);
        Queue::assertNothingPushed();

        $this->withoutVite()->get('/dashboard')->assertOk();
    }

    public function test_a_second_start_ends_the_session_it_replaces(): void
    {
        $this->postJson('/__e2e/session')->assertCreated();
        $firstToken = session('campus_session_token');

        $this->postJson('/__e2e/session')->assertCreated();

        $this->assertNotSame($firstToken, session('campus_session_token'));
        $this->assertNull(app(PortalSessionManager::class)->getSession($firstToken));
        $this->assertFalse($this->keepAliveLockIsHeld($firstToken));
        $this->assertTrue($this->keepAliveLockIsHeld(session('campus_session_token')));
    }

    public function test_ending_the_session_signs_the_browser_out_and_leaves_nothing_behind(): void
    {
        $this->postJson('/__e2e/session')->assertCreated();
        $token = session('campus_session_token');

        $this->assertTrue($this->keepAliveLockIsHeld($token), 'dispatching the keep-alive took its unique lock');

        $this->deleteJson('/__e2e/session')->assertNoContent();

        $this->assertNull(app(PortalSessionManager::class)->getSession($token));
        $this->assertFalse($this->keepAliveLockIsHeld($token));
        $this->assertNull(session('campus_session_token'));
        $this->withoutVite()->get('/dashboard')->assertRedirect('/login');
    }

    public function test_ending_without_a_session_is_harmless(): void
    {
        $this->deleteJson('/__e2e/session')->assertNoContent();

        $this->assertNull(session('campus_session_token'));
    }

    /**
     * The e2e server's portal clients, built as that server builds them
     * (createApplication() leaves the test suite's guard out of this test),
     * send through the e2e handler of bootstrap/e2e.php, which refuses. It is
     * bound while the application boots, before any route file loads, so a
     * cached route list (which skips routes/e2e.php) keeps it.
     */
    public function test_every_portal_client_of_the_e2e_server_refuses_to_send(): void
    {
        $this->assertSame('e2e', $this->app->environment());
        $this->assertSame(['bound' => true, 'routes_loaded' => false], $this->portalHandlerBeforeTheRoutes);
        config(['portal.request_delay' => 0, 'portal.scraping.max_retries' => 1]);

        $refusal = 'The e2e environment never reaches the campus portal.';

        try {
            app(PortalAuthService::class)->isSessionAlive(new CookieJar);
            $this->fail('PortalAuthService reached the portal.');
        } catch (PortalConnectionException $exception) {
            $this->assertStringContainsString($refusal, $exception->getMessage());
        }

        try {
            app(PortalReadOnlyFetcher::class)->fetch(PortalEndpointRegistry::url('dashboard'));
            $this->fail('A PortalScrapingService reached the portal.');
        } catch (PortalConnectionException $exception) {
            $this->assertStringContainsString($refusal, $exception->getMessage());
        }

        $handler = PortalHttpClientOptions::boundHandler()['handler'] ?? null;

        $this->assertIsCallable($handler);

        try {
            $handler(new PsrRequest('GET', 'https://portal.invalid/portal/mahasiswa/'), [])->wait();
            $this->fail('The e2e portal handler sent a request.');
        } catch (ConnectException $exception) {
            $this->assertSame($refusal, $exception->getMessage());
        }
    }

    /**
     * A guard bound before the application boots (the test suite's, which
     * every other test here runs with) is the one the e2e environment keeps.
     */
    public function test_the_e2e_environment_keeps_a_portal_guard_bound_before_it(): void
    {
        $this->answerPortalRequestsAsUnreachable();

        try {
            app(PortalAuthService::class)->isSessionAlive(new CookieJar);
            $this->fail('PortalAuthService reached the portal.');
        } catch (PortalConnectionException $exception) {
            $this->assertStringContainsString('(the test made the portal unreachable)', $exception->getMessage());
            $this->assertStringNotContainsString('The e2e environment never reaches the campus portal.', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function viteDevServerUse(): array
    {
        return [
            'e2e loads the build' => ['e2e', false],
            'local follows the dev server' => ['local', true],
        ];
    }

    /**
     * A Vite dev server running for the development site (public/hot) must
     * not reach the e2e pages: the specs let only the e2e server through,
     * and check the build in public/build before they run.
     */
    #[DataProvider('viteDevServerUse')]
    public function test_only_the_e2e_pages_ignore_a_running_vite_dev_server(string $environment, bool $loadsTheDevServer): void
    {
        $this->assertSame($environment, $this->app->environment());

        $public = sys_get_temp_dir().'/mycic-e2e-public-'.Str::random(12);

        File::ensureDirectoryExists($public.'/build');
        File::put($public.'/hot', 'http://[::1]:5173');
        File::put($public.'/build/manifest.json', (string) json_encode([
            'resources/css/app.css' => ['file' => 'assets/app-e2e.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
            'resources/js/app.js' => ['file' => 'assets/app-e2e.js', 'src' => 'resources/js/app.js', 'isEntry' => true],
        ]));
        $this->app->usePublicPath($public);

        try {
            $response = $this->get('/login');
        } finally {
            File::deleteDirectory($public);
        }

        $response->assertOk();

        if ($loadsTheDevServer) {
            $response->assertSee('<script type="module" src="http://[::1]:5173/resources/js/app.js"></script>', false);
        } else {
            $response->assertSee('<script type="module" src="'.asset('build/assets/app-e2e.js').'"></script>', false)
                ->assertDontSee('5173');
        }
    }

    /**
     * Whether the unique lock of the session's keep-alive job
     * (RefreshPortalSessionJob is unique until processing) is taken.
     */
    private function keepAliveLockIsHeld(string $token): bool
    {
        $lock = Cache::lock(UniqueLock::getKey(new RefreshPortalSessionJob($token)), 10);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }
}
