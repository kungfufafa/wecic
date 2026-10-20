<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class ExtractorSectionFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string<PortalScrapingService>, string, array<mixed>|null, string}>
     */
    public static function sectionExtractors(): array
    {
        return [
            'profile personal data' => [ProfileExtractor::class, 'extractPersonalData', [], 'Failed to extract personal data from profile'],
            'profile contact data' => [ProfileExtractor::class, 'extractContactData', [], 'Failed to extract contact data from profile'],
            'profile education data' => [ProfileExtractor::class, 'extractEducationData', [], 'Failed to extract education data from profile'],
            'profile photo' => [ProfileExtractor::class, 'extractPhotoUrl', null, 'Failed to extract photo URL'],
            'student course info' => [StudentCourseExtractor::class, 'extractStudentCourseInfo', [], 'Failed to extract student info from student course page'],
            'student course classes' => [StudentCourseExtractor::class, 'extractStudentCourseClassesList', [], 'Failed to extract classes list from student course page'],
            'student course semester' => [StudentCourseExtractor::class, 'extractStudentCourseSemesterInfo', [], 'Failed to extract semester info from student course page'],
            'sipandai student info' => [SipandaiClassesExtractor::class, 'extractSipandaiStudentInfo', [], 'Failed to extract student info from SIPANDAI dashboard'],
            'sipandai classes' => [SipandaiClassesExtractor::class, 'extractSipandaiClassesList', [], 'Failed to extract classes list from SIPANDAI dashboard'],
            'sipandai semester' => [SipandaiClassesExtractor::class, 'extractSipandaiSemesterInfo', [], 'Failed to extract semester info from SIPANDAI dashboard'],
            'classes student info' => [ClassesExtractor::class, 'extractStudentInfo', [], 'Failed to extract student info from classes page'],
            'classes list' => [ClassesExtractor::class, 'extractClassesList', [], 'Failed to extract classes list from SIPANDAI'],
        ];
    }

    /**
     * @param  class-string<PortalScrapingService>  $extractorClass
     */
    #[DataProvider('sectionExtractors')]
    public function test_unreadable_section_is_logged_and_left_empty(string $extractorClass, string $method, ?array $expected, string $logMessage): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with($logMessage, ['error' => 'Unexpected portal markup.']);

        $crawler = Mockery::mock(Crawler::class);
        $crawler->shouldReceive('filter')->once()->andThrow(new \RuntimeException('Unexpected portal markup.'));
        // ClassesExtractor::extractClassesList retries with a generic table scan.
        $crawler->shouldReceive('filter')->andReturnSelf();
        $crawler->shouldReceive('each')->andReturn([]);

        $extractor = new $extractorClass(Mockery::mock(PortalSessionManager::class));
        $reflectionMethod = new \ReflectionMethod($extractor, $method);

        $this->assertSame($expected, $reflectionMethod->invoke($extractor, $crawler));
    }
}
