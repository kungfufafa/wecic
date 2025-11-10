<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\StudentCourseExtractor;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class StudentCourseExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_student_course_schedule_from_fixture(): void
    {
        /** @var StudentCourseExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(
            StudentCourseExtractor::class,
            'tests/Fixtures/portal/student_course.html'
        );

        $data = $extractor->extractStudentCourseData('fake-session-token');

        $this->assertNotEmpty($data['classes']);

        $class = $data['classes'][1]; // Perancangan Aplikasi Mobile
        $this->assertSame('SI053025', $class['course_info']['code']);
        $this->assertStringContainsString('Perancangan Aplikasi Mobile', $class['course_info']['name']);
        $this->assertSame('12:30:00', $class['schedule']['start_time']);
        $this->assertSame('SI-EB-1/5, SI-EN-1/5', $class['class_room_info']['class_name']);
        $this->assertSame('Ruang Lab 2', $class['class_room_info']['room']);
        $this->assertSame('Muhammad Hatta, M.Kom.', $class['lecturer_info']['name']);
    }
}
