<?php

declare(strict_types=1);

use App\Jobs\RefreshPortalSessionJob;
use App\Services\PortalAuthService;
use App\Services\PortalDataOwner;
use App\Services\PortalDemoFixtures;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;

/**
 * The demo sign-in of a local checkout without a CIC account.
 *
 * bootstrap/app.php loads this file in the local environment with
 * PORTAL_DEMO=true only (config portal.demo); the login page shows its
 * button when the route exists, and tests/Feature/PortalDemoModeTest.php
 * checks that every other environment, and local without the flag, never
 * has it.
 *
 * Nothing here reaches the campus portal: the signed-in session comes from a
 * login stub, and every portal client answers from the fixture pages of
 * tests/Fixtures/portal (bootstrap/demo.php, set while the application
 * boots).
 */

/**
 * A session manager whose portal login and logout never leave the server:
 * the session it creates is an ordinary online session of the demo student
 * (PortalDemoFixtures::USERNAME, fixed so it can never read a real student's
 * data) without portal cookies, valid by the same local checks as a real
 * one.
 */
$demoSessions = static function (): PortalSessionManager {
    $loginStub = new class extends PortalAuthService
    {
        /**
         * @return array{success: true, user_info: array{name: string, nim: string}, cookies: array<int, array<string, mixed>>, session_data: array{login_time: string, last_activity: string, user_agent: string}}
         */
        public function login(string $username, string $password): array
        {
            return [
                'success' => true,
                'user_info' => PortalDemoFixtures::USER_INFO,
                'cookies' => [],
                'session_data' => [
                    'login_time' => now()->toISOString(),
                    'last_activity' => now()->toISOString(),
                    'user_agent' => 'MyCIC demo',
                ],
            ];
        }

        public function logout(CookieJar $cookieJar): void {}
    };

    return new PortalSessionManager($loginStub, app('encrypter'), app(PortalDataOwner::class));
};

/**
 * End the session a new demo sign-in replaces and leave nothing of it
 * behind: the campus session, and the unique lock its keep-alive job took
 * when it was (not) dispatched.
 */
$endDemoSession = static function (string $token) use ($demoSessions): void {
    $demoSessions()->destroySession($token);
    (new UniqueLock(app(CacheRepository::class)))->release(new RefreshPortalSessionJob($token));
};

/**
 * Sign this browser in as the demo student, like a successful login
 * (PortalAuthController::login) without the portal and without the
 * background sync a login starts. A session this browser already had ends
 * first, and the app session it signed in from is not kept. No job leaves
 * the request (Bus::fake()): the keep-alive createSession() schedules ends
 * a session without portal cookies as expired, and nothing needs keeping
 * alive here. The login page's button lands on the dashboard; a request
 * for JSON gets the session like the API login does.
 *
 * Answers 404 outside the local environment or without the flag, even if
 * this file were loaded by mistake (a cached route list).
 */
Route::post('/__demo/session', static function (Request $request) use ($demoSessions, $endDemoSession): JsonResponse|RedirectResponse {
    abort_unless(PortalDemoFixtures::enabledIn(app()), 404);
    Bus::fake();

    $sessions = $demoSessions();
    $previousToken = $request->session()->get('campus_session_token');

    if (is_string($previousToken) && $previousToken !== '') {
        $endDemoSession($previousToken);
    }

    $result = $sessions->createSession(PortalDemoFixtures::USERNAME, 'demo');

    $request->session()->regenerate(destroy: true);
    $request->session()->put([
        'campus_session_token' => $result['session_token'],
        'campus_user_info' => $result['user_info'],
        'campus_offline' => false,
        'campus_offline_reconnect' => null,
    ]);
    $sessions->rememberLaravelSessionId($result['session_token'], $request->session()->getId());

    if ($request->expectsJson()) {
        return response()->json([
            'session_id' => $request->session()->getId(),
            'user_info' => $result['user_info'],
        ], 201);
    }

    return redirect()->route('dashboard');
})->name('demo.session.start');
