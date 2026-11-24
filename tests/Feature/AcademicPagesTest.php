<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class AcademicPagesTest extends TestCase
{
    private const TOKEN = 'token-academic-pages';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function academicPages(): array
    {
        return [
            'krs' => ['/krs', 'krs-index', 'KRS & Perwalian', '/krs'],
            'krs detail' => ['/krs/90004', 'krs-show', 'Detail KRS', '/krs'],
            'grades' => ['/grades', 'grades-index', 'Nilai (KHS)', '/grades'],
            'transcript' => ['/transcript', 'transcript-index', 'Transkrip & Progres Studi', '/transcript'],
        ];
    }

    #[DataProvider('academicPages')]
    public function test_page_renders_with_its_sync_key_title_and_academic_menu(string $path, string $pageId, string $title, string $activeMenu): void
    {
        $crawler = $this->crawl($path);

        $this->assertSame($pageId, $crawler->filter('body')->attr('data-page'));
        $this->assertSame($title, trim($crawler->filter('title')->text()));

        $menu = $crawler->filter('main nav[aria-label="Menu akademik"] a');

        $this->assertSame(['KRS & Perwalian', 'Nilai', 'Transkrip'], $menu->each(fn (Crawler $link): string => trim($link->text())));
        $this->assertSame(['/krs', '/grades', '/transcript'], $menu->each(
            fn (Crawler $link): string => (string) parse_url((string) $link->attr('href'), PHP_URL_PATH)
        ));

        $current = $crawler->filter('main nav[aria-label="Menu akademik"] a[aria-current="page"]');

        $this->assertCount(1, $current);
        $this->assertSame($activeMenu, parse_url((string) $current->attr('href'), PHP_URL_PATH));
        // From lg up the sidebar trail lists these pages, so the in-page tabs show on phones only.
        $this->assertContains('lg:hidden', explode(' ', (string) $crawler->filter('main [data-slot="page-hero-tabs"]')->attr('class')));
        $this->assertGreaterThan(0, $crawler->filter('main [aria-busy="true"]')->count(), 'A skeleton is shown before the data loads.');
        $this->assertSame(1, $crawler->filter('main h1')->count());
    }

    #[DataProvider('academicPages')]
    public function test_page_loads_snapshot_first_and_registers_its_sync_handler(string $path, string $pageId): void
    {
        $html = $this->html($path);

        $this->assertStringContainsString('app.primeCachedView(', $html);
        $this->assertStringContainsString('await app.ensureAuthenticated()', $html);
        $this->assertStringContainsString('{ fresh: force, background }', $html, 'The sync handler passes the background flag on to its read.');
        $this->assertStringContainsString('app.markViewSynced(', $html);
        $this->assertStringContainsString('app.mountErrorState(', $html);
        $this->assertStringContainsString("app.registerSyncHandler('".$pageId."'", $html);
        $this->assertStringNotContainsString('Diperbarui ${ago}', $html);
    }

    #[DataProvider('academicPages')]
    public function test_page_redirects_to_login_without_a_session(string $path): void
    {
        $this->get($path)
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token');
    }

    #[DataProvider('academicPages')]
    public function test_page_redirects_to_login_when_the_campus_session_has_expired(string $path): void
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

    public function test_krs_page_shows_the_current_semester_tab_by_default(): void
    {
        $crawler = $this->crawl('/krs');
        $tabs = $crawler->filter('main [role="tablist"][aria-label="Bagian halaman KRS"] [role="tab"]');

        $this->assertSame(['Semester ini', 'Riwayat & DPA'], $tabs->each(fn (Crawler $tab): string => trim($tab->text())));
        $this->assertSame(['true', 'false'], $tabs->each(fn (Crawler $tab): string => (string) $tab->attr('aria-selected')));
        $this->assertSame(['0', '-1'], $tabs->each(fn (Crawler $tab): string => (string) $tab->attr('tabindex')));
        $this->assertSame(['krs-panel-semester', 'krs-panel-riwayat'], $tabs->each(fn (Crawler $tab): string => (string) $tab->attr('aria-controls')));

        $this->assertNull($crawler->filter('#krs-panel-semester')->attr('hidden'));
        $this->assertNotNull($crawler->filter('#krs-panel-riwayat')->attr('hidden'));
        $this->assertSame('tabpanel', $crawler->filter('#krs-panel-semester')->attr('role'));
        $this->assertSame('krs-tab-semester', $crawler->filter('#krs-panel-semester')->attr('aria-labelledby'));

        foreach (['#krs-status', '#krs-courses', '#krs-schedule', '#krs-notes', '#krs-print-header', '#krs-dpa', '#krs-history'] as $host) {
            $this->assertCount(1, $crawler->filter($host), $host);
        }

        // Freshness lives only in the shared sync strip, without duplicating it in the page hero.
        $this->assertCount(0, $crawler->filter('[data-krs-updated]'));
        $this->assertCount(0, $crawler->filter('main [data-slot="page-hero"] p'));
    }

    public function test_krs_page_opens_the_history_tab_from_the_query_string(): void
    {
        $crawler = $this->crawl('/krs?tab=riwayat');
        $tabs = $crawler->filter('main [role="tab"][data-krs-tab]');

        $this->assertSame(['false', 'true'], $tabs->each(fn (Crawler $tab): string => (string) $tab->attr('aria-selected')));
        $this->assertNotNull($crawler->filter('#krs-panel-semester')->attr('hidden'));
        $this->assertNull($crawler->filter('#krs-panel-riwayat')->attr('hidden'));
    }

    public function test_krs_page_ignores_an_unknown_tab(): void
    {
        $crawler = $this->crawl('/krs?tab=%3Cscript%3E');

        $this->assertSame('true', $crawler->filter('#krs-tab-semester')->attr('aria-selected'));
        $this->assertNull($crawler->filter('#krs-panel-semester')->attr('hidden'));
        $this->assertStringNotContainsString('<script>alert', $this->html('/krs?tab=%3Cscript%3Ealert(1)%3C/script%3E'));
    }

    public function test_krs_page_reads_krs_and_advising_and_contacts_the_dpa_through_the_contact_sheet(): void
    {
        $html = $this->html('/krs');

        $this->assertStringContainsString("app.request('/api/academic/krs', { fresh: force, background })", $html);
        $this->assertStringContainsString("app.request('/api/academic/advising', { fresh: force, background })", $html);
        $this->assertStringContainsString("{ aliases: ['advising-index', 'announcement-index'] }", $html);
        $this->assertStringContainsString('app.renderContactSheet(', $html);
        $this->assertStringContainsString('app.renderContactActions(', $html);
        $this->assertStringContainsString('Hubungi DPA', $html);
        $this->assertStringContainsString('Tahapan KRS', $html);
        $this->assertStringContainsString('aria-current="step"', $html);
        $this->assertStringContainsString("row.semester === 'T' ? 'Konversi' : 'Non-reguler'", $html);
        $this->assertStringContainsString('SKS diakui', $html);
        $this->assertStringContainsString('krs.print_available === true', $html);
        $this->assertStringContainsString('Cetak KRS', $html);
        $this->assertStringContainsString('bukan dokumen resmi', $html);
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('href="/krs/${esc(row.krs_id)}"', $html);
        $this->assertStringContainsString('window.setInterval(', $html);
        $this->assertStringContainsString("'visibilitychange'", $html);
    }

    public function test_krs_detail_page_carries_its_id(): void
    {
        $crawler = $this->crawl('/krs/90004');

        $this->assertSame('90004', $crawler->filter('#krs-show-root')->attr('data-krs-id'));
        $this->assertSame('Kartu Rencana Studi', trim($crawler->filter('#krs-show-pretitle')->text()));
        $this->assertStringNotContainsString('#90004', $crawler->filter('main header')->text());

        foreach (['#krs-show-courses', '#krs-show-aside', '#krs-show-alerts', '#krs-show-chips', '#krs-show-print-note'] as $host) {
            $this->assertCount(1, $crawler->filter($host), $host);
        }

        $this->assertSame('Semester lain', $crawler->filter('#krs-show-nav')->attr('aria-label'));

        // "Cetak KRS" prints the title, the period line and the status chips; the
        // academic menu stays off paper.
        foreach (['#krs-show-title', '#krs-show-subtitle', '#krs-show-pretitle', '#krs-show-chips'] as $printed) {
            $node = $crawler->filter('main '.$printed);
            $this->assertCount(1, $node, $printed);
            $this->assertCount(0, $node->ancestors()->reduce(fn (Crawler $ancestor): bool => in_array('print:hidden', explode(' ', (string) $ancestor->attr('class')), true)), $printed);
            $this->assertNotContains('print:hidden', explode(' ', (string) $node->attr('class')), $printed);
        }

        $this->assertContains('print:hidden', explode(' ', (string) $crawler->filter('main [data-slot="page-hero-tabs"]')->attr('class')));
        $this->assertCount(0, $crawler->filter('main [data-krs-show-updated]'));

        $html = $this->html('/krs/90004');

        $this->assertStringContainsString('const endpoint = `/api/academic/krs/${krsId}`;', $html);
        $this->assertStringContainsString("{ aliases: ['advising-index'] }", $html);
        $this->assertStringContainsString('error?.status === 404', $html);
        $this->assertStringContainsString('KRS semester T berisi mata kuliah konversi', $html);
        $this->assertStringContainsString("'/api/academic/grades'", $html);
    }

    public function test_krs_detail_page_rejects_a_non_numeric_id(): void
    {
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/krs/abc')
            ->assertNotFound();
    }

    public function test_grades_page_has_summary_semester_chips_and_an_explained_empty_state(): void
    {
        $crawler = $this->crawl('/grades');

        $this->assertSame(['IPK', 'IPS', 'SKS lulus'], $crawler->filter('#grades-summary [data-slot="metric-label"]')->each(
            fn (Crawler $label): string => trim($label->text())
        ));
        $this->assertSame('tablist', $crawler->filter('#grades-semesters')->attr('role'));
        $this->assertSame('Pilih semester', $crawler->filter('#grades-semesters')->attr('aria-label'));
        $this->assertSame('tabpanel', $crawler->filter('#grades-panel')->attr('role'));
        $this->assertCount(1, $crawler->filter('main a[data-slot="card"][href$="/transcript"]'));

        $html = $this->html('/grades');

        $this->assertStringContainsString("const endpoint = '/api/academic/grades';", $html);
        $this->assertStringContainsString("{ aliases: ['krs-index'] }", $html);
        $this->assertStringContainsString('krs_validation', $html);
        $this->assertStringContainsString('KRS kamu belum divalidasi DPA', $html);
        $this->assertStringContainsString('dosen mengisi nilai', $html);
        $this->assertStringContainsString('Data sebagian', $html);
        $this->assertStringContainsString('Semester T berisi mata kuliah konversi', $html);
        $this->assertStringContainsString("absen: 'Absen', tugas: 'Tugas', uts: 'UTS', uas: 'UAS'", $html);
        $this->assertStringContainsString("searchParams.set('semester', key)", $html);
    }

    public function test_transcript_page_has_the_simulator_form_with_labels_and_a_live_result(): void
    {
        $crawler = $this->crawl('/transcript');

        $target = $crawler->filter('#transcript-sim-target');
        $sks = $crawler->filter('#transcript-sim-sks');

        $this->assertSame('Target IPK', trim($crawler->filter('label[for="transcript-sim-target"]')->text()));
        $this->assertSame('SKS yang akan ditempuh', trim($crawler->filter('label[for="transcript-sim-sks"]')->text()));
        $this->assertSame(['0', '4', '0.01'], [$target->attr('min'), $target->attr('max'), $target->attr('step')]);
        $this->assertSame(['1', '200', '1'], [$sks->attr('min'), $sks->attr('max'), $sks->attr('step')]);
        $this->assertStringContainsString('transcript-sim-target-error', (string) $target->attr('aria-describedby'));
        $this->assertSame('polite', $crawler->filter('#transcript-sim-result')->attr('aria-live'));
        $this->assertNotNull($target->attr('disabled'), 'The simulator waits for the transcript data.');
        $this->assertCount(1, $crawler->filter('label[for="transcript-search"]'));
        $this->assertSame('transcript-table', $crawler->filter('#transcript-search')->attr('aria-controls'));

        foreach (['#transcript-hero', '#transcript-trend', '#transcript-letters', '#transcript-low', '#transcript-table', '#transcript-alerts'] as $host) {
            $this->assertCount(1, $crawler->filter($host), $host);
        }
    }

    public function test_transcript_page_draws_charts_inline_and_explains_unreachable_targets(): void
    {
        $html = $this->html('/transcript');

        $this->assertStringContainsString("const endpoint = '/api/academic/transcript';", $html);
        $this->assertStringContainsString("{ aliases: ['grades-index', 'krs-index'] }", $html);
        $this->assertStringContainsString('app.renderSparkline(', $html);
        $this->assertStringContainsString('app.renderStackedBar(', $html);
        $this->assertStringContainsString('app.renderProgressBar(', $html);
        $this->assertStringContainsString('Tidak tercapai semester ini', $html);
        $this->assertStringContainsString('dihitung WeCIC', $html);
        $this->assertStringContainsString('Angka resmi portal berbeda dengan hitungan WeCIC', $html);
        $this->assertStringContainsString('Kalau diulang dan dapat B', $html);
        $this->assertStringContainsString('projected_semesters_remaining', $html);
        $this->assertStringContainsString('Math.ceil(Number((needed * 100).toFixed(6))) / 100', $html);
        $this->assertStringNotContainsString('chart.js', $html);
    }

    public function test_pages_never_expose_portal_write_actions(): void
    {
        foreach (['/krs', '/krs/90004', '/grades', '/transcript'] as $path) {
            $html = $this->html($path);

            $this->assertStringNotContainsString('transcript_print_url', $html, $path);
            $this->assertStringNotContainsString('registration_form.', $html, $path);
            $this->assertDoesNotMatchRegularExpression('/act=(?:jobdel|jobdelujian|kesesuaian)/', $html, $path);
        }
    }

    private function crawl(string $path): Crawler
    {
        return new Crawler($this->html($path));
    }

    private function html(string $path): string
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
            })
        );

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->get($path);

        $response->assertOk();

        return (string) $response->getContent();
    }
}
