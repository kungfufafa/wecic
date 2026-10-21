<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\PortalContextExtractor;
use App\Services\PortalEndpointRegistry;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The dashboard as the live portal sends it (K14 sanitized copy): the SSO
 * `<li>` without a link and with a stray `</a>`, the questionnaire popup
 * inside the content and the guide PDFs in the navbar, parsed by
 * DomCrawler's HTML5 parser like a live response.
 */
class PortalContextExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const RAW_FIXTURE = 'tests/Fixtures/portal/student_dashboard_raw.html';

    private const NORMALIZED_FIXTURE = 'tests/Fixtures/portal/student_dashboard.html';

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

    public function test_the_raw_dashboard_is_requested_once_and_gives_the_academic_period(): void
    {
        /** @var PortalContextExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(PortalContextExtractor::class, self::RAW_FIXTURE, $requestedUrls);

        $data = $extractor->extractPortalContextData('fake-session-token');

        $this->assertSame([PortalEndpointRegistry::url('dashboard')], $requestedUrls);
        $this->assertSame([
            'label' => 'T.A 2026/2027 Semester Ganjil',
            'academic_year' => '2026/2027',
            'term' => 'ganjil',
            'source' => 'dashboard',
        ], $data['academic_period']);
        $this->assertSame('2026-10-20', $data['server_date']);
        $this->assertSame('Selasa, 20 Oktober 2026', $data['server_date_label']);
        $this->assertNull($data['notice']);
        $this->assertSame('upstream', $data['_sync']['data_origin']);
    }

    public function test_the_raw_navbar_gives_only_the_pdf_guide(): void
    {
        $this->assertSame([
            [
                'label' => 'Panduan Her-registrasi Online',
                'path' => 'files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
                'filename' => 'Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
            ],
        ], $this->extractRaw()['guide_links']);
    }

    public function test_the_raw_questionnaire_popup_is_read(): void
    {
        $questionnaire = $this->extractRaw()['questionnaire'];

        $this->assertTrue($questionnaire['available']);
        $this->assertSame('Kuesioner Pemahaman VMTS Untuk Tahun Akademik 2026/2027', $questionnaire['title']);
        $this->assertSame('2026/2027', $questionnaire['academic_year']);
        $this->assertStringStartsWith('Silakan saudara mengisi kuesioner pemahaman', (string) $questionnaire['description']);
        $this->assertCount(2, $questionnaire['details']);
        $this->assertStringStartsWith('Tujuan Kuesioner untuk melakukan evaluasi', $questionnaire['details'][0]);
        $this->assertSame('Kegiatan ini dilakukan secara reguler pada awal tahun akademik', $questionnaire['details'][1]);
    }

    public function test_the_raw_sidebar_gives_the_eleven_menu_items_and_nothing_from_the_navbar_or_footer(): void
    {
        $menu = $this->extractRaw()['sidebar_menu'];

        $this->assertSame([
            ['label' => 'Dashboard', 'href_act' => 'dashboard'],
            ['label' => 'Pengumuman', 'href_act' => 'pengumuman'],
            ['label' => 'Profil Mahasiswa', 'href_act' => 'profile'],
            ['label' => 'Jadwal Kuliah', 'href_act' => 'course'],
            ['label' => 'Reg & KRS Online', 'href_act' => 'credit'],
            ['label' => 'Perwalian', 'href_act' => 'perwalian'],
            ['label' => 'SiPANDAI', 'href_act' => null],
            ['label' => 'SIREKA (SKKM)', 'href_act' => 'skkm'],
            ['label' => 'SiMASITA', 'href_act' => 'simasita'],
            ['label' => 'Keuangan', 'href_act' => 'keuangan'],
            ['label' => 'e-Book', 'href_act' => 'ebook'],
        ], $menu);

        foreach (['Panduan MyCIC', 'Setting', 'Ganti Passowrd', 'Log out', 'Home'] as $chromeLabel) {
            $this->assertNotContains($chromeLabel, array_column($menu, 'label'));
        }
    }

    public function test_the_raw_sso_form_values_never_reach_the_context(): void
    {
        $html = str_replace(
            ['name="id" value=""', 'name="p" value=""', 'name="who" value=""', 'name="ak" value=""'],
            ['name="id" value="20990000001"', 'name="p" value="ssohashpmarker"', 'name="who" value="ssohashwhomarker"', 'name="ak" value="ssohashakmarker"'],
            $this->fixtureHtml(self::RAW_FIXTURE)
        );
        $this->assertStringContainsString('ssohashakmarker', $html);

        $encoded = (string) json_encode($this->extractRawHtml($html));

        foreach (['20990000001', 'ssohashpmarker', 'ssohashwhomarker', 'ssohashakmarker', 'loginact', 'kuesionerform', 'Klik Kuesioner', 'vmts'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_the_raw_dashboard_reads_like_its_browser_normalized_copy(): void
    {
        $raw = $this->extractRaw();
        $normalized = $this->makeExtractorWithFixture(PortalContextExtractor::class, self::NORMALIZED_FIXTURE)
            ->extractPortalContextData('normalized-session-token');

        foreach (['academic_period', 'guide_links', 'questionnaire', 'notice', 'sidebar_menu'] as $key) {
            $this->assertSame($normalized[$key], $raw[$key], $key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRaw(): array
    {
        /** @var PortalContextExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(PortalContextExtractor::class, self::RAW_FIXTURE);

        return $extractor->extractPortalContextData('fake-session-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawHtml(string $html): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'context-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        /** @var PortalContextExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(PortalContextExtractor::class, $path);

        return $extractor->extractPortalContextData('variant-session-token');
    }

    private function fixtureHtml(string $fixture): string
    {
        return (string) file_get_contents(base_path($fixture));
    }
}
