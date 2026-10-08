<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalDataCache;
use App\Services\PortalDataOwner;
use App\Services\PortalFetchPolicy;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PortalDataCacheTest extends TestCase
{
    private PortalDataCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = app(PortalDataCache::class);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_entries_belong_to_the_student_and_survive_a_relogin(): void
    {
        $owner = app(PortalDataOwner::class);
        $owner->remember('first-login-token', '20251030030', 600);
        $owner->remember('second-login-token', '20251030030', 600);
        $owner->remember('other-student-token', '20251030099', 600);

        $this->cache->remember('first-login-token', 'profile', [], fn (): array => ['nama' => 'Mahasiswa A']);

        $reused = $this->cache->remember('second-login-token', 'profile', [], fn (): array => $this->fail('Re-login must reuse the cached resource.'));
        $isolated = $this->cache->remember('other-student-token', 'profile', [], fn (): array => ['nama' => 'Mahasiswa B']);

        $this->assertSame(['data' => ['nama' => 'Mahasiswa A'], 'origin' => 'server_cache'], $reused);
        $this->assertSame(['data' => ['nama' => 'Mahasiswa B'], 'origin' => 'upstream'], $isolated);
    }

    public function test_sessions_without_a_known_owner_never_share_entries(): void
    {
        $this->cache->remember('anonymous-a', 'profile', [], fn (): array => ['nama' => 'A']);

        $result = $this->cache->remember('anonymous-b', 'profile', [], fn (): array => ['nama' => 'B']);

        $this->assertSame('upstream', $result['origin']);
        $this->assertSame(['nama' => 'B'], $result['data']);
    }

    public function test_parameter_order_and_scalar_types_do_not_split_entries(): void
    {
        $this->cache->remember('token', 'class_detail', ['id' => 2265, 'course_id' => 8810], fn (): array => ['name' => 'Algoritma']);

        $result = $this->cache->remember('token', 'class_detail', ['course_id' => '8810', 'id' => '2265'], fn (): array => ['name' => 'other']);

        $this->assertSame('server_cache', $result['origin']);
    }

    public function test_forced_fetch_bypasses_and_replaces_the_entry(): void
    {
        $this->cache->remember('token', 'profile', [], fn (): array => ['version' => 1]);

        $forced = $this->cache->remember('token', 'profile', [], fn (): array => ['version' => 2], forceFresh: true);
        $next = $this->cache->remember('token', 'profile', [], fn (): array => ['version' => 3]);

        $this->assertSame('upstream', $forced['origin']);
        $this->assertSame(['version' => 2], $next['data']);
    }

    public function test_invalidation_drops_every_parameter_variant_of_a_resource(): void
    {
        $this->cache->remember('token', 'class_detail', ['id' => '1'], fn (): array => ['v' => 'old-1']);
        $this->cache->remember('token', 'class_detail', ['id' => '2'], fn (): array => ['v' => 'old-2']);
        $this->cache->remember('token', 'profile', [], fn (): array => ['v' => 'profile']);

        $this->cache->invalidate('token', 'class_detail');

        $this->assertSame('upstream', $this->cache->remember('token', 'class_detail', ['id' => '1'], fn (): array => ['v' => 'new-1'])['origin']);
        $this->assertSame('upstream', $this->cache->remember('token', 'class_detail', ['id' => '2'], fn (): array => ['v' => 'new-2'])['origin']);
        $this->assertSame('server_cache', $this->cache->remember('token', 'class_detail', ['id' => '1'], fn (): array => [])['origin']);
        $this->assertSame('server_cache', $this->cache->remember('token', 'profile', [], fn (): array => [])['origin']);
    }

    public function test_invalidation_during_a_fetch_wins_over_the_fetched_entry(): void
    {
        $this->cache->remember('token', 'meeting_detail', ['meeting_id' => '445'], function (): array {
            // A portal action lands while the page is still downloading.
            usleep(1000);
            $this->cache->invalidate('token', 'meeting_detail');

            return ['status' => 'not_uploaded'];
        });

        $result = $this->cache->remember('token', 'meeting_detail', ['meeting_id' => '445'], fn (): array => ['status' => 'uploaded']);

        $this->assertSame(['status' => 'uploaded'], $result['data']);
    }

    public function test_results_rejected_by_the_cacheable_predicate_are_not_stored(): void
    {
        $this->cache->remember('token', 'class_detail', [], fn (): array => ['content_type' => 'error_page'], cacheable: fn (array $data): bool => $data['content_type'] !== 'error_page');

        $result = $this->cache->remember('token', 'class_detail', [], fn (): array => ['content_type' => 'class_info']);

        $this->assertSame('upstream', $result['origin']);
    }

    public function test_scrapers_follow_the_explicit_fetch_policy_instead_of_the_query_string(): void
    {
        $scraper = new class(Mockery::mock(PortalSessionManager::class)) extends PortalScrapingService
        {
            public int $fetches = 0;

            public function read(string $sessionToken): array
            {
                $this->sessionToken = $sessionToken;

                return $this->rememberResource('profile', [], function (): array {
                    $this->fetches++;

                    return ['fetch' => $this->fetches];
                });
            }
        };

        $scraper->read('token');
        $this->app['request']->query->set('fresh', '1');
        $cached = $scraper->read('token');
        $this->app['request']->query->remove('fresh');
        $forced = app(PortalFetchPolicy::class)->runFresh(fn (): array => $scraper->read('token'));

        $this->assertSame('server_cache', $cached['_sync']['data_origin']);
        $this->assertSame('upstream', $forced['_sync']['data_origin']);
        $this->assertTrue($forced['_sync']['requested_fresh']);
        $this->assertSame(2, $scraper->fetches);
    }
}
