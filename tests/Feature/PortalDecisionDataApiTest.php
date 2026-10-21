<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\FinancialHistoryExtractor;
use App\Services\Extractors\SkkmExtractor;
use App\Services\PortalContextResolver;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * GET /api/dashboard (portal_context + metrics), /api/portal/context,
 * /api/finance/summary and /api/skkm.
 */
class PortalDecisionDataApiTest extends TestCase
{
    private const TOKEN = 'token-decision';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalDataJob::class]);
        $this->travelTo(CarbonImmutable::create(2026, 10, 20, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_dashboard_adds_portal_context_and_metrics_from_snapshots_without_waiting(): void
    {
        $this->mockValidCampusSession();
        $this->mockDashboardExtractor();
        $this->snapshots()->put(self::TOKEN, 'grades', ['summary' => [
            'ipk' => 3.1, 'source' => 'official', 'sks_passed' => 20, 'predicate_estimate' => 'Sangat Memuaskan',
            'progress' => ['target_sks' => 144, 'remaining_sks' => 124, 'progress_percent' => 13.9],
        ]]);
        $this->snapshots()->put(self::TOKEN, 'payments', $this->paymentsData('clear'));
        // Nothing else may be scraped inline for the secondary widgets.
        $this->instance(PortalContextResolver::class, Mockery::mock(PortalContextResolver::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('resolve');
        }));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.dashboard_overview.gpa', null)
            ->assertJsonPath('data.dashboard_overview.outstanding_payments', null)
            ->assertJsonPath('data.portal_context', null)
            ->assertJsonPath('data.metrics.ipk.value', 3.1)
            ->assertJsonPath('data.metrics.ipk.status', 'ready')
            ->assertJsonPath('data.metrics.sks_passed.value', 20)
            ->assertJsonPath('data.metrics.sks_current.status', 'pending')
            ->assertJsonPath('data.metrics.bills.status', 'clear')
            ->assertJsonPath('data.metrics.skkm.status', 'pending')
            ->assertJsonPath('data.pending_sources', ['portal_context', 'krs', 'skkm']);

        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ['portal_context', 'krs', 'skkm']);
    }

    public function test_dashboard_includes_the_portal_context_snapshot(): void
    {
        $this->mockValidCampusSession();
        $this->mockDashboardExtractor();
        $this->snapshots()->put(self::TOKEN, 'portal_context', $this->portalContextData());

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.portal_context.summary_label', 'T.A 2026/2027 · Ganjil · Semester 3')
            ->assertJsonPath('data.portal_context.semester_number', 3)
            ->assertJsonPath('data.portal_context.questionnaire.available', true);
    }

    public function test_portal_context_endpoint_builds_the_dataset(): void
    {
        $this->mockValidCampusSession();
        $this->instance(PortalContextResolver::class, Mockery::mock(PortalContextResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->with(self::TOKEN)->andReturn([
                ...$this->portalContextData(),
                '_sync' => ['data_origin' => 'upstream'],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/portal/context')
            ->assertOk()
            ->assertJsonPath('data.academic_period.academic_year', '2026/2027')
            ->assertJsonPath('data.guide_links.0.path', 'files/Panduan_Uji.pdf')
            ->assertJsonPath('meta.data_origin', 'upstream')
            ->assertJsonMissingPath('data._sync');
    }

    public function test_finance_summary_reports_bills_stages_and_the_portal_deadline(): void
    {
        $this->mockValidCampusSession();
        $this->instance(FinancialHistoryExtractor::class, Mockery::mock(FinancialHistoryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractPaymentHistoryData')->once()->with(self::TOKEN)->andReturn($this->paymentsData('outstanding'));
        }));
        $this->snapshots()->put(self::TOKEN, 'portal_context', [
            ...$this->portalContextData(),
            'notice' => ['text' => 'Kewajiban Pembayaran Kuliah Tahap ke 2 paling lambat pada Hari Senin, 26 Oktober 2026', 'dates' => ['2026-10-26'], 'is_future' => true, 'kind' => 'payment'],
        ]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/finance/summary')
            ->assertOk()
            ->assertJsonPath('data.outstanding_summary.status', 'outstanding')
            ->assertJsonPath('data.has_outstanding', true)
            ->assertJsonPath('data.outstanding_total_label', 'Rp 1.250.000')
            ->assertJsonPath('data.outstanding_bills.0.name', 'UKT Tahap 2 tahun ke-1')
            ->assertJsonPath('data.current_study_year', 1)
            ->assertJsonPath('data.current_year_stages_paid', [1])
            ->assertJsonPath('data.stage1_paid_for_current_year', true)
            ->assertJsonPath('data.next_stage.stage', 2)
            ->assertJsonPath('data.active_deadline.date', '2026-10-26')
            ->assertJsonPath('data.active_deadline.days_left', 6)
            ->assertJsonPath('meta.notice_sources.portal_context', 'synced')
            ->assertJsonPath('meta.notice_sources.announcements', 'pending');
    }

    public function test_finance_summary_never_turns_an_unreadable_bill_table_into_rp_zero(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'payments', $this->paymentsData('unknown'));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/finance/summary');

        $response->assertOk()
            ->assertJsonPath('data.outstanding_summary.status', 'unknown')
            ->assertJsonPath('data.has_outstanding', null)
            ->assertJsonPath('data.outstanding_total', null)
            ->assertJsonPath('data.outstanding_total_label', null)
            ->assertJsonPath('data.active_deadline', null);
        $this->assertStringNotContainsString('Rp 0', (string) $response->getContent());
    }

    public function test_skkm_returns_points_and_strips_write_effect_links(): void
    {
        $this->mockValidCampusSession();
        $this->instance(SkkmExtractor::class, Mockery::mock(SkkmExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSkkmData')->once()->with(self::TOKEN)->andReturn([
                'found' => true,
                'stats' => ['stat_counts' => ['valid' => 2, 'invalid' => 0, 'pending' => 1], 'total_points' => ['valid' => 70, 'invalid' => 0, 'pending' => 10], 'required_points' => 100, 'required_points_source' => 'config'],
                'graduation_status' => ['text' => 'Belum Memenuhi', 'is_met' => false],
                'entries' => [[
                    'no' => 1, 'skkm_id' => '55', 'name' => 'Seminar Nasional', 'activity' => 'Ilmiah', 'level' => 'Nasional', 'type' => 'Peserta',
                    'points' => 10, 'points_label' => '10', 'status' => 'pending', 'status_label' => 'Belum divalidasi',
                    'has_attachment' => true, 'attachment_path' => 'files/skkm/bukti.pdf', 'has_actions' => true, 'action_types' => ['delete'],
                    'delete_href' => 'https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=delskkm&id=55',
                ]],
                'summary' => ['valid_points' => 70, 'pending_points' => 10, 'remaining_points' => 30, 'progress_percent' => 70.0],
                'notice' => null,
                'message' => null,
                'guide_path' => 'files/skkm/Pedoman_SKKM_Uji.pdf',
                'guide_year' => 2024,
                'add_available' => true,
                'extracted_at' => '2026-10-20T02:00:00+00:00',
            ]);
        }));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/skkm');

        $response->assertOk()
            ->assertJsonPath('data.found', true)
            ->assertJsonPath('data.summary.valid_points', 70)
            ->assertJsonPath('data.graduation_status.is_met', false)
            ->assertJsonPath('data.entries.0.attachment_path', 'files/skkm/bukti.pdf')
            ->assertJsonPath('data.entries.0.delete_href', null)
            ->assertJsonPath('data.guide_path', 'files/skkm/Pedoman_SKKM_Uji.pdf');
        $this->assertStringNotContainsString('act=delskkm', (string) $response->getContent());
    }

    public function test_decision_routes_require_a_valid_campus_session(): void
    {
        foreach (['/api/finance/summary', '/api/skkm', '/api/portal/context'] as $uri) {
            $this->getJson($uri)
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Sesi kampus tidak ditemukan. Silakan masuk lagi.');

            $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->once()->with('expired-token')->andReturnFalse();
            }));

            $this->withSession(['campus_session_token' => 'expired-token'])
                ->getJson($uri)
                ->assertUnauthorized()
                ->assertJsonPath('meta.campus_session_valid', false);
        }
    }

    public function test_skkm_upstream_session_expiry_is_unauthorized(): void
    {
        $this->mockValidCampusSession();
        $this->instance(SkkmExtractor::class, Mockery::mock(SkkmExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSkkmData')->once()->andThrow(PortalScrapingException::pageNotAccessible('skkm'));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/skkm')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.');
    }

    private function mockValidCampusSession(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
        }));
    }

    private function mockDashboardExtractor(): void
    {
        $this->instance(DashboardExtractor::class, Mockery::mock(DashboardExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractDashboardData')->once()->with(self::TOKEN)->andReturn([
                'user_info' => [],
                'dashboard_overview' => ['total_courses' => '6', 'gpa' => null, 'total_credits' => '14', 'outstanding_payments' => null],
                'recent_activity' => [],
                'classes_summary' => [],
                'status' => 'success',
                'timestamp' => '2026-10-20T09:00:00+07:00',
            ]);
        }));
    }

    private function snapshots(): PortalSnapshotStore
    {
        return app(PortalSnapshotStore::class);
    }

    /**
     * @return list<string>
     */
    private function jobDomains(RefreshPortalDataJob $job): array
    {
        return (new \ReflectionClass($job))->getProperty('domains')->getValue($job);
    }

    /**
     * @return array<string, mixed>
     */
    private function portalContextData(): array
    {
        return [
            'academic_period' => ['label' => 'T.A 2026/2027 Semester Ganjil', 'academic_year' => '2026/2027', 'term' => 'ganjil', 'source' => 'dashboard'],
            'server_date' => '2026-10-20',
            'server_date_label' => 'Selasa, 20 Oktober 2026',
            'guide_links' => [['label' => 'Panduan Uji', 'path' => 'files/Panduan_Uji.pdf', 'filename' => 'Panduan_Uji.pdf']],
            'questionnaire' => ['available' => true, 'title' => 'Kuesioner VMTS', 'academic_year' => '2026/2027', 'description' => null, 'details' => []],
            'notice' => null,
            'sidebar_menu' => [['label' => 'Dashboard', 'href_act' => 'dashboard']],
            'extracted_at' => '2026-10-20T02:00:00+00:00',
            'semester_number' => 3,
            'semester_source' => 'student_course',
            'semester_is_estimate' => false,
            'semester_label' => 'Semester 3',
            'summary_label' => 'T.A 2026/2027 · Ganjil · Semester 3',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentsData(string $status): array
    {
        return [
            'student_info' => ['academic_year' => '2026/2027'],
            'payment_history' => [[
                'no' => '1', 'paid_at' => '2026-09-05', 'paid_at_label' => '05 September 2026', 'category' => 'Pembayaran UKT',
                'amount' => 1250000, 'amount_label' => 'Rp 1.250.000', 'installment_stage' => 1, 'study_year' => 1, 'installment_amount' => 1250000,
            ]],
            'summary' => ['total_records' => 1, 'total_paid_amount' => 1250000, 'currency' => 'IDR', 'history_available' => true, 'latest_payment' => null],
            'outstanding_bills' => $status === 'outstanding' ? [[
                'no' => '1', 'name' => 'UKT Tahap 2 tahun ke-1', 'amount' => 1250000, 'amount_label' => 'Rp 1.250.000',
                'description' => null, 'installment_stage' => 2, 'study_year' => 1, 'installment_amount' => null, 'academic_year' => null, 'raw' => [],
            ]] : [],
            'outstanding_summary' => match ($status) {
                'outstanding' => ['status' => 'outstanding', 'has_outstanding' => true, 'total_amount' => 1250000, 'total_amount_label' => 'Rp 1.250.000', 'count' => 1, 'rows_sum_matches_total' => true],
                'clear' => ['status' => 'clear', 'has_outstanding' => false, 'total_amount' => 0, 'total_amount_label' => 'Rp 0', 'count' => 0, 'rows_sum_matches_total' => true],
                default => ['status' => 'unknown', 'has_outstanding' => null, 'total_amount' => null, 'total_amount_label' => null, 'count' => 0, 'rows_sum_matches_total' => null],
            },
            'extracted_at' => '2026-10-20T02:00:00+00:00',
        ];
    }
}
