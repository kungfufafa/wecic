<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalMobileSession;
use App\Services\PortalMobileSessions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalBroadcastingTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_a_bearer_token_is_signed_into_its_own_sessions_channel(): void
    {
        [$token, $sessionId] = $this->tokenForCampusSession('campus-token-mobile');
        $channel = 'private-portal.session.'.$sessionId;

        $response = $this->withToken($token)->postJson('/api/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '123.456',
        ]);

        $response->assertOk();
        $expectedSignature = hash_hmac('sha256', '123.456:'.$channel, (string) config('broadcasting.connections.reverb.secret'));
        $this->assertSame(config('broadcasting.connections.reverb.key').':'.$expectedSignature, $response->json('auth'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_a_bearer_token_cannot_join_another_sessions_channel(): void
    {
        [$token] = $this->tokenForCampusSession('campus-token-mobile');
        [, $otherSessionId] = $this->tokenForCampusSession('campus-token-other');

        $this->withToken($token)->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.$otherSessionId,
            'socket_id' => '123.456',
        ])->assertForbidden();

        $this->withToken($token)->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.self::SESSION_ID,
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    public function test_an_unknown_or_ended_token_is_refused_a_channel(): void
    {
        [$token, $sessionId] = $this->tokenForCampusSession(null);

        $this->withToken($token)->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.$sessionId,
            'socket_id' => '123.456',
        ])->assertForbidden();
        $this->assertSame(0, PortalMobileSession::query()->count(), 'A token whose session holds no campus session is revoked.');

        $this->withToken('mycic_unknown-token')->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.$sessionId,
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    public function test_a_bearer_tokens_channel_sign_ins_are_throttled_per_campus_session(): void
    {
        config(['portal.security.channel_auth_rate_limit_attempts' => 2]);
        [$token, $sessionId] = $this->tokenForCampusSession('campus-token-mobile');
        [$otherToken, $otherSessionId] = $this->tokenForCampusSession('campus-token-other');

        foreach (['/api/broadcasting/auth', '/api/v1/broadcasting/auth'] as $path) {
            $this->withToken($token)->postJson($path, [
                'channel_name' => 'private-portal.session.'.$sessionId,
                'socket_id' => '123.456',
            ])->assertOk();
        }

        $this->withToken($token)->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.$sessionId,
            'socket_id' => '123.456',
        ])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->withToken($otherToken)->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.$otherSessionId,
            'socket_id' => '123.456',
        ])->assertOk();
    }

    public function test_the_browsers_channel_sign_ins_are_throttled_too(): void
    {
        config(['portal.security.channel_auth_rate_limit_attempts' => 1]);
        $channel = 'private-portal.session.'.self::SESSION_ID;

        $this->authorizeChannel($channel)->assertOk();
        $this->authorizeChannel($channel)->assertTooManyRequests();
    }

    /**
     * The database session handler stores the auth guard's user id in the
     * integer `sessions.user_id` column; a session id there is refused by
     * MySQL and PostgreSQL (SQLite would quietly keep the text).
     */
    public function test_a_token_channel_auth_on_database_sessions_leaves_the_user_id_empty(): void
    {
        $this->useDatabaseSessions();
        [$token, $sessionId] = $this->tokenForCampusSession('campus-token-mobile');

        $this->withToken($token)->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-portal.session.'.$sessionId,
            'socket_id' => '123.456',
        ])->assertOk();

        $this->assertTrue(DB::table('sessions')->where('id', $sessionId)->exists());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_a_cookie_channel_auth_on_database_sessions_leaves_the_user_id_empty(): void
    {
        $this->useDatabaseSessions();

        $this->authorizeChannel('private-portal.session.'.self::SESSION_ID)->assertOk();

        $this->assertTrue(DB::table('sessions')->where('id', self::SESSION_ID)->exists());
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_the_api_channel_endpoint_still_checks_csrf_for_the_cookie_session(): void
    {
        $this->withSession(['campus_session_token' => 'campus-token-broadcast', '_token' => 'known-csrf-token'])
            ->postJson('/api/broadcasting/auth', [
                'channel_name' => 'private-portal.session.'.session()->getId(),
                'socket_id' => '123.456',
            ])->assertStatus(419);
    }

    /**
     * Production keeps sessions server-side (`database`); phpunit.xml uses `array`.
     */
    private function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /**
     * A token naming a stored session that holds the campus token (or none).
     *
     * @return array{string, string} The token and its session id.
     */
    private function tokenForCampusSession(?string $campusToken): array
    {
        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId(null);
        $store->start();

        if ($campusToken !== null) {
            $store->put('campus_session_token', $campusToken);
        }

        $store->save();
        $sessionId = $store->getId();
        $store->flush();

        return [app(PortalMobileSessions::class)->issue($sessionId)['token'], $sessionId];
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
