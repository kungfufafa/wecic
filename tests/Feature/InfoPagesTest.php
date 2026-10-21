<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalEndpointRegistry;
use App\Services\PortalSessionManager;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class InfoPagesTest extends TestCase
{
    private const TOKEN = 'token-info';

    private const ANNOUNCEMENT_ID = '3f1c2b0a9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function infoPages(): array
    {
        return [
            'announcements' => ['/announcement', 'announcement-index', "app.registerSyncHandler('announcement-index', loadAnnouncements);", "app.primeCachedView('/api/announcement', renderFeed, 'pengumuman');"],
            'announcement detail' => ['/announcement/'.self::ANNOUNCEMENT_ID, 'announcement-show', "app.registerSyncHandler('announcement-show', loadAnnouncement, { aliases: ['announcement-index'] });", "app.primeCachedView(detailUrl, renderDetail, 'detail pengumuman')"],
            'skkm' => ['/skkm', 'skkm-index', "app.registerSyncHandler('skkm-index', loadSkkm);", "app.primeCachedView('/api/skkm', renderSkkm, 'poin SKKM');"],
            'finance' => ['/finance', 'finance', "app.registerSyncHandler('finance', loadFinance, { aliases: ['portal-context', 'announcement-index'] });", "app.primeCachedView('/api/finance/summary', renderSummary, 'ringkasan keuangan');"],
            'thesis' => ['/thesis', 'thesis-index', "app.registerSyncHandler('thesis-index', loadThesis);", "app.primeCachedView('/api/thesis', renderThesis, 'syarat & status skripsi');"],
            'library' => ['/library', 'library-index', "app.registerSyncHandler('library-index', loadLibrary);", "app.primeCachedView('/api/library', renderLibrary, 'perpustakaan digital');"],
            'library theses' => ['/library/theses', 'library-theses', "app.registerSyncHandler('library-theses', ({ force = false } = {}) => fetchResults({ force }));", "app.primeCachedView('/api/library/theses', (payload) => renderResults(payload), 'repositori skripsi');"],
        ];
    }

    #[DataProvider('infoPages')]
    public function test_page_follows_the_snapshot_first_load_cycle(string $path, string $pageId, string $syncHandler, string $prime): void
    {
        $this->mockValidCampusSession();

        $content = $this->page($path)
            ->assertSee('data-page="'.$pageId.'"', false)
            ->assertSee($prime, false)
            ->assertSee('await app.ensureAuthenticated();', false)
            ->assertSee('{ fresh: force }', false)
            ->assertSee('app.markViewSynced(', false)
            ->assertSee($syncHandler, false)
            ->assertSee('app.mountErrorState(', false)
            ->assertSee('aria-busy="true"', false)
            ->getContent();

        $this->assertLessThan(
            strpos((string) $content, 'await app.ensureAuthenticated();'),
            strpos((string) $content, $prime),
            'The device copy must render before the session check.'
        );
    }

    #[DataProvider('infoPages')]
    public function test_page_redirects_to_login_without_a_session(string $path): void
    {
        $this->get($path)
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token');
    }

    #[DataProvider('infoPages')]
    public function test_page_redirects_to_login_when_the_session_has_expired(string $path): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->once()->with('expired-token')->andReturnFalse();
            })
        );

        $this->withSession(['campus_session_token' => 'expired-token'])
            ->get($path)
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token');
    }

    #[DataProvider('infoPages')]
    public function test_device_storage_is_only_touched_inside_try_blocks(string $path): void
    {
        $this->mockValidCampusSession();

        $script = $this->pageScript($path);

        $this->assertDoesNotMatchRegularExpression('/(?<!window\.)localStorage\./', $script, 'Use the guarded storage helper.');
        $this->assertSame(
            substr_count($script, 'window.localStorage.'),
            preg_match_all('/try \{\s+(?:const raw = )?window\.localStorage\.(?:getItem|setItem)\(/', $script),
            'Every storage read and write sits directly in a try block.'
        );
        $this->assertStringNotContainsString('sessionStorage', $script);
    }

    public function test_announcement_list_offers_search_filters_deadlines_and_paging(): void
    {
        $this->mockValidCampusSession();

        $response = $this->page('/announcement');
        $crawler = new Crawler((string) $response->getContent());

        $this->assertCount(1, $crawler->filter('label[for="announcement-search"]'));
        $this->assertSame('search', $crawler->filter('#announcement-search')->attr('type'));
        $this->assertSame('announcement-result-status', $crawler->filter('#announcement-search')->attr('aria-describedby'));
        $this->assertSame('polite', $crawler->filter('#announcement-result-status')->attr('aria-live'));
        $this->assertSame('group', $crawler->filter('#announcement-categories')->attr('role'));
        $this->assertSame('Saring kategori pengumuman', $crawler->filter('#announcement-categories')->attr('aria-label'));
        $this->assertStringContainsString('hidden', (string) $crawler->filter('#announcement-deadlines')->attr('class'));
        $this->assertSame('Tenggat mendatang', trim($crawler->filter('#announcement-deadlines-title')->text()));
        $this->assertCount(3, $crawler->filter('#announcement-list .mycic-skeleton.w-4\\/5'));

        $response
            ->assertSee("app.request('/api/announcement', { fresh: force })", false)
            ->assertSee('app.request(`/api/announcement?page=${page}`)', false)
            ->assertSee('app.formatRelativeDays(deadline.date)', false)
            ->assertSee("const READ_KEY = 'mycic-announcement-read';", false)
            ->assertSee('data-unread-dot', false)
            ->assertSee('Belum dibaca.', false)
            ->assertSee('Muat pengumuman lama')
            ->assertSee('Belum ada pengumuman.')
            ->assertSee('Tidak ada pengumuman yang cocok.')
            ->assertSee('}, 250);', false)
            ->assertSee("CATEGORY_ORDER.includes(requestedCategory) ? requestedCategory : 'semua'", false);
    }

    public function test_announcement_detail_keeps_its_id_and_opens_files_through_the_proxy(): void
    {
        $this->mockValidCampusSession();

        $response = $this->page('/announcement/'.self::ANNOUNCEMENT_ID);
        $crawler = new Crawler((string) $response->getContent());

        $this->assertSame(self::ANNOUNCEMENT_ID, $crawler->filter('#announcement-detail')->attr('data-announcement-id'));
        $this->assertSame(route('announcement.index'), $crawler->filter('[data-announcement-back]')->attr('href'));

        $response
            ->assertSee('const detailUrl = `/api/announcement/${announcementId}`;', false)
            ->assertSee("app.getCachedResponse('/api/announcement')", false)
            ->assertSee('app.buildCampusDownloadUrl(link.url, fileName(link))', false)
            ->assertSee('app.safeHref(element.getAttribute(\'href\'))', false)
            ->assertSee("element.setAttribute('rel', 'noopener noreferrer');", false)
            ->assertSee("'text/calendar;charset=utf-8'", false)
            ->assertSee('mycic-sticky-action md:hidden', false)
            ->assertSee('Pengumuman tidak ditemukan')
            ->assertSee('Langkah yang perlu dilakukan')
            ->assertSee('error?.status === 404', false);
    }

    public function test_skkm_add_button_opens_the_official_portal_form_and_says_so(): void
    {
        $this->mockValidCampusSession();

        $crawler = new Crawler((string) $this->page('/skkm')->getContent());
        $add = $crawler->filter('a[data-skkm-add]');

        $this->assertCount(1, $add);
        $this->assertSame(PortalEndpointRegistry::url('student_skkm_add_form'), $add->attr('href'));
        $this->assertSame('_blank', $add->attr('target'));
        $this->assertStringContainsString('noopener', (string) $add->attr('rel'));
        $this->assertStringContainsString('Tambah SKKM di portal', $add->text());
        $this->assertStringContainsString('membuka portal kampus di tab baru', $add->filter('.sr-only')->text());
        $this->assertStringContainsString('perlu masuk lagi', $crawler->filter('#'.$add->attr('aria-describedby'))->text());
        $this->assertCount(1, $crawler->filter('.mycic-sticky-action a[data-skkm-add]'));
    }

    public function test_skkm_page_shows_points_status_filters_and_the_guide(): void
    {
        $this->mockValidCampusSession();

        $response = $this->page('/skkm');
        $crawler = new Crawler((string) $response->getContent());

        $this->assertSame('group', $crawler->filter('#skkm-metrics')->attr('role'));
        $this->assertSame('group', $crawler->filter('#skkm-tabs')->attr('role'));
        $this->assertSame('polite', $crawler->filter('#skkm-filter-status')->attr('aria-live'));
        $this->assertSame('Pedoman SKKM', trim($crawler->filter('#skkm-guide-title')->text()));
        $this->assertSame('Validasi oleh DPA', trim($crawler->filter('#skkm-dpa-title')->text()));

        $response
            ->assertSee("app.request('/api/skkm', { fresh: force })", false)
            ->assertSee('summary.valid_points ?? stats.total_points?.valid ?? null', false)
            ->assertDontSee('stat_counts?.valid', false)
            ->assertSee('SIREKA tidak bisa diakses')
            ->assertSee('Ini bukan berarti poinmu nol.')
            ->assertSee('Memenuhi syarat kelulusan')
            ->assertSee('Belum memenuhi syarat kelulusan')
            ->assertSee('Status kelulusan tidak diketahui')
            ->assertSee('aria-pressed="${filter === metric.key ? \'true\' : \'false\'}"', false)
            ->assertSee('app.buildCampusDownloadUrl(path, String(path).split(\'/\').pop())', false)
            ->assertSee('app.buildCampusDownloadUrl(entry.attachment_path, attachmentName)', false)
            ->assertSee('app.renderContactButton(', false)
            ->assertSee('/krs?tab=riwayat', false);
    }

    public function test_finance_page_puts_bill_status_and_ukt_stages_above_the_history(): void
    {
        $this->mockValidCampusSession();

        $response = $this->page('/finance');
        $content = (string) $response->getContent();
        $crawler = new Crawler($content);

        $this->assertSame('Status tagihan', trim($crawler->filter('#tagihan h2')->text()));
        $this->assertSame('Progres UKT', trim($crawler->filter('#tahap-ukt h2')->text()));
        $this->assertSame(PortalEndpointRegistry::url('student_finance'), $crawler->filter('#finance-page')->attr('data-portal-finance-url'));
        $this->assertCount(1, $crawler->filter('label[for="finance-search"]'));
        $this->assertLessThan(strpos($content, 'id="finance-payments"'), strpos($content, 'id="tagihan"'));
        $this->assertLessThan(strpos($content, 'id="finance-payments"'), strpos($content, 'id="tahap-ukt"'));

        $response
            ->assertSee('Terbayar')
            ->assertSee("app.request('/api/finance/summary', { fresh: force })", false)
            ->assertSee("app.request('/api/finance/payments', { fresh: force })", false)
            ->assertSee('Status tagihan tidak terbaca')
            ->assertSee('Tidak ada tagihan aktif')
            ->assertSee('Total tagihan aktif')
            ->assertSee('data-finance-retry', false)
            ->assertSee('Salin nominal')
            ->assertSee('Cara bayar')
            ->assertSee('Belum pasti')
            ->assertSee('tidak tercatat di histori portal')
            ->assertSee('app.mountErrorState(container, \'Riwayat pembayaran gagal dimuat.\'', false)
            ->assertSee('app.mountErrorState(billsHost, \'Status tagihan gagal dimuat.\'', false)
            ->assertSee('app.openSheet(', false)
            ->assertDontSee("'Rp 0'", false)
            ->assertDontSee("|| 'Lunas'", false)
            ->assertDontSee('finance-detail-modal', false)
            ->assertDontSee("app.showNotice(error.payload?.message || error.message, 'error');", false)
            ->assertDontSee('finance-student-badge');
    }

    public function test_portal_urls_cannot_break_out_of_the_markup(): void
    {
        config()->set('portal.base_url', 'https://portal.example.test/</script><script>alert(1)</script>');

        foreach (['/skkm', '/finance', '/thesis'] as $path) {
            $this->mockValidCampusSession();

            $this->page($path)
                ->assertDontSee('</script><script>alert(1)</script>', false)
                ->assertDontSee('"><script>', false);
        }
    }

    private function page(string $path): TestResponse
    {
        return $this->withSession(['campus_session_token' => self::TOKEN])
            ->get($path)
            ->assertOk();
    }

    /**
     * The inline script the page pushes, without the layout's own scripts.
     */
    private function pageScript(string $path): string
    {
        $content = (string) $this->page($path)->getContent();
        $start = strpos($content, "document.addEventListener('DOMContentLoaded', async () => {");

        $this->assertNotFalse($start, 'The page script was not found.');

        return substr($content, (int) $start, (int) strpos($content, '</script>', (int) $start) - (int) $start);
    }

    private function mockValidCampusSession(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
            })
        );
    }
}
