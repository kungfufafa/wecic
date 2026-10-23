<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Behind a reverse proxy, load balancer or CDN every request reaches MyCIC
 * from the proxy's address, so the per-address limits (login failures,
 * actions) would pool every student into one budget. Listing the proxy in
 * `portal.security.trusted_proxies` (comma-separated addresses or CIDR
 * ranges, or "*" for whichever proxy calls MyCIC) takes the client address
 * from its X-Forwarded-For header instead.
 *
 * Unset (the default) no proxy is trusted and the header is ignored: on a
 * server clients reach directly, anyone could forge it to dodge those
 * limits. Read per request, like the framework's own
 * `trustedproxy.proxies` fallback, which still applies when this is unset.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $configured = config('portal.security.trusted_proxies');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map(strval(...), $configured));
        }

        return parent::proxies();
    }
}
