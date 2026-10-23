<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalClassCatalog;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The class link parameters come from the SIPANDAI class list as the merged
 * class list reads it.
 */
class PortalClassCatalogTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const TOKEN = 'token-class-catalog';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_classes_are_found_by_class_id_and_by_course_id_from_the_sipandai_class_list(): void
    {
        $this->instance(
            SipandaiClassesExtractor::class,
            $this->makeExtractorWithFixture(SipandaiClassesExtractor::class, 'tests/Fixtures/portal/sipandai_dashboard.html')
        );
        $catalog = app(PortalClassCatalog::class);

        $this->assertSame(['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810'], $catalog->findById(self::TOKEN, '2265'));
        $this->assertSame(['id' => '3142', 'dosen_id' => '1863', 'course_id' => '8861'], $catalog->findByCourseId(self::TOKEN, '8861'));
        $this->assertNull($catalog->findById(self::TOKEN, '999999'));
    }

    public function test_a_class_without_a_join_link_is_left_out(): void
    {
        $this->instance(SipandaiClassesExtractor::class, Mockery::mock(SipandaiClassesExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSipandaiClassesData')->once()->with(self::TOKEN)->andReturn(['classes' => [
                ['course_info' => ['name' => 'Tanpa Link'], 'join_link' => []],
                ['course_info' => ['name' => 'Basis Data'], 'join_link' => ['params' => ['id' => '10021', 'dosen_id' => '5240', 'course_id' => '10012']]],
            ]]);
        }));
        $catalog = app(PortalClassCatalog::class);

        $this->assertSame(['id' => '10021', 'dosen_id' => '5240', 'course_id' => '10012'], $catalog->findById(self::TOKEN, '10021'));
        $this->assertSame(['id' => '10021', 'dosen_id' => '5240', 'course_id' => '10012'], $catalog->findByCourseId(self::TOKEN, '10012'));
    }
}
