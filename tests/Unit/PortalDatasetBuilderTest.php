<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extractors\AdvisingExtractor;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\Extractors\KrsDetailExtractor;
use App\Services\Extractors\KrsExtractor;
use App\Services\Extractors\SkkmExtractor;
use App\Services\PortalAnnouncementFeed;
use App\Services\PortalContextResolver;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalGradesResolver;
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
