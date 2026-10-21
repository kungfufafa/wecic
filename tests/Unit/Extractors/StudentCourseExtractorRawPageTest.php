<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\StudentCourseExtractor;
use App\Services\PortalEndpointRegistry;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The schedule page (Jadwal Kuliah) as the live portal sends it (K14
 * sanitized copy), the first source of the semester number (K2): tables
 * without tbody, `&nbsp;` in every cell and two card headings, of which only
 * "Semester : <n>" carries the number.
 */
class StudentCourseExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const RAW_FIXTURE = 'tests/Fixtures/portal/student_course_raw.html';

    private const SEMESTER_HEADING = '<h4 class="title">Semester : 5</h4>';

    /**
     * @var list<string>
     */
    private array $temporaryFixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFixtures as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_the_raw_schedule_page_is_requested_once_and_gives_the_semester_number(): void
    {
        /** @var StudentCourseExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(StudentCourseExtractor::class, self::RAW_FIXTURE, $requestedUrls);

        $data = $extractor->extractStudentCourseData('fake-session-token');

        $this->assertSame([PortalEndpointRegistry::url('student_course')], $requestedUrls);
        $this->assertSame(['semester' => '5'], $data['semester_info']);
        $this->assertCount(10, $data['classes']);
        $this->assertSame('SI090001', $data['classes'][0]['course_info']['code']);
        $this->assertSame('SI090010', $data['classes'][9]['course_info']['code']);
    }

    public function test_the_raw_header_rows_give_the_student_values_after_the_separator_cell(): void
    {
        /** @var StudentCourseExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(StudentCourseExtractor::class, self::RAW_FIXTURE);

        $data = $extractor->extractStudentCourseData('fake-session-token');

        $this->assertSame([
            'program_studi' => 'SISTEM INFORMASI - S1',
            'nim' => '20990000001',
            'nama' => 'Mahasiswa Contoh',
        ], $data['student_info']);
    }

    public function test_raw_course_cells_give_decoded_course_codes_and_names(): void
    {
        /** @var StudentCourseExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(StudentCourseExtractor::class, self::RAW_FIXTURE);

        $classes = $extractor->extractStudentCourseData('fake-session-token')['classes'];

        $this->assertSame(['code' => 'SI090001', 'name' => 'Mata Kuliah Contoh Satu'], $classes[0]['course_info']);
        $this->assertSame(['code' => 'SI090010', 'name' => 'Mata Kuliah Contoh Sepuluh'], $classes[9]['course_info']);

        foreach ($classes as $class) {
            $this->assertStringNotContainsString('&', $class['course_info']['name']);
            $this->assertSame(trim($class['course_info']['name']), $class['course_info']['name']);
        }
    }

    public function test_an_ampersand_in_a_raw_course_name_is_decoded(): void
    {
        $html = $this->fixtureHtml();
        $this->assertStringContainsString('Mata Kuliah Contoh Dua&nbsp;', $html);
        $html = str_replace('Mata Kuliah Contoh Dua&nbsp;', 'UI &amp; UX Contoh&nbsp;', $html);

        $this->assertSame('UI & UX Contoh', $this->extractRawHtml($html)['classes'][1]['course_info']['name']);
    }

    public function test_period_headings_never_become_a_semester_number(): void
    {
        $html = str_replace(self::SEMESTER_HEADING, '<h4 class="title">Daftar Mata Kuliah</h4>', $this->fixtureHtml());
        $this->assertStringContainsString('SEMESTER GANJIL</h4>', $html);
        $this->assertStringContainsString('SEMESTER GANJIL</a>', $html);

        $this->assertSame([], $this->extractRawHtml($html)['semester_info']);
    }

    public function test_a_non_breaking_space_in_the_semester_heading_is_tolerated(): void
    {
        $html = str_replace(self::SEMESTER_HEADING, '<h4 class="title">Semester&nbsp;:&nbsp;5&nbsp;</h4>', $this->fixtureHtml());

        $this->assertSame(['semester' => '5'], $this->extractRawHtml($html)['semester_info']);
    }

    public function test_a_non_regular_semester_heading_gives_no_number(): void
    {
        $html = str_replace(self::SEMESTER_HEADING, '<h4 class="title">Semester : T</h4>', $this->fixtureHtml());

        $this->assertSame([], $this->extractRawHtml($html)['semester_info']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawHtml(string $html): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'course-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        /** @var StudentCourseExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(StudentCourseExtractor::class, $path);

        return $extractor->extractStudentCourseData('variant-session-token');
    }

    private function fixtureHtml(): string
    {
        $html = (string) file_get_contents(base_path(self::RAW_FIXTURE));
        $this->assertStringContainsString(self::SEMESTER_HEADING, $html);

        return $html;
    }
}
