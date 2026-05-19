<?php

declare(strict_types=1);

namespace App\Services;

class PortalHttpClientOptions
{
    /**
     * @return array{curl?: array<int, string>}
     */
    public static function secureDns(): array
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
