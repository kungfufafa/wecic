<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class PublicLegalPagesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function legalPages(): array
    {
        return [
            'privacy policy' => ['/privasi', 'legal-privacy', [
                'Kebijakan privasi WeCIC',
                'Gemini',
                'tingkat gratis',
                'dibagikan ke Google',
                'developer@rizqis.com',
                'Sentry',
                'wilayah Uni Eropa',
                'Token push',
                'mati secara bawaan',
                'sampai kamu mematikan sinkronisasi atau keluar',
                'setelah kamu memberi persetujuan di dalam aplikasi',
                '20 November 2026',
                'wecic.rizqis.com',
                'unofficial',
                'Lainnya → Hapus akun',
                'Lainnya → Privasi → Hapus akun & data WeCIC',
                'Lainnya → Privasi → Izin Notebook AI di aplikasi, atau di halaman Notebook di web',
                'hapus',
            ]],
            'support' => ['/bantuan', 'legal-support', [
                'Bantuan WeCIC',
                'Gemini',
                'developer@rizqis.com',
                'Lainnya → Hapus akun',
                'Lainnya → Privasi → Hapus akun & data WeCIC',
                'Lainnya → Privasi → Izin Notebook AI di aplikasi, atau di halaman Notebook di web',
                'unofficial',
                'hapus',
            ]],
            'data deletion' => ['/hapus-data', 'legal-data-deletion', [
                'Hapus akun dan data WeCIC',
                'Lainnya',
                'Hapus akun',
                'Hapus data WeCIC',
                'paling lambat 30 hari',
                'developer@rizqis.com',
                'Gemini',
                'Akun portal akademik kampus',
                'Saya mengerti data ini tidak bisa dikembalikan',
                'Hapus permanen',
                'paling lama dalam 7 hari',
                'hapus',
            ]],
        ];
    }

    /**
     * @param  list<string>  $phrases
     */
    #[DataProvider('legalPages')]
    public function test_guest_can_read_the_page_with_its_key_phrases(string $path, string $pageId, array $phrases): void
    {
        $response = $this->get($path)
            ->assertOk()
            ->assertSee('data-page="'.$pageId.'"', false)
            ->assertSee('data-auth="guest"', false);

        $text = (new Crawler((string) $response->getContent()))->filter('body')->text();

        foreach ($phrases as $phrase) {
            $this->assertStringContainsString($phrase, $text, "{$path} is missing \"{$phrase}\".");
        }
    }

    #[DataProvider('legalPages')]
    public function test_page_links_to_the_other_legal_pages_and_marks_itself_current(string $path): void
    {
        $page = new Crawler((string) $this->get($path)->assertOk()->getContent());
        $headerLinks = $page->filter('header nav[aria-label="Informasi WeCIC"] a');

        $this->assertSame(
            [route('legal.privacy'), route('legal.support'), route('legal.data-deletion')],
            $headerLinks->extract(['href'])
        );
        $this->assertSame([url($path)], $page->filter('header nav a[aria-current="page"]')->extract(['href']));
        $this->assertCount(1, $page->filter('footer a[href="mailto:developer@rizqis.com"]'));
        $this->assertCount(1, $page->filter('footer a[href="'.route('login').'"]'));
    }

    public function test_routes_are_named_and_outside_the_signed_in_middleware(): void
    {
        foreach (['legal.privacy' => '/privasi', 'legal.support' => '/bantuan', 'legal.data-deletion' => '/hapus-data'] as $name => $path) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertSame(url($path), route($name));
            $this->assertNotContains('portal.web', $route->gatherMiddleware(), $name);
            $this->assertNotContains('auth', $route->gatherMiddleware(), $name);
        }
    }

    public function test_a_signed_in_browser_reads_the_page_without_a_portal_check_or_redirect(): void
    {
        $this->mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('validateSession');
        });

        $this->withSession(['campus_session_token' => 'token-legal'])
            ->get('/hapus-data')
            ->assertOk()
            ->assertSee('Hapus akun dan data WeCIC');
    }

    public function test_query_strings_do_not_change_the_page(): void
    {
        $this->get('/privasi?lang=en&utm_source=play')
            ->assertOk()
            ->assertSee('English summary');
    }

    public function test_unknown_paths_under_the_pages_are_not_found(): void
    {
        $this->get('/privasi/extra')->assertNotFound();
    }

    public function test_sign_in_page_links_to_every_legal_page(): void
    {
        $page = new Crawler((string) $this->get('/login')->assertOk()->getContent());

        $this->assertSame(
            [route('legal.privacy'), route('legal.support'), route('legal.data-deletion')],
            $page->filter('[data-legal-links] a')->extract(['href'])
        );
    }

    public function test_more_hub_links_to_the_privacy_page(): void
    {
        $this->mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->once()->with('token-legal')->andReturnTrue();
        });

        $page = new Crawler((string) $this->withSession(['campus_session_token' => 'token-legal'])->get('/more')->assertOk()->getContent());

        $this->assertSame(route('legal.privacy'), $page->filter('main [data-more-tile="privacy"]')->attr('href'));
    }
}
