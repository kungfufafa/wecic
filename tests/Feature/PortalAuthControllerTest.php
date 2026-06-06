<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfTokenMiddleware;
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
