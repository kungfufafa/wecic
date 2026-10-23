<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalPasswordService;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalPasswordServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_inspects_password_form_without_returning_field_values(): void
    {
        $service = $this->mockPasswordService();

        $service->shouldReceive('get')
            ->once()
            ->andReturn($this->passwordFormHtml());

        $data = $service->inspectPasswordForm('fake-session-token');

        $this->assertSame('https://portal.invalid/portal/mahasiswa/?mod=student&act=passwordact', $data['action_url']);
        $this->assertSame('POST', $data['method']);
        $this->assertArrayHasKey('data[passwordold]', $data['fields']);
        $this->assertFalse($data['fields']['data[passwordold]']['has_value']);
        $this->assertContains('data[passwordold]', $data['password_fields']);
    }

    public function test_password_form_rejects_external_action_url(): void
    {
        $service = $this->mockPasswordService();

        $service->shouldReceive('get')
            ->once()
            ->andReturn(<<<'HTML'
            <html><body>
                <form method="post" action="https://evil.example.test/steal">
                    <input type="password" name="data[passwordold]" />
                    <input type="password" name="data[password]" />
                    <input type="password" name="data[konfirm]" />
                </form>
            </body></html>
            HTML);

        $this->expectException(\App\Exceptions\PortalScrapingException::class);

        $service->inspectPasswordForm('fake-session-token');
    }

    public function test_it_posts_password_change_payload_using_upstream_field_names(): void
    {
        $service = $this->mockPasswordService();

        $service->shouldReceive('get')
            ->once()
            ->andReturn($this->passwordFormHtml());

        $service->shouldReceive('dispatchPasswordRequest')
            ->once()
            ->withArgs(function (string $method, string $url, array $payload, string $referer): bool {
                $this->assertSame('POST', $method);
                $this->assertSame('https://portal.invalid/portal/mahasiswa/?mod=student&act=passwordact', $url);
                $this->assertSame('https://portal.invalid/portal/mahasiswa/?mod=student&act=password', $referer);
                $this->assertSame('old-secret', $payload['data[passwordold]']);
                $this->assertSame('new-secret', $payload['data[password]']);
                $this->assertSame('new-secret', $payload['data[konfirm]']);

                return true;
            })
            ->andReturn(new Response(200, [], '<div class="alert alert-success">Password berhasil diubah</div>'));

        $result = $service->changePassword('fake-session-token', 'old-secret', 'new-secret', 'new-secret');

        $this->assertTrue($result['success']);
        $this->assertSame('Password berhasil diubah', $result['message']);
        $this->assertSame([
            'data[passwordold]',
            'data[password]',
            'data[konfirm]',
        ], $result['data']['sent_fields']);
    }

    private function mockPasswordService(): MockInterface&PortalPasswordService
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getSessionCookieJar')
                ->once()
                ->with('fake-session-token')
                ->andReturn(new CookieJar);
        });

        return Mockery::mock(PortalPasswordService::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    private function passwordFormHtml(): string
    {
        return <<<'HTML'
            <html>
                <body>
                    <form method="post" action="?mod=student&act=passwordact">
                        <input type="password" name="data[passwordold]" />
                        <input type="password" name="data[password]" />
                        <input type="password" name="data[konfirm]" />
                        <button type="submit">Simpan</button>
                    </form>
                </body>
            </html>
        HTML;
    }
}
