<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PortalScrapingServiceCacheKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_cache_key_remains_stable_for_the_same_session_token(): void
    {
        $service = new class(Mockery::mock(PortalSessionManager::class)) extends PortalScrapingService
        {
            public function write(string $sessionToken, CookieJar $cookieJar, string $url, string $html): void
            {
                $this->sessionToken = $sessionToken;
                $this->cookieJar = $cookieJar;
                $this->cacheResponse($url, $html);
            }

            public function read(string $sessionToken, CookieJar $cookieJar, string $url): ?string
            {
                $this->sessionToken = $sessionToken;
                $this->cookieJar = $cookieJar;

                return $this->getCachedResponse($url);
            }
        };

        $service->write('session-123', new CookieJar, 'student_course_example', 'cached-value');

        $this->assertSame(
            'cached-value',
            $service->read('session-123', new CookieJar, 'student_course_example')
        );

        $this->assertNull(
            $service->read('session-456', new CookieJar, 'student_course_example')
        );
    }

    public function test_fresh_request_bypasses_existing_cache_entry(): void
    {
        $service = new class(Mockery::mock(PortalSessionManager::class)) extends PortalScrapingService
        {
            public function write(string $sessionToken, CookieJar $cookieJar, string $url, string $html): void
            {
                $this->sessionToken = $sessionToken;
                $this->cookieJar = $cookieJar;
                $this->cacheResponse($url, $html);
            }

            public function read(string $sessionToken, CookieJar $cookieJar, string $url): ?string
            {
                $this->sessionToken = $sessionToken;
                $this->cookieJar = $cookieJar;

                return $this->getCachedResponse($url);
            }
        };

        $service->write('session-123', new CookieJar, 'student_course_example', 'cached-value');
        $this->app['request']->query->set('fresh', '1');

        $this->assertNull(
            $service->read('session-123', new CookieJar, 'student_course_example')
        );

        $this->app['request']->query->remove('fresh');
    }
}
