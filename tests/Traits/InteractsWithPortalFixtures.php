<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;

trait InteractsWithPortalFixtures
{
    /**
     * Build a partial extractor instance that replays the given fixture HTML
     * instead of performing a real HTTP request.
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

        $extractor->shouldReceive('getCachedResponse')->andReturn(null);
        $extractor->shouldReceive('cacheResponse')->andReturnNull();
        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor;
    }
}
