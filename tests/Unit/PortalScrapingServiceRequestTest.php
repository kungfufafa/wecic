<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class PortalScrapingServiceRequestTest extends TestCase
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.request_delay' => 0]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_scrapers_in_one_scope_share_a_single_download_of_the_same_page(): void
    {
        $handler = new MockHandler([new Response(200, [], $this->authenticatedPage('first'))]);
        $sessionManager = $this->sessionManagerLoadingCookiesOnce();

        $first = $this->makeScraper($sessionManager, $handler);
        $second = $this->makeScraper($sessionManager, $handler);

        $this->assertSame($first->fetch('https://my.cic.ac.id/portal/sipandai/'), $second->fetch('https://my.cic.ac.id/portal/sipandai/'));
        $this->assertCount(1, $this->history);
    }

    public function test_state_changing_request_drops_memoized_pages(): void
    {
        $handler = new MockHandler([
            new Response(200, [], $this->authenticatedPage('before upload')),
            new Response(200, [], $this->authenticatedPage('upload accepted')),
            new Response(200, [], $this->authenticatedPage('after upload')),
        ]);

        $scraper = $this->makeScraper($this->sessionManagerLoadingCookiesOnce(), $handler);

        $scraper->fetch('https://my.cic.ac.id/portal/sipandai/?act=send');
        $scraper->submit('https://my.cic.ac.id/portal/sipandai/?act=sent');

        $this->assertStringContainsString('after upload', $scraper->fetch('https://my.cic.ac.id/portal/sipandai/?act=send'));
        $this->assertCount(3, $this->history);
    }

    public function test_login_form_response_invalidates_the_session_instead_of_being_parsed(): void
    {
        $loginPage = '<html><head><title>MyUCIC</title></head><body><form action="?mod=loginact" method="post">'
            .'<input type="text" name="txt_username"><input type="password" name="txt_password">'
            .str_repeat('<p>Silakan masuk.</p>', 10).'</form></body></html>';

        $sessionManager = $this->sessionManagerLoadingCookiesOnce();
        $sessionManager->shouldReceive('invalidateSession')
            ->once()
            ->with('session-token', 'login_page_detected', Mockery::type('array'), Mockery::type('array'));

        $scraper = $this->makeScraper($sessionManager, new MockHandler([new Response(200, [], $loginPage)]));

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionCode(403);

        $scraper->fetch('https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=profile');
    }

    public function test_authenticated_page_with_sipandai_sso_form_is_not_a_login_page(): void
    {
        $profilePage = (string) file_get_contents(base_path('tests/Fixtures/portal/profile.html'));

        $this->assertStringContainsString('loginact', $profilePage);
        $this->assertFalse(PortalScrapingService::isLoginPage($profilePage));
    }

    private function sessionManagerLoadingCookiesOnce(): Mockery\MockInterface
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->once()->with('session-token')->andReturn(new CookieJar);

        return $sessionManager;
    }

    private function makeScraper(PortalSessionManager $sessionManager, MockHandler $handler): object
    {
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));

        $scraper = new class($sessionManager) extends PortalScrapingService
        {
            public function useClient(Client $client): void
            {
                $this->client = $client;
            }

            public function fetch(string $url): string
            {
                $this->setSession('session-token');

                return $this->get($url);
            }

            public function submit(string $url): string
            {
                $this->setSession('session-token');

                return $this->post($url);
            }
        };

        $scraper->useClient(new Client(['handler' => $stack]));

        return $scraper;
    }

    private function authenticatedPage(string $marker): string
    {
        return '<html><head><title>Portal - SiPANDAI CIC</title></head><body>'
            .'<a href="controllers/logout.php">Log Out</a><p>'.$marker.'</p>'
            .str_repeat('<p>Konten portal.</p>', 10).'</body></html>';
    }
}
