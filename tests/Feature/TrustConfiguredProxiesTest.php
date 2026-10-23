<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalAuthenticationException;
use App\Services\PortalSessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Behind a reverse proxy every request comes from the proxy's address: the
 * client address must come from X-Forwarded-For, or the per-address limits
 * would pool every student into one budget. Only a configured proxy may set
 * it; anyone else could forge it.
 */
class TrustConfiguredProxiesTest extends TestCase
{
    private const PROXY = '10.0.0.5';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__test/client-ip', static fn (Request $request): string => (string) $request->ip());
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_no_proxy_is_trusted_by_default(): void
    {
        $this->assertSame(self::PROXY, $this->clientIp(from: self::PROXY, forwardedFor: '203.0.113.7'));
    }

    public function test_a_configured_proxy_passes_the_client_address_on(): void
    {
        config(['portal.security.trusted_proxies' => self::PROXY]);

        $this->assertSame('203.0.113.7', $this->clientIp(from: self::PROXY, forwardedFor: '203.0.113.7'));

        config(['portal.security.trusted_proxies' => '192.168.1.1, 10.0.0.0/8']);

        $this->assertSame('203.0.113.8', $this->clientIp(from: '10.1.2.3', forwardedFor: '203.0.113.8'));
    }

    public function test_a_forwarded_address_from_anyone_else_is_ignored(): void
    {
        config(['portal.security.trusted_proxies' => self::PROXY]);

        $this->assertSame('198.51.100.9', $this->clientIp(from: '198.51.100.9', forwardedFor: '203.0.113.7'));
    }

    public function test_students_behind_the_proxy_each_get_their_own_failed_login_budget(): void
    {
        config([
            'portal.security.trusted_proxies' => self::PROXY,
            'portal.security.login_failure_attempts_per_ip' => 1,
        ]);
        // Bound before the first request: the route keeps the controller it built.
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSession')->andThrow(PortalAuthenticationException::invalidCredentials());
        }));

        $this->loginFrom('203.0.113.7', '20990000501')->assertStatus(401);
        $this->loginFrom('203.0.113.8', '20990000502')->assertStatus(401);
        $this->loginFrom('203.0.113.7', '20990000503')->assertStatus(429);
    }

    private function clientIp(string $from, string $forwardedFor): string
    {
        return (string) $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->get('/__test/client-ip', ['X-Forwarded-For' => $forwardedFor])
            ->assertOk()
            ->getContent();
    }

    private function loginFrom(string $client, string $username): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withSession(['_token' => 'known-csrf-token'])
            ->post('/api/auth/login', [
                '_token' => 'known-csrf-token',
                'username' => $username,
                'password' => 'tebakan-umum',
            ], ['Accept' => 'application/json', 'X-Forwarded-For' => $client]);
    }
}
