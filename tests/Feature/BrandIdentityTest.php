<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use DOMDocument;
use DOMXPath;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class BrandIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_logo_component_is_accessible_and_adapts_to_dark_mode(): void
    {
        $logo = new Crawler((string) $this->blade('<x-brand-logo class="h-10" />'));
        $image = $logo->filter('img');

        $this->assertSame('WeCIC', $image->attr('alt'));
        $this->assertSame(asset('icons/wecic-logo.svg'), $image->attr('src'));
        $this->assertSame('880', $image->attr('width'));
        $this->assertSame('256', $image->attr('height'));
        $this->assertStringContainsString('h-10', (string) $image->attr('class'));
        $this->assertStringContainsString('dark:brightness-0 dark:invert', (string) $image->attr('class'));

        $inverse = new Crawler((string) $this->blade('<x-brand-logo :on-dark="true" class="h-16" />'));
        $classes = explode(' ', (string) $inverse->filter('img')->attr('class'));

        $this->assertContains('brightness-0', $classes);
        $this->assertContains('invert', $classes);
        $this->assertNotContains('dark:invert', $classes);
    }

    public function test_login_uses_the_approved_logo_on_desktop_and_mobile(): void
    {
        $page = new Crawler((string) $this->get('/login')->assertOk()->getContent());

        $this->assertCount(2, $page->filter('img[alt="WeCIC"]'));
        $this->assertCount(1, $page->filter('aside img[alt="WeCIC"]'));
        $this->assertCount(1, $page->filter('main img[alt="WeCIC"]'));
        $this->assertCount(1, $page->filter('link[rel="icon"][href="/favicon.svg"][type="image/svg+xml"]'));
        $this->assertCount(1, $page->filter('link[rel="icon"][href="/favicon.ico"]'));
        $this->assertStringContainsString('Aplikasi tidak resmi untuk MyCIC', $page->text());
    }

    public function test_authenticated_header_uses_the_same_logo_and_favicon(): void
    {
        $this->mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->once()->with('token-brand')->andReturnTrue();
        });

        $response = $this->withSession(['campus_session_token' => 'token-brand'])->get('/dashboard')->assertOk();
        $page = new Crawler((string) $response->getContent());
        $brand = $page->filter('a[aria-label="WeCIC, beranda"]');

        $this->assertSame(route('dashboard'), $brand->attr('href'));
        $this->assertCount(1, $brand->filter('img[alt="WeCIC"]'));
        $this->assertCount(0, $brand->filter('span'));
        $this->assertCount(1, $page->filter('link[rel="icon"][href="/favicon.svg"]'));
    }

    public function test_web_logo_is_vector_artwork_and_offline_page_embeds_the_same_paths(): void
    {
        $document = new DOMDocument;
        $this->assertTrue($document->load(public_path('icons/wecic-logo.svg')));
        $this->assertSame('0 0 880 256', $document->documentElement?->getAttribute('viewBox'));
        $this->assertSame(0, (new DOMXPath($document))->query('//*[local-name()="text" or local-name()="image" or local-name()="filter"]')?->length);

        $offline = new Crawler((string) file_get_contents(public_path('offline.html')));
        $embedded = $offline->filter('.brand > svg[role="img"][aria-label="WeCIC"]');

        $this->assertCount(1, $embedded);
        $this->assertCount(0, $offline->filter('.brand img, .brand image'));
        $this->assertSame(
            array_map(fn (\DOMNode $path): ?string => $path->attributes?->getNamedItem('d')?->nodeValue, iterator_to_array($document->getElementsByTagName('path'))),
            $embedded->filter('path')->each(fn (Crawler $path): ?string => $path->attr('d'))
        );
    }

    public function test_every_pwa_icon_and_apple_touch_icon_has_the_declared_dimensions(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('WeCIC', $manifest['name']);
        $this->assertStringContainsString('MyCIC', $manifest['description']);

        foreach ($manifest['icons'] as $icon) {
            $dimensions = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertNotFalse($dimensions);
            $this->assertSame($icon['sizes'], $dimensions[0].'x'.$dimensions[1]);
            $this->assertSame(IMAGETYPE_PNG, $dimensions[2]);
        }

        $appleIcon = getimagesize(public_path('icons/apple-touch-icon.png'));
        $this->assertNotFalse($appleIcon);
        $this->assertSame([180, 180], array_slice($appleIcon, 0, 2));
        $this->assertFileExists(public_path('favicon.ico'));
    }

    public function test_svg_favicon_has_an_optical_cut_and_a_dark_background_treatment(): void
    {
        $favicon = (string) file_get_contents(public_path('favicon.svg'));
        $document = new DOMDocument;

        $this->assertTrue($document->loadXML($favicon));
        $this->assertSame('0 0 256 256', $document->documentElement?->getAttribute('viewBox'));
        $this->assertCount(4, $document->getElementsByTagName('path'));
        $this->assertStringContainsString('prefers-color-scheme: dark', $favicon);
        $this->assertStringContainsString('fill: #fff', $favicon);
    }
}
