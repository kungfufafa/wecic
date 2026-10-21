<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\PortalContextExtractor;
use App\Services\PortalEndpointRegistry;
use Mockery;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class InteractsWithPortalFixturesTest extends TestCase
{
    use InteractsWithPortalFixtures;

    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_a_raw_fixture_extractor_parses_the_page_with_the_real_code_and_records_the_url(): void
    {
        /** @var PortalContextExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(PortalContextExtractor::class, 'tests/Fixtures/portal/student_dashboard.html', $requestedUrls);

        $data = $extractor->extractPortalContextData('fake-session-token');

        $this->assertSame('2026/2027', $data['academic_period']['academic_year']);
        $this->assertSame('upstream', $data['_sync']['data_origin']);
        $this->assertSame([PortalEndpointRegistry::url('dashboard')], $requestedUrls);
    }

    public function test_a_raw_fixture_extractor_validates_the_page_like_the_network_get(): void
    {
        $fixture = $this->temporaryFixture('<!doctype html><html><head><title>404 Not Found</title></head><body>'
            .str_repeat('<p>Halaman tidak ditemukan</p>', 10).'</body></html>');

        /** @var PortalContextExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(PortalContextExtractor::class, $fixture);

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionMessage('Error page detected: 404 not found');

        $extractor->extractPortalContextData('fake-session-token');
    }

    public function test_a_fixture_without_a_leading_doctype_is_rejected_as_raw(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertFixtureParsesLikeLivePortal('tests/Fixtures/portal/meeting_assignment_detail.html');
    }

    public function test_a_doctype_after_page_content_is_rejected_as_raw(): void
    {
        $fixture = $this->temporaryFixture("<p>prefix</p>\n<!doctype html><html><body></body></html>");

        $this->expectException(AssertionFailedError::class);

        $this->assertFixtureParsesLikeLivePortal($fixture);
    }

    public function test_whitespace_and_comments_may_precede_the_doctype(): void
    {
        $fixture = $this->temporaryFixture("\n  <!-- sanitized -->\n<!DOCTYPE html>\n<html><body></body></html>");

        $this->assertFixtureParsesLikeLivePortal($fixture);
        $this->assertFixtureParsesLikeLivePortal('tests/Fixtures/portal/student_advising.html');
    }

    private function temporaryFixture(string $html): string
    {
        $file = tempnam(sys_get_temp_dir(), 'portal-fixture-');
        $this->assertIsString($file);
        file_put_contents($file, $html);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
