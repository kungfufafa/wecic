<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use PHPUnit\Framework\Assert;

trait InteractsWithPortalFixtures
{
    /**
     * Build a partial extractor instance that replays the given fixture HTML
     * instead of performing a real HTTP request.
     *
     * Only the network `get()` is replaced: session handling, the resource
     * cache and every parsing step (`createCrawler()` included) run the real
     * code.
     *
     * @template T of \App\Services\PortalScrapingService
     *
     * @param  class-string<T>  $extractorClass
     * @return T
     */
    protected function makeExtractorWithFixture(string $extractorClass, string $fixture): object
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')
            ->andReturn(new CookieJar);

        $extractor = Mockery::mock($extractorClass, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $html = file_get_contents(base_path($fixture));

        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor;
    }

    /**
     * Extractor that receives a sanitized raw server page (`*_raw.html`)
     * exactly as the live portal sends it
     *
     * The fixture must keep the page's leading doctype, so DomCrawler parses it
     * with masterminds/html5 like a live response (no tbody insertion, no
     * foster parenting). The stubbed `get()` validates the page the way the
     * network `get()` does, then returns it; everything else is the real code.
     * Every requested URL is recorded in `$requestedUrls`. The fixture is a
     * path relative to the project root, or an absolute path.
     *
     * @template T of \App\Services\PortalScrapingService
     *
     * @param  class-string<T>  $extractorClass
     * @param  list<string>|null  $requestedUrls
     * @return T
     */
    protected function makeExtractorWithRawFixture(string $extractorClass, string $fixture, ?array &$requestedUrls = null): object
    {
        $this->assertFixtureParsesLikeLivePortal($fixture);

        $requestedUrls = [];
        $html = (string) file_get_contents($this->portalFixturePath($fixture));

        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);
        $sessionManager->shouldReceive('invalidateSession')->byDefault();

        $extractor = Mockery::mock($extractorClass, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')->andReturnUsing(function (string $url) use ($extractor, $html, &$requestedUrls): string {
            $requestedUrls[] = $url;
            (fn () => $this->validateResponse($html, $url))->call($extractor);

            return $html;
        });

        return $extractor;
    }

    /**
     * Fail unless DomCrawler would parse the fixture with its HTML5 parser,
     * as it does every live portal page (doctype first, after optional
     * whitespace or comments)
     */
    protected function assertFixtureParsesLikeLivePortal(string $fixture): void
    {
        $html = (string) file_get_contents($this->portalFixturePath($fixture));
        $position = stripos($html, '<!doctype html>');

        Assert::assertNotFalse($position, "{$fixture} must keep the raw page's <!doctype html>.");
        Assert::assertMatchesRegularExpression(
            '/^\x{FEFF}?\s*(<!--[^>]*?-->\s*)*$/u',
            substr($html, 0, (int) $position),
            "{$fixture} must start with its <!doctype html>."
        );
    }

    private function portalFixturePath(string $fixture): string
    {
        return str_starts_with($fixture, DIRECTORY_SEPARATOR) ? $fixture : base_path($fixture);
    }
}
