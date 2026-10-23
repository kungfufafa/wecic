<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Services\PortalAuthService;
use App\Services\PortalMobileSessions;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;

/**
 * Logs in like the mobile app (`POST /api/auth/token`) against a mocked
 * portal, through the real session manager. Call portalAcceptsLogin()
 * before anything builds the session manager.
 */
trait LogsInWithMobileToken
{
    protected function portalAcceptsLogin(string $username = '20990000501', string $name = 'Mahasiswa Mobile'): void
    {
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock) use ($username, $name): void {
            $mock->shouldReceive('login')->andReturn([
                'user_info' => ['name' => $name, 'student_id' => $username],
                'cookies' => [[
                    'Name' => 'PHPSESSID',
                    'Value' => 'cookie-value',
                    'Domain' => 'portal.invalid',
                    'Path' => '/',
                    'Expires' => null,
                    'Secure' => false,
                    'HttpOnly' => true,
                ]],
                'session_data' => ['login_time' => now()->toISOString()],
            ]);
            $mock->shouldReceive('isSessionAlive')->andReturnTrue();
            $mock->shouldReceive('logout');
        }));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function tokenLogin(string $username = '20990000501', string $password = 'rahasia-mobile'): TestResponse
    {
        return $this->postJson('/api/auth/token', [
            'username' => $username,
            'password' => $password,
            'device_name' => 'Pixel 8',
        ]);
    }

    protected function loginForToken(string $username = '20990000501', string $password = 'rahasia-mobile'): string
    {
        return (string) $this->tokenLogin($username, $password)->assertOk()->json('data.token');
    }

    /**
     * The campus session token the token's Laravel session holds, read
     * through the session store (encrypted or not); nothing of it stays
     * loaded afterwards.
     */
    protected function campusTokenOf(string $token): string
    {
        $mobileSession = app(PortalMobileSessions::class)->resolve($token);
        Assert::assertNotNull($mobileSession);

        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId($mobileSession->session_id);
        $store->start();
        $campusToken = (string) $store->get('campus_session_token', '');
        $store->flush();

        return $campusToken;
    }
}
