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
        $slots = $crawler->filter('nav[aria-label="Navigasi mobile"] [data-nav-slot]');

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
            $crawler->filter('nav[aria-label="Navigasi mobile"] a[data-nav-slot]')->extract(['href'])
        ));
        $this->assertCount(1, $crawler->filter('nav[aria-label="Navigasi mobile"] [data-qr-quick]'));
    }

    public function test_desktop_nav_lists_main_sections_and_the_more_menu(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');
        $links = $crawler->filter('nav[aria-label="Navigasi utama"] > a[data-nav-slot]');

        $this->assertSame(
            ['Beranda', 'Kuliah', 'Akademik', 'Keuangan'],
            $links->each(fn (Crawler $link): string => trim($link->text()))
        );
        $this->assertSame(['beranda', 'kuliah', 'akademik', 'keuangan'], $links->extract(['data-nav-slot']));

        // The sidebar lists the "Lainnya" menu as a labelled group, ending with the /more hub.
        $group = $crawler->filter('nav[aria-label="Navigasi utama"] [role="group"]');
        $this->assertCount(1, $group);
        $this->assertSame('Lainnya', trim($crawler->filter('#'.$group->attr('aria-labelledby'))->text()));

        $moreMenuPaths = array_map(fn (string $href): string => (string) parse_url($href, PHP_URL_PATH), $group->filter('a')->extract(['href']));

        $this->assertSame(['/skkm', '/thesis', '/library', '/announcement', '/notebook', '/archive', '/more'], $moreMenuPaths);
        $this->assertSame('Semua menu', trim($group->filter('a[data-nav-slot="more"]')->text()));

        // Profil and Ubah password moved to the account menu at the foot of the sidebar.
        $accountPaths = array_map(fn (string $href): string => (string) parse_url($href, PHP_URL_PATH), $crawler->filter('[data-account-menu-panel] a')->extract(['href']));
        $this->assertContains('/profile', $accountPaths);
        $this->assertContains('/password', $accountPaths);
    }

    /**
     * The path, the mobile slot marked current, and the sidebar slot marked
     * current (null: a page of the account menu, which marks its own link).
     *
     * @return array<string, array{0: string, 1: string, 2: ?string}>
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
            'skkm' => ['/skkm', 'lainnya', 'skkm'],
            'announcement' => ['/announcement', 'lainnya', 'announcement'],
            'thesis' => ['/thesis', 'lainnya', 'thesis'],
            'library' => ['/library', 'lainnya', 'library'],
            'library theses' => ['/library/theses', 'lainnya', 'library'],
            'profile' => ['/profile', 'lainnya', null],
            'password' => ['/password', 'lainnya', null],
            'more' => ['/more', 'lainnya', 'more'],
        ];
    }

    #[DataProvider('activeSections')]
    public function test_the_current_section_is_marked_active(string $path, string $mobileSlot, ?string $desktopSlot): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl($path);

        $mobileActive = $crawler->filter('nav[aria-label="Navigasi mobile"] [data-nav-slot][aria-current="page"]');
        $this->assertCount(1, $mobileActive);
        $this->assertSame($mobileSlot, $mobileActive->attr('data-nav-slot'));

        $desktopActive = $crawler->filter('nav[aria-label="Navigasi utama"] [aria-current="page"]');

        if ($desktopSlot === null) {
            $this->assertCount(0, $desktopActive);

            // Both copies of the account menu (sidebar and top bar) mark the page's link.
            foreach (['aside', 'header'] as $container) {
                $accountActive = $crawler->filter($container.' [data-account-menu-panel] a[aria-current="page"]');
                $this->assertCount(1, $accountActive, $container);
                $this->assertSame(parse_url($path, PHP_URL_PATH), parse_url((string) $accountActive->attr('href'), PHP_URL_PATH), $container);
            }

            return;
        }

        $this->assertCount(1, $desktopActive);
        $this->assertSame($desktopSlot, $desktopActive->attr('data-nav-slot'));
        $this->assertCount(0, $crawler->filter('[data-account-menu-panel] [aria-current="page"]'));
    }

    /**
     * The path and the crumbs the top bar shows for it (from lg up).
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function topBarBreadcrumbs(): array
    {
        return [
            'dashboard' => ['/dashboard', ['Beranda']],
            'class list' => ['/class', ['Kuliah', 'Jadwal & Kelas']],
            'grades' => ['/grades', ['Akademik', 'Nilai']],
            'skkm' => ['/skkm', ['Lainnya', 'SKKM']],
            'notifications' => ['/notifications', ['Notifikasi']],
        ];
    }

    /**
     * @param  list<string>  $crumbs
     */
    #[DataProvider('topBarBreadcrumbs')]
    public function test_the_top_bar_shows_where_the_page_sits(string $path, array $crumbs): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl($path);
        $topBar = $crawler->filter('header [data-top-bar-crumbs]');
        $breadcrumb = $topBar->filter('nav[aria-label="Lokasi halaman"]');

        $this->assertStringContainsString('lg:flex', (string) $topBar->attr('class'));
        $this->assertStringContainsString('hidden', (string) $topBar->attr('class'));
        $this->assertSame($crumbs, $breadcrumb->filter('li > :not([aria-hidden])')->each(fn (Crawler $crumb): string => $crumb->text()));
        $this->assertSame(end($crumbs), $breadcrumb->filter('[aria-current="page"]')->text());
        // Text only: no accent tile or section icon before the crumbs.
        $this->assertCount(0, $breadcrumb->filter('svg'));

        // The top bar is the only breadcrumb: the page's own header repeats none.
        $this->assertCount(0, $crawler->filter('main nav[aria-label="Lokasi halaman"]'));
    }

    public function test_the_sidebar_marks_the_current_section_without_a_colour(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/krs');
        $items = $crawler->filter('nav[aria-label="Navigasi utama"] a[data-nav-slot]');

        // No coloured edge marker: the current item is raised (panel + ring) and its icon turns ink.
        $this->assertCount(0, $items->filter('[data-slot="sidebar-marker"]'));
        $this->assertDoesNotMatchRegularExpression('/\b(violet|emerald|rose|indigo|teal|orange|sky|fuchsia|slate|zinc)-\d/', (string) $crawler->filter('aside')->html());

        $current = $crawler->filter('nav[aria-label="Navigasi utama"] a[aria-current="page"]');
        $this->assertSame(['akademik'], $current->each(fn (Crawler $item): ?string => $item->attr('data-nav-slot')));
        $this->assertStringContainsString('aria-[current=page]:ring-border', (string) $current->attr('class'));
        $this->assertStringContainsString('group-aria-[current=page]:text-ink', (string) $current->filter('[data-slot="sidebar-icon"]')->attr('class'));

        // Nothing wide scrolls the page sideways, and the panel is no scroll container (sticky asides still stick).
        $this->assertContains('overflow-x-clip', explode(' ', (string) $crawler->filter('main')->attr('class')));
    }

    public function test_nav_slots_carry_badge_hooks(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');

        foreach (['kuliah', 'akademik', 'lainnya'] as $slot) {
            $this->assertCount(1, $crawler->filter('nav[aria-label="Navigasi mobile"] [data-nav-badge="'.$slot.'"]'), $slot);
        }

        // The sidebar's "Semua menu" has its own key: Keuangan is a top-level link there.
        foreach (['kuliah', 'akademik', 'keuangan', 'lainnya-desktop', 'skkm', 'announcement'] as $slot) {
            $this->assertCount(1, $crawler->filter('nav[aria-label="Navigasi utama"] [data-nav-badge="'.$slot.'"]'), $slot);
        }

        foreach (['profile', 'password'] as $slot) {
            $this->assertCount(1, $crawler->filter('aside [data-account-menu-panel] [data-nav-badge="'.$slot.'"]'), $slot);
            $this->assertCount(1, $crawler->filter('header [data-account-menu-panel] [data-nav-badge="'.$slot.'"]'), $slot);
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
        // Two copies of the account menu: the sidebar's (lg+) and the top bar's avatar menu (below lg).
        $this->assertCount(1, $crawler->filter('aside [data-account-menu-trigger] [data-user-avatar]'));
        $this->assertCount(1, $crawler->filter('header [data-account-menu-trigger] [data-user-avatar]'));
        $this->assertCount(1, $crawler->filter('header [data-account-menu-panel] [data-logout]'));
        $this->assertStringContainsString('lg:hidden', (string) $crawler->filter('header [data-account-menu]')->attr('class'));
    }

    public function test_desktop_nav_is_a_sidebar_beside_the_page_instead_of_an_overlay(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');
        $nav = $crawler->filter('aside nav[aria-label="Navigasi utama"]');
        $classes = explode(' ', (string) $nav->attr('class'));

        $this->assertCount(1, $nav);
        $this->assertCount(0, $crawler->filter('main nav[aria-label="Navigasi utama"]'));
        $this->assertNotContains('absolute', $classes);
        $this->assertContains('lg:flex', explode(' ', (string) $crawler->filter('aside')->attr('class')));
        $this->assertContains('lg:hidden', explode(' ', (string) $crawler->filter('nav[aria-label="Navigasi mobile"]')->attr('class')));
    }

    public function test_sync_strip_sits_in_the_top_bar_from_lg_and_above_the_content_below(): void
    {
        $this->mockValidCampusSession('token-shell');

        $crawler = $this->crawl('/dashboard');
        $desktop = $crawler->filter('header [data-sync-alert]');
        $mobile = $crawler->filter('main [data-sync-alert]');

        $this->assertCount(1, $desktop);
        $this->assertCount(1, $mobile);
        // The strip's own class is rewritten by app.setSyncStatus(), so its wrapper does the showing.
        $this->assertStringContainsString('lg:block', (string) $desktop->ancestors()->first()->attr('class'));
        $this->assertStringContainsString('lg:hidden', (string) $mobile->ancestors()->first()->attr('class'));
        $this->assertCount(1, $crawler->filter('[data-pull-indicator]'));
        $this->assertCount(1, $crawler->filter('[data-operation-status]'));
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
