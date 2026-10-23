<?php

declare(strict_types=1);

use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalAuthService;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-test helpers for tests/e2e (Playwright).
 *
 * bootstrap/app.php loads this file in the e2e environment only (APP_ENV=e2e,
 * which reads .env.e2e); tests/Feature/E2eRouteGuardTest.php checks that the
 * local, testing, staging and production environments never have these
 * routes.
 *
 * Nothing here reaches the campus portal: the signed-in session comes from a
 * login stub, and every portal client of the e2e server refuses to send
 * (bootstrap/e2e.php, set while the application boots).
 */

/**
 * The student every fake session belongs to. Fixed, so a fake session can
 * never read a real student's data (owner keys derive from the username).
 */
$e2eUsername = 'e2e-mahasiswa';

/**
 * A session manager whose portal login and logout never leave the server:
 * the session it creates is an ordinary online session without portal
 * cookies, valid by the same local checks as a real one.
 */
$e2eSessions = static function () use ($e2eUsername): PortalSessionManager {
    $loginStub = new class($e2eUsername) extends PortalAuthService
    {
        public function __construct(private readonly string $e2eUsername)
        {
            parent::__construct();
        }

        /**
         * @return array{success: true, user_info: array{name: string, nim: string}, cookies: array<int, array<string, mixed>>, session_data: array{login_time: string, last_activity: string, user_agent: string}}
         */
        public function login(string $username, string $password): array
        {
            return [
                'success' => true,
                'user_info' => ['name' => 'Mahasiswa E2E', 'nim' => $this->e2eUsername],
                'cookies' => [],
                'session_data' => [
                    'login_time' => now()->toISOString(),
                    'last_activity' => now()->toISOString(),
                    'user_agent' => 'MyCIC e2e',
                ],
            ];
        }

        public function logout(CookieJar $cookieJar): void {}
    };

    return new PortalSessionManager($loginStub, app('encrypter'), app(PortalDataOwner::class));
};

/**
 * End a fake session and leave nothing of it behind: the campus session, and
 * the unique lock its keep-alive job took when it was (not) dispatched.
 */
$endE2eSession = static function (string $token) use ($e2eSessions): void {
    $e2eSessions()->destroySession($token);
    (new UniqueLock(app(CacheRepository::class)))->release(new RefreshPortalSessionJob($token));
};

/**
 * Every route answers 404 outside the e2e environment and to anyone but this
 * machine, even if this file were loaded by mistake (a cached route list).
 */
$e2eOnly = static function (Request $request): void {
    abort_unless(app()->environment('e2e') && in_array($request->ip(), ['127.0.0.1', '::1'], true), 404);
};

/**
 * The specs call these routes outside a page (Playwright's request context,
 * which shares the browser's cookies), so they carry no CSRF token: every
 * CSRF middleware (VerifyCsrfToken and its subclasses) is left out.
 */
Route::prefix('__e2e')
    ->name('e2e.')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->group(static function () use ($e2eOnly, $e2eSessions, $e2eUsername, $endE2eSession): void {
        /**
         * Sign this browser in as the e2e student, like a successful login
         * (PortalAuthController::login) without the portal and without the
         * background sync a login starts. A session this browser already had
         * ends first, and the app session it signed in from is not kept. No
         * job leaves the request (Bus::fake()): the keep-alive createSession()
         * schedules would ping the portal.
         */
        Route::post('/session', static function (Request $request) use ($e2eOnly, $e2eSessions, $e2eUsername, $endE2eSession): JsonResponse {
            $e2eOnly($request);
            Bus::fake();

            $sessions = $e2eSessions();
            $previousToken = $request->session()->get('campus_session_token');

            if (is_string($previousToken) && $previousToken !== '') {
                $endE2eSession($previousToken);
            }

            $result = $sessions->createSession($e2eUsername, 'e2e');

            $request->session()->regenerate(destroy: true);
            $request->session()->put([
                'campus_session_token' => $result['session_token'],
                'campus_user_info' => $result['user_info'],
                'campus_offline' => false,
                'campus_offline_reconnect' => null,
            ]);
            $sessions->rememberLaravelSessionId($result['session_token'], $request->session()->getId());

            return response()->json([
                'session_id' => $request->session()->getId(),
                'user_info' => $result['user_info'],
            ], 201);
        })->name('session.start');

        /**
         * End the e2e session like a logout (PortalAuthController::logout)
         * without the portal and the broadcast: the campus session is gone
         * and this browser's app session is invalidated. Safe to call when
         * nothing is signed in.
         */
        Route::delete('/session', static function (Request $request) use ($e2eOnly, $endE2eSession): Response {
            $e2eOnly($request);

            $token = $request->session()->get('campus_session_token');

            if (is_string($token) && $token !== '') {
                $endE2eSession($token);
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->noContent();
        })->name('session.end');
    });
