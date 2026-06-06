<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalScrapingException;
use Illuminate\Support\Str;

class PortalUrlGuard
{
    public static function resolve(string $action, string $referenceUrl): string
    {
        $action = trim(html_entity_decode($action, ENT_QUOTES, 'UTF-8'));

        if ($action === '' || $action === '#') {
            return self::assertAllowed($referenceUrl);
        }

        if (Str::startsWith($action, ['http://', 'https://'])) {
            return self::assertAllowed($action);
        }

        if (Str::startsWith($action, '?')) {
            return self::assertAllowed(Str::before($referenceUrl, '?').$action);
        }

        if (Str::startsWith($action, '/')) {
            return self::assertAllowed(rtrim((string) config('portal.base_url'), '/').'/'.ltrim($action, '/'));
        }

        return self::assertAllowed(rtrim(Str::beforeLast($referenceUrl, '/'), '/').'/'.$action);
    }

    public static function assertAllowed(string $url): string
    {
        $baseHost = (string) parse_url((string) config('portal.base_url'), PHP_URL_HOST);
        $urlHost = (string) parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'https') {
            throw PortalScrapingException::invalidResponse($url, 'Portal URL must use HTTPS');
        }

        if ($baseHost === '' || $urlHost === '' || ! hash_equals($baseHost, $urlHost)) {
            throw PortalScrapingException::invalidResponse($url, 'External portal host is not allowed');
        }

        return $url;
    }
}
