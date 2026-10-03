<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use Mockery;
use Tests\TestCase;

class PortalScrapingServiceValidationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_error_wording_in_body_without_error_title_is_accepted(): void
    {
        $service = $this->makeService();

        $html = '<html><head><title>Dashboard Mahasiswa</title></head><body>'
            .'<script>if (xhr.status >= 500) { showToast("server error occurred"); }</script>'
            .str_repeat('<p>Konten halaman portal.</p>', 10)
            .'</body></html>';

        $service->validate($html, 'https://my.cic.ac.id/dashboard');

        $this->assertTrue(true);
    }

    public function test_error_page_title_is_rejected(): void
    {
        $service = $this->makeService();

        $html = '<html><head><title>500 Internal Server Error</title></head><body>'
            .str_repeat('<p>ups</p>', 10)
            .'</body></html>';

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionMessage('Error page detected');

        $service->validate($html, 'https://my.cic.ac.id/dashboard');
    }

    public function test_session_expired_title_invalidates_session_with_attached_cookies(): void
    {
        $attachedCookies = [
            ['Name' => 'PHPSESSID', 'Value' => 'cookie-value', 'Domain' => 'my.cic.ac.id', 'Path' => '/'],
        ];

        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('invalidateSession')
            ->once()
            ->with(
                'session-token',
                'session_expired_indicator_detected',
                Mockery::on(fn (array $context): bool => ($context['indicator'] ?? null) === 'your session has expired'),
                Mockery::on(fn (array $cookies): bool => ($cookies[0]['Name'] ?? null) === 'PHPSESSID')
            );

        $service = new class($sessionManager) extends PortalScrapingService
        {
            public function adopt(array $cookies): void
            {
                $this->sessionToken = 'session-token';
                $this->attachedSessionCookies = $cookies;
            }

            public function validate(string $html, string $url): void
            {
                $this->validateResponse($html, $url);
            }
        };

        $service->adopt($attachedCookies);

        $html = '<html><head><title>Your Session Has Expired</title></head><body>'
            .str_repeat('<p>ups</p>', 10)
            .'</body></html>';

        $this->expectException(PortalScrapingException::class);

        $service->validate($html, 'https://my.cic.ac.id/dashboard');
    }

    public function test_blank_response_is_rejected_and_invalidates_session(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('invalidateSession')
            ->once()
            ->with('session-token', 'blank_response_detected', Mockery::on(fn (array $context): bool => isset($context['response_length'])), null);

        $service = new class($sessionManager) extends PortalScrapingService
        {
            public function adopt(): void
            {
                $this->sessionToken = 'session-token';
            }

            public function validate(string $html, string $url): void
            {
                $this->validateResponse($html, $url);
            }
        };

        $service->adopt();

        $this->expectException(PortalScrapingException::class);

        $service->validate('<html><body>ok</body></html>', 'https://my.cic.ac.id/dashboard');
    }

    public function test_html_title_extracts_and_unescapes_title_contents(): void
    {
        $this->assertSame(
            'Nilai & Transkrip',
            PortalScrapingService::htmlTitle('<html><head><title>Nilai &amp; Transkrip</title></head></html>')
        );
        $this->assertSame('', PortalScrapingService::htmlTitle('<html><body>no title</body></html>'));
    }

    private function makeService(): object
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldNotReceive('invalidateSession');

        return new class($sessionManager) extends PortalScrapingService
        {
            public function validate(string $html, string $url): void
            {
                $this->validateResponse($html, $url);
            }
        };
    }
}
