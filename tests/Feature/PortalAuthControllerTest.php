<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalNotificationSent;
use App\Events\PortalSessionNoticeSent;
use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataOwner;
use App\Services\PortalLogContext;
use App\Services\PortalOfflineAccess;
use App\Services\PortalPasswordService;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalAuthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_login_rejects_missing_csrf_token(): void
    {
        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->post('/api/auth/login', [
                'username' => '20990000001',
                'password' => 'secret-password',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(419);
    }

    public function test_login_validation_returns_json_without_accept_header(): void
    {
        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->withSession(['_token' => 'known-csrf-token'])
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'Username dan password harus diisi',
            ]);

        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('content-type')
        );
    }

    public function test_login_rotates_laravel_session_id_and_does_not_return_session_token(): void
    {
        Event::fake([PortalSessionNoticeSent::class, PortalNotificationSent::class]);
        Bus::fake([ScanPortalNotificationsJob::class, RefreshPortalDataJob::class]);

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('createSession')
                    ->once()
                    ->with('20990000001', 'secret-password')
                    ->andReturn([
                        'success' => true,
                        'session_token' => 'campus-token-123',
                        'user_info' => ['name' => 'Mahasiswa Uji'],
                        'expires_at' => '2026-06-06T10:00:00+07:00',
                    ]);
                $mock->shouldReceive('rememberLaravelSessionId')
                    ->once()
                    ->with('campus-token-123', Mockery::type('string'));
            })
        );

        $this->startSession();
        session()->put('_token', 'known-csrf-token');
        $oldSessionId = session()->getId();

        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => '20990000001',
                'password' => 'secret-password',
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonMissingPath('data.session_token')
            ->assertSessionHas('campus_session_token', 'campus-token-123');

        $this->assertNotSame($oldSessionId, session()->getId());
        Bus::assertDispatched(ScanPortalNotificationsJob::class, fn (ScanPortalNotificationsJob $job): bool => $job->delay !== null);
    }

    public function test_successful_password_change_purges_stored_credentials(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('validateSession')
            ->once()
            ->with('campus-token-123')
            ->andReturnTrue();
        $sessionManager->shouldReceive('forgetStoredCredentials')
            ->once()
            ->with('campus-token-123');
        $sessionManager->shouldReceive('forgetStoredCredentialsForOwner')
            ->once()
            ->with(app(PortalDataOwner::class)->keyForUsername('20990000001'))
            ->andReturnTrue();
        $sessionManager->shouldReceive('getSession')
            ->with('campus-token-123')
            ->andReturn(['username' => '20990000001', 'user_info' => ['name' => 'Mahasiswa Uji']]);
        $this->expectOfflinePasswordUpdate('20990000001', 'new-secret');

        $passwordService = Mockery::mock(PortalPasswordService::class);
        $passwordService->shouldReceive('changePassword')
            ->once()
            ->with('campus-token-123', 'old-secret', 'new-secret', 'new-secret')
            ->andReturn([
                'success' => true,
                'message' => 'Password changed successfully.',
                'data' => [],
            ]);

        $this->instance(PortalSessionManager::class, $sessionManager);
        $this->instance(PortalPasswordService::class, $passwordService);

        $response = $this->withSession([
            '_token' => 'known-csrf-token',
            'campus_session_token' => 'campus-token-123',
        ])->post('/api/auth/password', [
            '_token' => 'known-csrf-token',
            'current_password' => 'old-secret',
            'new_password' => 'new-secret',
            'new_password_confirmation' => 'new-secret',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_password_page_json_payload_changes_password(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('validateSession')
            ->once()
            ->with('campus-token-123')
            ->andReturnTrue();
        $sessionManager->shouldReceive('forgetStoredCredentials')
            ->once()
            ->with('campus-token-123');
        $sessionManager->shouldReceive('forgetStoredCredentialsForOwner')->once()->andReturnTrue();
        $sessionManager->shouldReceive('getSession')
            ->with('campus-token-123')
            ->andReturn(['username' => '20990000001', 'user_info' => ['name' => 'Mahasiswa Uji']]);
        $this->expectOfflinePasswordUpdate('20990000001', 'new-secret');

        $passwordService = Mockery::mock(PortalPasswordService::class);
        $passwordService->shouldReceive('changePassword')
            ->once()
            ->with('campus-token-123', 'old-secret', 'new-secret', 'new-secret')
            ->andReturn([
                'success' => true,
                'message' => 'Password changed successfully.',
                'data' => [],
            ]);

        $this->instance(PortalSessionManager::class, $sessionManager);
        $this->instance(PortalPasswordService::class, $passwordService);

        $response = $this->withSession([
            '_token' => 'known-csrf-token',
            'campus_session_token' => 'campus-token-123',
        ])->postJson('/api/auth/password', [
            'current_password' => 'old-secret',
            'new_password' => 'new-secret',
            'new_password_confirmation' => 'new-secret',
        ], ['X-CSRF-TOKEN' => 'known-csrf-token']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password changed successfully.');
    }

    public function test_password_change_reports_errors_per_field(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('validateSession')
            ->with('campus-token-123')
            ->andReturnTrue();
        $sessionManager->shouldNotReceive('forgetStoredCredentials');

        $passwordService = Mockery::mock(PortalPasswordService::class);
        $passwordService->shouldNotReceive('changePassword');

        $this->instance(PortalSessionManager::class, $sessionManager);
        $this->instance(PortalPasswordService::class, $passwordService);

        $response = $this->withSession([
            '_token' => 'known-csrf-token',
            'campus_session_token' => 'campus-token-123',
        ])->postJson('/api/auth/password', [
            'current_password' => '',
            'new_password' => 'short',
            'new_password_confirmation' => 'different',
        ], ['X-CSRF-TOKEN' => 'known-csrf-token']);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'Password lama wajib diisi.')
            ->assertJsonPath('errors.new_password.0', 'Password baru minimal 6 karakter.')
            ->assertJsonMissingPath('errors.new_password_confirmation');
    }

    public function test_a_new_password_reaches_the_portal_exactly_as_typed(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('validateSession')->with('campus-token-123')->andReturnTrue();
        $sessionManager->shouldReceive('forgetStoredCredentials')->once()->with('campus-token-123');
        $sessionManager->shouldReceive('forgetStoredCredentialsForOwner')->once()->andReturnTrue();
        $sessionManager->shouldReceive('getSession')
            ->with('campus-token-123')
            ->andReturn(['username' => '20990000001', 'user_info' => ['name' => 'Mahasiswa Uji']]);
        $this->expectOfflinePasswordUpdate('20990000001', '  spasi baru  ');

        $passwordService = Mockery::mock(PortalPasswordService::class);
        $passwordService->shouldReceive('changePassword')
            ->once()
            ->with('campus-token-123', ' old-secret ', '  spasi baru  ', '  spasi baru  ')
            ->andReturn(['success' => true, 'message' => 'Password berhasil diubah.', 'data' => []]);

        $this->instance(PortalSessionManager::class, $sessionManager);
        $this->instance(PortalPasswordService::class, $passwordService);

        $this->withSession([
            '_token' => 'known-csrf-token',
            'campus_session_token' => 'campus-token-123',
        ])->postJson('/api/auth/password', [
            'current_password' => ' old-secret ',
            'new_password' => '  spasi baru  ',
            'new_password_confirmation' => '  spasi baru  ',
        ], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_a_failed_password_change_leaves_the_offline_password_alone(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('validateSession')->with('campus-token-123')->andReturnTrue();
        $sessionManager->shouldNotReceive('forgetStoredCredentials');
        $sessionManager->shouldNotReceive('forgetStoredCredentialsForOwner');

        $this->instance(PortalOfflineAccess::class, Mockery::mock(PortalOfflineAccess::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isOfflineSession')->andReturnFalse();
            $mock->shouldNotReceive('remember');
        }));

        $passwordService = Mockery::mock(PortalPasswordService::class);
        $passwordService->shouldReceive('changePassword')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Password lama salah.', 'data' => null]);

        $this->instance(PortalSessionManager::class, $sessionManager);
        $this->instance(PortalPasswordService::class, $passwordService);

        $this->withSession([
            '_token' => 'known-csrf-token',
            'campus_session_token' => 'campus-token-123',
        ])->postJson('/api/auth/password', [
            'current_password' => 'old-secret',
            'new_password' => 'new-secret',
            'new_password_confirmation' => 'new-secret',
        ], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_refresh_session_does_not_return_raw_session_token(): void
    {
        Event::fake([PortalSessionNoticeSent::class, PortalNotificationSent::class]);
        Bus::fake([ScanPortalNotificationsJob::class]);

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('refreshSession')
                    ->once()
                    ->with('campus-token-123')
                    ->andReturn([
                        'success' => true,
                        'session_token' => 'campus-token-123',
                        'expires_at' => '2026-06-06T10:00:00+07:00',
                    ]);
                $mock->shouldReceive('rememberLaravelSessionId')
                    ->once()
                    ->with('campus-token-123', Mockery::type('string'));
            })
        );

        $response = $this->withSession([
            '_token' => 'known-csrf-token',
            'campus_session_token' => 'campus-token-123',
        ])->post('/api/auth/session/refresh', [
            '_token' => 'known-csrf-token',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonMissingPath('data.session_token')
            ->assertJsonPath('data.expires_at', '2026-06-06T10:00:00+07:00');
    }

    public function test_login_is_rate_limited_by_username_and_ip(): void
    {
        config([
            'portal.security.login_rate_limit_attempts' => 2,
            'portal.security.login_rate_limit_decay' => 60,
        ]);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $this->withSession(['_token' => 'known-csrf-token'])
                ->post('/api/auth/login', [
                    '_token' => 'known-csrf-token',
                    'username' => '20990000001',
                ], ['Accept' => 'application/json']);

            $response->assertStatus(422);
        }

        $response = $this->withSession(['_token' => 'known-csrf-token'])
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => '20990000001',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(429);
    }

    public function test_failed_logins_from_one_address_are_limited_across_usernames(): void
    {
        config(['portal.security.login_failure_attempts_per_ip' => 2]);
        $this->portalRejectsLogins();

        $this->loginAs('20990000101')->assertStatus(401);
        $this->loginAs('20990000102')->assertStatus(401);

        $this->loginAs('20990000103')
            ->assertStatus(429)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Terlalu banyak login gagal dari jaringan ini. Coba lagi dalam beberapa menit.');
    }

    public function test_requests_that_are_not_rejected_logins_do_not_count_against_the_address(): void
    {
        config(['portal.security.login_failure_attempts_per_ip' => 2]);
        // Bound before the first request: the route keeps the controller it built.
        $this->portalRejectsLogins();

        foreach (['20990000201', '20990000202', '20990000203', '20990000204'] as $username) {
            // No password: invalid input, not a password the portal rejected.
            $this->withSession(['_token' => 'known-csrf-token'])
                ->post('/api/auth/login', ['_token' => 'known-csrf-token', 'username' => $username], ['Accept' => 'application/json'])
                ->assertStatus(422);
        }

        $this->loginAs('20990000205')->assertStatus(401);
    }

    public function test_rejected_logins_are_logged_without_the_nim(): void
    {
        Log::spy();
        $this->portalRejectsLogins();

        $this->loginAs('20990000009')->assertStatus(401);

        $this->assertLoggedWithoutNim('warning', 'Campus authentication rejected', '20990000009');
    }

    public function test_logins_while_the_portal_is_unreachable_are_logged_without_the_nim(): void
    {
        Log::spy();
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSession')->andThrow(PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection timed out'));
        }));
        $this->instance(PortalOfflineAccess::class, Mockery::mock(PortalOfflineAccess::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(['user_info' => null, 'wrong_password' => false]);
        }));

        $this->loginAs('20990000009')->assertStatus(503);

        $this->assertLoggedWithoutNim('error', 'Campus portal is unreachable during login', '20990000009');
    }

    public function test_logout_is_never_held_up_by_the_portal_action_budget(): void
    {
        config(['portal.security.action_rate_limit_attempts' => 1]);
        Event::fake([PortalSessionNoticeSent::class]);

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('destroySession')->twice()->with('campus-token-123')->andReturnTrue();
            })
        );

        foreach ([1, 2] as $attempt) {
            $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => 'campus-token-123'])
                ->post('/api/auth/logout', ['_token' => 'known-csrf-token'], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertSessionMissing('campus_session_token');
        }
    }

    public function test_logout_invalidates_laravel_session(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('destroySession')
                    ->once()
                    ->with('campus-token-123')
                    ->andReturnTrue();
            })
        );

        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->withSession([
                '_token' => 'known-csrf-token',
                'campus_session_token' => 'campus-token-123',
                'campus_user_info' => ['name' => 'Mahasiswa Uji'],
            ])
            ->post('/api/auth/logout', [
                '_token' => 'known-csrf-token',
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertSessionMissing('campus_session_token')
            ->assertSessionMissing('campus_user_info');
    }

    public function test_logout_notifies_other_tabs_only_after_the_session_is_destroyed(): void
    {
        $events = [];

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use (&$events): void {
                $mock->shouldReceive('destroySession')
                    ->once()
                    ->with('campus-token-123')
                    ->andReturnUsing(function () use (&$events): bool {
                        $events[] = 'destroyed';

                        return true;
                    });
            })
        );
        $this->instance(
            PortalBroadcastService::class,
            Mockery::mock(PortalBroadcastService::class, function (MockInterface $mock) use (&$events): void {
                $mock->shouldReceive('sessionNotice')
                    ->once()
                    ->withArgs(fn (string $sessionId, string $token, string $status): bool => $sessionId !== '' && $status === 'logged_out')
                    ->andReturnUsing(function () use (&$events): void {
                        $events[] = 'notice';
                    });
            })
        );

        $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => 'campus-token-123'])
            ->post('/api/auth/logout', ['_token' => 'known-csrf-token'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(['destroyed', 'notice'], $events);
    }

    public function test_logout_accepts_csrf_token_from_request_header(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('destroySession')
                    ->once()
                    ->with('campus-token-123')
                    ->andReturnTrue();
            })
        );

        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->withSession([
                '_token' => 'known-csrf-token',
                'campus_session_token' => 'campus-token-123',
                'campus_user_info' => ['name' => 'Mahasiswa Uji'],
            ])
            ->post('/api/auth/logout', [], [
                'Accept' => 'application/json',
                'X-CSRF-TOKEN' => 'known-csrf-token',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertSessionMissing('campus_session_token')
            ->assertSessionMissing('campus_user_info');
    }

    /**
     * Call before the first request: the route keeps the controller (and the
     * session manager) it resolved first.
     */
    private function portalRejectsLogins(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSession')->andThrow(PortalAuthenticationException::invalidCredentials());
        }));
    }

    private function loginAs(string $username): TestResponse
    {
        return $this->withSession(['_token' => 'known-csrf-token'])
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => $username,
                'password' => 'tebakan-umum',
            ], ['Accept' => 'application/json']);
    }

    private function assertLoggedWithoutNim(string $level, string $logMessage, string $username): void
    {
        Log::shouldHaveReceived($level)
            ->withArgs(fn (string $message, array $context = []): bool => $message === $logMessage
                && ($context['username_fingerprint'] ?? null) === PortalLogContext::fingerprint($username)
                && ! str_contains((string) json_encode($context), $username))
            ->once();
    }

    /**
     * A successful password change moves offline access to the new password.
     */
    private function expectOfflinePasswordUpdate(string $username, string $newPassword): void
    {
        $this->instance(PortalOfflineAccess::class, Mockery::mock(PortalOfflineAccess::class, function (MockInterface $mock) use ($username, $newPassword): void {
            $mock->shouldReceive('isOfflineSession')->andReturnFalse();
            $mock->shouldReceive('remember')->once()->with($username, $newPassword, ['name' => 'Mahasiswa Uji']);
        }));
    }
}
