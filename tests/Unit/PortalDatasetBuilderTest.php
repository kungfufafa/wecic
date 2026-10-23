<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AttendanceShortcutService;
use App\Services\Extractors\AdvisingExtractor;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\KrsDetailExtractor;
use App\Services\Extractors\KrsExtractor;
use App\Services\Extractors\LibraryExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\SkkmExtractor;
use App\Services\Extractors\SkripsiExtractor;
use App\Services\Extractors\ThesisRepositoryExtractor;
use App\Services\PortalAnnouncementFeed;
use App\Services\PortalContextResolver;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalGradesResolver;
use App\Services\PortalSnapshotStore;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalDatasetBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_decision_datasets_are_built_by_their_extractors(): void
    {
        $this->expectCall(PortalContextResolver::class, 'resolve', ['token'], ['dataset' => 'portal_context']);
        $this->expectCall(PortalAnnouncementFeed::class, 'fetch', ['token'], ['dataset' => 'announcements']);
        $this->expectCall(AnnouncementExtractor::class, 'extractAnnouncementsData', ['token', 4], ['dataset' => 'announcement_page']);
        $this->expectCall(KrsExtractor::class, 'extractKrsData', ['token'], ['dataset' => 'krs']);
        $this->expectCall(AdvisingExtractor::class, 'extractAdvisingData', ['token'], ['dataset' => 'advising']);
        $this->expectCall(KrsDetailExtractor::class, 'extractKrsDetailData', ['token', 90004], ['dataset' => 'krs_detail']);
        $this->expectCall(PortalGradesResolver::class, 'resolve', ['token'], ['dataset' => 'grades']);
        $this->expectCall(SkkmExtractor::class, 'extractSkkmData', ['token'], ['dataset' => 'skkm']);

        $builder = app(PortalDatasetBuilder::class);

        foreach (['portal_context', 'announcements', 'krs', 'advising', 'grades', 'skkm'] as $dataset) {
            $this->assertSame(['dataset' => $dataset], $builder->build('token', $dataset));
        }

        $this->assertSame(['dataset' => 'announcement_page'], $builder->build('token', 'announcement_page', ['page' => '4']));
        $this->assertSame(['dataset' => 'krs_detail'], $builder->build('token', 'krs_detail', ['id' => '90004']));
    }

    public function test_thesis_and_library_datasets_are_built_by_their_extractors(): void
    {
        $this->expectCall(SkripsiExtractor::class, 'extractSkripsiData', ['token'], ['dataset' => 'thesis']);
        $this->expectCall(LibraryExtractor::class, 'extractLibraryLinksData', ['token'], ['dataset' => 'library']);
        $this->expectCall(ThesisRepositoryExtractor::class, 'extractRepositoryIndex', ['token', null], ['dataset' => 'thesis_repository']);

        $builder = app(PortalDatasetBuilder::class);

        foreach (['thesis', 'library', 'thesis_repository'] as $dataset) {
            $this->assertSame(['dataset' => $dataset], $builder->build('token', $dataset));
        }
    }

    public function test_the_repository_index_receives_the_previous_snapshot(): void
    {
        $previous = ['records' => [['record_key' => 'r1']], 'total' => 1, 'complete' => true, 'first_record_key' => 'r1'];
        app(PortalSnapshotStore::class)->put('token', 'thesis_repository', $previous);
        $this->expectCall(ThesisRepositoryExtractor::class, 'extractRepositoryIndex', ['token', $previous], ['dataset' => 'thesis_repository']);

        $this->assertSame(['dataset' => 'thesis_repository'], app(PortalDatasetBuilder::class)->build('token', 'thesis_repository'));
    }

    public function test_the_lms_class_list_is_read_by_the_sipandai_class_list_parser_directly(): void
    {
        $this->expectCall(SipandaiClassesExtractor::class, 'extractSipandaiClassesData', ['token'], ['dataset' => 'lms_classes']);
        $this->instance(ClassesExtractor::class, Mockery::mock(ClassesExtractor::class));

        $this->assertSame(['dataset' => 'lms_classes'], app(PortalDatasetBuilder::class)->build('token', 'lms_classes'));
    }

    public function test_the_dashboard_schedule_and_todays_attendance_are_separate_builds(): void
    {
        $this->expectCall(DashboardExtractor::class, 'extractDashboardData', ['token'], ['dataset' => 'dashboard']);
        $this->expectCall(AttendanceShortcutService::class, 'getTodayShortcutsSnapshot', ['token'], ['dataset' => 'dashboard_attendance']);

        $builder = app(PortalDatasetBuilder::class);

        $this->assertSame(['dataset' => 'dashboard'], $builder->build('token', 'dashboard'));
        $this->assertSame(['dataset' => 'dashboard_attendance'], $builder->build('token', 'dashboard', PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS));
    }

    public function test_only_the_per_class_and_per_meeting_crawls_count_as_crawls(): void
    {
        $this->assertTrue(PortalDatasetBuilder::isCrawl('assignments'));
        $this->assertTrue(PortalDatasetBuilder::isCrawl('dashboard', PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS));
        $this->assertFalse(PortalDatasetBuilder::isCrawl('dashboard'));
        $this->assertFalse(PortalDatasetBuilder::isCrawl('krs'));
        $this->assertFalse(PortalDatasetBuilder::isCrawl('classes'));
    }

    public function test_every_configured_dataset_has_a_builder(): void
    {
        $source = (string) file_get_contents(app_path('Services/PortalDatasetBuilder.php'));

        foreach (array_keys(config('portal.datasets')) as $dataset) {
            $this->assertStringContainsString("'{$dataset}' =>", $source, "Dataset [{$dataset}] has no build arm.");
        }
    }

    public function test_krs_detail_needs_a_numeric_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PortalDatasetBuilder::class)->build('token', 'krs_detail', ['id' => '90004 OR 1']);
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $arguments
     * @param  array<string, mixed>  $result
     */
    private function expectCall(string $class, string $method, array $arguments, array $result): void
    {
        $this->instance($class, Mockery::mock($class, function (MockInterface $mock) use ($method, $arguments, $result): void {
            $mock->shouldReceive($method)->once()->with(...$arguments)->andReturn($result);
        }));
    }
}
