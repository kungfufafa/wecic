<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class DashboardDecisionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_beranda_renders_the_hero_with_the_academic_period_hook(): void
    {
        $crawler = $this->crawlDashboard();

        $this->assertSame('Beranda', trim($crawler->filter('title')->text()));
        $this->assertSame('dashboard', $crawler->filter('body')->attr('data-page'));

        $hero = $crawler->filter('main [data-dashboard-widget="hero"]');
        $this->assertCount(1, $hero);
        $this->assertSame('dashboard-greeting', $hero->attr('aria-labelledby'));
        $this->assertCount(1, $hero->filter('h1#dashboard-greeting [data-user-name]'));
        $this->assertStringContainsString('Halo,', $hero->filter('h1#dashboard-greeting')->text());

        $period = $hero->filter('ul#dashboard-period');
        $this->assertCount(1, $period);
        $this->assertSame('Periode akademik', $period->attr('aria-label'));
        $this->assertSame('true', $period->attr('aria-busy'));
    }

    public function test_widgets_follow_the_beranda_priority_order_on_mobile(): void
    {
        $crawler = $this->crawlDashboard();

        // The DOM itself is in the §4.2 mobile order, so focus and reading order match the screen.
        $widgets = $crawler->filter('main [data-dashboard-widget]');
        $this->assertSame(
            ['hero', 'actions', 'today', 'assignments', 'metrics', 'announcements'],
            $widgets->each(fn (Crawler $widget): string => (string) $widget->attr('data-dashboard-widget'))
        );

        foreach (['actions', 'today', 'assignments', 'metrics', 'announcements'] as $widget) {
            $section = $crawler->filter('main [data-dashboard-widget="'.$widget.'"]');
            $classes = explode(' ', (string) $section->attr('class'));

            $this->assertContains('min-w-0', $classes, $widget);
            $this->assertEmpty(preg_grep('/^(?:lg:)?order-/', $classes), $widget);
            $this->assertNotNull($section->ancestors()->first()->attr('data-dashboard-layout'), $widget);
        }

        // From lg the widgets move into an 8/4 grid: main column first, then the aside.
        $columns = $crawler->filter('main [data-dashboard-layout] > [data-dashboard-column]');
        $this->assertSame(['main', 'aside'], $columns->each(fn (Crawler $column): string => (string) $column->attr('data-dashboard-column')));
        $this->assertContains('lg:col-span-8', explode(' ', (string) $columns->eq(0)->attr('class')));
        $this->assertContains('lg:col-span-4', explode(' ', (string) $columns->eq(1)->attr('class')));
        $this->assertContains('hidden', explode(' ', (string) $columns->eq(1)->attr('class')));
        $this->assertContains('lg:flex', explode(' ', (string) $columns->eq(1)->attr('class')));
    }

    public function test_every_widget_starts_with_its_own_skeleton(): void
    {
        $crawler = $this->crawlDashboard();

        foreach (['#dashboard-actions', '#dashboard-class-summary', '#dashboard-pending-assignments', '#dashboard-announcements'] as $host) {
            $node = $crawler->filter('main '.$host);

            $this->assertCount(1, $node, $host);
            $this->assertSame('true', $node->attr('aria-busy'), $host);
            $this->assertGreaterThan(0, $node->filter('.mycic-skeleton')->count(), $host);
            $this->assertGreaterThan(0, $node->filter('.sr-only')->count(), $host);
        }

        foreach (['ipk', 'sks_passed', 'sks_current', 'bills', 'skkm'] as $metric) {
            $tile = $crawler->filter('main [data-dashboard-metric="'.$metric.'"]');

            $this->assertSame('true', $tile->attr('aria-busy'), $metric);
            $this->assertCount(1, $tile->filter('[data-metric-value] .mycic-skeleton'), $metric);
        }
    }

    public function test_metric_tiles_are_labelled_distinctly_and_link_to_their_pages(): void
    {
        $crawler = $this->crawlDashboard();
        $tiles = $crawler->filter('main [data-dashboard-widget="metrics"] a[data-dashboard-metric]');

        $this->assertSame(
            ['ipk', 'sks_passed', 'sks_current', 'bills', 'skkm'],
            $tiles->each(fn (Crawler $tile): string => (string) $tile->attr('data-dashboard-metric'))
        );
        $this->assertSame(
            ['IPK', 'SKS lulus', 'SKS semester ini', 'Tagihan', 'Poin SKKM'],
            $tiles->each(fn (Crawler $tile): string => trim($tile->filter('.mycic-metric-label')->text()))
        );
        $this->assertSame(
            ['/transcript', '/transcript', '/krs', '/finance', '/skkm'],
            $tiles->each(fn (Crawler $tile): string => (string) parse_url((string) $tile->attr('href'), PHP_URL_PATH))
        );

        $tiles->each(function (Crawler $tile): void {
            $this->assertCount(1, $tile->filter('[data-metric-value]'));
            $this->assertCount(1, $tile->filter('[data-metric-note]'));
            $this->assertStringNotContainsString('Rp 0', $tile->text());
        });

        $this->assertCount(4, $crawler->filter('main [data-dashboard-widget="metrics"] .grid.grid-cols-2 > a.mycic-metric'));
    }

    public function test_metrics_read_the_snapshot_values_and_never_show_fake_zeroes(): void
    {
        $this->dashboardResponse()
            ->assertSee('const metrics = data?.metrics;', false)
            ->assertSee("setMetric('ipk', describeIpk(metrics.ipk));", false)
            ->assertSee("setMetric('sks_passed', describeSksPassed(metrics.sks_passed));", false)
            ->assertSee("setMetric('sks_current', describeSksCurrent(metrics.sks_current, data));", false)
            ->assertSee("setMetric('bills', describeBills(metrics.bills));", false)
            ->assertSee("setMetric('skkm', describeSkkm(metrics.skkm));", false)
            ->assertSee('IPK belum bisa dibaca dari portal.')
            ->assertSee('Status tagihan tidak terbaca dari portal.')
            ->assertSee('SIREKA tidak bisa diakses, jadi poin SKKM belum terbaca.')
            ->assertSee("value: 'Lunas'", false)
            ->assertSee("new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 2 })", false)
            ->assertSee('srValue: shortLabel !== fullLabel ? fullLabel : \'\',', false)
            ->assertSee('<span class="sr-only">belum ada data</span>', false)
            ->assertDontSee('Belum ada nilai yang keluar')
            ->assertDontSee('Rp 0')
            ->assertDontSee('overview.gpa', false);
    }

    public function test_action_center_reads_action_items_and_stays_in_sync(): void
    {
        $this->dashboardResponse()
            ->assertSee('Pusat tindakan')
            ->assertSee("app.primeCachedView('/api/action-items', renderActionCenter, 'pusat tindakan');", false)
            ->assertSee("app.request('/api/action-items', { fresh: force, timeout: 15000 })", false)
            ->assertSee('app.actionItemsRequest = ownActionItemsRequest;', false)
            ->assertSee('app.receiveActionItems(app.extractActionItems(payload));', false)
            ->assertSee("window.addEventListener('portal:action-items', renderActionCenterFromDevice);", false)
            ->assertSee("app.registerSyncHandler('dashboard-action-items', loadActionCenter);", false)
            ->assertSee('const ACTION_PREVIEW_LIMIT = 3;', false)
            ->assertSee('data-action-center-all aria-haspopup="dialog"', false)
            ->assertSee('app.openSheet({', false)
            ->assertSee("'Pusat tindakan gagal dimuat.'", false)
            ->assertSee('app.mountErrorState(', false);
    }

    public function test_action_items_show_severity_countdown_cta_and_dpa_contact(): void
    {
        $this->dashboardResponse()
            ->assertSee('app.renderSeverityBadge(item.severity)', false)
            ->assertSee('app.fillToneClass(', false)
            ->assertSee('app.daysUntil(item.due_at)', false)
            ->assertSee('<span class="sr-only">Tenggat:</span>', false)
            ->assertSee('app.safeHref(item.cta_url)', false)
            ->assertSee('target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('(membuka tab baru)')
            ->assertSee('app.renderContactButton({', false)
            ->assertSee("label: 'Hubungi DPA'", false)
            ->assertSee("role: 'Dosen Pembimbing Akademik (DPA)'", false)
            ->assertSee('Mohon bantuan validasi KRS saya di portal.');
    }

    public function test_action_center_explains_empty_pending_and_failed_sources(): void
    {
        $this->dashboardResponse()
            ->assertSee('Semua beres')
            ->assertSee('Tidak ada tenggat atau kewajiban yang perlu kamu urus sekarang.')
            ->assertSee('Belum ada yang perlu diurus')
            ->assertSee('meta.pending_sources', false)
            ->assertSee('sedang diperbarui dari portal. Daftar ini menyesuaikan sendiri setelah selesai.', false)
            ->assertSee('tidak terbaca dari portal, jadi belum masuk daftar ini.', false)
            ->assertSee("scheduleRecheck('actions',", false)
            ->assertSee('app.realtimeConnected', false);
    }

    public function test_dashboard_keeps_its_sync_handlers_and_load_sequence(): void
    {
        $this->dashboardResponse()
            ->assertSee("app.primeCachedView('/api/dashboard', renderDashboard, 'ringkasan beranda');", false)
            ->assertSee("app.primeCachedView('/api/assignment/pending', renderPendingAssignments, 'tugas belum dikumpulkan');", false)
            ->assertSee('const ready = await app.ensureAuthenticated();', false)
            ->assertSee("app.request('/api/dashboard', { fresh: force })", false)
            ->assertSee("app.markViewSynced('Beranda', payload, { force });", false)
            ->assertSee("app.registerSyncHandler('dashboard-overview', loadDashboard, { aliases: ['portal-context'] });", false)
            ->assertSee("app.registerSyncHandler('dashboard-pending-assignments', loadPendingAssignments);", false)
            ->assertSee("app.registerSyncHandler('dashboard-announcements', loadAnnouncements, { aliases: ['announcement-index'] });", false)
            ->assertSee('renderPeriod(data.portal_context, pendingSources);', false)
            ->assertSee("pendingSources.includes('portal_context')", false);
    }

    public function test_pull_to_refresh_rebuilds_only_stale_secondary_snapshots_in_the_background(): void
    {
        $this->dashboardResponse()
            ->assertSee("const SECONDARY_DATASETS = ['portal_context', 'krs', 'advising', 'grades', 'payments', 'skkm', 'announcements', 'exams'];", false)
            ->assertSee("app.request('/api/refresh', {", false)
            ->assertSee('json: { domains },', false)
            ->assertSee('app.filterRefreshableDomains(SECONDARY_DATASETS.filter(', false)
            ->assertSee('const SECONDARY_REFRESH_COOLDOWN_MS = 60 * 1000;', false)
            ->assertSee('void queueSecondaryRefresh();', false)
            ->assertSee("app.request('/api/announcement', { fresh: false })", false);
    }

    public function test_latest_announcements_card_links_to_the_feed_and_details(): void
    {
        $crawler = $this->crawlDashboard();
        $section = $crawler->filter('main [data-dashboard-widget="announcements"]');

        $this->assertSame('Pengumuman terbaru', trim($section->filter('h2')->text()));
        $this->assertSame('/announcement', parse_url((string) $section->filter('a')->attr('href'), PHP_URL_PATH));
        $this->assertSame('Lihat semua', trim($section->filter('a')->text()));
        $this->assertCount(2, $section->filter('#dashboard-announcements .mycic-card'));

        $this->dashboardResponse()
            ->assertSee("app.primeCachedView('/api/announcement', renderAnnouncements, 'pengumuman');", false)
            ->assertSee('.filter((item) => item && !item.duplicate_of)', false)
            ->assertSee('.slice(0, 2);', false)
            ->assertSee('/^[0-9a-f]{40}$/.test(', false)
            ->assertSee('`/announcement/${id}`', false)
            ->assertSee('Belum ada pengumuman.')
            ->assertSee("'Pengumuman gagal dimuat.'", false);
    }

    public function test_attendance_inputs_are_labelled_and_reachable_by_keyboard(): void
    {
        $this->dashboardResponse()
            ->assertSee('<label class="sr-only" for="dashboard-attendance-code-${index}">', false)
            ->assertSee('id="dashboard-attendance-code-${index}" data-field="kode"', false)
            ->assertSee('<input type="file" class="peer sr-only" id="qr-file-${index}" data-qr-file accept="image/*">', false)
            ->assertSee('peer-focus-visible:outline-2', false)
            ->assertSee('<label class="sr-only" for="dashboard-assignment-file-${index}">', false)
            ->assertDontSee('mycic-input hidden', false);
    }

    public function test_existing_schedule_attendance_and_assignment_flows_are_kept(): void
    {
        $this->dashboardResponse()
            ->assertSee('app.setQuickQrTarget(', false)
            ->assertSee('app.bindAttendanceQrTools(form, codeInput, {', false)
            ->assertSee("app.request('/api/attendance', {", false)
            ->assertSee("app.request('/api/assignment', {", false)
            ->assertSee("await app.trackOperation(operationId, 'Upload tugas');", false)
            ->assertSee('Jadwal hari ini sedang diambil dari portal kampus.', false)
            // Background syncs never re-render an unchanged schedule (a typed code survives).
            ->assertSee("if (!hasChanged('today', { date: classesSummary.date, day: classesSummary.day, todayClasses })) {", false)
            ->assertSee('typedCodes.set(form.dataset.attendanceKey, typed);', false)
            ->assertSee('const ASSIGNMENT_PREVIEW_LIMIT = 3;', false)
            ->assertSee('data-assignment-toggle aria-controls="dashboard-assignment-list"', false);
    }

    public function test_beranda_redirects_to_login_without_a_session(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token');
    }

    public function test_beranda_redirects_to_login_when_the_campus_session_expired(): void
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
            ->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token');
    }

    private function dashboardResponse(): TestResponse
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('token-beranda')
                    ->andReturnTrue();
            })
        );

        return $this->withSession(['campus_session_token' => 'token-beranda'])
            ->get('/dashboard')
            ->assertOk();
    }

    private function crawlDashboard(): Crawler
    {
        return new Crawler((string) $this->dashboardResponse()->getContent());
    }
}
