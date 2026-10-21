<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Services\Extractors\SkripsiExtractor;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * GET /api/thesis (SiMASITA + e-Skripsi status) and /api/thesis/readiness.
 */
class PortalThesisApiTest extends TestCase
{
    private const TOKEN = 'token-thesis';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalDataJob::class]);
        $this->travelTo(CarbonImmutable::create(2026, 10, 21, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_thesis_builds_the_dataset_and_flags_a_thesis_course_awaiting_its_status(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'krs', [
            'found' => true,
            'courses' => [['code' => 'MK001', 'name' => 'Manajemen Proyek'], ['code' => 'SK499', 'name' => 'SKRIPSI']],
        ]);
        $this->instance(SkripsiExtractor::class, Mockery::mock(SkripsiExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSkripsiData')->once()->with(self::TOKEN)->andReturn([
                ...$this->thesisData(),
                '_sync' => ['data_origin' => 'upstream'],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/thesis')
            ->assertOk()
            ->assertJsonPath('data.student.jenjang', 'S1')
            ->assertJsonPath('data.thresholds.min_sks.S1', 130)
            ->assertJsonPath('data.documents.0.path', 'files/SOP_Uji.pdf')
            ->assertJsonPath('data.status.state', 'not_enrolled')
            ->assertJsonPath('data.krs_hint.has_thesis_course', true)
            ->assertJsonPath('data.krs_hint.course.name', 'SKRIPSI')
            ->assertJsonPath('data.krs_hint.awaiting_status', true)
            ->assertJsonPath('meta.data_origin', 'upstream')
            ->assertJsonPath('meta.insight_sources.krs', 'synced')
            ->assertJsonMissingPath('data._sync');
    }

    public function test_thesis_strips_portal_action_links_from_raw_sections(): void
    {
        $this->mockValidCampusSession();
        $data = $this->thesisData();
        $data['status'] = [
            'state' => 'active_unparsed',
            'message' => null,
            'raw_sections' => [['title' => 'Data Skripsi', 'fields' => [], 'links' => [['label' => 'Hapus', 'path' => 'https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=jobdel&id=7']], 'form_fields' => []]],
            'available' => true,
        ];
        $this->snapshots()->put(self::TOKEN, 'thesis', $data);

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/thesis');

        $response->assertOk()
            ->assertJsonPath('data.status.raw_sections.0.links.0.path', null)
            ->assertJsonPath('data.krs_hint.has_thesis_course', null);
        $this->assertStringNotContainsString('act=jobdel', (string) $response->getContent());
    }

    public function test_readiness_is_evaluated_from_snapshots_without_portal_requests(): void
    {
        $this->mockValidCampusSession();
        $this->instance(SkripsiExtractor::class, Mockery::mock(SkripsiExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractSkripsiData');
        }));
        $this->snapshots()->put(self::TOKEN, 'thesis', $this->thesisData());
        $this->snapshots()->put(self::TOKEN, 'grades', [
            'found' => true,
            'semesters' => [[
                'label' => 'KHS Semester : 1', 'semester' => 1, 'is_regular' => true, 'ips' => null,
                'courses' => [
                    ['code' => 'MK01', 'name' => 'Mata Kuliah Satu', 'sks' => 3, 'letter' => 'D', 'weight' => 1.0, 'is_graded' => true],
                    ['code' => 'MK02', 'name' => 'Mata Kuliah Dua', 'sks' => 3, 'letter' => 'A', 'weight' => 4.0, 'is_graded' => true],
                ],
            ]],
            'summary' => ['official' => ['ipk' => 3.3, 'sks_total' => 132], 'ipk' => 3.3, 'sks_passed' => 132, 'progress' => ['average_sks_per_semester' => 20.0]],
        ]);
        $this->snapshots()->put(self::TOKEN, 'payments', ['outstanding_summary' => ['status' => 'outstanding', 'total_amount_label' => 'Rp 2.000.000']]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/thesis/readiness')
            ->assertOk()
            ->assertJsonPath('data.jenjang', 'S1')
            ->assertJsonPath('data.threshold_source', 'portal')
            ->assertJsonPath('data.thresholds.min_sks', 130)
            ->assertJsonPath('data.checklist_items.0.key', 'sks')
            ->assertJsonPath('data.checklist_items.0.status', 'passed')
            ->assertJsonPath('data.checklist_items.2.key', 'nilai_d')
            ->assertJsonPath('data.checklist_items.2.current', 1)
            ->assertJsonPath('data.checklist_items.4.key', 'keuangan')
            ->assertJsonPath('data.checklist_items.4.status', 'failed')
            ->assertJsonPath('data.summary.academic_ready', true)
            ->assertJsonPath('meta.status', 'synced')
            ->assertJsonPath('meta.data_origin', 'snapshot')
            ->assertJsonPath('meta.sources', ['thesis' => 'synced', 'grades' => 'synced', 'payments' => 'synced']);

        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_missing_snapshots_are_queued_and_their_rows_stay_unknown(): void
    {
        $this->mockValidCampusSession();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/thesis/readiness')
            ->assertOk()
            ->assertJsonPath('data.threshold_source', 'default')
            ->assertJsonPath('data.jenjang_source', 'default')
            ->assertJsonPath('data.grades_available', false)
            ->assertJsonPath('data.checklist_items.0.status', 'unknown')
            ->assertJsonPath('data.checklist_items.1.status', 'unknown')
            ->assertJsonPath('meta.status', 'pending')
            ->assertJsonPath('meta.sources', ['thesis' => 'pending', 'grades' => 'pending', 'payments' => 'pending']);

        Bus::assertDispatchedTimes(RefreshPortalDataJob::class, 1);
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ['thesis', 'grades', 'payments']);
    }

    public function test_thesis_routes_require_a_valid_campus_session(): void
    {
        foreach (['/api/thesis', '/api/thesis/readiness'] as $uri) {
            $this->getJson($uri)
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Sesi kampus tidak ditemukan. Silakan masuk lagi.');
        }
    }

    public function test_an_expired_portal_session_is_unauthorized(): void
    {
        $this->mockValidCampusSession();
        $this->instance(SkripsiExtractor::class, Mockery::mock(SkripsiExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSkripsiData')->once()->andThrow(PortalScrapingException::pageNotAccessible('simasita'));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/thesis')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.');
    }

    public function test_a_portal_failure_without_snapshot_is_a_generic_error(): void
    {
        $this->mockValidCampusSession();
        $this->instance(SkripsiExtractor::class, Mockery::mock(SkripsiExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractSkripsiData')->once()->andThrow(PortalScrapingException::invalidResponse('https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=simasita', 'timeout'));
        }));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/thesis');

        $response->assertStatus(500)->assertJsonPath('message', 'Gagal memuat data skripsi. Coba lagi beberapa saat.');
        $this->assertStringNotContainsString('act=simasita', (string) $response->getContent());
    }

    private function mockValidCampusSession(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
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
    private function thesisData(): array
    {
        return [
            'found' => true,
            'title' => 'Sistem Layanan e-Skripsi',
            'student' => ['faculty' => 'FAKULTAS UJI', 'program' => 'SISTEM INFORMASI', 'program_raw' => 'SISTEM INFORMASI - Strata S1', 'jenjang' => 'S1', 'nim' => '200100099', 'name' => 'MAHASISWA UJI'],
            'requirements' => ['thesis' => ['IPK minimal 2,75'], 'exam' => ['Lembar pengesahan']],
            'thresholds' => ['min_sks' => ['D3' => 90, 'S1' => 130], 'min_ipk' => 2.75, 'max_d' => 2, 'allow_e' => false],
            'threshold_sources' => ['min_sks_d3' => 'portal', 'min_sks_s1' => 'portal', 'min_ipk' => 'portal', 'max_d' => 'portal', 'allow_e' => 'portal'],
            'threshold_source' => 'portal',
            'documents' => [['label' => 'SOP Uji', 'path' => 'files/SOP_Uji.pdf', 'filename' => 'SOP_Uji.pdf']],
            'status' => ['state' => 'not_enrolled', 'message' => 'Anda Tidak Mengambil Mata Kuliah Skripsi', 'raw_sections' => [], 'available' => true],
            'extracted_at' => '2026-10-21T02:00:00+00:00',
        ];
    }
}
