<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalScrapingException;
use Illuminate\Support\Str;

class PortalUrlGuard
{
    /**
     * Directories under /portal/files/ that only hold static campus files.
     *
     * @var list<string>
     */
    private const CAMPUS_FILE_DIRECTORIES = [
        'sap',
        'modul',
        'latihan',
        'soal',
        'tugasmhs',
        'skkm',
        'fotostudent',
        'skripsi/kolokium',
    ];

    private const CAMPUS_ROOT_FILE_PATTERN = '/^(?:SOP|Panduan)_.+\.pdf$/i';

    private const SERVER_SCRIPT_PATTERN = '/\.(?:php\d*|phtml|pht|phar|cgi|pl)(?:\.|$)/i';

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

    /**
     * Map a portal file reference (relative href or absolute portal URL) to the
     * canonical URL of a static file under /portal/files/.
     *
     * Dot-segments are resolved after percent-decoding, before the allowlist, so
     * the result can never point at a portal PHP endpoint (jobdel, logout, ...).
     */
    public static function campusFileUrl(string $reference): ?string
    {
        $reference = Str::before(trim($reference), '#');

        if ($reference === '' || str_contains($reference, '?') || self::hasControlCharacters($reference)) {
            return null;
        }

        $isAbsolute = str_starts_with($reference, '//') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $reference) === 1;
        $path = $isAbsolute ? self::portalUrlPath($reference) : $reference;

        if ($path === null) {
            return null;
        }

        $decodedPath = rawurldecode($path);

        if (preg_match('/%[0-9a-f]{2}/i', $decodedPath) === 1 || self::hasControlCharacters($decodedPath)) {
            return null;
        }

        $segments = self::removeDotSegments(str_replace('\\', '/', $decodedPath));

        $fileSegments = match (true) {
            $segments === null => null,
            array_slice($segments, 0, 2) === ['portal', 'files'] => array_slice($segments, 2),
            ! $isAbsolute && ($segments[0] ?? null) === 'files' => array_slice($segments, 1),
            default => null,
        };

        if ($fileSegments === null || ! self::isAllowedCampusFile($fileSegments)) {
            return null;
        }

        return rtrim((string) config('portal.base_url'), '/').'/portal/files/'.implode('/', array_map('rawurlencode', $fileSegments));
    }

    public static function assertCampusFileUrl(string $reference): string
    {
        return self::campusFileUrl($reference)
            ?? throw PortalScrapingException::invalidResponse($reference, 'Only static campus files under /portal/files/ can be downloaded');
    }

    private static function portalUrlPath(string $url): ?string
    {
        $parts = parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url);
        $baseHost = strtolower((string) parse_url((string) config('portal.base_url'), PHP_URL_HOST));

        if (
            ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || $baseHost === ''
            || ! hash_equals($baseHost, strtolower($parts['host'] ?? ''))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        ) {
            return null;
        }

        return $parts['path'] ?? '';
    }

    /**
     * @return list<string>|null
     */
    private static function removeDotSegments(string $path): ?array
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            if (trim($segment, ' .') === '') {
                return null;
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * @param  list<string>  $segments
     */
    private static function isAllowedCampusFile(array $segments): bool
    {
        if ($segments === [] || preg_grep(self::SERVER_SCRIPT_PATTERN, $segments) !== []) {
            return false;
        }

        if (count($segments) === 1) {
            return preg_match(self::CAMPUS_ROOT_FILE_PATTERN, $segments[0]) === 1;
        }

        $directory = implode('/', array_slice($segments, 0, -1));

        foreach (self::CAMPUS_FILE_DIRECTORIES as $allowedDirectory) {
            if ($directory === $allowedDirectory || str_starts_with($directory, $allowedDirectory.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
