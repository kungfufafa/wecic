<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalNotificationSent;
use App\Events\PortalSessionNoticeSent;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Services\PortalBroadcastService;
use App\Services\PortalPasswordService;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
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
}
