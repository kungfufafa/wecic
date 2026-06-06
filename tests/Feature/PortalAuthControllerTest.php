<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Services\PortalPasswordService;
use App\Services\PortalSessionManager;
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
                'username' => '20251030030',
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
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('createSession')
                    ->once()
                    ->with('20251030030', 'secret-password')
                    ->andReturn([
                        'success' => true,
                        'session_token' => 'campus-token-123',
                        'user_info' => ['name' => 'Mahasiswa Uji'],
                        'expires_at' => '2026-06-06T10:00:00+07:00',
                    ]);
            })
        );

        $this->startSession();
        session()->put('_token', 'known-csrf-token');
        $oldSessionId = session()->getId();

        $response = $this->withMiddleware(VerifyCsrfTokenMiddleware::class)
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => '20251030030',
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

    public function test_refresh_session_does_not_return_raw_session_token(): void
    {
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
                    'username' => '20251030030',
                ], ['Accept' => 'application/json']);

            $response->assertStatus(422);
        }

        $response = $this->withSession(['_token' => 'known-csrf-token'])
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => '20251030030',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(429);
    }

    public function test_logout_invalidates_laravel_session(): void
    {
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
}
