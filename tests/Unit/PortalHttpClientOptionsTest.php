<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalAuthService;
use App\Services\PortalHttpClientOptions;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Tests\TestCase;

class PortalHttpClientOptionsTest extends TestCase
{
    public function test_secure_dns_returns_cloudflare_doh_curl_option_when_supported(): void
    {
        config(['portal.scraping.secure_dns_url' => 'https://chrome.cloudflare-dns.com/dns-query']);

        $options = PortalHttpClientOptions::shared();

        if (! defined('CURLOPT_DOH_URL')) {
            $this->assertArrayNotHasKey('curl', $options);

            return;
        }

        $this->assertSame(
            'https://chrome.cloudflare-dns.com/dns-query',
            $options['curl'][CURLOPT_DOH_URL] ?? null
        );
    }

    public function test_secure_dns_ignores_empty_or_invalid_urls(): void
    {
        config(['portal.scraping.secure_dns_url' => '']);
        $this->assertArrayNotHasKey('curl', PortalHttpClientOptions::shared());

        config(['portal.scraping.secure_dns_url' => 'not-a-url']);
        $this->assertArrayNotHasKey('curl', PortalHttpClientOptions::shared());
    }

    public function test_without_the_binding_a_portal_client_keeps_guzzles_own_handler(): void
    {
        $this->app->offsetUnset(PortalHttpClientOptions::HANDLER_BINDING);

        $this->assertSame([], PortalHttpClientOptions::shared());
    }

    public function test_every_portal_client_sends_through_the_bound_handler(): void
    {
        $guards = [];
        $this->app->bind(PortalHttpClientOptions::HANDLER_BINDING, function () use (&$guards): HandlerStack {
            return $guards[] = HandlerStack::create();
        });

        $this->assertSame(PortalHttpClientOptions::shared()['handler'], end($guards));

        $clients = [
            $this->clientOf(new PortalAuthService, PortalAuthService::class),
            $this->clientOf(new SipandaiSubmissionService($this->createMock(PortalSessionManager::class)), PortalScrapingService::class),
            $this->clientOf(app(PortalAuthService::class), PortalAuthService::class),
        ];

        foreach ($clients as $client) {
            $this->assertContains($client->getConfig('handler'), $guards);
        }

        // Each client gets a stack of its own, as without the binding.
        $this->assertCount(3, array_unique(array_map(static fn (Client $client): int => spl_object_id($client->getConfig('handler')), $clients)));
    }

    private function clientOf(object $service, string $declaringClass): Client
    {
        $client = (new \ReflectionProperty($declaringClass, 'client'))->getValue($service);
        $this->assertInstanceOf(Client::class, $client);

        return $client;
    }
}
