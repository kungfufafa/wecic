<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalOfflineAccount;
use App\Services\PortalAuthService;
use App\Services\PortalDemoFixtures;
use App\Services\PortalHttpClientOptions;
use App\Services\PortalOperationTracker;
use App\Services\PortalReviewerAccount;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;
use Tests\Traits\LogsInWithMobileToken;

/**
 * The store reviewer account (PORTAL_REVIEWER_USERNAME and
 * PORTAL_REVIEWER_PASSWORD) in the production environment: its exact
 * credentials open a session served from tests/Fixtures/portal on both the
 * token and the web login, and nothing of it reaches the portal (the test
 * suite's guard fails the test on any portal request it did not expect).
 * Every other login goes to the portal as before.
 */
class PortalReviewerModeTest extends TestCase
{
    use LogsInWithMobileToken, RefreshDatabase;

    private const REVIEWER_USERNAME = 'reviewer-toko';

    private const REVIEWER_PASSWORD = 'Rahasia-Reviewer-2026!';

    private const CSRF_TOKEN = 'reviewer-csrf-token';

    /**
     * Portal requests the recording handler saw, as "METHOD url".
     *
     * @var list<string>
     */
    private array $portalRequests = [];

    /**
     * The reviewer account is configured once the configuration is loaded,
     * before the providers boot, without touching the process environment
     * the other tests read.
     */
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->guardPortalRequests($app);

        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app->make('config')->set([
                'portal.demo' => false,
                'portal.reviewer.username' => self::REVIEWER_USERNAME,
                'portal.reviewer.password' => self::REVIEWER_PASSWORD,
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Production from here on: the database is migrated first, which the
     * production environment would refuse without confirmation.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_the_reviewer_signs_in_with_a_token_in_production_and_reads_the_fixtures(): void
    {
        Queue::fake();
        $this->assertTrue($this->app->environment('production'));
        $this->assertTrue(PortalReviewerAccount::enabled());

        $response = $this->postJson('/api/v1/auth/token', [
            'username' => self::REVIEWER_USERNAME,
            'password' => self::REVIEWER_PASSWORD,
            'device_name' => 'App Review',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.offline', false)
            ->assertJsonPath('data.user_info.name', 'Mahasiswa Contoh')
            ->assertJsonPath('data.user_info.nim', '20990000001');

        $token = (string) $response->json('data.token');
        $this->assertNotSame('', $token);

        $this->withToken($token)->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('profile.personal_data.nama', 'Mahasiswa Contoh')
            ->assertJsonPath('profile.personal_data.nim', '20990000001');

        $this->withToken($token)->getJson('/api/v1/class')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(7, 'classes');

        $this->withToken($token)->getJson('/api/v1/auth/session')->assertOk();

        $this->assertNoPasswordKept($this->campusTokenOf($token));
    }

    public function test_the_reviewer_can_never_switch_background_sync_on(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/auth/token', [
            'username' => self::REVIEWER_USERNAME,
            'password' => self::REVIEWER_PASSWORD,
            'device_name' => 'App Review',
            'background_sync' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.background_sync.available', false)
            ->assertJsonPath('data.background_sync.enabled', false);
        $token = (string) $response->json('data.token');

        $this->withToken($token)->patchJson('/api/v1/archive/background-sync', ['enabled' => true, 'password' => self::REVIEWER_PASSWORD])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Sinkronisasi background tidak tersedia untuk akun demo.')
            ->assertJsonPath('data.available', false);
        $this->withToken($token)->patchJson('/api/v1/archive/background-sync', ['enabled' => false])->assertOk();

        $this->assertSame(0, PortalOfflineAccount::query()->count());
        $this->assertNoPasswordKept($this->campusTokenOf($token));
    }

    /**
     * Every reviewer login shares one owner, so one reviewer deleting the
     * account ends only their own login, never another reviewer's.
     */
    public function test_a_reviewer_deleting_the_account_leaves_other_reviewers_signed_in(): void
    {
        Queue::fake();

        $deleting = (string) $this->postJson('/api/v1/auth/token', [
            'username' => self::REVIEWER_USERNAME,
            'password' => self::REVIEWER_PASSWORD,
            'device_name' => 'App Review',
        ])->assertOk()->json('data.token');
        $other = (string) $this->postJson('/api/v1/auth/token', [
            'username' => self::REVIEWER_USERNAME,
            'password' => self::REVIEWER_PASSWORD,
            'device_name' => 'Play Review',
        ])->assertOk()->json('data.token');
        $otherCampusToken = $this->campusTokenOf($other);

        $this->withToken($deleting)->deleteJson('/api/v1/account', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.sessions', 1)
            ->assertJsonPath('data.mobile_sessions', 1);

        $this->app['auth']->forgetGuards();
        $this->withToken($deleting)->getJson('/api/v1/auth/session')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/session')->assertOk();
        $this->assertNotNull(app(PortalSessionManager::class)->getSession($otherCampusToken));
    }

    public function test_the_reviewer_signs_in_on_the_web_login_in_production(): void
    {
        Queue::fake();

        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.user_info.name', 'Mahasiswa Contoh')
            ->assertJsonMissingPath('data.token');

        $sessionToken = (string) session('campus_session_token');
        $session = app(PortalSessionManager::class)->getSession($sessionToken);

        $this->assertIsArray($session);
        $this->assertSame(PortalReviewerAccount::SESSION_USERNAME, $session['username']);
        $this->assertTrue(PortalReviewerAccount::isReviewerCookieJar(app(PortalSessionManager::class)->getSessionCookieJar($sessionToken)));

        $this->getJson('/api/finance/payments')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(4, 'data.payment_history');

        $this->assertNoPasswordKept($sessionToken);
    }

    /**
     * The work a login starts (warm-up sync, keep-alive, notification
     * scan) and the logout run for the reviewer from the fixtures alone.
     */
    public function test_the_background_work_and_logout_of_a_reviewer_session_never_reach_the_portal(): void
    {
        Queue::fake();

        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertOk();
        $sessionToken = (string) session('campus_session_token');

        $ran = $this->runQueuedJobs();
        $this->assertNotSame([], $ran);

        $sessions = app(PortalSessionManager::class);
        $this->assertSame('alive', $sessions->keepAlive($sessionToken));
        $this->assertTrue($sessions->validateSession($sessionToken));

        $this->postJson('/api/auth/logout', [], ['X-CSRF-TOKEN' => session()->token()])->assertOk();
        $this->assertFalse($sessions->validateSession($sessionToken));
    }

    public function test_a_wrong_reviewer_password_is_refused_without_the_portal(): void
    {
        Queue::fake();

        $this->webLogin(self::REVIEWER_USERNAME, 'bukan-password-reviewer')->assertStatus(401);
        $this->assertNull(session('campus_session_token'));

        $this->postJson('/api/v1/auth/token', ['username' => self::REVIEWER_USERNAME, 'password' => ''])->assertStatus(422);
        $this->postJson('/api/v1/auth/token', ['username' => self::REVIEWER_USERNAME, 'password' => self::REVIEWER_PASSWORD.' '])->assertStatus(401);

        $this->assertSame(0, PortalOfflineAccount::query()->count());
    }

    /**
     * A student's NIM, even with the reviewer's password, goes to the
     * portal like before and never to the fixtures.
     */
    public function test_a_real_nim_goes_to_the_portal_and_never_to_the_fixtures(): void
    {
        Queue::fake();
        $this->recordPortalRequestsAsUnreachable();

        $this->webLogin('20990000777', self::REVIEWER_PASSWORD)->assertStatus(503);

        $this->assertNull(session('campus_session_token'));
        $this->assertNotSame([], $this->portalRequests);
        $this->assertStringStartsWith('GET https://portal.invalid/', $this->portalRequests[0]);

        $this->assertFalse(PortalReviewerAccount::isReviewerCookieJar(new CookieJar));
    }

    public function test_the_mode_is_off_without_both_credentials_or_with_a_nim_as_username(): void
    {
        Queue::fake();
        $this->recordPortalRequestsAsUnreachable();

        foreach ([['', self::REVIEWER_PASSWORD], [self::REVIEWER_USERNAME, ''], ['', ''], ['20990000888', self::REVIEWER_PASSWORD]] as [$username, $password]) {
            config(['portal.reviewer.username' => $username, 'portal.reviewer.password' => $password]);

            $this->assertFalse(PortalReviewerAccount::enabled());
            $this->assertFalse(PortalReviewerAccount::matches($username, $password));
            $this->assertFalse(PortalReviewerAccount::isReviewerUsername($username));
        }

        config(['portal.reviewer.username' => '', 'portal.reviewer.password' => '']);
        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertStatus(503);

        $this->assertNull(session('campus_session_token'));
        $this->assertNotSame([], $this->portalRequests, 'With the mode off the reviewer username is an ordinary login.');
    }

    /**
     * A reviewer session's requests keep going to the fixtures even after
     * the account is switched off, and a client never sends them on.
     */
    public function test_a_reviewer_session_stays_on_the_fixtures_once_the_mode_is_switched_off(): void
    {
        Queue::fake();

        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertOk();
        config(['portal.reviewer.username' => '', 'portal.reviewer.password' => '']);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('profile.personal_data.nama', 'Mahasiswa Contoh');

        $this->assertTrue((new PortalAuthService)->isSessionAlive(app(PortalSessionManager::class)->getSessionCookieJar((string) session('campus_session_token'))));
    }

    /**
     * Attendance with any code is answered with the portal's success page:
     * the reviewer sees it marked, and nothing reaches the portal.
     */
    public function test_the_reviewer_marks_attendance_without_the_portal(): void
    {
        Queue::fake();
        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertOk();

        $this->postJson('/api/attendance', ['courseid' => 9496, 'modulesid' => 36365, 'kode' => 'KODE-APA-SAJA'], $this->csrf())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'marked');
    }

    /**
     * An uploaded assignment completes, shows on its meeting with a delete
     * link, and the delete takes it away again; the portal is never asked.
     */
    public function test_the_reviewer_uploads_and_deletes_an_assignment_without_the_portal(): void
    {
        Queue::fake();
        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertOk();

        $operationId = (string) $this->post('/api/assignment', [
            'courses_id' => 9496,
            'id' => 36365,
            'pertemuan' => 3,
            'attachment' => UploadedFile::fake()->create('tugas.pdf', 64, 'application/pdf'),
        ], ['Accept' => 'application/json', ...$this->csrf()])
            ->assertStatus(202)
            ->json('data.operation_id');

        $this->runQueuedJobs();

        $this->assertOperationCompleted($operationId);

        $this->assertTrue(Cache::has(PortalDemoFixtures::UPLOADED_ASSIGNMENT_CACHE_PREFIX.'9496:36365:3'));

        $this->deleteJson('/api/assignment', ['id' => 9496, 'idx' => 36365, 'pertemuan' => 3, 'courses_id' => 9496], $this->csrf())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'deleted');

        $this->assertFalse(Cache::has(PortalDemoFixtures::UPLOADED_ASSIGNMENT_CACHE_PREFIX.'9496:36365:3'));
    }

    /**
     * The demo exam is open: the reviewer can upload an answer to it.
     */
    public function test_the_reviewer_uploads_an_exam_answer_without_the_portal(): void
    {
        Queue::fake();
        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertOk();

        $this->getJson('/api/exam/99001')->assertOk();

        $operationId = (string) $this->post('/api/exam', [
            'id' => 99001,
            'attachment' => UploadedFile::fake()->create('jawaban.pdf', 64, 'application/pdf'),
        ], ['Accept' => 'application/json', ...$this->csrf()])
            ->assertStatus(202)
            ->json('data.operation_id');

        $this->runQueuedJobs();

        $this->assertOperationCompleted($operationId);
    }

    /**
     * A password change is simulated: it says so, keeps nothing for offline
     * login, and the reviewer password keeps working.
     */
    public function test_a_reviewer_password_change_is_simulated(): void
    {
        Queue::fake();
        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertOk();
        $sessionToken = (string) session('campus_session_token');

        $this->postJson('/api/auth/password', [
            'current_password' => 'apa-saja',
            'new_password' => 'sandi-baru-123',
            'new_password_confirmation' => 'sandi-baru-123',
        ], $this->csrf())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.simulated', true)
            ->assertJsonPath('message', 'Password berhasil diubah (mode demo: password akun ini tetap sama)');

        $this->assertSame(0, PortalOfflineAccount::query()->count());
        $this->assertTrue(app(PortalSessionManager::class)->validateSession($sessionToken));

        $this->postJson('/api/v1/auth/token', [
            'username' => self::REVIEWER_USERNAME,
            'password' => self::REVIEWER_PASSWORD,
            'device_name' => 'App Review',
        ])->assertOk();
    }

    /**
     * A course file opens as a generated PDF instead of the portal's file.
     */
    public function test_the_reviewer_downloads_a_course_file_without_the_portal(): void
    {
        Queue::fake();
        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertOk();

        $response = $this->get('/api/support/download?path='.rawurlencode('/portal/files/modul/IF101-mod-1.pdf'));

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-1.4', $response->streamedContent());
    }

    /**
     * Nothing the reviewer sees links to the real portal: no SKKM add button
     * and no questionnaire item.
     */
    public function test_the_reviewer_gets_no_link_to_the_real_portal(): void
    {
        Queue::fake();
        $this->webLogin(self::REVIEWER_USERNAME, self::REVIEWER_PASSWORD)->assertOk();

        $this->getJson('/api/skkm')
            ->assertOk()
            ->assertJsonPath('data.add_available', false);

        $items = $this->getJson('/api/action-items')->assertOk()->json('data.items');
        $this->assertIsArray($items);
        $this->assertSame([], array_values(array_filter($items, static fn (array $item): bool => $item['cta_external'] === true)));

        $this->get('/skkm')->assertOk()->assertDontSee('data-skkm-add', false);
    }

    private function assertOperationCompleted(string $operationId): void
    {
        $operation = app(PortalOperationTracker::class)->get($operationId);

        $this->assertIsArray($operation);
        $this->assertSame('completed', $operation['stage'] ?? null, (string) ($operation['message'] ?? ''));
    }

    /**
     * @return array{X-CSRF-TOKEN: string}
     */
    private function csrf(): array
    {
        return ['X-CSRF-TOKEN' => (string) session()->token()];
    }

    private function webLogin(string $username, string $password): TestResponse
    {
        return $this->withSession(['_token' => self::CSRF_TOKEN])
            ->postJson('/api/auth/login', ['username' => $username, 'password' => $password], ['X-CSRF-TOKEN' => self::CSRF_TOKEN]);
    }

    /**
     * Every portal request is recorded and answered as an unreachable host.
     */
    private function recordPortalRequestsAsUnreachable(): void
    {
        $this->app->instance(PortalHttpClientOptions::HANDLER_BINDING, function (RequestInterface $request, array $options): PromiseInterface {
            $this->portalRequests[] = $request->getMethod().' '.$request->getUri();

            return Create::rejectionFor(new ConnectException('cURL error 6: Could not resolve host (the test made the portal unreachable).', $request));
        });
    }

    /**
     * Run what the queue received, and what those jobs queue in turn, a
     * few rounds deep (keep-alive jobs queue themselves again).
     *
     * @return list<class-string>
     */
    private function runQueuedJobs(): array
    {
        $ran = [];
        $seen = [];

        for ($round = 0; $round < 3; $round++) {
            $pending = [];

            foreach (Queue::pushedJobs() as $pushes) {
                foreach ($pushes as $push) {
                    $job = $push['job'];

                    if (is_object($job) && ! isset($seen[spl_object_id($job)])) {
                        $seen[spl_object_id($job)] = true;
                        $pending[] = $job;
                    }
                }
            }

            foreach ($pending as $job) {
                $this->app->call([$job, 'handle']);
                $ran[] = $job::class;
            }
        }

        return $ran;
    }

    private function assertNoPasswordKept(string $sessionToken): void
    {
        $this->assertSame(0, PortalOfflineAccount::query()->count(), 'Nothing of the reviewer is kept for offline login or background sync.');

        $session = app(PortalSessionManager::class)->getSession($sessionToken);
        $this->assertIsArray($session);
        $this->assertArrayNotHasKey('encrypted_credentials', $session);
        $this->assertSame(PortalReviewerAccount::SESSION_USERNAME, $session['username']);
    }
}
