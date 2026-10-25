<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalConnectionException;
use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalAuthService;
use App\Services\PortalDemoFixtures;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalHttpClientOptions;
use App\Services\PortalReadOnlyFetcher;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The local demo mode (PORTAL_DEMO=true in APP_ENV=local): routes/demo.php
 * signs a browser in as the demo student without the portal, and
 * bootstrap/demo.php answers every portal client from the fixture pages of
 * tests/Fixtures/portal. Both must exist in the local environment with the
 * flag alone, and the pages must show the fixtures' data.
 */
class PortalDemoModeTest extends TestCase
{
    private const CSRF_TOKEN = 'demo-csrf-token';

    /**
     * Tests that run with the demo's own portal handler instead of the test
     * suite's guard (createApplication()).
     */
    private const TESTS_ON_THE_DEMO_HANDLER = [
        'test_the_demo_does_not_exist_outside_the_local_environment_or_without_the_flag',
        'test_every_portal_client_answers_from_the_fixtures',
        'test_the_pages_show_the_fixtures_data',
    ];

    /**
     * Whether a portal handler was bound when the application started to
     * boot, before any route file loaded (createApplication()).
     *
     * @var array{bound: bool, routes_loaded: bool}|null
     */
    private ?array $portalHandlerBeforeTheRoutes = null;

    /**
     * Boots as the environment the data set names (local otherwise) with
     * the flag the data set gives (on otherwise). Both are set once the
     * configuration is loaded, before the providers boot and
     * bootstrap/app.php decides what to load, without touching the process
     * environment the other tests read.
     */
    public function createApplication(): Application
    {
        $environment = $this->providedData()[0] ?? 'local';
        $demo = $this->providedData()[1] ?? true;
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        if (! in_array($this->name(), self::TESTS_ON_THE_DEMO_HANDLER, true)) {
            $this->guardPortalRequests($app);
        }

        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($environment, $demo): void {
            $app->detectEnvironment(static fn (): string => $environment);
            $app->make('config')->set('portal.demo', $demo);
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
     * @return array<string, array{string, bool}>
     */
    public static function environmentsWithoutTheDemo(): array
    {
        return [
            'local without the flag' => ['local', false],
            'testing' => ['testing', true],
            'staging' => ['staging', true],
            'production' => ['production', true],
            'e2e' => ['e2e', true],
        ];
    }

    /**
     * Outside the local environment the flag is ignored, and local without
     * the flag has no demo either: no route, and no portal handler bound
     * while booting (this test boots without the test suite's guard, which
     * setUp() binds right after).
     */
    #[DataProvider('environmentsWithoutTheDemo')]
    public function test_the_demo_does_not_exist_outside_the_local_environment_or_without_the_flag(string $environment, bool $demo): void
    {
        $this->assertSame($environment, $this->app->environment());
        $this->assertSame($demo, config('portal.demo'));
        $this->assertFalse(Route::has('demo.session.start'));
        $this->assertSame(['bound' => $environment === 'e2e', 'routes_loaded' => false], $this->portalHandlerBeforeTheRoutes);

        $this->startDemoSession()->assertNotFound();
        $this->assertNull(session('campus_session_token'));
    }

    public function test_the_local_environment_with_the_flag_has_the_demo(): void
    {
        $this->assertSame('local', $this->app->environment());
        $this->assertTrue(config('portal.demo'));
        $this->assertTrue(Route::has('demo.session.start'));
        $this->assertSame(['bound' => true, 'routes_loaded' => false], $this->portalHandlerBeforeTheRoutes);

        $this->withoutVite()->get('/login')
            ->assertOk()
            ->assertSee('Masuk sebagai mahasiswa demo')
            ->assertSee(route('demo.session.start'));
    }

    public function test_the_login_page_offers_no_demo_sign_in_without_the_route(): void
    {
        config(['portal.demo' => false]);

        $this->assertTrue(Route::has('demo.session.start'), 'the route list was built with the demo on');

        $this->withoutVite()->get('/login')->assertOk()->assertDontSee('mahasiswa demo');
    }

    public function test_the_route_answers_404_once_the_flag_is_off_or_the_environment_changes(): void
    {
        config(['portal.demo' => false]);
        $this->startDemoSession()->assertNotFound();

        config(['portal.demo' => true]);
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->startDemoSession()->assertNotFound();

        $this->assertNull(session('campus_session_token'));
    }

    /**
     * The sign-in is a form on the login page, so it carries the CSRF token
     * like any web form; without one it is refused.
     */
    public function test_starting_a_session_needs_the_csrf_token(): void
    {
        $this->post('/__demo/session')->assertStatus(419);

        $this->assertNull(session('campus_session_token'));
    }

    public function test_starting_a_session_signs_the_browser_in_without_the_portal(): void
    {
        Queue::fake();

        $this->startDemoSession()->assertRedirect(route('dashboard'));

        $token = session('campus_session_token');
        $sessions = app(PortalSessionManager::class);

        $this->assertIsString($token);
        $this->assertTrue($sessions->validateSession($token));
        $this->assertSame(PortalDemoFixtures::USERNAME, $sessions->getSession($token)['username']);
        $this->assertSame([], $sessions->getSession($token)['cookies']);
        $this->assertArrayNotHasKey('offline', $sessions->getSession($token));
        $this->assertArrayNotHasKey('encrypted_credentials', $sessions->getSession($token));
        $this->assertSame(session()->getId(), $sessions->laravelSessionId($token));
        $this->assertSame(PortalDemoFixtures::USER_INFO, session('campus_user_info'));
        $this->assertFalse(session('campus_offline'));
        Queue::assertNotPushed(RefreshPortalSessionJob::class);
        Queue::assertNothingPushed();

        $this->withoutVite()->get('/dashboard')->assertOk();
    }

    public function test_a_request_for_json_gets_the_session_like_the_api_login(): void
    {
        $response = $this->withSession(['_token' => self::CSRF_TOKEN])
            ->withHeader('X-CSRF-TOKEN', self::CSRF_TOKEN)
            ->postJson('/__demo/session');

        $response->assertCreated()
            ->assertJsonPath('session_id', session()->getId())
            ->assertJsonPath('user_info.name', 'Mahasiswa Contoh')
            ->assertJsonPath('user_info.nim', '20990000001');

        $this->assertTrue(app(PortalSessionManager::class)->validateSession((string) session('campus_session_token')));
    }

    public function test_a_second_start_ends_the_session_it_replaces(): void
    {
        $this->startDemoSession()->assertRedirect();
        $firstToken = session('campus_session_token');

        $this->startDemoSession()->assertRedirect();

        $this->assertNotSame($firstToken, session('campus_session_token'));
        $this->assertNull(app(PortalSessionManager::class)->getSession($firstToken));
        $this->assertFalse($this->keepAliveLockIsHeld($firstToken));
        $this->assertTrue($this->keepAliveLockIsHeld(session('campus_session_token')));
    }

    /**
     * The demo's portal clients, built as the demo server builds them
     * (createApplication() leaves the test suite's guard out of this test),
     * send through the handler of bootstrap/demo.php: a captured page is
     * read from its fixture, a page without one is refused like an
     * unreachable host. The handler is bound while the application boots,
     * before any route file loads, so a cached route list keeps it.
     */
    public function test_every_portal_client_answers_from_the_fixtures(): void
    {
        $this->assertSame(['bound' => true, 'routes_loaded' => false], $this->portalHandlerBeforeTheRoutes);
        $this->assertSame(0, config('portal.request_delay'));
        $this->assertSame(1, config('portal.scraping.max_retries'));

        $this->assertTrue(app(PortalAuthService::class)->isSessionAlive(new CookieJar), 'the dashboard fixture reads as a signed-in page');

        $this->startDemoSession()->assertRedirect();
        $token = (string) session('campus_session_token');

        $fetcher = app(PortalReadOnlyFetcher::class);
        $fetcher->setSession($token);

        $this->assertStringEqualsFile(base_path('tests/Fixtures/portal/profile.html'), $fetcher->fetch(PortalEndpointRegistry::url('profile')));

        try {
            $fetcher->fetch(PortalEndpointRegistry::url('password_form'));
            $this->fail('A page without a fixture was answered.');
        } catch (PortalConnectionException $exception) {
            $this->assertStringContainsString(PortalDemoFixtures::NO_FIXTURE, $exception->getMessage());
            $this->assertStringContainsString('[password_form]', $exception->getMessage());
        }

        $this->assertTrue(app(PortalSessionManager::class)->validateSession($token), 'a refused page does not end the demo session');
    }

    /**
     * Signed in as the demo student, the pages of the dashboard, classes,
     * a class, a meeting, announcements, finance, SKKM and the library get
     * their data from the fixtures through the API the web app calls.
     */
    public function test_the_pages_show_the_fixtures_data(): void
    {
        $this->startDemoSession()->assertRedirect();

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('profile.personal_data.nama', 'Mahasiswa Contoh')
            ->assertJsonPath('profile.personal_data.nim', '20990000001');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user_info.name', 'Mahasiswa Contoh')
            ->assertJsonPath('data.dashboard_overview.total_courses', '7')
            ->assertJsonPath('data.classes_summary.total_classes', 7);

        $this->getJson('/api/class')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user_info.nim', '20990000001')
            ->assertJsonCount(7, 'classes');

        $this->getJson('/api/class/2343?dosen_id=4961&course_id=8987')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('class_detail.found', true)
            ->assertJsonPath('class_detail.course_info.name', 'Enterprise Resource Planning');

        // A captured meeting opens as itself; one that was not captured opens the first capture.
        $this->getJson('/api/meeting/36365?courses_id=9496')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('class_detail.found', true)
            ->assertJsonPath('class_detail.meeting_info.breadcrumb', 'Beranda > Kelas Data Governance >Pertemuan 3');
        $this->getJson('/api/meeting/30061?courses_id=8987')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('class_detail.found', true);

        $announcements = $this->getJson('/api/announcement')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('data.items');

        $this->assertIsArray($announcements);
        $this->assertNotEmpty($announcements);

        $this->getJson('/api/finance/payments')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.summary.history_available', true)
            ->assertJsonCount(4, 'data.payment_history');

        $this->getJson('/api/skkm')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.found', true)
            ->assertJsonCount(3, 'data.entries');

        $this->getJson('/api/library')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.found', true);
    }

    /**
     * Post the login page's demo form, with its CSRF token.
     */
    private function startDemoSession(): TestResponse
    {
        return $this->withSession(['_token' => self::CSRF_TOKEN])->post('/__demo/session', ['_token' => self::CSRF_TOKEN]);
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
