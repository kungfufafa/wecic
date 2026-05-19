<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalHttpClientOptions;
use Tests\TestCase;

class PortalHttpClientOptionsTest extends TestCase
{
    public function test_secure_dns_returns_cloudflare_doh_curl_option_when_supported(): void
    {
        config(['portal.scraping.secure_dns_url' => 'https://chrome.cloudflare-dns.com/dns-query']);

        $options = PortalHttpClientOptions::secureDns();

        if (! defined('CURLOPT_DOH_URL')) {
            $this->assertSame([], $options);

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
        $this->assertSame([], PortalHttpClientOptions::secureDns());

        config(['portal.scraping.secure_dns_url' => 'not-a-url']);
        $this->assertSame([], PortalHttpClientOptions::secureDns());
    }
}
