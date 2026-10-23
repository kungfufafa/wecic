<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * `portal.cache.resources` holds one TTL per resource the code actually
 * caches (PortalScrapingService::rememberResource(), PortalDataCache::remember()),
 * so a TTL never sits in the config without anything reading it.
 */
class PortalResourceCacheConfigTest extends TestCase
{
    public function test_every_configured_resource_ttl_belongs_to_a_cached_resource(): void
    {
        $source = implode("\n", array_map(
            static fn (SplFileInfo $file): string => $file->getContents(),
            File::allFiles(app_path())
        ));
        $resources = array_keys((array) config('portal.cache.resources'));

        $this->assertNotEmpty($resources);

        foreach ($resources as $resource) {
            $pattern = '/(?:rememberResource\(\s*|dataCache->remember\(\s*\$\w+,\s*)\''.preg_quote($resource, '/').'\'/';

            $this->assertMatchesRegularExpression($pattern, $source, "Nothing caches the resource [{$resource}] its TTL is configured for.");
        }
    }

    /**
     * The `lms_classes` dataset reads the SIPANDAI class list through the `sipandai_classes` resource.
     */
    public function test_the_lms_class_list_has_no_resource_ttl_of_its_own(): void
    {
        $this->assertNull(config('portal.cache.resources.lms_classes'));
        $this->assertIsInt(config('portal.cache.resources.sipandai_classes'));
    }
}
