<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\TestCase;

class PortalBroadcastingTest extends TestCase
{
    private const SESSION_ID = 'aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7d';

    /**
     * Boots with the reverb broadcaster, which signs channel auth, while
     * phpunit.xml keeps every other test on the `null` one. It is set before
     * the providers boot, since routes/channels.php registers the channels
     * on the default broadcaster then.
     */
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set('broadcasting.default', 'reverb');
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_portal_session_channel_requires_active_campus_session(): void
    {
        $response = $this->withSession([])->postJson('/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.session()->getId(),
            'socket_id' => '123.456',
        ]);

        $response->assertForbidden();
    }

    public function test_campus_session_is_signed_into_its_own_channel(): void
    {
        $channel = 'private-portal.session.'.self::SESSION_ID;

        $response = $this->authorizeChannel($channel);

        $response->assertOk();
        $expectedSignature = hash_hmac('sha256', '123.456:'.$channel, (string) config('broadcasting.connections.reverb.secret'));
        $this->assertSame(config('broadcasting.connections.reverb.key').':'.$expectedSignature, $response->json('auth'));
    }

    public function test_campus_session_cannot_join_another_sessions_channel(): void
    {
        $this->authorizeChannel('private-portal.session.'.str_repeat('z', 40))->assertForbidden();
    }

    /**
     * Posts like the browser does: the Laravel session cookie identifies the
     * session, which holds a campus token.
     *
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function authorizeChannel(string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['campus_session_token' => 'campus-token-broadcast', '_token' => 'known-csrf-token'])
            ->withCookie((string) config('session.cookie'), self::SESSION_ID)
            ->withCredentials()
            ->postJson('/broadcasting/auth', [
                'channel_name' => $channel,
                'socket_id' => '123.456',
            ], ['X-CSRF-TOKEN' => 'known-csrf-token']);
    }
}
