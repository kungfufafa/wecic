<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalAuthService;
use Tests\TestCase;

class PortalAuthServiceTest extends TestCase
{
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

    public function test_portal_url_guard_rejects_external_login_action(): void
    {
        $this->expectException(\App\Exceptions\PortalScrapingException::class);

        \App\Services\PortalUrlGuard::resolve(
            'https://evil.example.test/login',
            'https://my.cic.ac.id/portal/mahasiswa/'
        );
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
