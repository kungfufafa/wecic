<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Digital library links from portal/mahasiswa/?mod=student&act=ebook (F45)
 *
 * Each `.col-lg-6 > .card` holds a title (`.card-header h6`) and one link
 * (`.col-auto > a[href]`, sometimes with a trailing space). The portal's own
 * repository link becomes MyCIC's `/library/theses`; card images are never
 * hotlinked. When the page yields no link, `portal.library.fallback_links`
 * is served with `source: config`.
 *
 * @phpstan-type LibraryLink array{
 *     key: string,
 *     title: string,
 *     url: string|null,
 *     host: string|null,
 *     is_internal: bool,
 *     internal_route: string|null,
 *     requires_account: bool
 * }
 */
class LibraryExtractor extends PortalScrapingService
{
    public const REPOSITORY_ROUTE = '/library/theses';

    /**
     * Libraries that need their own account (the eBook link opens a sign-up page).
     */
    private const ACCOUNT_TITLE_PATTERN = '/\b(?:e-?book|digido)\b/iu';

    /**
     * @return array{found: bool, links: list<LibraryLink>, source: 'portal'|'config', extracted_at: string, _sync?: array<string, mixed>}
     */
    public function extractLibraryLinksData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting library links', ['session_token' => $sessionToken]);

            return $this->rememberResource(
                'library_links',
                [],
                function (): array {
                    $url = PortalEndpointRegistry::url('student_ebook');
                    $html = $this->get($url);

                    $this->ensureNotLoginPage($html, $url);

                    $data = $this->parseLibraryPage($html);

                    $this->logScraping('Library links extracted', [
                        'source' => $data['source'],
                        'link_count' => count($data['links']),
                    ]);

                    return $data;
                },
                static fn (array $data): bool => ($data['source'] ?? null) === 'portal'
            );
        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting library links', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('library links', $e->getMessage());
        }
    }

    /**
     * @return array{found: bool, links: list<LibraryLink>, source: 'portal'|'config', extracted_at: string}
     */
    public function parseLibraryPage(string $html): array
    {
        $content = $this->mainContent($this->createCrawler($html));
        $links = [];

        $cards = $content->filter('.col-lg-6 > .card');

        if ($cards->count() === 0) {
            $cards = $content->filter('.card');
        }

        $cards->each(function (Crawler $card) use (&$links): void {
            $titleNode = $card->filter('.card-header h6, .card-header .title, h6');
            $anchor = $card->filter('.col-auto > a[href]');
            $anchor = $anchor->count() > 0 ? $anchor : $card->filter('a[href]');

            if ($titleNode->count() === 0 || $anchor->count() === 0) {
                return;
            }

            $link = $this->buildLink($this->cellText($titleNode->first()), trim((string) $anchor->first()->attr('href')));

            if ($link !== null && ! isset($links[$link['key']])) {
                $links[$link['key']] = $link;
            }
        });

        if ($links === []) {
            return [
                'found' => false,
                'links' => self::fallbackLinks(),
                'source' => 'config',
                'extracted_at' => now()->toISOString(),
            ];
        }

        return [
            'found' => true,
            'links' => array_values($links),
            'source' => 'portal',
            'extracted_at' => now()->toISOString(),
        ];
    }

    /**
     * @return list<LibraryLink>
     */
    public static function fallbackLinks(): array
    {
        $links = [];

        foreach ((array) config('portal.library.fallback_links', []) as $link) {
            if (! is_array($link) || ! is_string($link['title'] ?? null)) {
                continue;
            }

            $internalRoute = is_string($link['internal_route'] ?? null) ? $link['internal_route'] : null;
            $url = is_string($link['url'] ?? null) ? trim($link['url']) : null;
            $host = $url !== null ? parse_url($url, PHP_URL_HOST) : null;

            $links[] = [
                'key' => self::linkKey($internalRoute ?? (string) $url),
                'title' => $link['title'],
                'url' => $internalRoute !== null ? null : $url,
                'host' => is_string($host) ? strtolower($host) : null,
                'is_internal' => $internalRoute !== null,
                'internal_route' => $internalRoute,
                'requires_account' => (bool) ($link['requires_account'] ?? preg_match(self::ACCOUNT_TITLE_PATTERN, $link['title']) === 1),
            ];
        }

        return $links;
    }

    /**
     * External http(s) links keep their URL; the portal's repository link maps
     * to MyCIC; any other portal page is dropped, so no portal action link
     * reaches the browser (K13)
     *
     * @return LibraryLink|null
     */
    private function buildLink(string $title, string $href): ?array
    {
        if ($title === '' || $href === '' || $href === '#') {
            return null;
        }

        if (preg_match('/[?&]act=repositori\b/i', $href) === 1) {
            return [
                'key' => self::linkKey(self::REPOSITORY_ROUTE),
                'title' => $title,
                'url' => null,
                'host' => null,
                'is_internal' => true,
                'internal_route' => self::REPOSITORY_ROUTE,
                'requires_account' => false,
            ];
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $portalHost = strtolower((string) parse_url((string) config('portal.base_url'), PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $host === $portalHost) {
            $this->logScrapingDebug('Library link skipped', ['title' => $title]);

            return null;
        }

        return [
            'key' => self::linkKey($href),
            'title' => $title,
            'url' => $href,
            'host' => $host,
            'is_internal' => false,
            'internal_route' => null,
            'requires_account' => preg_match(self::ACCOUNT_TITLE_PATTERN, $title) === 1,
        ];
    }

    private static function linkKey(string $target): string
    {
        return substr(sha1(strtolower(rtrim(trim($target), '/'))), 0, 12);
    }
}
