<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Services\PortalAuthService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class PortalAuthServiceTest extends TestCase
{
    private const LOGIN_PAGE = '<html><head><title>Login</title></head><body>'
        .'<form action="?mod=loginact" method="post">'
        .'<input type="text" name="txt_username"><input type="password" name="txt_password">'
        .'<button type="submit">Masuk</button></form></body></html>';

    public function test_a_login_form_opened_between_table_rows_keeps_its_fields(): void
    {
        $service = new PortalAuthService;
        (new \ReflectionProperty(PortalAuthService::class, 'client'))->setValue($service, new Client([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], '<!DOCTYPE html><html><head><title>Login</title></head><body>'
                .'<form action="?mod=cari"><input type="text" name="q"></form>'
                .'<table><tr><td>Login Mahasiswa</td></tr><form action="?mod=masuk" method="post"><input type="hidden" name="token" value="abc">'
                .'<tr><td><input type="text" name="txt_username"></td></tr><tr><td><input type="password" name="txt_password" required></td></tr>'
                .'</form></table></body></html>')])),
        ]));

        $form = $service->analyzeLoginForm();

        $this->assertSame('https://portal.invalid/portal/mahasiswa/?mod=masuk', $form['action']);
        $this->assertSame(['token', 'txt_username', 'txt_password'], array_keys($form['fields']));
        $this->assertSame(['type' => 'hidden', 'value' => 'abc', 'required' => false], $form['fields']['token']);
        $this->assertTrue($form['fields']['txt_password']['required']);
    }

    public function test_unknown_login_response_is_not_successful(): void
    {
        $service = new PortalAuthService;

        $result = $this->invokeNonPublicMethod($service, 'isLoginSuccessful', [
            '<html><body><p>Maintenance window</p></body></html>',
            [],
        ]);

        $this->assertFalse($result);
    }

    public function test_session_loading_without_followable_success_is_not_successful(): void
    {
        // The meta refresh of the "session loading" page is followed, and the portal does not answer it.
        $this->answerPortalRequestsAsUnreachable();
        $service = new PortalAuthService;

        $result = $this->invokeNonPublicMethod($service, 'isLoginSuccessful', [
            '<html><head><meta http-equiv="refresh" content="0;url=?mod=student&act=loading"></head><body>Session Loading</body></html>',
            [],
        ]);

        $this->assertFalse($result);
    }

    public function test_blank_or_minimal_session_page_is_expired(): void
    {
        $service = new PortalAuthService;

        $this->assertTrue($service->isSessionExpired(''));
        $this->assertTrue($service->isSessionExpired('<html><body></body></html>'));
        $this->assertTrue($service->isSessionExpired('<html><body>OK</body></html>'));
    }

    public function test_dashboard_markers_are_not_expired(): void
    {
        $service = new PortalAuthService;

        $html = '<html><body><a href="?mod=logout">Logout</a><h1>Dashboard Mahasiswa</h1></body></html>';

        $this->assertFalse($service->isSessionExpired($html));
    }

    public function test_expiry_wording_in_body_only_is_not_expired(): void
    {
        $service = new PortalAuthService;

        $html = '<html><head><title>Dashboard Mahasiswa</title></head><body>'
            .'<script>if (data === "silahkan login") { window.location.reload(); }</script>'
            .str_repeat('<p>Selamat datang di portal mahasiswa.</p>', 5)
            .'</body></html>';

        $this->assertFalse($service->isSessionExpired($html));
    }

    public function test_expiry_wording_in_title_is_expired(): void
    {
        $service = new PortalAuthService;

        $html = '<html><head><title>Session Expired</title></head><body>'
            .str_repeat('<p>Silahkan login kembali.</p>', 5)
            .'</body></html>';

        $this->assertTrue($service->isSessionExpired($html));
    }

    public function test_a_login_answered_with_the_login_form_again_refuses_the_password(): void
    {
        $exception = $this->failedLogin([
            new Response(200, [], self::LOGIN_PAGE.'<div class="alert">Username atau password salah</div>'),
        ]);

        $this->assertTrue(PortalAuthService::refusedPassword($exception));
    }

    public function test_a_login_whose_dashboard_is_the_login_form_refuses_the_password(): void
    {
        // The portal answers a wrong password with its own page (which links
        // back to /portal/mahasiswa/), then never lets the dashboard open.
        $exception = $this->failedLogin([
            new Response(200, [], '<html><body><script>window.location="/portal/mahasiswa/";</script></body></html>'),
            new Response(200, [], self::LOGIN_PAGE),
        ]);

        $this->assertSame('Campus session has expired', $exception->getMessage());
        $this->assertTrue(PortalAuthService::refusedPassword($exception));
    }

    public function test_a_login_answered_with_a_maintenance_page_does_not_refuse_the_password(): void
    {
        $exception = $this->failedLogin([
            new Response(200, [], '<html><head><title>Pemeliharaan</title></head><body><p>Terjadi kesalahan pada server. Hubungi salah satu admin.</p></body></html>'),
        ]);

        $this->assertSame(401, $exception->getCode());
        $this->assertFalse(PortalAuthService::refusedPassword($exception));
    }

    public function test_a_login_whose_dashboard_comes_back_blank_does_not_refuse_the_password(): void
    {
        $exception = $this->failedLogin([
            new Response(200, [], '<html><body><h1>Dashboard Mahasiswa</h1></body></html>'),
            new Response(200, [], ''),
        ]);

        $this->assertFalse(PortalAuthService::refusedPassword($exception));
    }

    public function test_only_authentication_failures_can_refuse_a_password(): void
    {
        $this->assertTrue(PortalAuthService::refusedPassword(PortalAuthenticationException::invalidCredentials()));
        $this->assertFalse(PortalAuthService::refusedPassword(PortalAuthService::unconfirmedRefusal(PortalAuthenticationException::invalidCredentials())));
        $this->assertFalse(PortalAuthService::refusedPassword(PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'timeout')));
    }

    public function test_portal_url_guard_rejects_external_login_action(): void
    {
        $this->expectException(\App\Exceptions\PortalScrapingException::class);

        \App\Services\PortalUrlGuard::resolve(
            'https://evil.example.test/login',
            'https://my.cic.ac.id/portal/mahasiswa/'
        );
    }

    /**
     * Logs in against canned portal answers (the login page first, then
     * `$answers`) and returns what the login threw. Nothing leaves the test.
     *
     * @param  list<Response>  $answers
     */
    private function failedLogin(array $answers): PortalAuthenticationException
    {
        $service = new PortalAuthService;
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], self::LOGIN_PAGE),
            ...$answers,
        ]))]);
        (new \ReflectionProperty($service, 'client'))->setValue($service, $client);

        try {
            $service->login('20990000001', 'password-lama');
        } catch (PortalAuthenticationException $exception) {
            return $exception;
        }

        $this->fail('The login was expected to fail.');
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokeNonPublicMethod(object $instance, string $method, array $arguments): mixed
    {
        $reflectionMethod = new \ReflectionMethod($instance, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($instance, $arguments);
    }
}
