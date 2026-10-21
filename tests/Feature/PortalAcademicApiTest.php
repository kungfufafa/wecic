<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Services\Extractors\AdvisingExtractor;
use App\Services\Extractors\KrsDetailExtractor;
use App\Services\Extractors\KrsExtractor;
use App\Services\PortalGradesResolver;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalAcademicApiTest extends TestCase
{
    private const TOKEN = 'token-academic';

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

    public function test_krs_returns_the_current_krs_with_insights_and_no_form_action(): void
    {
        $this->mockValidCampusSession();
        $this->instance(KrsExtractor::class, Mockery::mock(KrsExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractKrsData')->once()->with(self::TOKEN)->andReturn([
                ...$this->krsData('pending_dpa'),
                'registration_form_detected' => true,
                'registration_form' => [
                    'forms' => [['action' => '?mod=student&act=simpankrs', 'method' => 'post', 'field_names' => ['mk[]'], 'option_count' => 12]],
                    'control_count' => 13,
                ],
                '_sync' => ['data_origin' => 'upstream'],
            ]);
        }));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/academic/krs');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status.code', 'pending_dpa')
            ->assertJsonPath('data.totals.sks', 14)
            ->assertJsonPath('data.registration_form_detected', true)
            ->assertJsonMissingPath('data.registration_form')
            ->assertJsonPath('data.insights.active_period.kind', 'perwalian_perbaikan')
            ->assertJsonPath('data.insights.active_period.days_left', 10)
            ->assertJsonPath('data.insights.tracker.2.state', 'current')
            ->assertJsonPath('data.insights.dpa_source', 'credit')
            ->assertJsonPath('meta.campus_session_valid', true)
            ->assertJsonPath('meta.status', 'synced')
            ->assertJsonPath('meta.insight_sources.advising', 'pending')
            ->assertJsonPath('meta.insight_sources.announcements', 'pending');

        $this->assertStringNotContainsString('simpankrs', (string) $response->getContent());
        // Missing insight sources are built together in one background job.
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => $this->jobDomains($job) === ['advising', 'announcements']);
    }

    public function test_krs_uses_the_advising_dpa_when_its_snapshot_exists(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'krs', $this->krsData('pending_dpa'));
        $this->snapshots()->put(self::TOKEN, 'advising', $this->advisingData());

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs')
            ->assertOk()
            ->assertJsonPath('meta.data_origin', 'snapshot')
            ->assertJsonPath('data.insights.dpa_source', 'advising')
            ->assertJsonPath('data.insights.dpa.name', 'Dosen Pembimbing Uji')
            ->assertJsonPath('data.insights.latest_krs_id', 90004);
    }

    public function test_krs_fresh_request_rebuilds_from_the_portal(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'krs', $this->krsData('pending_dpa'));
        $this->travel(5)->minutes();
        $this->instance(KrsExtractor::class, Mockery::mock(KrsExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractKrsData')->once()->with(self::TOKEN)->andReturn($this->krsData('validated'));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs?fresh=1')
            ->assertOk()
            ->assertJsonPath('meta.requested_fresh', true)
            ->assertJsonPath('data.status.code', 'validated');
    }

    public function test_krs_requires_a_campus_session(): void
    {
        $this->getJson('/api/academic/krs')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Sesi kampus tidak ditemukan. Silakan masuk lagi.')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_academic_routes_reject_an_expired_campus_session(): void
    {
        foreach (['/api/academic/krs', '/api/academic/advising', '/api/academic/krs/90004', '/api/academic/grades', '/api/academic/transcript'] as $uri) {
            $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->once()->with('expired-token')->andReturnFalse();
            }));

            $this->withSession(['campus_session_token' => 'expired-token'])
                ->getJson($uri)
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.')
                ->assertJsonPath('meta.campus_session_valid', false);
        }
    }

    public function test_krs_reports_an_upstream_session_expiry_as_unauthorized(): void
    {
        $this->mockValidCampusSession();
        $this->instance(KrsExtractor::class, Mockery::mock(KrsExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractKrsData')->once()->andThrow(PortalScrapingException::pageNotAccessible('credit'));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.')
            ->assertSessionMissing('campus_session_token');
    }

    public function test_krs_reports_a_portal_failure_in_indonesian(): void
    {
        $this->mockValidCampusSession();
        $this->instance(KrsExtractor::class, Mockery::mock(KrsExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractKrsData')->once()->andThrow(PortalScrapingException::dataExtractionFailed('KRS', 'timeout'));
        }));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/academic/krs');

        $response->assertStatus(500)->assertJsonPath('status', 'error');
        $this->assertStringStartsWith('Gagal memuat KRS', (string) $response->json('message'));
    }

    public function test_advising_returns_dpa_and_krs_history(): void
    {
        $this->mockValidCampusSession();
        $this->instance(AdvisingExtractor::class, Mockery::mock(AdvisingExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAdvisingData')->once()->with(self::TOKEN)->andReturn($this->advisingData());
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/advising')
            ->assertOk()
            ->assertJsonPath('data.dpa.whatsapp', '6281200000001')
            ->assertJsonPath('data.latest_krs_id', 90004)
            ->assertJsonPath('data.krs_history.2.semester', 'T')
            ->assertJsonPath('data.krs_history.2.semester_is_regular', false);
    }

    public function test_krs_detail_serves_a_krs_from_the_students_history_with_navigation(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'advising', $this->advisingData());
        $this->instance(KrsDetailExtractor::class, Mockery::mock(KrsDetailExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractKrsDetailData')->once()->with(self::TOKEN, 90003)->andReturn($this->krsDetailData(90003));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs/90003')
            ->assertOk()
            ->assertJsonPath('data.krs_id', 90003)
            ->assertJsonPath('data.totals.sks', 13)
            ->assertJsonPath('data.history.semester_label', 'Semester 2')
            ->assertJsonPath('data.navigation.newer_krs_id', 90004)
            ->assertJsonPath('data.navigation.older_krs_id', 90001)
            ->assertJsonPath('data.navigation.is_latest', false)
            ->assertJsonPath('meta.krs_id', 90003);

        $this->assertNotNull($this->snapshots()->get(self::TOKEN, 'krs_detail', ['id' => 90003]));
    }

    public function test_krs_detail_builds_the_advising_history_when_it_has_no_snapshot(): void
    {
        $this->mockValidCampusSession();
        $this->instance(AdvisingExtractor::class, Mockery::mock(AdvisingExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAdvisingData')->once()->with(self::TOKEN)->andReturn($this->advisingData());
        }));
        $this->instance(KrsDetailExtractor::class, Mockery::mock(KrsDetailExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractKrsDetailData')->once()->with(self::TOKEN, 90004)->andReturn($this->krsDetailData(90004));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs/90004')
            ->assertOk()
            ->assertJsonPath('data.navigation.newer_krs_id', null)
            ->assertJsonPath('data.navigation.is_latest', true);
    }

    public function test_krs_detail_rejects_an_id_outside_the_students_history_without_calling_the_portal(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'advising', $this->advisingData());
        $this->instance(KrsDetailExtractor::class, Mockery::mock(KrsDetailExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractKrsDetailData');
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs/12345')
            ->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'KRS tidak ditemukan di riwayat KRS kamu.')
            ->assertJsonPath('meta.campus_session_valid', true);

        $this->assertNull($this->snapshots()->get(self::TOKEN, 'krs_detail', ['id' => 12345]));
    }

    public function test_krs_detail_validates_the_id(): void
    {
        $this->mockValidCampusSession();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs/0')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Parameter id KRS harus berupa angka positif.')
            ->assertJsonValidationErrors(['id']);

        // Non-numeric ids never reach the controller.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs/abc')
            ->assertNotFound();
    }

    public function test_krs_detail_answers_404_for_an_unreadable_page_and_does_not_keep_it(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'advising', $this->advisingData());
        $this->instance(KrsDetailExtractor::class, Mockery::mock(KrsDetailExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractKrsDetailData')->once()->with(self::TOKEN, 90003)->andReturn([...$this->krsDetailData(90003), 'found' => false]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/krs/90003')
            ->assertNotFound()
            ->assertJsonPath('message', 'KRS ini tidak terbaca di portal. Coba lagi beberapa saat.');

        $this->assertNull($this->snapshots()->get(self::TOKEN, 'krs_detail', ['id' => 90003]));
    }

    public function test_grades_hide_the_portal_print_url_and_explain_the_krs_validation(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'krs', $this->krsData('pending_dpa'));
        $this->instance(PortalGradesResolver::class, Mockery::mock(PortalGradesResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->with(self::TOKEN)->andReturn($this->gradesData());
        }));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/academic/grades');

        $response->assertOk()
            ->assertJsonPath('data.summary.ipk', 3.1)
            ->assertJsonPath('data.semesters.0.status', 'empty')
            ->assertJsonPath('data.transcript_print_available', true)
            ->assertJsonMissingPath('data.transcript_print_url')
            ->assertJsonPath('data.krs_validation.code', 'pending_dpa');
    }

    public function test_transcript_summarizes_progress_and_simulates_a_target_ipk(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'grades', $this->gradesData());
        $this->snapshots()->put(self::TOKEN, 'krs', $this->krsData('pending_dpa'));

        $response = $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/transcript?target_ipk=3.5&next_sks=20');

        $response->assertOk()
            ->assertJsonPath('data.summary.ipk', 3.1)
            ->assertJsonPath('data.summary.source', 'official')
            ->assertJsonPath('data.summary.sks_total', 20)
            ->assertJsonPath('data.summary.sks_passed', 86)
            ->assertJsonPath('data.summary.conversion_sks', 66)
            ->assertJsonPath('data.target_sks', 144)
            ->assertJsonPath('data.remaining_sks', 58)
            ->assertJsonPath('data.progress.progress_percent', 59.7)
            ->assertJsonPath('data.conversion', ['status' => 'ok', 'sks' => 66, 'course_count' => 24, 'already_graded_codes' => []])
            ->assertJsonPath('data.current_load.sks', 14)
            ->assertJsonPath('data.low_grades.d_courses.0.code', 'MK1004')
            ->assertJsonPath('data.letter_counts.D', 1)
            ->assertJsonPath('data.simulation.target_ipk', 3.5)
            ->assertJsonPath('data.simulation.required_ips', 3.9)
            ->assertJsonPath('data.simulation.achievable', true)
            ->assertJsonPath('data.transcript_print_available', true)
            ->assertJsonMissingPath('data.transcript_print_url');

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/academic/transcript')
            ->assertOk()
            ->assertJsonPath('data.simulation', null);
    }

    public function test_transcript_validates_the_simulation_parameters(): void
    {
        $cases = [
            '/api/academic/transcript?target_ipk=4.5&next_sks=20' => ['target_ipk', 'Target IPK harus di antara 0 dan 4.'],
            '/api/academic/transcript?target_ipk=3.5' => ['next_sks', 'Rencana SKS wajib diisi bersama target IPK.'],
            '/api/academic/transcript?target_ipk=3.5&next_sks=30' => ['next_sks', 'Rencana SKS harus di antara 1 dan 24.'],
        ];

        foreach ($cases as $uri => [$field, $message]) {
            $this->mockValidCampusSession();

            $this->withSession(['campus_session_token' => self::TOKEN])
                ->getJson($uri)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field])
                ->assertJsonPath('message', $message);
        }
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
    private function krsData(string $status): array
    {
        return [
            'found' => true,
            'academic_period' => ['label' => 'T.A 2026/2027 Semester Ganjil', 'academic_year' => '2026/2027', 'term' => 'ganjil', 'source' => 'credit'],
            'student' => ['faculty' => 'Fakultas Uji', 'program' => 'Teknik Informatika', 'academic_year' => '2026/2027', 'nim' => '20990000001', 'name' => 'Mahasiswa Uji'],
            'period' => ['start' => '2026-10-01', 'end' => '2026-10-30'],
            'periods' => [['kind' => 'perwalian_perbaikan', 'start' => '2026-10-01', 'end' => '2026-10-30', 'label' => 'Perwalian & perbaikan KRS', 'source' => 'credit', 'source_ref' => 'table_row']],
            'notice' => null,
            'notice_text' => null,
            'status' => ['code' => $status, 'label' => 'Menunggu validasi DPA', 'raw_text' => 'Belum Validasi DPA'],
            'dpa' => ['name' => 'Dosen DPA Kredit', 'nidn' => null, 'phone' => '081200000002', 'whatsapp' => '6281200000002', 'email' => null],
            'courses' => [['code' => 'MK1004', 'name' => 'Matematika Diskrit', 'sks' => 2, 'semester' => 1]],
            'totals' => ['courses' => 6, 'sks' => 14, 'source' => 'portal', 'consistent' => true],
            'print_available' => true,
            'registration_form_detected' => false,
            'registration_form' => null,
            'extracted_at' => '2026-10-20T02:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function advisingData(): array
    {
        return [
            'found' => true,
            'student' => ['photo_path' => null, 'program' => 'Teknik Informatika', 'cohort' => '2025', 'nim' => '20990000001', 'name' => 'Mahasiswa Uji', 'status' => 'Aktif'],
            'dpa' => ['name' => 'Dosen Pembimbing Uji', 'nidn' => '0000000001', 'phone' => '081200000001', 'whatsapp' => '6281200000001', 'email' => 'dosen@example.test'],
            'krs_history' => [
                ['krs_id' => 90004, 'academic_year' => '2026/2027', 'date' => '2026-09-14', 'semester' => 3, 'semester_is_regular' => true, 'semester_label' => 'Semester 3'],
                ['krs_id' => 90003, 'academic_year' => '2025/2026', 'date' => '2026-02-10', 'semester' => 2, 'semester_is_regular' => true, 'semester_label' => 'Semester 2'],
                ['krs_id' => 90001, 'academic_year' => '2025/2026', 'date' => '2026-07-01', 'semester' => 'T', 'semester_is_regular' => false, 'semester_label' => 'Semester T (konversi)'],
            ],
            'latest_krs_id' => 90004,
            'latest_semester_number' => 3,
            'krs_add_action_detected' => false,
            'extracted_at' => '2026-10-20T02:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function krsDetailData(int $krsId): array
    {
        return [
            'found' => true,
            'krs_id' => $krsId,
            'header' => ['academic_year' => '2025/2026', 'semester' => 2, 'semester_is_regular' => true, 'semester_label' => 'Semester 2', 'date' => '2026-02-10', 'nim' => '20990000001', 'name' => 'Mahasiswa Uji'],
            'counts_as_study_load' => true,
            'status' => ['code' => 'pending_dpa', 'label' => 'Menunggu validasi DPA', 'raw_text' => 'Segera menghubungi Dosen DPA'],
            'courses' => [['code' => 'MK2001', 'name' => 'Struktur Data', 'sks' => 3, 'semester' => 2, 'status' => 'Baru', 'is_retake' => false]],
            'totals' => ['courses' => 5, 'sks' => 13, 'source' => 'portal', 'consistent' => true],
            'print_available' => true,
            'krs_id_confirmed' => true,
            'extracted_at' => '2026-10-20T02:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gradesData(): array
    {
        $dCourse = ['code' => 'MK1004', 'name' => 'Matematika Diskrit', 'sks' => 2, 'letter' => 'D', 'weight' => 1.0, 'quality_points' => 2.0, 'semester' => 1, 'attempt_count' => 1, 'attempts' => [['semester' => 1, 'letter' => 'D', 'weight' => 1.0]]];

        return [
            'semesters' => [
                ['label' => 'KHS Semester : 3', 'semester' => 3, 'is_regular' => true, 'status' => 'empty', 'columns' => [], 'courses' => [], 'course_count' => 0, 'graded_count' => 0, 'total_sks' => 0, 'total_quality_points' => null, 'ips' => null],
            ],
            'summary' => [
                'official' => ['ipk' => 3.1, 'sks_total' => 20, 'quality_points_total' => 62.0, 'sks_remaining' => 124, 'target_sks' => 144, 'letter_counts' => ['A' => 3, 'B' => 3, 'C' => 1, 'D' => 1, 'E' => 0], 'fields' => []],
                'computed' => [
                    'ipk' => 3.1,
                    'sks_total' => 20,
                    'sks_passed' => 20,
                    'quality_points_total' => 62.0,
                    'letter_counts' => ['A' => 3, 'B' => 3, 'C' => 1, 'D' => 1, 'E' => 0],
                    'd_courses' => [$dCourse],
                    'e_courses' => [],
                    'low_grade_courses' => [$dCourse],
                    'repeated_courses' => [],
                    'ips_series' => [['semester' => 1, 'label' => 'Semester 1', 'ips' => 3.0], ['semester' => 2, 'label' => 'Semester 2', 'ips' => 3.2]],
                    'latest_ips' => ['semester' => 2, 'ips' => 3.2, 'previous_semester' => 1, 'previous_ips' => 3.0, 'delta' => 0.2],
                    'excluded_semesters' => ['T'],
                ],
                'mismatch' => false,
                'mismatch_fields' => [],
                'ipk' => 3.1,
                'sks_passed' => 86,
                'conversion_sks' => 66,
                'quality_points_total' => 62.0,
                'source' => 'official',
                'progress' => ['target_sks' => 144, 'earned_sks' => 86, 'remaining_sks' => 58, 'progress_percent' => 59.7, 'average_sks_per_semester' => 10.0, 'projected_semesters_remaining' => 13, 'target_sks_source' => 'official', 'graduation_targets' => []],
                'predicate_estimate' => 'Sangat Memuaskan',
            ],
            'transcript' => [$dCourse],
            'transcript_print_url' => 'https://my.cic.ac.id/portal/sipandai/views/transcripts/print.php',
            'conversion' => ['status' => 'ok', 'krs_ids' => [90001], 'courses' => [['code' => 'SI013002', 'name' => 'Manajemen dan organisasi', 'sks' => 3]], 'course_count' => 24, 'sks' => 66, 'already_graded_codes' => []],
            'sources' => ['khs' => 'sipandai_score', 'is_partial' => false, 'score_page' => 'ok', 'krs_detail' => 'ok', 'krs_id' => '90004', 'cross_check' => ['compared' => 8, 'mismatched' => 0]],
            'extracted_at' => '2026-10-20T02:00:00+00:00',
        ];
    }
}
