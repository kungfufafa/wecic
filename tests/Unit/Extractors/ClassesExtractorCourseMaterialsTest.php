<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
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

    public function test_it_reads_material_dates_in_the_portal_format(): void
    {
        /** @var ClassesExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(
            ClassesExtractor::class,
            'tests/Fixtures/portal/sipandai_course_detail.html'
        );

        $materials = $extractor->extractClassDetail('fake-session-token', [
            'id' => '1234',
            'dosen_id' => '5678',
            'course_id' => '9999',
        ])['course_materials'];

        $this->assertSame('Rabu, 05 November 2025', $materials[0]['date']);
        $this->assertSame('16:00', $materials[0]['time']);
        $this->assertSame('Senin, 29 September 2025', $materials[4]['date']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function materialDateVariants(): array
    {
        return [
            'upper case' => ['RABU, 05 NOVEMBER 2025, Jam : 16:00', 'RABU, 05 NOVEMBER 2025'],
            'lower case' => ['rabu, 5 november 2025, Jam : 16:00', 'rabu, 5 november 2025'],
            'apostrophe in day name' => ["Jum'at, 07 Maret 2025, Jam : 08:00", "Jum'at, 07 Maret 2025"],
            'non-breaking spaces' => ['Sabtu,&nbsp;08&nbsp;Maret&nbsp;2025, Jam : 08:00', 'Sabtu, 08 Maret 2025'],
        ];
    }

    #[DataProvider('materialDateVariants')]
    public function test_it_reads_material_dates_regardless_of_case_and_spacing(string $dateLine, string $expectedDate): void
    {
        $extractor = new ClassesExtractor(Mockery::mock(PortalSessionManager::class));
        $crawler = new Crawler(<<<HTML
            <div class="card">
                <div class="header"><h5 class="title">Materi Perkuliahan Algoritma</h5></div>
                <table>
                    <tr>
                        <td>
                            <b><h6>Materi Pertemuan 2 Pokok Bahasan : Rekursi</h6></b>
                            {$dateLine}
                            <b><h6>Uraian Pokok Pembahasan : </h6></b>
                            <p>Fungsi rekursif</p>
                        </td>
                    </tr>
                </table>
            </div>
            HTML);

        $materials = (new \ReflectionMethod($extractor, 'extractCourseMaterials'))->invoke($extractor, $crawler);

        $this->assertSame($expectedDate, $materials[0]['date']);
        $this->assertSame(2, $materials[0]['meeting_number']);
    }
}
