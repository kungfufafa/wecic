<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PortalOperationStage;
use App\Events\PortalSessionNoticeSent;
use App\Http\Middleware\PortalSessionMiddleware;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Process;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class FrontendShellTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_layout_names_the_device_cache_owner_per_student_without_the_nim(): void
    {
        $owner = app(PortalDataOwner::class);
        $owner->remember('token-a-first', '12345678', 600);
        $owner->remember('token-a-again', '12345678', 600);
        $owner->remember('token-b', '87654321', 600);
        $this->mockValidCampusSessions();

        $studentA = $this->cacheOwnerFor('token-a-first');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $studentA);
        $this->assertStringNotContainsString('12345678', $studentA);
        $this->assertSame($studentA, $this->cacheOwnerFor('token-a-again'), 'A re-login keeps the device copies of the same student.');
        $this->assertNotSame($studentA, $this->cacheOwnerFor('token-b'), 'Another student never reads them.');
    }

    public function test_the_notebook_review_page_is_a_signed_in_page_with_its_own_page_id(): void
    {
        $route = app('router')->getRoutes()->getByName('notebook.review');

        $this->assertNotNull($route);
        $this->assertSame('notebook/ulasan', $route->uri());
        $this->assertContains('portal.web', $route->gatherMiddleware());

        $this->get('/notebook/ulasan')->assertRedirect();

        $this->mockValidCampusSessions();
        $this->withSession(['campus_session_token' => 'token-review-page'])
            ->get('/notebook/ulasan')
            ->assertOk()
            ->assertSee('data-page="notebook-review"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('Ulasan harian');
    }

    public function test_session_without_a_known_owner_still_gets_its_own_cache_owner(): void
    {
        $this->mockValidCampusSessions();

        $unknown = $this->cacheOwnerFor('token-unmapped');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $unknown);
        $this->assertNotSame($unknown, $this->cacheOwnerFor('token-other-unmapped'));
    }

    public function test_bundle_isolates_the_device_cache_before_page_scripts_run(): void
    {
        $script = (string) file_get_contents(resource_path('js/app.js'));

        $construct = strpos($script, 'window.portalApp = new PortalApp();');
        $isolate = strpos($script, 'window.portalApp.isolateDeviceCache();');
        $init = strrpos($script, "document.addEventListener('DOMContentLoaded'");

        $this->assertNotFalse($construct);
        $this->assertNotFalse($isolate);
        $this->assertNotFalse($init);
        $this->assertTrue($construct < $isolate && $isolate < $init, 'The owner check must run when the bundle loads, not on DOMContentLoaded.');
    }

    public function test_first_page_after_a_login_keeps_its_offline_copy_while_unknown_owners_are_still_cleared(): void
    {
        $script = $this->appScript();

        $this->assertSame(1, preg_match("/this\.clearedCacheOwner = '([^']+)';/", $script, $sentinel));
        $this->assertDoesNotMatchRegularExpression('/^[0-9a-f]{32}$/', $sentinel[1], 'A real owner digest must never read as a cleared device.');
        $this->assertStringNotContainsString('localStorage.removeItem(this.cacheOwnerKey)', $script, 'A missing owner means copies of anyone, so a cleared device is marked instead.');

        foreach (['forgetEndedSession', 'handleLoginSuccess'] as $method) {
            $this->assertMatchesRegularExpression(
                '/this\.clear(OfflineData|ProtectedPageCache)\(\);.*this\.markDeviceCacheCleared\(\);/s',
                $this->scriptMethod($script, $method),
                "{$method} marks the device cleared only after dropping the earlier copies."
            );
        }

        $this->assertMatchesRegularExpression(
            '/const claim = cacheRules\.deviceCacheClaim\(owner, localStorage\.getItem\(this\.cacheOwnerKey\), this\.clearedCacheOwner\);\s*if \(claim\.clear\) \{\s*this\.clearOfflineData\(\);\s*\}\s*if \(claim\.store\) \{\s*localStorage\.setItem\(this\.cacheOwnerKey, owner\);/',
            $this->scriptMethod($script, 'isolateDeviceCache'),
            'The first signed-in page claims a cleared device without wiping its own copy; any other stored owner, or none, is cleared (decided in lib/cache.js, covered by tests/js/cache.test.mjs).'
        );
    }

    public function test_login_page_forgets_the_ended_session_and_has_no_cache_owner(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('window.portalApp?.forgetEndedSession()', false);

        $this->assertNull((new Crawler((string) $response->getContent()))->filter('body')->attr('data-cache-owner'));
    }

    /**
     * app.js joins `portal.session.{html[data-session-id]}` (tests/js/portal-app-realtime.test.mjs),
     * so a logout in one tab reaches the others only while the layout names the
     * same session the server broadcasts to.
     */
    public function test_signed_in_layout_names_the_channel_session_notices_are_broadcast_on(): void
    {
        $guestPage = new Crawler((string) $this->get('/login')->assertOk()->getContent());

        $this->assertNull($guestPage->filter('html')->attr('data-session-id'), 'A guest page opens no realtime channel.');

        $this->mockValidCampusSessions();

        $response = $this->withSession(['campus_session_token' => 'token-realtime'])->get('/dashboard');

        $response->assertOk();

        $sessionId = (string) (new Crawler((string) $response->getContent()))->filter('html')->attr('data-session-id');

        $this->assertNotSame('', $sessionId);
        $this->assertSame(session()->getId(), $sessionId);
        $this->assertSame('private-portal.session.'.$sessionId, (new PortalSessionNoticeSent($sessionId, []))->broadcastOn()->name);
    }

    public function test_login_form_reports_errors_inline_and_never_submits_as_get(): void
    {
        $crawler = new Crawler((string) $this->get('/login')->assertOk()->getContent());

        $this->assertSame('post', $crawler->filter('#login-form')->attr('method'));
        $this->assertSame('alert', $crawler->filter('#login-error')->attr('role'));
        $this->assertSame('', trim($crawler->filter('#login-error')->text('')));
        $this->assertSame('login-error', $crawler->filter('#username')->attr('aria-describedby'));
        $this->assertSame('login-error', $crawler->filter('#password')->attr('aria-describedby'));
    }

    public function test_login_script_handles_a_missing_bundle_and_an_expired_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('const appReady = new Promise', false)
            ->assertSee('Aplikasi belum termuat sepenuhnya. Muat ulang halaman lalu coba lagi.')
            ->assertSee('if (error.status === 419) {', false)
            ->assertSee('Halaman dimuat ulang karena sudah kedaluwarsa. Masukkan password lagi.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function guestPages(): array
    {
        return [
            'login' => ['/login'],
            'not found' => ['/halaman-yang-tidak-ada'],
        ];
    }

    #[DataProvider('guestPages')]
    public function test_guest_pages_announce_toasts_through_live_regions(string $path): void
    {
        $crawler = new Crawler((string) $this->get($path)->getContent());

        $this->assertSame('guest', $crawler->filter('body')->attr('data-auth'));
        $this->assertSame('polite', $crawler->filter('[data-live-polite]')->attr('aria-live'));
        $this->assertSame('status', $crawler->filter('[data-live-polite]')->attr('role'));
        $this->assertSame('assertive', $crawler->filter('[data-live-assertive]')->attr('aria-live'));
    }

    public function test_qr_scanner_is_a_labelled_modal_dialog(): void
    {
        $this->mockValidCampusSessions();

        $crawler = new Crawler((string) $this->withSession(['campus_session_token' => 'token-shell'])
            ->get('/dashboard')
            ->assertOk()
            ->getContent());
        $panel = $crawler->filter('[data-qr-scanner-modal] [data-qr-scanner-panel]');

        $this->assertSame('dialog', $panel->attr('role'));
        $this->assertSame('true', $panel->attr('aria-modal'));
        $this->assertSame('qr-scanner-title', $panel->attr('aria-labelledby'));
        $this->assertSame('Scan kode QR', trim($crawler->filter('#qr-scanner-title')->text()));
        $this->assertCount(1, $crawler->filter('[data-qr-scanner-modal] [data-qr-scanner-backdrop]'));
    }

    public function test_service_worker_keeps_every_signed_in_route_in_the_cache_logout_clears(): void
    {
        $protected = $this->serviceWorkerList('PROTECTED_PAGE_PREFIXES');
        $signedInPrefixes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true)
                && in_array('portal.web', $route->gatherMiddleware(), true))
            ->map(fn (Route $route): string => '/'.explode('/', trim($route->uri(), '/'))[0])
            ->unique()
            ->values();

        $this->assertContains('/archive', $signedInPrefixes->all());
        $this->assertContains('/notebook', $signedInPrefixes->all());

        foreach ($signedInPrefixes as $prefix) {
            $this->assertContains($prefix, $protected, "{$prefix} pages would be cached where logout does not clear them.");
        }
    }

    public function test_service_worker_general_cache_holds_guest_pages_only_and_keeps_them_when_the_session_ends(): void
    {
        $serviceWorker = (string) file_get_contents(public_path('sw.js'));

        $this->assertSame(['/login'], $this->serviceWorkerList('GUEST_PAGES'));
        $this->assertStringContainsString('GUEST_PAGES.includes(url.pathname)', $serviceWorker);
        $this->assertStringNotContainsString("const CACHE_NAME = 'mycic-v4';", $serviceWorker, 'v4 may still hold signed-in pages and must be dropped on activate.');
        $this->assertMatchesRegularExpression(
            "/CLEAR_AUTH_CACHES.*caches\.delete\(PROTECTED_PAGE_CACHE_NAME\).*caches\.open\(CACHE_NAME\).*const kept = \[\.\.\.SHELL_ASSETS, \.\.\.GUEST_PAGES\];.*!kept\.includes\(new URL\(request\.url\)\.pathname\)/s",
            $serviceWorker,
            'The login page clears the session caches as it loads; its own guest copy must survive that.'
        );
    }

    public function test_service_worker_never_caches_a_url_carrying_credentials(): void
    {
        $serviceWorker = (string) file_get_contents(public_path('sw.js'));

        $this->assertSame(1, preg_match('/function carriesCredentials\(url\) \{\s*return \[\.\.\.url\.searchParams\.keys\(\)\]\.some\(\(name\) => (\/[^\/]+\/i)\.test\(name\)\);/', $serviceWorker, $guard));
        $this->assertMatchesRegularExpression("/networkResponse\.ok && !finalUrl\.pathname\.startsWith\('\/login'\) && !carriesCredentials\(url\)\) \{\s*cache\.put/", $serviceWorker, 'Signed-in pages');
        $this->assertMatchesRegularExpression('/GUEST_PAGES\.includes\(url\.pathname\) &&\s*!carriesCredentials\(url\)/', $serviceWorker, 'Guest pages');

        $passwordFields = collect(glob(resource_path('views/{,*/,*/*/}*.blade.php'), GLOB_BRACE) ?: [])
            ->flatMap(function (string $view): array {
                preg_match_all('/<input\b[^>]*\btype="password"[^>]*>/s', (string) file_get_contents($view), $inputs);

                return array_map(fn (string $input): string => preg_match('/\bname="([^"]+)"/', $input, $name) ? $name[1] : '', $inputs[0]);
            })
            ->unique()
            ->values();

        $this->assertContains('password', $passwordFields->all());
        $this->assertContains('new_password', $passwordFields->all());

        foreach ($passwordFields as $field) {
            $this->assertMatchesRegularExpression($guard[1], $field, "A URL carrying {$field} would become a cache key.");
        }

        $this->assertDoesNotMatchRegularExpression($guard[1], 'class_id', 'Meeting and class pages keep their offline copy.');
    }

    public function test_login_page_forgets_the_typed_password_when_restored_from_history(): void
    {
        $content = (string) $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            "/window\.addEventListener\('pageshow', \(event\) => \{\s*if \(event\.persisted\) \{\s*passwordInput\.value = '';\s*syncPasswordToggle\(false\);/",
            $content,
            'Back from the dashboard on a shared device must not bring the last password back.'
        );
        $this->assertMatchesRegularExpression("/syncPasswordToggle\(false\);\s*window\.location\.href = '\/dashboard';/", $content);
    }

    public function test_offline_page_reloads_only_once_the_server_answers_a_probe(): void
    {
        $page = (string) file_get_contents(public_path('offline.html'));
        $unreachable = $this->offlinePageFunction($page, 'showServerUnreachable');
        $probe = $this->offlinePageFunction($page, 'probeServer');

        $this->assertMatchesRegularExpression('/if \(navigator\.onLine\) \{\s*showServerUnreachable\(\);\s*\} else \{\s*showDeviceOffline\(\);/', $page, 'Served while the device is online means the server did not answer.');
        $this->assertStringContainsString("label: 'Mencoba menyambung lagi…',", $unreachable);
        $this->assertStringContainsString('busy: false,', $unreachable, 'The retry button stays usable.');
        $this->assertStringContainsString('scheduleProbe();', $unreachable);
        $this->assertStringNotContainsString('reload', $unreachable, 'A failed page load never reloads on a timer.');
        $this->assertStringContainsString('return Math.min(firstRetryMs * (2 ** count), maxRetryMs);', $this->offlinePageFunction($page, 'retryDelay'));
        $this->assertStringContainsString('const firstRetryMs = 2000;', $page);
        $this->assertStringContainsString('const maxRetryMs = 60000;', $page);
        $this->assertMatchesRegularExpression('/if \(answered\) \{.*?reloadPage\(\);/s', $probe);
        $this->assertStringContainsString('answered = response.ok;', $probe);
        $this->assertSame(1, substr_count($page, 'window.location.reload()'), 'Only reloadPage reloads.');
        $this->assertStringContainsString("window.addEventListener('online', showReconnected);", $page);
        $this->assertStringContainsString("retryButton.addEventListener('click', reloadPage);", $page);

        $this->assertStringContainsString("const probeUrl = '/up';", $page);
        $this->get('/up')->assertOk();

        $this->assertStringContainsString("const attemptStorageKey = 'mycic-offline-retry';", $page);
        $this->assertStringContainsString("this.offlineRetryKey = 'mycic-offline-retry';", $this->appScript());
        $this->assertStringContainsString('this.forgetOfflineRetries();', $this->scriptMethod($this->appScript(), 'init'));
    }

    public function test_dark_mode_prints_with_the_light_tokens(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertSame(1, preg_match('/@theme \{(.*?)\n\}/s', $css, $theme));
        $this->assertSame(1, preg_match("/\nhtml\[data-theme='dark'\] \{(.*?)\n\}/s", $css, $dark, PREG_OFFSET_CAPTURE));
        $this->assertSame(1, preg_match("/\n@media print \{\s*html\[data-theme='dark'\] \{(.*?)\n    \}\n\}/s", $css, $print, PREG_OFFSET_CAPTURE));
        $this->assertGreaterThan($dark[0][1], $print[0][1], 'The print tokens must come after the dark ones to win.');
        $this->assertStringContainsString('color-scheme: light;', $print[1][0]);

        $tokens = function (string $block): array {
            preg_match_all('/(--color-[a-z-]+):\s*([^;]+);/', $block, $matches);

            return array_combine($matches[1], $matches[2]);
        };
        $light = $tokens($theme[1]);
        $printed = $tokens($print[1][0]);

        foreach (array_keys($tokens($dark[1][0])) as $token) {
            $this->assertArrayHasKey($token, $printed, "{$token} would print with its dark value.");
            $this->assertSame($token === '--color-canvas' ? '#ffffff' : $light[$token], $printed[$token], $token);
        }
    }

    public function test_printed_pages_take_their_light_colours_from_app_css_alone(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            "/\n@custom-variant dark \{\s*@media screen \{\s*&:where\(\[data-theme='dark'\], \[data-theme='dark'\] \*\) \{\s*@slot;\s*\}\s*\}\s*\}/",
            $css,
            'A dark: utility (light text meant for a dark surface) would print on white paper.'
        );

        foreach (['app', 'guest'] as $layout) {
            $this->assertStringContainsString("@vite(['resources/css/app.css'", (string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php")), $layout);
        }

        $views = array_merge(glob(resource_path('views/*.blade.php')) ?: [], glob(resource_path('views/*/*.blade.php')) ?: []);

        $this->assertNotEmpty($views);

        foreach ($views as $path) {
            $this->assertDoesNotMatchRegularExpression('/--color-[a-z-]+\s*:/', (string) file_get_contents($path), basename(dirname($path)).'/'.basename($path).' carries its own copy of the theme tokens; app.css owns them.');
        }
    }

    public function test_failed_session_check_still_loads_the_page_and_only_an_ended_session_stops_it(): void
    {
        $check = $this->scriptMethod($this->appScript(), 'performAuthenticationCheck');

        $this->assertMatchesRegularExpression('/if \(error\.status === 401 \|\| error\.status === 419\) \{.*?return null;\s*\}/s', $check);
        $this->assertStringContainsString("return authSnapshot || { status: 'unverified', data: null };", $check, 'A timeout, 5xx or proxy error must not leave the page unloaded.');
    }

    public function test_any_answer_brings_the_shell_back_online(): void
    {
        $this->assertMatchesRegularExpression(
            '/const response = await fetch\(requestUrl\.toString\(\), config\);.*?if \(this\.onlineState === false && navigator\.onLine\) \{\s*this\.updateConnectionState\(true, \{ announce: false \}\);/s',
            $this->scriptMethod($this->appScript(), 'performRequest')
        );
    }

    public function test_pages_restored_from_history_are_checked_again(): void
    {
        $script = $this->appScript();
        $restore = $this->scriptMethod($script, 'bindHistoryRestore');

        $this->assertStringContainsString('this.bindHistoryRestore();', $this->scriptMethod($script, 'init'));
        $this->assertStringContainsString("window.addEventListener('pageshow', (event) => {", $restore);
        $this->assertMatchesRegularExpression('/if \(sessionEnded\) \{\s*document\.body\.hidden = true;\s*window\.location\.reload\(\);/', $restore);
        $this->assertStringContainsString('void this.ensureAuthenticated({ force: true });', $restore);
    }

    public function test_quick_scans_use_the_page_binding_and_explicit_options_win(): void
    {
        $script = $this->appScript();
        $open = $this->scriptMethod($script, 'openQrScanner');

        $this->assertStringContainsString('this.qrScannerOptions.set(input, options);', $this->scriptMethod($script, 'bindAttendanceQrTools'));
        $this->assertStringContainsString('const scanOptions = { ...this.qrScannerOptionsFor(input), ...options };', $open);
        $this->assertStringContainsString('scanOptions.onDetected?.(input.value, {', $open, 'Pages resolve the live form from the scanned code.');
        $this->assertStringContainsString('options.onDetected?.(input.value, {', $this->scriptMethod($script, 'decodeQrFromFile'));
        $this->assertStringNotContainsString('openQrScannerFromUrl', $script, 'The meeting page handles ?qr=1 itself.');
        $this->assertStringContainsString('this.qrFromUrlHandled = false;', $script);
    }

    public function test_camera_taps_while_it_opens_are_answered_and_a_stuck_request_can_be_retried(): void
    {
        $script = $this->appScript();
        $open = $this->scriptMethod($script, 'openQrScanner');

        $this->assertMatchesRegularExpression(
            "/if \(this\.qrScannerState\.opening && Date\.now\(\) - this\.qrScannerState\.openingSince < this\.qrScannerOpeningLimitMs\) \{\s*this\.showNotice\('Kamera sedang dibuka\. Izinkan akses kamera bila diminta\.', 'info'\);\s*return;/",
            $open
        );
        $this->assertStringContainsString('this.qrScannerState.openingSince = Date.now();', $open);
        $this->assertMatchesRegularExpression('/if \(attempt !== this\.qrScannerState\.attempt\) \{\s*stream\.getTracks\(\)\.forEach\(\(track\) => track\.stop\(\)\);/', $open, 'A stale camera stream is released.');
        $this->assertStringContainsString('this.qrScannerState.attempt += 1;', $this->scriptMethod($script, 'closeQrScanner'));
        $this->assertMatchesRegularExpression('/this\.qrScannerOpeningLimitMs = \d{4,5};/', $script);
    }

    public function test_own_data_and_unknown_pages_never_fall_back_to_a_dashboard_scrape(): void
    {
        $domains = $this->scriptMethod($this->appScript(), 'refreshDomainsForCurrentPage');

        $this->assertStringContainsString('return this.filterRefreshableDomains(pageDomains[page] ?? []);', $domains);
        $this->assertStringNotContainsString("['dashboard'])", $domains);

        foreach (['notification-index', 'archive-index', 'archive-show', 'notebook-index', 'notebook-show', 'notebook-review'] as $page) {
            $this->assertStringContainsString("'{$page}': [],", $domains);
        }

        $this->assertStringContainsString('password: [],', $domains);
    }

    public function test_download_links_are_held_back_offline_and_stay_in_the_installed_app(): void
    {
        $script = $this->appScript();
        $downloads = $this->scriptMethod($script, 'bindDownloadLinks');
        $installed = $this->scriptMethod($script, 'isInstalledApp');

        $this->assertStringContainsString('this.bindDownloadLinks();', $this->scriptMethod($script, 'init'));
        $this->assertMatchesRegularExpression('/if \(this\.isPortalOfflineSession\(\) \|\| !navigator\.onLine\) \{\s*event\.preventDefault\(\);/', $downloads);
        $this->assertMatchesRegularExpression(
            "/if \(!link\.target && !link\.hasAttribute\('download'\) && !this\.isInstalledApp\(\)\) \{\s*link\.target = '_blank';/",
            $downloads,
            'On iOS a new tab from the installed app has no session cookie.'
        );
        $this->assertStringContainsString('window.navigator.standalone === true', $installed);
        $this->assertStringContainsString("window.matchMedia?.('(display-mode: standalone)').matches", $installed);
    }

    public function test_http_errors_read_in_indonesian_with_the_retry_after_wait(): void
    {
        $request = $this->scriptMethod($this->appScript(), 'performRequest');
        $http = $this->libModule('http');
        $errors = $this->libFunction($http, 'httpErrorMessage');

        $this->assertMatchesRegularExpression(
            '/const outcome = httpRules\.responseOutcome\(response, payload, \{\s*unreadable,\s*redirectOnAuth: options\.redirectOnAuth,\s*authMode: document\.body\.dataset\.auth,\s*\}\);/',
            $request
        );
        $this->assertMatchesRegularExpression("/if \(outcome\.action === 'login'\) \{\s*this\.sendToLogin\(outcome\.message, outcome\.tone\);\s*return null;\s*\}\s*if \(outcome\.action === 'fail'\) \{\s*throw outcome\.error;/", $request);
        $this->assertStringContainsString(': httpErrorMessage(response, payload);', $this->libFunction($http, 'responseError'));
        $this->assertStringContainsString('Too Many Attempts\.|Server Error|Service Unavailable|CSRF token mismatch\.', $http);
        $this->assertMatchesRegularExpression("/if \(response\.status === 429\) \{\s*const seconds = Number\.parseInt\(response\.headers\.get\('retry-after'\)/", $errors);
        $this->assertStringContainsString('Terlalu sering mencoba. Coba lagi dalam ${Math.ceil(seconds / 60)} menit.', $errors);
        $this->assertStringContainsString('Terlalu sering mencoba. Coba lagi dalam ${seconds} detik.', $errors);
        $this->assertStringContainsString('Server sedang bermasalah. Coba lagi sebentar lagi.', $errors);
    }

    public function test_an_ended_session_while_following_an_upload_goes_to_login(): void
    {
        $poll = $this->scriptMethod($this->appScript(), 'pollOperation');

        $this->assertMatchesRegularExpression("/if \(error\.status === 401\) \{\s*this\.finishOperation\(operationId, 'unknown'\);\s*this\.sendToLogin\(`Sesi kampus berakhir sebelum status/", $poll);
        $this->assertMatchesRegularExpression("/if \(error\.status === 404\) \{\s*this\.finishOperation\(operationId, 'unknown'\);\s*this\.showNotice\(.*?, 'warning'\);/", $poll);
    }

    public function test_realtime_connection_state_drives_the_indicator_and_catches_up_after_a_gap(): void
    {
        $script = $this->appScript();
        $watch = $this->scriptMethod($script, 'watchRealtimeConnection');

        $this->assertStringContainsString('this.watchRealtimeConnection(this.echo);', $this->scriptMethod($script, 'initRealtime'));
        $this->assertStringContainsString("connection.bind('state_change'", $watch);
        $this->assertMatchesRegularExpression("/if \(current === 'unavailable' \|\| current === 'failed'\) \{\s*this\.setRealtimeStatus\('Pembaruan otomatis tidak tersedia\.', 'warning'\);/", $watch);
        $this->assertStringContainsString("this.setRealtimeStatus('Menyambung ulang pembaruan otomatis...', 'warning');", $watch);
        $this->assertStringContainsString('void this.catchUpAfterRealtimeGap();', $watch);
        $this->assertMatchesRegularExpression('/if \(this\.hasUnsentFormInput\(\)\) \{\s*return;\s*\}\s*await this\.runSyncHandlers\(/', $this->scriptMethod($script, 'catchUpAfterRealtimeGap'), 'A re-render must not wipe a form being filled in.');
        $this->assertStringNotContainsString('Data dicek berkala', $script);
    }

    public function test_logout_leaves_only_once_the_session_is_gone(): void
    {
        $logout = $this->scriptMethod($this->appScript(), 'bindLogout');

        $this->assertMatchesRegularExpression("/if \(error\.status === 401 \|\| error\.status === 419\) \{\s*this\.storeNotice\('Kamu sudah keluar dari akun\.', 'success'\);\s*loggedOut = true;/", $logout);
        $this->assertMatchesRegularExpression("/this\.showNotice\(`Belum berhasil keluar\. .*?`, 'error'\);/", $logout);
        $this->assertMatchesRegularExpression(
            "/if \(!loggedOut\) \{\s*this\.isLoggingOut = false;.*?return;\s*\}\s*this\.disconnectRealtime\(\);\s*this\.clearOfflineData\(\);\s*window\.location\.href = '\/login';/s",
            $logout,
            'A 429, 5xx, timeout or offline logout keeps the student signed in and says so.'
        );
    }

    public function test_requests_the_app_makes_on_its_own_never_count_as_activity(): void
    {
        $script = $this->appScript();
        $request = $this->scriptMethod($script, 'performRequest');
        $realtime = $this->scriptMethod($script, 'handleRealtimePayload');
        $scope = $this->scriptMethod($script, 'runAsBackground');

        $this->assertStringContainsString("this.backgroundRequestHeader = '".PortalSessionMiddleware::BACKGROUND_HEADER."';", $script);
        $this->assertStringContainsString("const background = method === 'GET' && options.background === true;", $request, 'A POST is always the student\'s own action.');
        $this->assertStringContainsString("...(background ? { [this.backgroundRequestHeader]: '1' } : {}),", $request);
        $this->assertStringContainsString('const requestOptions = this.backgroundWork > 0 ? { ...options, background: true } : options;', $this->scriptMethod($script, 'request'), 'Decided as the read starts.');
        $this->assertMatchesRegularExpression('/this\.backgroundWork \+= 1;\s*try \{\s*return task\(\{ background: true \}\);\s*\} finally \{\s*this\.backgroundWork -= 1;/', $scope);
        $this->assertStringNotContainsString('await', $scope, 'The scope ends when the task first waits, so a read the student starts meanwhile stays theirs.');
        $this->assertStringContainsString('this.runAsBackground(() => handler({ force, background }))', $this->scriptMethod($script, 'runSyncHandlers'));
        $this->assertStringContainsString('this.performAuthenticationCheck({ background })', $this->scriptMethod($script, 'ensureAuthenticated'));
        $this->assertStringContainsString("this.request('/api/auth/session', { background })", $this->scriptMethod($script, 'performAuthenticationCheck'));

        $this->assertStringContainsString('this.loadActionItems({ force: true, background: true });', $realtime);
        $this->assertStringContainsString('this.runAsBackground(() => handler({ force: false, background: true }));', $realtime);
        $this->assertStringContainsString('background: true', $this->scriptMethod($script, 'loadNotificationCount'));
        $this->assertStringContainsString('background: true', $this->scriptMethod($script, 'pollOperation'));
        $this->assertSame(2, substr_count($this->scriptMethod($script, 'scheduleActionItemBadges'), 'this.loadActionItems({ background: true });'));

        foreach (['updateConnectionState', 'catchUpAfterRealtimeGap', 'awaitBackgroundRefresh'] as $method) {
            $this->assertMatchesRegularExpression('/this\.runSyncHandlers\(\{ (?:force: false, )?background: true \}\)/', $this->scriptMethod($script, $method), $method);
        }

        foreach (['queueBackgroundRefresh', 'bindPullToRefresh', 'bindLogout'] as $method) {
            $this->assertStringNotContainsString('background: true', $this->scriptMethod($script, $method), "{$method} is the student's own request.");
        }
    }

    /**
     * Every re-read a page starts on its own (a timer, the tab shown again) is
     * marked as background work, call by call. Typing debounces run a search
     * the student typed; whenIdle only defers the first load's secondary card.
     */
    public function test_pages_mark_the_re_reads_their_timers_and_tab_switches_start(): void
    {
        $studentTimers = [
            'library/theses.blade.php' => ['debounceTimer'],
            'announcements/index.blade.php' => ['searchTimer'],
        ];
        $deferredCallbacks = ['dashboard.blade.php' => ['callback']];
        $checked = 0;
        $reads = 0;

        foreach ($this->pageScripts() as $view => $source) {
            $bodies = [];

            $this->assertDoesNotMatchRegularExpression('/(?<![.\w])set(?:Timeout|Interval)\(/', $source, "{$view}: timers go through window.* so they are checked here.");

            preg_match_all('/\n( +)document\.addEventListener\(\'visibilitychange\', (?:async )?\(\) => \{\n(.*?)\n\1\}\);/s', $source, $handlers);
            $this->assertSame(substr_count($source, "addEventListener('visibilitychange'"), count($handlers[2]), "{$view}: every visibilitychange handler is checked.");
            array_push($bodies, ...$handlers[2]);

            preg_match_all('/window\.setInterval\((\w+),/', $source, $intervals);
            $this->assertSame(substr_count($source, 'window.setInterval('), count($intervals[1]), "{$view}: every window.setInterval callback is a named function, checked here.");

            foreach ($intervals[1] as $callback) {
                $bodies[] = $this->viewFunction($source, $callback, $view)['body'];
            }

            $timers = substr_count($source, 'window.setTimeout(');
            preg_match_all('/\n( +)([^\n]*?)window\.setTimeout\((?:async )?\(\) => \{\n(.*?)\n\1\}, [^\n]+/s', $source, $blocks, PREG_SET_ORDER);
            preg_match_all('/window\.setTimeout\((?:async )?\(\) => (?!\{)([^\n]+), [^,\n]+\);\n/', $source, $arrows);
            preg_match_all('/window\.setTimeout\(([A-Za-z_]\w*), /', $source, $named);

            $this->assertSame($timers, count($blocks) + count($arrows[1]) + count($named[1]), "{$view}: every window.setTimeout callback is checked.");

            foreach ($blocks as [, , $target, $body]) {
                $timer = preg_match('/(\w+) = $/', $target, $assigned) === 1 ? $assigned[1] : null;

                if ($timer !== null && in_array($timer, $studentTimers[$view] ?? [], true)) {
                    $this->assertStringContainsString("window.clearTimeout({$timer});\n", $source, "{$view}: {$timer} is a typing debounce.");

                    continue;
                }

                $bodies[] = $body;
            }

            array_push($bodies, ...$arrows[1]);

            foreach ($named[1] as $callback) {
                if (in_array($callback, $deferredCallbacks[$view] ?? [], true)) {
                    continue;
                }

                $bodies[] = $this->viewFunction($source, $callback, $view)['body'];
            }

            foreach ($bodies as $body) {
                $checked += 1;

                foreach ($this->readCalls($body, '/\b(?:app\.runAsBackground|app\.request|app\.loadActionItems|app\.ensureAuthenticated|(?:load|fetch)[A-Z]\w*|load|run)\(/') as $call) {
                    $reads += 1;
                    $this->assertMatchesRegularExpression(
                        '/^app\.runAsBackground\(|\bbackground: true\b/',
                        $call,
                        "{$view}: `{$call}` is a re-read a timer or a tab switch starts, so it must not count as the student's activity."
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(13, $checked);
        $this->assertGreaterThanOrEqual(7, $reads);
    }

    /**
     * A page's sync handler runs for the student's pull-to-refresh and for
     * the app's own re-reads (realtime, a reconnect): it takes `background`
     * and passes it on to every read it makes, including the ones after its
     * first `await` that runAsBackground() no longer covers. Retry buttons
     * and other handlers inside it are the student's own. The notebook page
     * is checked by running it (tests/js/notebook-background.test.mjs).
     */
    public function test_pages_pass_the_background_flag_from_their_sync_handlers_to_every_read(): void
    {
        $handlers = 0;

        foreach ($this->pageScripts() as $view => $source) {
            if (str_starts_with($view, 'notebook/')) {
                continue;
            }

            preg_match_all("/app\.registerSyncHandler\('([\w-]+)', (.+?)(?:, \{ aliases: \[[^\]]*\] \})?\);\n/", $source, $registrations, PREG_SET_ORDER);
            $this->assertSame(substr_count($source, 'app.registerSyncHandler('), count($registrations), "{$view}: every sync handler is a loader or a one-line arrow, checked here.");

            foreach ($registrations as [, $key, $handler]) {
                $handlers += 1;

                if (preg_match('/^\((\{[^)]*\}(?: = \{\})?)\) => (.+)$/', $handler, $inline) === 1) {
                    $function = ['params' => $inline[1], 'body' => $inline[2]];
                } else {
                    $function = $this->viewFunction($source, $handler, $view);
                }

                $this->assertThreadsBackground($source, $view, "{$key} handler", $function, []);
            }
        }

        $this->assertGreaterThanOrEqual(25, $handlers);
    }

    /**
     * @param  array{params: string, body: string}  $function
     * @param  list<string>  $visited
     */
    private function assertThreadsBackground(string $source, string $view, string $name, array $function, array $visited): void
    {
        $this->assertMatchesRegularExpression('/\bbackground = false\b/', $function['params'], "{$view}: {$name} takes the background flag.");

        $body = $this->withoutListeners($function['body']);

        foreach ($this->readCalls($body, '/(?<!=> )\b(?:app\.request|app\.loadActionItems|(?:load|fetch)[A-Z]\w*)\(/') as $call) {
            if (str_starts_with($call, 'app.request(') && preg_match("/\bmethod: '(?!GET')/", $call) === 1) {
                continue;
            }

            $this->assertMatchesRegularExpression('/\bbackground\b/', $call, "{$view}: {$name} passes the background flag on to `{$call}`.");

            $callee = strstr($call, '(', true);

            if (! str_starts_with($callee, 'app.') && ! in_array($callee, $visited, true)) {
                $this->assertThreadsBackground($source, $view, $callee, $this->viewFunction($source, $callee, $view), [...$visited, $name, $callee]);
            }
        }
    }

    /**
     * The inline scripts' source of every page view, keyed by its path under
     * resources/views, with the partials it includes in place (the Beranda's
     * script is split into resources/views/dashboard/scripts, other pages'
     * into <view>/scripts). Components hold no script and are left out.
     *
     * @return array<string, string>
     */
    private function pageScripts(): array
    {
        $views = [];

        foreach ([...(glob(resource_path('views/*.blade.php')) ?: []), ...(glob(resource_path('views/*/*.blade.php')) ?: [])] as $path) {
            $view = str_replace(resource_path('views/'), '', $path);

            if (! str_starts_with($view, 'components/')) {
                $views[$view] = $this->withIncludes((string) file_get_contents($path));
            }
        }

        $this->assertArrayHasKey('dashboard.blade.php', $views);
        $this->assertArrayHasKey('notebook/show.blade.php', $views);
        $this->assertStringContainsString("app.registerSyncHandler('dashboard-overview'", $views['dashboard.blade.php'], 'The Beranda is checked with its script parts.');

        return $views;
    }

    /**
     * `$source` with every `@include('a.b')` replaced by that view's source
     * the way Blade renders it: Blade comments left out, the included view's
     * leading whitespace trimmed, and the line break right after the
     * directive dropped. A split script then reads exactly as written before
     * the split.
     */
    private function withIncludes(string $source): string
    {
        return (string) preg_replace_callback(
            "/@include\('([\w.-]+)'\)\n?/",
            fn (array $include): string => ltrim($this->withIncludes((string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                (string) file_get_contents(resource_path('views/'.str_replace('.', '/', $include[1]).'.blade.php'))
            ))),
            $source
        );
    }

    /**
     * The parameters and body of `[const|let] name = [async] (params) => { … };`
     * in a view's script (the last one, after a `let` placeholder).
     *
     * @return array{params: string, body: string}
     */
    private function viewFunction(string $source, string $name, string $view): array
    {
        $count = preg_match_all('/\n( +)(?:const |let )?'.preg_quote($name, '/').' = (?:async )?\(([^)]*)\) => \{\n(.*?)\n\1\};/s', $source, $matches, PREG_SET_ORDER);

        $this->assertGreaterThan(0, $count, "{$view}: {$name}");

        return ['params' => end($matches)[2], 'body' => end($matches)[3]];
    }

    /**
     * Each call matching `$pattern` in `$code`, up to its closing parenthesis;
     * calls nested inside a runAsBackground() call are part of it.
     *
     * @return list<string>
     */
    private function readCalls(string $code, string $pattern): array
    {
        $calls = [];
        $offset = 0;

        while (preg_match($pattern, $code, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $depth = 0;
            $end = $start + strlen($match[0][0]) - 1;

            for ($index = $end; $index < strlen($code); $index++) {
                $depth += match ($code[$index]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    break;
                }
            }

            $call = substr($code, $start, $index - $start + 1);
            $calls[] = $call;
            $offset = str_starts_with($call, 'app.runAsBackground(') ? $index + 1 : $start + strlen($match[0][0]);
        }

        return $calls;
    }

    /**
     * `$code` without the event listeners it adds (a Coba lagi click is the
     * student's own).
     */
    private function withoutListeners(string $code): string
    {
        return (string) preg_replace('/\n( *)[^\n]*addEventListener\([^\n]*=> \{\n.*?\n\1\}\);/s', "\n", "\n".$code);
    }

    public function test_an_unverified_upload_warns_and_re_reads_the_page(): void
    {
        $script = $this->appScript();
        $upload = $this->scriptMethod($script, 'handleUploadProgress');
        $unverified = PortalOperationStage::Unverified->value;

        $this->assertStringContainsString("const isFinal = ['completed', 'failed', '{$unverified}'].includes(payload.status);", $upload);
        $this->assertMatchesRegularExpression(
            "/if \(payload\.status === '{$unverified}'\) \{.*?this\.showNotice\(payload\.next_step \? `\\\$\{message\} \\\$\{payload\.next_step\}` : message, 'warning'\);\s*this\.handleRealtimePayload\(\{ \.\.\.payload, urgency: payload\.urgency \|\| 'warning' \}\);\s*this\.finishOperation\(operationId, 'unknown'\);/s",
            $upload,
            'Shown as a warning, the page re-read through cache_keys/sync_keys, the wait settled as unknown.'
        );
        $this->assertStringContainsString("{$unverified}: 100,", $this->scriptMethod($script, 'percentForStage'));
        $this->assertStringContainsString("{ failed: 'danger', {$unverified}: 'warning' }[operation.status]", $this->scriptMethod($script, 'renderOperationStatus'));
    }

    public function test_shared_javascript_passes_its_node_tests(): void
    {
        $tests = glob(base_path('tests/js/*.test.mjs')) ?: [];
        $scripts = json_decode((string) file_get_contents(base_path('package.json')), true)['scripts'] ?? [];

        $this->assertNotEmpty($tests);
        $this->assertSame('node --test tests/js/*.test.mjs', $scripts['test:js'] ?? null, '`npm run test:js` runs the same files.');

        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js is not installed here; run `npm run test:js` where it is.');
        }

        $result = Process::path(base_path())->timeout(120)->run([$node, '--test', ...$tests]);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }

    private function cacheOwnerFor(string $token): string
    {
        $response = $this->withSession(['campus_session_token' => $token])->get('/dashboard');

        $response->assertOk();

        return (string) (new Crawler((string) $response->getContent()))->filter('body')->attr('data-cache-owner');
    }

    /**
     * The app's own script: the entry (resources/js/app.js) and the feature
     * modules it mixes into PortalApp (resources/js/app/*.js).
     */
    private function appScript(): string
    {
        $files = [resource_path('js/app.js'), ...glob(resource_path('js/app/*.js')) ?: []];

        return implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
    }

    /**
     * The body of a PortalApp method in resources/js/app.js.
     */
    private function scriptMethod(string $script, string $method): string
    {
        $this->assertSame(1, preg_match('/\n    (?:async )?'.$method.'\([^)]*\) \{\n(.*?)\n    \}\n/s', $script, $match), $method);

        return $match[1];
    }

    /**
     * The source of a helper module in resources/js/lib.
     */
    private function libModule(string $module): string
    {
        return (string) file_get_contents(resource_path("js/lib/{$module}.js"));
    }

    /**
     * The body of an exported function in a resources/js/lib module.
     */
    private function libFunction(string $source, string $function): string
    {
        $this->assertSame(1, preg_match('/\nexport function '.$function.'\([^)]*\) \{\n(.*?)\n\}\n/s', $source, $match), $function);

        return $match[1];
    }

    /**
     * The body of a top-level function in public/offline.html.
     */
    private function offlinePageFunction(string $page, string $function): string
    {
        $this->assertSame(1, preg_match('/\n        (?:async )?function '.$function.'\([^)]*\) \{\n(.*?)\n        \}\n/s', $page, $match), $function);

        return $match[1];
    }

    /**
     * @return list<string>
     */
    private function serviceWorkerList(string $constant): array
    {
        $serviceWorker = (string) file_get_contents(public_path('sw.js'));

        $this->assertSame(1, preg_match('/const '.$constant.' = \[(.*?)\];/s', $serviceWorker, $match), $constant);
        preg_match_all("/'([^']+)'/", $match[1], $entries);

        return $entries[1];
    }

    private function mockValidCampusSessions(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->andReturnTrue();
            })
        );
    }
}
