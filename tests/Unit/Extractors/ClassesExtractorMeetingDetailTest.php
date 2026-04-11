<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class ClassesExtractorMeetingDetailTest extends TestCase
{
    use InteractsWithPortalFixtures;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_assignment_materials_from_meeting_detail_fixture(): void
    {
        /** @var ClassesExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(
            ClassesExtractor::class,
            'tests/Fixtures/portal/meeting_assignment_detail.html'
        );

        $data = $extractor->extractMeetingDetail('fake-session-token', [
            'meeting_id' => '33118',
            'courses_id' => '9188',
        ]);

        $this->assertSame('Pokok Bahasan : Bussines Model', $data['materials']['main_topic']);
        $this->assertSame('../files/modul/ae614c557843b1df326cb29c57225459-3-mod-fa8.pdf', $data['materials']['main_material_iframe']);
        $this->assertSame('Pertemuan 3', $data['materials']['assignment_topic']);
        $this->assertSame('Kamis, 09 April 2026', $data['materials']['assignment_instructions']);
        $this->assertSame('../files/latihan/ae614c557843b1df326cb29c57225459-3-lat-fa8.pdf', $data['materials']['assignment_material_iframe']);
    }
}
