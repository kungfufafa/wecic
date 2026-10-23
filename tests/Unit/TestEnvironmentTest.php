<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extractors\KhsExtractor;
use App\Services\PortalAuthService;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * What phpunit.xml sets up for every test.
 */
class TestEnvironmentTest extends TestCase
{
    public function test_logs_written_by_tests_never_reach_the_application_log(): void
    {
        $this->assertSame('null', config('logging.default'));

        $handlers = Log::channel()->getLogger()->getHandlers();

        $this->assertNotEmpty($handlers);
        $this->assertContainsOnlyInstancesOf(NullHandler::class, $handlers);
    }

    public function test_tests_never_touch_the_development_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_every_portal_url_points_at_an_unroutable_host(): void
    {
        $urls = array_filter(config('portal'), static fn (mixed $value, string $key): bool => is_string($value) && (str_ends_with($key, '_url') || $key === 'login_action'), ARRAY_FILTER_USE_BOTH);

        $this->assertNotEmpty($urls);

        foreach ($urls as $key => $url) {
            $this->assertSame('portal.invalid', parse_url($url, PHP_URL_HOST), "portal.{$key}");
        }

        $this->assertSame('portal.invalid', parse_url(PortalEndpointRegistry::url('login_page'), PHP_URL_HOST));
        $this->assertSame('', config('portal.scraping.secure_dns_url'));
    }

    public function test_tests_carry_no_real_ai_key_and_broadcast_nowhere(): void
    {
        $this->assertSame('', config('services.gemini.key'));
        $this->assertSame('null', config('broadcasting.default'));
    }

    public function test_a_portal_request_the_test_did_not_mock_fails_the_test(): void
    {
        $started = microtime(true);

        try {
            $this->portalClient()->get(PortalEndpointRegistry::url('login_page'));
            $this->fail('The request was sent.');
        } catch (AssertionFailedError $failure) {
            $this->assertStringStartsWith('A portal request the test did not mock was sent: GET https://portal.invalid/portal/mahasiswa/', $failure->getMessage());
        }

        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertSame(['GET https://portal.invalid/portal/mahasiswa/'], $this->forgetUnmockedPortalRequests());
    }

    public function test_a_failure_the_code_under_test_swallows_still_fails_the_test(): void
    {
        try {
            $this->portalClient()->get('https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=dashboard');
        } catch (\Throwable) {
            // Code under test that catches everything.
        }

        $this->callBeforeApplicationDestroyedCallbacks();
        $failure = $this->callbackException;
        $this->callbackException = null;

        $this->assertInstanceOf(AssertionFailedError::class, $failure);
        $this->assertStringContainsString('GET https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=dashboard', $failure->getMessage());
        $this->assertSame([], $this->forgetUnmockedPortalRequests());
    }

    public function test_every_scraper_the_container_builds_is_guarded(): void
    {
        $scraper = app(KhsExtractor::class);
        $client = (new \ReflectionProperty(PortalScrapingService::class, 'client'))->getValue($scraper);

        try {
            $client->get(PortalEndpointRegistry::url('dashboard'));
            $this->fail('The request was sent.');
        } catch (AssertionFailedError) {
        }

        $this->assertCount(1, $this->forgetUnmockedPortalRequests());
    }

    public function test_a_portal_client_a_test_builds_itself_is_guarded_too(): void
    {
        $clients = [
            (new \ReflectionProperty(PortalAuthService::class, 'client'))->getValue(new PortalAuthService),
            (new \ReflectionProperty(PortalScrapingService::class, 'client'))->getValue(new SipandaiSubmissionService($this->createMock(PortalSessionManager::class))),
        ];

        foreach ($clients as $client) {
            try {
                $client->post((string) config('portal.login_action'), ['form_params' => ['txt_username' => '20990000001']]);
                $this->fail('The request was sent.');
            } catch (AssertionFailedError $failure) {
                $this->assertStringContainsString('POST https://portal.invalid/portal/mahasiswa/?mod=loginact', $failure->getMessage());
            }
        }

        $this->assertCount(2, $this->forgetUnmockedPortalRequests());
    }

    public function test_a_client_the_test_gives_its_own_handler_is_not_affected(): void
    {
        $service = new PortalAuthService;
        (new \ReflectionProperty(PortalAuthService::class, 'client'))->setValue($service, new Client([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], '<html><body><form action="?mod=loginact" method="post"><input type="text" name="txt_username"><input type="password" name="txt_password"></form></body></html>')])),
        ]));

        $this->assertArrayHasKey('txt_password', $service->analyzeLoginForm()['fields']);
        $this->assertSame([], $this->forgetUnmockedPortalRequests());
    }

    public function test_a_test_can_make_the_portal_unreachable(): void
    {
        $this->answerPortalRequestsAsUnreachable();

        try {
            $this->portalClient()->get(PortalEndpointRegistry::url('login_page'));
            $this->fail('The request was sent.');
        } catch (ConnectException $exception) {
            $this->assertStringContainsString('portal.invalid', $exception->getMessage());
        }

        $this->assertSame([], $this->forgetUnmockedPortalRequests());
    }

    public function test_an_http_call_the_test_did_not_fake_throws(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://generativelanguage.googleapis.com/v1beta/models');
    }

    private function portalClient(): Client
    {
        return (new \ReflectionProperty(PortalAuthService::class, 'client'))->getValue(app(PortalAuthService::class));
    }

    /**
     * Read and clear what the guard recorded, so a request a test sends on
     * purpose does not fail it at tear down.
     *
     * @return list<string>
     */
    private function forgetUnmockedPortalRequests(): array
    {
        $recorded = new \ReflectionProperty(TestCase::class, 'unmockedPortalRequests');
        $requests = $recorded->getValue($this);
        $recorded->setValue($this, []);

        return $requests;
    }
}
