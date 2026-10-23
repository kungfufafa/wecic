<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PortalSessionMiddleware;
use App\Services\PortalAuthService;
use App\Services\PortalDataOwner;
use App\Services\PortalLogContext;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Requests the app makes on its own (re-reads after a realtime event) check
 * the session without counting as the student's activity, so an unattended
 * open tab still reaches the idle timeout.
 */
class PortalSessionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'campus-token-middleware';

    protected function setUp(): void
    {
        parent::setUp();

        // Bound before the session manager is built: nothing here may reach the portal.
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('login');
            $mock->shouldNotReceive('isSessionAlive');
            $mock->shouldNotReceive('logout');
        }));
        Queue::fake();
        config(['portal.session_activity_touch_interval' => 60]);
        app(PortalDataOwner::class)->remember(self::TOKEN, '20990000301', 3600);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_background_re_read_does_not_count_as_activity(): void
    {
        $session = $this->storeSession(lastActivity: now()->subMinutes(10));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/notifications/unread-count', [PortalSessionMiddleware::BACKGROUND_HEADER => '1'])
            ->assertOk();

        $stored = app(PortalSessionManager::class)->getSession(self::TOKEN);
        $this->assertSame($session['last_activity'], $stored['last_activity']);
        $this->assertSame($session['expires_at'], $stored['expires_at']);
    }

    public function test_a_request_the_student_makes_slides_the_idle_expiry(): void
    {
        $session = $this->storeSession(lastActivity: now()->subMinutes(10));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/notifications/unread-count')
            ->assertOk();

        $stored = app(PortalSessionManager::class)->getSession(self::TOKEN);
        $this->assertNotSame($session['last_activity'], $stored['last_activity']);
        $this->assertTrue(CarbonImmutable::parse($stored['expires_at'])->greaterThan(CarbonImmutable::parse($session['expires_at'])));
    }

    public function test_a_background_re_read_of_an_idle_session_is_refused(): void
    {
        $this->storeSession(lastActivity: now()->subHours(3), expiresAt: now()->subMinute());

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/notifications/unread-count', [PortalSessionMiddleware::BACKGROUND_HEADER => '1'])
            ->assertUnauthorized()
            ->assertSessionMissing('campus_session_token');
    }

    public function test_the_automatic_session_check_does_not_count_as_activity_either(): void
    {
        $session = $this->storeSession(lastActivity: now()->subMinutes(10));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/auth/session', [PortalSessionMiddleware::BACKGROUND_HEADER => '1'])
            ->assertOk();

        $this->assertSame($session['expires_at'], app(PortalSessionManager::class)->getSession(self::TOKEN)['expires_at']);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/auth/session')
            ->assertOk();

        $this->assertNotSame($session['expires_at'], app(PortalSessionManager::class)->getSession(self::TOKEN)['expires_at']);
    }

    public function test_the_automatic_session_check_neither_records_activity_nor_revives_an_idle_session(): void
    {
        $session = $this->storeSession(lastActivity: now()->subMinutes(10));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/auth/session', [PortalSessionMiddleware::BACKGROUND_HEADER => '1'])
            ->assertOk()
            ->assertJsonPath('data.offline', false)
            ->assertJsonPath('data.offline_reconnect', null);

        $stored = app(PortalSessionManager::class)->getSession(self::TOKEN);
        $this->assertSame($session['last_activity'], $stored['last_activity']);
        Queue::assertNothingPushed();

        $this->storeSession(lastActivity: now()->subHours(3), expiresAt: now()->subMinute());

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/auth/session', [PortalSessionMiddleware::BACKGROUND_HEADER => '1'])
            ->assertUnauthorized()
            ->assertJsonPath('meta.campus_session_valid', false);

        $this->assertFalse(app(PortalSessionManager::class)->getSession(self::TOKEN)['is_active']);
    }

    public function test_a_download_opened_in_a_tab_without_a_session_goes_to_the_login_page(): void
    {
        $this->get('/api/support/download?path=../files/modul/sample.pdf', ['Accept' => 'text/html'])
            ->assertRedirect('/login');

        $this->storeSession(lastActivity: now()->subHours(3), expiresAt: now()->subMinute());
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/api/support/download?path=../files/modul/sample.pdf', ['Accept' => 'text/html'])
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token');

        // A fetch still gets JSON it can read, as every other API route does.
        $this->getJson('/api/support/download?path=../files/modul/sample.pdf')
            ->assertUnauthorized()
            ->assertJsonPath('meta.campus_session_valid', false);
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/api/notifications/unread-count', ['Accept' => 'text/html'])
            ->assertUnauthorized();
    }

    public function test_a_failing_session_check_is_logged_without_the_session_token(): void
    {
        Log::spy();
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andThrow(new \RuntimeException('Cache store unavailable'));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(503);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'Campus session validation failed'
                && ! array_key_exists('session_token', $context)
                && ($context['session_token_fingerprint'] ?? null) === PortalLogContext::fingerprint(self::TOKEN)
                && ! str_contains((string) json_encode($context), self::TOKEN))
            ->once();
    }

    /**
     * A locked database or an unreadable cache says nothing about the
     * session: the request is refused for now, the session stays.
     */
    public function test_a_failed_session_check_keeps_the_session_for_the_next_request(): void
    {
        $this->storeSession(lastActivity: now()->subMinutes(10));
        $sessions = app(PortalSessionManager::class);
        $this->failSessionChecks();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', true)
            ->assertSessionHas('campus_session_token', self::TOKEN);

        $this->instance(PortalSessionManager::class, $sessions);

        $this->getJson('/api/notifications/unread-count')->assertOk();
    }

    public function test_a_failed_session_check_on_a_page_keeps_the_student_signed_in(): void
    {
        $this->failSessionChecks();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/dashboard')
            ->assertStatus(503)
            ->assertSee('MyCIC sedang sibuk')
            ->assertSessionHas('campus_session_token', self::TOKEN);

        $this->get('/api/support/download?path=../files/modul/sample.pdf', ['Accept' => 'text/html'])
            ->assertStatus(503)
            ->assertSessionHas('campus_session_token', self::TOKEN);

        // The login form stays usable, but does not wipe the device's offline copies.
        $this->get('/login')->assertOk()
            ->assertSessionHas('campus_session_token', self::TOKEN)
            ->assertDontSee('forgetEndedSession', false);
        $this->get('/')->assertRedirect('/dashboard')->assertSessionHas('campus_session_token', self::TOKEN);
    }

    private function failSessionChecks(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andThrow(new \RuntimeException('database is locked'));
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function storeSession(\DateTimeInterface $lastActivity, ?\DateTimeInterface $expiresAt = null): array
    {
        $session = [
            'token' => self::TOKEN,
            'username' => '20990000301',
            'user_info' => ['name' => 'Mahasiswa Uji'],
            'cookies' => [],
            'login_time' => now()->subHours(4)->toISOString(),
            'last_activity' => CarbonImmutable::instance($lastActivity)->toISOString(),
            'expires_at' => CarbonImmutable::instance($expiresAt ?? now()->addMinutes(110))->toISOString(),
            'upstream_checked_at' => now()->subMinutes(5)->toISOString(),
            'is_active' => true,
        ];

        Cache::put(config('portal.session_prefix').self::TOKEN, $session, 3600);

        return $session;
    }
}
