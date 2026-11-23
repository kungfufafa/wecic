<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Whether an endpoint a student typed may be called by the server: HTTPS on
 * a host whose every address is public. A student's own endpoint must never
 * reach the server's own network (localhost, a private range, a cloud
 * metadata address), so it is checked when saved and again before each
 * request, since a name may resolve elsewhere later.
 */
class PublicAiEndpoint
{
    /**
     * Host names that only make sense inside a network.
     */
    private const PRIVATE_NAMES = '/(^localhost$|\.(localhost|local|internal|intranet|lan|home|test|invalid|example)$)/i';

    public function allows(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || preg_match(self::PRIVATE_NAMES, $host) === 1) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every IPv4 and IPv6 address a host name resolves to.
     *
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
