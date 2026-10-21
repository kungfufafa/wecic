<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshPortalDataJob;
use App\Services\ActionCenterBuilder;
use App\Services\Extractors\KrsExtractor;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalActionCenterApiTest extends TestCase
{
    private const TOKEN = 'token-action-items';

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

    public function test_action_items_are_built_from_snapshots_and_report_pending_sources(): void
    {
        $this->mockValidCampusSession();
        // Building the list must never scrape the portal inline.
        $this->instance(KrsExtractor::class, Mockery::mock(KrsExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractKrsData');
        }));
        $snapshots = app(PortalSnapshotStore::class);
        $snapshots->put(self::TOKEN, 'payments', [
            'student_info' => ['academic_year' => '2026/2027'],
            'payment_history' => [],
            'summary' => ['history_available' => true],
            'outstanding_bills' => [['no' => '1', 'name' => 'UKT Tahap 1 tahun ke-1', 'amount' => 1250000, 'amount_label' => 'Rp 1.250.000', 'installment_stage' => 1, 'study_year' => 1]],
            'outstanding_summary' => ['status' => 'outstanding', 'has_outstanding' => true, 'total_amount' => 1250000, 'total_amount_label' => 'Rp 1.250.000', 'count' => 1, 'rows_sum_matches_total' => true],
        ]);
        $snapshots->put(self::TOKEN, 'portal_context', [
            'academic_period' => ['label' => 'T.A 2026/2027 Semester Ganjil', 'academic_year' => '2026/2027', 'term' => 'ganjil', 'source' => 'dashboard'],
            'questionnaire' => ['available' => true, 'title' => 'Kuesioner VMTS', 'academic_year' => '2026/2027', 'description' => null, 'details' => []],
            'notice' => null,
        ]);

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/action-items');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.counts.total', 2)
            ->assertJsonPath('data.items.0.id', 'bills-outstanding')
            ->assertJsonPath('data.items.0.severity', 'warning')
            ->assertJsonPath('data.items.0.cta_url', '/finance')
            ->assertJsonPath('data.items.0.source', 'payments')
            ->assertJsonPath('data.items.1.type', 'questionnaire')
            ->assertJsonPath('data.items.1.cta_external', true)
            ->assertJsonPath('meta.campus_session_valid', true)
            ->assertJsonPath('meta.pending_sources', ['krs', 'advising', 'skkm', 'announcements', 'grades', 'exams'])
            ->assertJsonPath('meta.sources.payments.status', 'ready')
            ->assertJsonPath('meta.is_complete', false);

        $this->assertStringContainsString('PIN KRS', (string) $response->json('data.items.0.description'));
        $this->assertSame(
            ['id', 'type', 'title', 'description', 'due_at', 'days_left', 'severity', 'cta_label', 'cta_url', 'cta_external', 'source'],
            array_keys($response->json('data.items.1'))
        );
        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
    }

    public function test_action_items_are_empty_but_complete_when_every_source_is_clear(): void
    {
        $this->mockValidCampusSession();
        $snapshots = app(PortalSnapshotStore::class);

        foreach (['krs' => ['found' => true, 'status' => ['code' => 'validated'], 'periods' => []], 'advising' => ['found' => true], 'payments' => ['outstanding_summary' => ['status' => 'clear', 'total_amount' => 0, 'total_amount_label' => 'Rp 0', 'count' => 0]], 'skkm' => ['found' => true, 'graduation_status' => ['is_met' => true]], 'announcements' => ['items' => [], 'upcoming_deadlines' => []], 'portal_context' => ['questionnaire' => ['available' => false]], 'grades' => ['summary' => ['computed' => ['d_courses' => [], 'e_courses' => []]]], 'exams' => ['exams' => [], 'source' => 'exam_page'], 'assignments' => ['assignments' => []]] as $dataset => $data) {
            $snapshots->put(self::TOKEN, $dataset, $data);
        }

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/action-items')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.counts.total', 0)
            ->assertJsonPath('meta.pending_sources', [])
            ->assertJsonPath('meta.failed_sources', [])
            ->assertJsonPath('meta.missing_sources', [])
            ->assertJsonPath('meta.is_complete', true);

        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_action_items_are_not_complete_without_the_coursework_snapshots(): void
    {
        $this->mockValidCampusSession();
        $snapshots = app(PortalSnapshotStore::class);

        // Everything clear except that nobody has crawled pending assignments yet:
        // an open upload or a task due today could still be out there.
        foreach (['krs' => ['found' => true, 'status' => ['code' => 'validated'], 'periods' => []], 'advising' => ['found' => true], 'payments' => ['outstanding_summary' => ['status' => 'clear', 'count' => 0]], 'skkm' => ['found' => true, 'graduation_status' => ['is_met' => true]], 'announcements' => ['items' => [], 'upcoming_deadlines' => []], 'portal_context' => ['questionnaire' => ['available' => false]], 'grades' => ['summary' => ['computed' => ['d_courses' => [], 'e_courses' => []]]], 'exams' => ['exams' => []]] as $dataset => $data) {
            $snapshots->put(self::TOKEN, $dataset, $data);
        }

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/action-items')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('meta.pending_sources', [])
            ->assertJsonPath('meta.missing_sources', ['assignments'])
            ->assertJsonPath('meta.sources.assignments.status', 'missing')
            ->assertJsonPath('meta.is_complete', false);

        // The heavy assignments crawl is never started by the action center.
        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_action_center_failure_never_leaks_the_exception_text(): void
    {
        $this->mockValidCampusSession();
        $this->instance(ActionCenterBuilder::class, Mockery::mock(ActionCenterBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->andThrow(new \RuntimeException('SQLSTATE[HY000]: /var/www/mycic/storage https://my.cic.ac.id/?act=secret&id=42'));
        }));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/action-items');

        $response->assertStatus(500)->assertJsonPath('status', 'error');
        $this->assertSame('Gagal menyusun pusat tindakan. Coba lagi beberapa saat.', $response->json('message'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('my.cic.ac.id', $response->getContent());
    }

    public function test_action_items_require_a_campus_session(): void
    {
        $this->getJson('/api/action-items')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_action_items_reject_an_expired_campus_session(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->once()->with('expired-token')->andReturnFalse();
        }));

        $this->withSession(['campus_session_token' => 'expired-token'])
            ->getJson('/api/action-items')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.')
            ->assertSessionMissing('campus_session_token');

        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    private function mockValidCampusSession(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
        }));
    }
}
