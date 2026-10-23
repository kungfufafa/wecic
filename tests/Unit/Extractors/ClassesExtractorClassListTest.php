<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Tests\TestCase;

/**
 * The SIPANDAI class list (act=dashboardmahasiswa) as both entry points read
 * it: `/api/lms/class` (the `lms_classes` dataset) and the merged class list,
 * dashboard, notifications and notebooks (SipandaiClassesExtractor). Both go
 * through the one parser in SipandaiClassesExtractor.
 */
class ClassesExtractorClassListTest extends TestCase
{
    private const FIXTURE = 'tests/Fixtures/portal/sipandai_dashboard.html';

    /**
     * Cloudflare encoding of dosen.tiga@example.test with key 0x5a
     */
    private const ENCODED_EMAIL = '5a3e35293f34742e333d3b1a3f223b372a363f742e3f292e';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_lms_class_list_is_the_payload_of_the_merged_class_list_parser(): void
    {
        $html = $this->fixture();
        $this->app->instance(SipandaiClassesExtractor::class, $this->extractorReturning(SipandaiClassesExtractor::class, $html));

        $lmsClasses = app(PortalDatasetBuilder::class)->build('lms-session-token', 'lms_classes');
        $sipandaiClasses = $this->extractorReturning(SipandaiClassesExtractor::class, $html)->extractSipandaiClassesData('sipandai-session-token');

        $this->assertSame('20990000001', $lmsClasses['student_info']['nim'] ?? null);
        $this->assertCount(count($sipandaiClasses['classes']), $lmsClasses['classes']);
        $this->assertSame($this->withoutScrapeTimes($sipandaiClasses), $this->withoutScrapeTimes($lmsClasses));
    }

    public function test_the_day_and_time_are_split_although_the_portal_pads_them_with_non_breaking_spaces(): void
    {
        foreach ($this->firstClassOfBothEntryPoints($this->fixture()) as $entryPoint => $class) {
            $this->assertSame('Senin', $class['schedule']['day'] ?? null, $entryPoint);
            $this->assertSame('09:30:00', $class['schedule']['start_time'] ?? null, $entryPoint);
            $this->assertSame('11:00:00', $class['schedule']['end_time'] ?? null, $entryPoint);
            $this->assertSame('09:30 - 11:00', $class['schedule']['formatted_time'] ?? null, $entryPoint);
        }
    }

    public function test_the_lecturer_name_is_only_the_first_line_of_the_lecturer_cell(): void
    {
        foreach ($this->firstClassOfBothEntryPoints($this->fixture()) as $entryPoint => $class) {
            $this->assertSame([
                'name' => 'Dosen Contoh Tiga, M.Pd',
                'contact' => '081200000001',
                'email' => 'dosen.tiga@example.test',
            ], $class['lecturer_info'], $entryPoint);
        }
    }

    public function test_a_cloudflare_protected_lecturer_email_is_decoded_instead_of_kept_as_a_placeholder(): void
    {
        $html = $this->fixture([
            'Email Dosen : dosen.tiga@example.test</b>' => 'Email Dosen : <a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="'.self::ENCODED_EMAIL.'">[email&#160;protected]</a></b>',
        ]);

        foreach ($this->firstClassOfBothEntryPoints($html) as $entryPoint => $class) {
            $this->assertSame('Dosen Contoh Tiga, M.Pd', $class['lecturer_info']['name'], $entryPoint);
            $this->assertSame('081200000001', $class['lecturer_info']['contact'], $entryPoint);
            $this->assertSame('dosen.tiga@example.test', $class['lecturer_info']['email'], $entryPoint);
        }
    }

    public function test_a_blank_first_line_in_the_lecturer_cell_does_not_empty_the_name(): void
    {
        $html = $this->fixture([
            '<td><b>Dosen Contoh Tiga, M.Pd<br/>' => '<td><b><br/> &nbsp; <br/>Dosen Contoh Tiga, M.Pd<br/>',
        ]);

        foreach ($this->firstClassOfBothEntryPoints($html) as $entryPoint => $class) {
            $this->assertSame([
                'name' => 'Dosen Contoh Tiga, M.Pd',
                'contact' => '081200000001',
                'email' => 'dosen.tiga@example.test',
            ], $class['lecturer_info'], $entryPoint);
        }
    }

    public function test_a_lecturer_without_contact_details_has_null_contact_and_email(): void
    {
        $html = $this->fixture([
            'Kontak Dosen : 081200000001<br/>' => 'Kontak Dosen : -<br/>',
            'Email Dosen : dosen.tiga@example.test</b>' => 'Email Dosen : -</b>',
        ]);

        foreach ($this->firstClassOfBothEntryPoints($html) as $entryPoint => $class) {
            $this->assertSame(['name' => 'Dosen Contoh Tiga, M.Pd', 'contact' => null, 'email' => null], $class['lecturer_info'], $entryPoint);
        }
    }

    public function test_html_entities_in_the_course_cell_are_decoded(): void
    {
        $html = $this->fixture([
            '<td width="25%">UC01024011 <br/> Bahasa Indonesia</td>' => '<td width="25%">SI033016&nbsp;<br/> Etika Profesi &amp; Hukum Siber&nbsp;</td>',
        ]);

        foreach ($this->firstClassOfBothEntryPoints($html) as $entryPoint => $class) {
            $this->assertSame(['code' => 'SI033016', 'name' => 'Etika Profesi & Hukum Siber'], $class['course_info'], $entryPoint);
        }
    }

    public function test_plain_course_cells_keep_their_code_and_name(): void
    {
        foreach ($this->firstClassOfBothEntryPoints($this->fixture()) as $entryPoint => $class) {
            $this->assertSame(['code' => 'UC01024011', 'name' => 'Bahasa Indonesia'], $class['course_info'], $entryPoint);
        }
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function fixture(array $replacements = []): string
    {
        $html = (string) file_get_contents(base_path(self::FIXTURE));

        foreach ($replacements as $search => $replace) {
            $this->assertStringContainsString($search, $html);
            $html = str_replace($search, $replace, $html);
        }

        return $html;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function firstClassOfBothEntryPoints(string $html): array
    {
        $this->app->instance(SipandaiClassesExtractor::class, $this->extractorReturning(SipandaiClassesExtractor::class, $html));

        return [
            'lms_classes' => app(PortalDatasetBuilder::class)->build('lms-session-token', 'lms_classes')['classes'][0],
            SipandaiClassesExtractor::class => $this->extractorReturning(SipandaiClassesExtractor::class, $html)->extractSipandaiClassesData('sipandai-session-token')['classes'][0],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutScrapeTimes(array $payload): array
    {
        unset($payload['_sync'], $payload['extracted_at']);

        return $payload;
    }

    /**
     * @template T of PortalScrapingService
     *
     * @param  class-string<T>  $extractorClass
     * @return T
     */
    private function extractorReturning(string $extractorClass, string $html): object
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock($extractorClass, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturn($html);

        return $extractor;
    }
}
