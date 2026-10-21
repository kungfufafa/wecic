<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\SipandaiClassesExtractor;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class SipandaiClassesExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_classes_from_sipandai_dashboard_fixture(): void
    {
        /** @var SipandaiClassesExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(
            SipandaiClassesExtractor::class,
            'tests/Fixtures/portal/sipandai_dashboard.html'
        );

        $data = $extractor->extractSipandaiClassesData('fake-session-token');

        $this->assertSame('SISTEM INFORMASI - S1', $data['student_info']['program_studi']);
        $this->assertCount(7, $data['classes']);

        $firstClass = $data['classes'][0];
        $this->assertSame('Senin', $firstClass['schedule']['day']);
        $this->assertSame('09:30:00', $firstClass['schedule']['start_time']);
        $this->assertSame('UC01024011', $firstClass['course_info']['code']);
        $this->assertSame('Bahasa Indonesia', $firstClass['course_info']['name']);
        $this->assertSame('Dosen Contoh Tiga, M.Pd', $firstClass['lecturer_info']['name']);
        $this->assertSame('8810', $firstClass['join_link']['params']['course_id']);
    }
}
