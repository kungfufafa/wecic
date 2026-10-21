<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\LibraryExtractor;
use App\Services\PortalEndpointRegistry;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The e-Book page (act=ebook) as the live portal sends it: `.col-lg-6 > .card`
 * with the title in `.card-header h6`, the link in `.col-auto > a` (one href
 * with a trailing space, `target='blank'`), an irrelevant `fa-comments` icon
 * and card images that must never be hotlinked.
 */
class LibraryExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURE = 'tests/Fixtures/portal/student_ebook_raw.html';

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

    public function test_every_card_becomes_one_link_and_the_page_is_requested_once(): void
    {
        $extractor = $this->makeExtractorWithRawFixture(LibraryExtractor::class, self::FIXTURE, $requestedUrls);

        $data = $extractor->extractLibraryLinksData('fake-session-token');

        $this->assertSame([PortalEndpointRegistry::url('student_ebook')], $requestedUrls);
        $this->assertTrue($data['found']);
        $this->assertSame('portal', $data['source']);
        $this->assertSame(
            ['Perpustakaan UCIC', 'Digital Library Mahardika', 'Repository USNI', 'DIGIDO UCIC Library', 'eBook UCIC', 'SiMASITA Repositori'],
            array_column($data['links'], 'title')
        );
    }

    public function test_external_links_are_trimmed_with_their_host(): void
    {
        $links = $this->links();

        $this->assertSame('https://repository.usni.ac.id/', $links['Repository USNI']['url'], 'The trailing space of the href is trimmed.');
        $this->assertSame('repository.usni.ac.id', $links['Repository USNI']['host']);
        $this->assertFalse($links['Repository USNI']['is_internal']);
        $this->assertNull($links['Repository USNI']['internal_route']);
    }

    public function test_the_portal_repository_link_becomes_the_mycic_route(): void
    {
        $this->assertSame([
            'title' => 'SiMASITA Repositori',
            'url' => null,
            'host' => null,
            'is_internal' => true,
            'internal_route' => '/library/theses',
            'requires_account' => false,
        ], array_diff_key($this->links()['SiMASITA Repositori'], ['key' => true]));
    }

    public function test_ebook_and_digido_need_their_own_account(): void
    {
        $links = $this->links();

        $this->assertTrue($links['eBook UCIC']['requires_account']);
        $this->assertTrue($links['DIGIDO UCIC Library']['requires_account']);
        $this->assertFalse($links['Perpustakaan UCIC']['requires_account']);
    }

    public function test_card_images_and_other_portal_pages_never_reach_the_payload(): void
    {
        // A card that points to another portal page (here one with a write effect) is dropped (K13).
        $fixture = $this->variant([
            'href="https://pustaka.cic.ac.id/"' => 'href="?mod=student&act=hapusbuku&id=3"',
        ]);

        $data = $this->makeExtractorWithRawFixture(LibraryExtractor::class, $fixture)->parseLibraryPage((string) file_get_contents($fixture));
        $json = (string) json_encode($data);

        $this->assertNotContains('Perpustakaan UCIC', array_column($data['links'], 'title'));
        $this->assertStringNotContainsString('hapusbuku', $json);
        $this->assertStringNotContainsString('.png', $json);
        $this->assertStringNotContainsString('skripsi/controllers', $json);
    }

    public function test_a_page_without_links_serves_the_configured_fallback(): void
    {
        $html = (string) file_get_contents(base_path(self::FIXTURE));
        $start = (int) strpos($html, '        <div class="content">');
        $end = (int) strpos($html, '        <footer class="footer">');
        $fixture = $this->temporaryFixture(substr($html, 0, $start).'        <div class="content"><div class="container-fluid"></div></div>'."\n".substr($html, $end));

        $data = $this->makeExtractorWithRawFixture(LibraryExtractor::class, $fixture)->extractLibraryLinksData('fake-session-token');

        $this->assertFalse($data['found']);
        $this->assertSame('config', $data['source']);
        $this->assertSame(array_column(config('portal.library.fallback_links'), 'title'), array_column($data['links'], 'title'));
        $repository = collect($data['links'])->firstWhere('is_internal', true);
        $this->assertSame('/library/theses', $repository['internal_route']);
        $this->assertNull($repository['url']);
        $this->assertTrue(collect($data['links'])->firstWhere('title', 'eBook UCIC')['requires_account']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function links(): array
    {
        $data = $this->makeExtractorWithRawFixture(LibraryExtractor::class, self::FIXTURE)
            ->parseLibraryPage((string) file_get_contents(base_path(self::FIXTURE)));

        return array_column($data['links'], null, 'title');
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function variant(array $replacements): string
    {
        $html = (string) file_get_contents(base_path(self::FIXTURE));

        foreach ($replacements as $search => $replace) {
            $this->assertStringContainsString($search, $html);
            $html = str_replace($search, $replace, $html);
        }

        return $this->temporaryFixture($html);
    }

    private function temporaryFixture(string $html): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ebook-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        return $path;
    }
}
