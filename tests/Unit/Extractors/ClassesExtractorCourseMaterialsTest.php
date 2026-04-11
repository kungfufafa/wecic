<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class ClassesExtractorCourseMaterialsTest extends TestCase
{
    use InteractsWithPortalFixtures;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_uses_meeting_number_from_scraped_material_instead_of_array_index(): void
    {
        /** @var ClassesExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(
            ClassesExtractor::class,
            'tests/Fixtures/portal/sipandai_course_detail.html'
        );

        $data = $extractor->extractClassDetail('fake-session-token', [
            'id' => '1234',
            'dosen_id' => '5678',
            'course_id' => '9999',
        ]);

        $materials = $data['course_materials'];

        $this->assertSame(5, $materials[0]['meeting_number']);
        $this->assertSame(4, $materials[1]['meeting_number']);
        $this->assertSame(3, $materials[2]['meeting_number']);
        $this->assertStringNotContainsString('Join Pertemuan', $materials[0]['description']);
    }
}
