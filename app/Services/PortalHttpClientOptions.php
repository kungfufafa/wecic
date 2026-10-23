<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Container\Container;

/**
 * Guzzle options every portal client (PortalAuthService, PortalScrapingService
 * and its subclasses) merges into its own defaults.
 */
class PortalHttpClientOptions
{
    /**
     * Container binding holding the Guzzle handler every portal client sends
     * through instead of the network. The test suite binds it
     * (tests/TestCase.php), and so does the e2e environment (bootstrap/e2e.php:
     * every portal request is refused), so a client built with `new` is guarded
     * like one the container builds.
     */
    public const HANDLER_BINDING = 'portal.http.handler';

    /**
     * Everything a portal client shares: secure DNS resolution and the
     * handler bound as HANDLER_BINDING.
     *
     * @return array{curl?: array<int, string>, handler?: callable}
     */
    public static function shared(): array
    {
        return [...self::dnsOverHttps(), ...self::boundHandler()];
    }

    /**
     * @return array{handler?: callable}
     */
    public static function boundHandler(): array
    {
        $container = Container::getInstance();

        if (! $container->bound(self::HANDLER_BINDING)) {
            return [];
        }

        $handler = $container->make(self::HANDLER_BINDING);

        return is_callable($handler) ? ['handler' => $handler] : [];
    }

    /**
     * @return array{curl?: array<int, string>}
     */
    private static function dnsOverHttps(): array
    {
        $secureDnsUrl = trim((string) config('portal.scraping.secure_dns_url', ''));

        if ($secureDnsUrl === '' || ! defined('CURLOPT_DOH_URL')) {
            return [];
        }

        if (filter_var($secureDnsUrl, FILTER_VALIDATE_URL) === false) {
            return [];
        }

        return [
            'curl' => [
                constant('CURLOPT_DOH_URL') => $secureDnsUrl,
            ],
        ];
    }
}
