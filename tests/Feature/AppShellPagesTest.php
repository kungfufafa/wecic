<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class AppShellPagesTest extends TestCase
{
    private const ANNOUNCEMENT_ID = '0a1b2c3d4e5f60718293a4b5c6d7e8f901234567';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function newPages(): array
    {
        return [
            'krs' => ['/krs', 'krs-index'],
            'krs detail' => ['/krs/90004', 'krs-show'],
            'grades' => ['/grades', 'grades-index'],
            'transcript' => ['/transcript', 'transcript-index'],
            'announcements' => ['/announcement', 'announcement-index'],
            'announcement detail' => ['/announcement/'.self::ANNOUNCEMENT_ID, 'announcement-show'],
            'skkm' => ['/skkm', 'skkm-index'],
            'thesis' => ['/thesis', 'thesis-index'],
            'library' => ['/library', 'library-index'],
            'library theses' => ['/library/theses', 'library-theses'],
            'more' => ['/more', 'more-index'],
            'notifications' => ['/notifications', 'notification-index'],
        ];
    }

    #[DataProvider('newPages')]
    public function test_new_page_renders_for_a_valid_session(string $path, string $pageId): void
    {
        $this->mockValidCampusSession('token-shell');

        $this->withSession(['campus_session_token' => 'token-shell'])
            ->get($path)
            ->assertOk()
            ->assertSee('data-page="'.$pageId.'"', false)
            ->assertSee('aria-label="Navigasi mobile"', false)
            ->assertSee('aria-label="Navigasi utama"', false);
    }

    #[DataProvider('newPages')]
    public function test_new_page_redirects_to_login_without_a_session(string $path): void
    {
        $this->get($path)
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token');
    }

    #[DataProvider('newPages')]
    public function test_new_page_redirects_to_login_when_the_session_has_expired(string $path): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('expired-token')
                    ->andReturnFalse();
            })
        );

        $this->withSession(['campus_session_token' => 'expired-token', 'campus_user_info' => ['name' => 'Mahasiswa Uji']])
            ->get($path)
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token')
            ->assertSessionMissing('campus_user_info');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedDetailPaths(): array
    {
        return [
            'krs id with letters' => ['/krs/12a'],
            'krs id negative' => ['/krs/-5'],
            'announcement id too short' => ['/announcement/abc123'],
            'announcement id uppercase' => ['/announcement/'.strtoupper(self::ANNOUNCEMENT_ID)],
            'announcement id with path traversal' => ['/announcement/..%2F..%2Fetc'],
        ];
    }

    #[DataProvider('malformedDetailPaths')]
    public function test_malformed_detail_ids_are_not_found(string $path): void
    {
        $this->withSession(['campus_session_token' => 'token-shell'])
            ->get($path)
            ->assertNotFound();
    }

    public function test_detail_pages_receive_their_id(): void
    {
        $this->mockValidCampusSession('token-shell', 2);

        $this->withSession(['campus_session_token' => 'token-shell'])
            ->get('/krs/90004')
            ->assertOk()
            ->assertSee('data-krs-id="90004"', false);

        $this->withSession(['campus_session_token' => 'token-shell'])
            ->get('/announcement/'.self::ANNOUNCEMENT_ID)
            ->assertOk()
            ->assertSee('data-announcement-id="'.self::ANNOUNCEMENT_ID.'"', false);
    }

    public function test_mobile_nav_has_exactly_five_slots_in_order(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');
        $slots = $crawler->filter('nav[aria-label="Navigasi mobile"] .mycic-bottom-nav-item');

        $this->assertCount(5, $slots);
        $this->assertSame(
            ['Beranda', 'Kuliah', 'Absen', 'Akademik', 'Lainnya'],
            $slots->each(fn (Crawler $slot): string => trim($slot->text()))
        );
        $this->assertSame(
            ['beranda', 'kuliah', 'absen', 'akademik', 'lainnya'],
            $slots->each(fn (Crawler $slot): string => (string) $slot->attr('data-nav-slot'))
        );
        $this->assertSame(['/dashboard', '/class', '/krs', '/more'], array_map(
            fn (string $href): string => (string) parse_url($href, PHP_URL_PATH),
            $crawler->filter('nav[aria-label="Navigasi mobile"] a.mycic-bottom-nav-item')->extract(['href'])
        ));
        $this->assertCount(1, $crawler->filter('nav[aria-label="Navigasi mobile"] [data-qr-quick]'));
    }

    public function test_desktop_nav_lists_main_sections_and_the_more_menu(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');
        $links = $crawler->filter('nav[aria-label="Navigasi utama"] > a.mycic-top-nav-link');

        $this->assertSame(
            ['Beranda', 'Kuliah', 'Akademik', 'Keuangan'],
            $links->each(fn (Crawler $link): string => trim($link->text()))
        );
        $this->assertSame('Lainnya', trim($crawler->filter('nav[aria-label="Navigasi utama"] [data-dropdown-trigger]')->text()));

        $moreMenu = $crawler->filter('nav[aria-label="Navigasi utama"] [data-dropdown-panel] a')->extract(['href']);
        $moreMenuPaths = array_map(fn (string $href): string => (string) parse_url($href, PHP_URL_PATH), $moreMenu);

        $this->assertSame(['/skkm', '/thesis', '/library', '/announcement', '/notebook', '/archive', '/profile', '/password', '/more'], $moreMenuPaths);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function activeSections(): array
    {
        return [
            'dashboard' => ['/dashboard', 'beranda', 'beranda'],
            'class list' => ['/class', 'kuliah', 'kuliah'],
            'meeting' => ['/meeting/445?courses_id=8861', 'kuliah', 'kuliah'],
            'exam' => ['/exam', 'kuliah', 'kuliah'],
            'krs' => ['/krs', 'akademik', 'akademik'],
            'grades' => ['/grades', 'akademik', 'akademik'],
            'transcript' => ['/transcript', 'akademik', 'akademik'],
            'finance' => ['/finance', 'lainnya', 'keuangan'],
            'skkm' => ['/skkm', 'lainnya', 'lainnya'],
            'announcement' => ['/announcement', 'lainnya', 'lainnya'],
            'thesis' => ['/thesis', 'lainnya', 'lainnya'],
            'library' => ['/library', 'lainnya', 'lainnya'],
            'library theses' => ['/library/theses', 'lainnya', 'lainnya'],
            'profile' => ['/profile', 'lainnya', 'lainnya'],
            'password' => ['/password', 'lainnya', 'lainnya'],
            'more' => ['/more', 'lainnya', 'lainnya'],
        ];
    }

    #[DataProvider('activeSections')]
    public function test_the_current_section_is_marked_active(string $path, string $mobileSlot, string $desktopSlot): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl($path);

        $mobileActive = $crawler->filter('nav[aria-label="Navigasi mobile"] .mycic-bottom-nav-item-active');
        $this->assertCount(1, $mobileActive);
        $this->assertSame($mobileSlot, $mobileActive->attr('data-nav-slot'));
        $this->assertSame('page', $mobileActive->attr('aria-current'));

        $desktopActive = $crawler->filter('nav[aria-label="Navigasi utama"] .mycic-top-nav-link-active');
        $this->assertCount(1, $desktopActive);
        $this->assertSame($desktopSlot, $desktopActive->attr('data-nav-slot'));
    }

    public function test_nav_slots_carry_badge_hooks(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');

        foreach (['kuliah', 'akademik', 'lainnya'] as $slot) {
            $this->assertCount(1, $crawler->filter('nav[aria-label="Navigasi mobile"] [data-nav-badge="'.$slot.'"]'), $slot);
        }

        // The desktop dropdown has its own key: Keuangan is a top-level link there.
        foreach (['kuliah', 'akademik', 'keuangan', 'lainnya-desktop', 'skkm', 'announcement'] as $slot) {
            $this->assertCount(1, $crawler->filter('nav[aria-label="Navigasi utama"] [data-nav-badge="'.$slot.'"]'), $slot);
        }

        $this->assertCount(0, $crawler->filter('[data-nav-badge]:not(.hidden)'));
    }

    public function test_exam_list_is_reachable_from_the_kuliah_section_and_the_more_hub(): void
    {
        $this->mockValidCampusSession('token-shell', 2);

        $classPage = $this->crawl('/class');
        $examLink = $classPage->filter('main header a[href$="/exam"]');

        $this->assertCount(1, $examLink);
        $this->assertStringContainsString('Ujian', $examLink->text());
        $this->assertCount(1, $examLink->filter('[data-nav-badge="exam"]'));

        $moreHub = $this->crawl('/more');
        $this->assertCount(1, $moreHub->filter('main [data-more-tile="exam"][href$="/exam"]'));
    }

    public function test_class_page_honours_the_today_shortcut_without_overwriting_the_saved_view(): void
    {
        $this->mockValidCampusSession('token-shell');

        $this->withSession(['campus_session_token' => 'token-shell'])
            ->get('/class?view=today')
            ->assertOk()
            ->assertSee("new URLSearchParams(window.location.search).get('view')", false)
            ->assertSee("if (requestedView === 'today') {", false)
            ->assertSee('switchView(currentViewMode, { persist: persistViewMode });', false);
    }

    public function test_toasts_are_announced_through_live_regions_present_from_page_load(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');

        $this->assertSame('polite', $crawler->filter('[data-live-polite]')->attr('aria-live'));
        $this->assertSame('assertive', $crawler->filter('[data-live-assertive]')->attr('aria-live'));
        // Only the header avatar shows the student's initial; other avatars keep their own text.
        $this->assertCount(1, $crawler->filter('[data-account-menu-trigger] .mycic-avatar[data-user-avatar]'));
    }

    public function test_desktop_nav_is_a_flex_row_instead_of_an_absolute_overlay(): void
    {
        $this->mockValidCampusSession('token-shell');

        $nav = $this->crawl('/dashboard')->filter('nav[aria-label="Navigasi utama"]');
        $classes = explode(' ', (string) $nav->attr('class'));

        $this->assertNotContains('absolute', $classes);
        $this->assertNotContains('inset-x-0', $classes);
        $this->assertContains('mycic-top-nav', $classes);
    }

    public function test_layout_lists_only_refreshable_datasets(): void
    {
        config()->set('portal.datasets', [
            'dashboard' => ['fresh_for' => 600],
            'krs' => ['fresh_for' => 900],
            'krs_detail' => ['fresh_for' => 21600, 'parameterized' => true],
        ]);
        $this->mockValidCampusSession('token-shell');

        $body = $this->crawl('/krs')->filter('body');

        $this->assertSame('dashboard,krs', $body->attr('data-refreshable-datasets'));
        $this->assertSame('krs-index', $body->attr('data-page'));
    }

    public function test_more_hub_lists_every_tile(): void
    {
        config()->set('portal.base_url', 'https://portal.example.test');
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/more');
        $tiles = $crawler->filter('main [data-more-tile]');
        $paths = array_map(
            fn (string $href): string => (string) parse_url($href, PHP_URL_PATH),
            $tiles->extract(['href'])
        );

        foreach (['/class', '/exam', '/krs', '/grades', '/transcript', '/finance', '/skkm', '/announcement', '/thesis', '/library', '/profile', '/password'] as $path) {
            $this->assertContains($path, $paths);
        }

        $portalTile = $crawler->filter('main [data-more-tile="portal"]');
        $this->assertSame('https://portal.example.test', $portalTile->attr('href'));
        $this->assertSame('_blank', $portalTile->attr('target'));
        $this->assertStringContainsString('noopener', (string) $portalTile->attr('rel'));

        $this->assertCount(1, $crawler->filter('main [data-logout]'));
        $this->assertCount(1, $crawler->filter('#panduan #more-guides'));

        foreach (['class', 'exam', 'krs', 'grades', 'transcript', 'finance', 'skkm', 'announcement', 'profile', 'password'] as $key) {
            $this->assertCount(1, $crawler->filter('main [data-more-tile="'.$key.'"] [data-nav-badge="'.$key.'"]'), $key);
            $this->assertCount(1, $crawler->filter('main [data-more-status="'.$key.'"]'), $key);
        }
    }

    public function test_more_hub_reads_guides_from_the_portal_context_and_registers_its_sync(): void
    {
        $this->mockValidCampusSession('token-shell');

        $this->withSession(['campus_session_token' => 'token-shell'])
            ->get('/more')
            ->assertOk()
            ->assertSee("app.primeCachedView('/api/portal/context', renderGuides, 'panduan')", false)
            ->assertSee("app.request('/api/portal/context', { fresh: force, background })", false)
            ->assertSee("app.registerSyncHandler('more-index', loadMore, { aliases: ['portal-context'] });", false)
            ->assertSee('app.buildCampusDownloadUrl(link.path, link.filename)', false)
            ->assertSee('portal:action-items', false)
            ->assertSee('Panduan')
            ->assertSee('Keluar');
    }

    public function test_more_hub_keeps_the_portal_url_out_of_inline_script_injection(): void
    {
        config()->set('portal.base_url', 'https://portal.example.test/</script><script>alert(1)</script>');
        $this->mockValidCampusSession('token-shell');

        $this->withSession(['campus_session_token' => 'token-shell'])
            ->get('/more')
            ->assertOk()
            ->assertDontSee('</script><script>alert(1)</script>', false);
    }

    public function test_account_menu_shows_the_name_and_links_the_guides(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');
        $menu = $crawler->filter('[data-account-menu]');

        $this->assertGreaterThan(0, $menu->filter('[data-user-name]')->count());
        $this->assertContains('/more#panduan', array_map(
            fn (string $href): string => (string) parse_url($href, PHP_URL_PATH).'#'.parse_url($href, PHP_URL_FRAGMENT),
            $menu->filter('[data-account-menu-panel] a')->extract(['href'])
        ));
    }

    public function test_service_worker_protects_every_signed_in_page(): void
    {
        $serviceWorker = (string) file_get_contents(public_path('sw.js'));

        foreach (['/dashboard', '/profile', '/password', '/class', '/meeting', '/exam', '/finance', '/krs', '/grades', '/transcript', '/announcement', '/skkm', '/more'] as $prefix) {
            $this->assertMatchesRegularExpression(
                '/PROTECTED_PAGE_PREFIXES = \[[^\]]*\''.preg_quote($prefix, '/').'\'/s',
                $serviceWorker,
                $prefix
            );
        }
    }

    public function test_manifest_offers_the_four_quick_shortcuts(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['Absen sekarang', 'Jadwal hari ini', 'Nilai', 'Pengumuman'],
            array_column($manifest['shortcuts'], 'name')
        );
        $this->assertSame(
            ['/dashboard?absen=1', '/class?view=today', '/grades', '/announcement'],
            array_column($manifest['shortcuts'], 'url')
        );
    }

    private function crawl(string $path): Crawler
    {
        $response = $this->withSession(['campus_session_token' => 'token-shell'])->get($path);

        $response->assertOk();

        return new Crawler((string) $response->getContent());
    }

    private function mockValidCampusSession(string $token, int $times = 1): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token, $times): void {
                $mock->shouldReceive('validateSession')
                    ->times($times)
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }
}
