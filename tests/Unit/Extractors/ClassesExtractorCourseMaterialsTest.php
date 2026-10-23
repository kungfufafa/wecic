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
     * @return array<string, array{string}>
     */
    public static function lecturerLinksInTheDescription(): array
    {
        return [
            'meet link' => ['<p>Link kelas: <a href="https://meet.google.com/abc-defg-hij">https://meet.google.com/abc-defg-hij</a></p>'],
            'two short links' => ['<p>Tugas: <a href="https://s.id/Tugas1_DataGovernance">s.id/Tugas1</a> dan <a href="https://s.id/Rubrik">s.id/Rubrik</a></p>'],
            'drive link with an id parameter' => ['<p>Slide: <a href="https://drive.google.com/open?id=XYZ">drive</a></p>'],
        ];
    }

    #[DataProvider('lecturerLinksInTheDescription')]
    public function test_a_link_in_the_description_does_not_replace_the_meeting_join_link(string $descriptionLink): void
    {
        $html = str_replace(
            '<p>Manajemen Material</p>',
            '<p>Manajemen Material</p>'.$descriptionLink,
            (string) file_get_contents(base_path('tests/Fixtures/portal/sipandai_course_detail.html')),
            $replacements
        );
        $this->assertSame(1, $replacements);

        $materials = $this->extractMaterials($html);

        $this->assertSame('?mod=sipandai&act=discuss&id=30061&courses_id=8987', $materials[0]['join_link']['href']);
        $this->assertSame('Join Pertemuan 5', $materials[0]['join_link']['text']);
        $this->assertSame(5, $materials[0]['meeting_number']);
        $this->assertStringStartsWith("Modul-modul ERP\n1.Keuangan", $materials[0]['description']);
        $this->assertStringContainsString("Manajemen Material\n", $materials[0]['description']);
        $this->assertStringContainsString(trim(strip_tags($descriptionLink)), $materials[0]['description']);
        $this->assertStringNotContainsString('Join Pertemuan', $materials[0]['description']);
        $this->assertSame('?mod=sipandai&act=discuss&id=29524&courses_id=8987', $materials[1]['join_link']['href']);
    }

    public function test_materials_do_not_carry_the_raw_html_of_their_cell(): void
    {
        $inlineImage = '<p><img src="data:image/png;base64,'.str_repeat('iVBORw0KGgoAAAANSUhEUg', 5000).'"></p>';
        $html = str_replace(
            '<p>Manajemen Material</p>',
            '<p>Manajemen Material</p>'.$inlineImage,
            (string) file_get_contents(base_path('tests/Fixtures/portal/sipandai_course_detail.html')),
            $replacements
        );
        $this->assertSame(1, $replacements);

        $materials = $this->extractMaterials($html);

        $this->assertCount(5, $materials);
        foreach ($materials as $material) {
            $this->assertArrayNotHasKey('content', $material);
        }
        $this->assertStringNotContainsString('base64', (string) json_encode($materials));
        $this->assertStringEndsWith('Manajemen Material', $materials[0]['description']);
        $this->assertSame('Rabu, 05 November 2025', $materials[0]['date']);
        $this->assertSame('16:00', $materials[0]['time']);
        $this->assertSame('?mod=sipandai&act=discuss&id=30061&courses_id=8987', $materials[0]['join_link']['href']);
    }

    public function test_the_join_button_is_found_by_its_label_when_the_link_is_not_a_discuss_page(): void
    {
        $materials = $this->extractMaterials(<<<'HTML'
            <div class="card">
                <div class="header"><h5 class="title">Materi Perkuliahan Algoritma</h5></div>
                <table>
                    <tr>
                        <td>
                            <b><h6>Materi Pertemuan 2 Pokok Bahasan : Rekursi</h6></b>
                            Rabu, 05 November 2025, Jam : 16:00
                            <b><h6>Uraian Pokok Pembahasan : </h6></b>
                            <p>Lihat <a href="https://youtu.be/abc">video</a> sebelum kelas</p>
                            <a href="?mod=sipandai&act=pertemuan&id=41&courses_id=9310"><button type="button">Join Pertemuan 2</button></a>
                            <br/><hr/>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <b><h6>Materi Pertemuan 1 Pokok Bahasan : Pengantar</h6></b>
                            <b><h6>Uraian Pokok Pembahasan : </h6></b>
                            <p>Bacaan: <a href="https://example.test/bab1.pdf">bab 1</a></p>
                        </td>
                    </tr>
                </table>
            </div>
            HTML);

        $this->assertSame('?mod=sipandai&act=pertemuan&id=41&courses_id=9310', $materials[0]['join_link']['href']);
        $this->assertSame('Lihat video sebelum kelas', $materials[0]['description']);
        $this->assertArrayNotHasKey('join_link', $materials[1], 'A lecturer link is never taken for the join button.');
        $this->assertSame('Bacaan: bab 1', $materials[1]['description']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractMaterials(string $html): array
    {
        $extractor = new ClassesExtractor(Mockery::mock(PortalSessionManager::class));

        return (new \ReflectionMethod($extractor, 'extractCourseMaterials'))->invoke($extractor, new Crawler($html));
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
