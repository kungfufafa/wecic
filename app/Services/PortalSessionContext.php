<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;

/**
 * Request/job scoped upstream state shared by every scraper in that scope.
 *
 * One HTTP request or queue job typically runs several extractors for the same
 * session. They share one cookie jar (loaded once, without a session write per
 * extractor) and one page memo, so a page such as the SIPANDAI dashboard is
 * downloaded at most once per scope.
 */
class PortalSessionContext
{
    /**
     * @var array<string, CookieJar>
     */
    private array $cookieJars = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $pages = [];

    /**
     * @param  callable(): ?CookieJar  $resolve
     */
    public function cookieJar(string $sessionToken, callable $resolve): ?CookieJar
    {
        if (isset($this->cookieJars[$sessionToken])) {
            return $this->cookieJars[$sessionToken];
        }

        $cookieJar = $resolve();

        if ($cookieJar !== null) {
            $this->cookieJars[$sessionToken] = $cookieJar;
        }

        return $cookieJar;
    }

    /**
     * @param  callable(): string  $fetch
     */
    public function rememberPage(string $sessionToken, string $pageKey, callable $fetch): string
    {
        if (isset($this->pages[$sessionToken][$pageKey])) {
            return $this->pages[$sessionToken][$pageKey];
        }

        $html = $fetch();
        $this->pages[$sessionToken][$pageKey] = $html;

        return $html;
    }

    /**
     * Drop memoized pages after a state-changing request so follow-up reads
     * observe the portal's new state.
     */
    public function forgetPages(string $sessionToken): void
    {
        unset($this->pages[$sessionToken]);
    }

    public function forget(string $sessionToken): void
    {
        unset($this->cookieJars[$sessionToken], $this->pages[$sessionToken]);
    }
}
