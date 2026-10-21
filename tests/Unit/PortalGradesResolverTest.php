<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalScrapingException;
use App\Services\AcademicProgressCalculator;
use App\Services\Extractors\AdvisingExtractor;
use App\Services\Extractors\KhsExtractor;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalGradesResolver;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class PortalGradesResolverTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private KhsExtractor&MockInterface $khsExtractor;

    private AdvisingExtractor&MockInterface $advisingExtractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->khsExtractor = Mockery::mock(KhsExtractor::class);
        $this->advisingExtractor = Mockery::mock(AdvisingExtractor::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_score_rows_are_primary_and_the_krs_detail_page_adds_the_official_summary(): void
    {
        $this->scoreReturns($this->scorePayload());
        $this->advisingReturns(90001);
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->once()->with('token', '90001')->andReturn($this->krsPayload());

        $grades = $this->resolver()->resolve('token');

        $this->assertSame([
            'khs' => KhsExtractor::SOURCE_SCORE,
            'is_partial' => false,
            'score_page' => 'ok',
            'krs_detail' => 'ok',
            'krs_id' => '90001',
            'cross_check' => ['compared' => 9, 'mismatched' => 0],
        ], $grades['sources']);
        $this->assertSame(['absen', 'tugas', 'uts', 'uas'], $grades['semesters'][1]['columns']);
        $this->assertSame(3.1, $grades['summary']['official']['ipk']);
        $this->assertSame(3.1, $grades['summary']['computed']['ipk']);
        $this->assertFalse($grades['summary']['mismatch']);
        $this->assertSame([], $grades['summary']['mismatch_fields']);
        $this->assertSame(3.1, $grades['summary']['ipk']);
        $this->assertSame(20, $grades['summary']['sks_passed']);
        $this->assertSame(62.0, $grades['summary']['quality_points_total']);
        $this->assertSame('official', $grades['summary']['source']);
        $this->assertSame('Sangat Memuaskan', $grades['summary']['predicate_estimate']);
        $this->assertSame(PortalEndpointRegistry::url('sipandai_transcript_print'), $grades['transcript_print_url']);
        $this->assertSame('upstream', $grades['_sync']['data_origin']);
    }

    public function test_progress_uses_the_official_target_and_remaining_sks(): void
    {
        $this->scoreReturns($this->scorePayload());
        $this->advisingReturns(90001);
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andReturn($this->krsPayload());

        $progress = $this->resolver()->resolve('token')['summary']['progress'];

        $this->assertSame(144, $progress['target_sks']);
        $this->assertSame('official', $progress['target_sks_source']);
        $this->assertSame(20, $progress['earned_sks']);
        $this->assertSame(124, $progress['remaining_sks']);
        $this->assertSame(13.9, $progress['progress_percent']);
        $this->assertSame(13, $progress['projected_semesters_remaining']);
        $this->assertSame([2.71, 3.0, 3.58], array_column($progress['graduation_targets'], 'required_ips'));
    }

    public function test_the_transcript_keeps_the_best_attempt_of_each_course(): void
    {
        $this->scoreReturns($this->scorePayload());
        $this->advisingReturns(90001);
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andReturn($this->krsPayload());

        $transcript = $this->resolver()->resolve('token')['transcript'];
        $retaken = array_values(array_filter($transcript, static fn (array $row): bool => $row['code'] === 'MK1003'));

        $this->assertCount(8, $transcript);
        $this->assertSame(['MK1001', 'MK1002', 'MK1004', 'MK2001', 'MK2002', 'MK2003', 'MK1003', 'MK2004'], array_column($transcript, 'code'));
        $this->assertSame('B', $retaken[0]['letter']);
        $this->assertSame(2, $retaken[0]['semester']);
        $this->assertSame(2, $retaken[0]['attempt_count']);
    }

    public function test_the_krs_detail_rows_replace_a_failed_score_page_as_partial_data(): void
    {
        $this->khsExtractor->shouldReceive('extractKhsData')->andThrow(PortalScrapingException::dataExtractionFailed('KHS', 'timeout'));
        $this->advisingReturns(90001);
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andReturn($this->krsPayload());

        $grades = $this->resolver()->resolve('token');

        $this->assertSame(KhsExtractor::SOURCE_KRS_DETAIL, $grades['sources']['khs']);
        $this->assertTrue($grades['sources']['is_partial']);
        $this->assertSame('failed', $grades['sources']['score_page']);
        $this->assertNull($grades['sources']['cross_check']);
        $this->assertSame(['uts', 'uas'], $grades['semesters'][1]['columns']);
        $this->assertSame(3.1, $grades['summary']['ipk']);
        $this->assertNull($grades['transcript_print_url']);
    }

    public function test_an_empty_score_page_also_falls_back_to_the_krs_detail_rows(): void
    {
        $this->scoreReturns(['source' => KhsExtractor::SOURCE_SCORE, 'found' => false, 'semesters' => [], 'cumulative' => null, 'transcript_print_url' => null]);
        $this->advisingReturns('90001');
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andReturn($this->krsPayload());

        $grades = $this->resolver()->resolve('token');

        $this->assertSame('empty', $grades['sources']['score_page']);
        $this->assertSame(KhsExtractor::SOURCE_KRS_DETAIL, $grades['sources']['khs']);
    }

    public function test_without_a_krs_history_the_summary_is_computed_from_the_config_target(): void
    {
        $this->scoreReturns($this->scorePayload());
        $this->advisingReturns(null);
        $this->khsExtractor->shouldNotReceive('extractKrsGradesData');

        $grades = $this->resolver()->resolve('token');

        $this->assertSame('skipped', $grades['sources']['krs_detail']);
        $this->assertNull($grades['sources']['krs_id']);
        $this->assertNull($grades['summary']['official']);
        $this->assertFalse($grades['summary']['mismatch']);
        $this->assertSame('computed', $grades['summary']['source']);
        $this->assertSame(3.1, $grades['summary']['ipk']);
        $this->assertSame('config', $grades['summary']['progress']['target_sks_source']);
        $this->assertSame(144, $grades['summary']['progress']['target_sks']);
        $this->assertSame(124, $grades['summary']['progress']['remaining_sks']);
    }

    public function test_a_non_numeric_latest_krs_id_is_never_requested(): void
    {
        $this->scoreReturns($this->scorePayload());
        $this->advisingReturns('90001&act=delete');
        $this->khsExtractor->shouldNotReceive('extractKrsGradesData');

        $this->assertSame('skipped', $this->resolver()->resolve('token')['sources']['krs_detail']);
    }

    public function test_an_advising_failure_only_drops_the_official_summary(): void
    {
        $this->scoreReturns($this->scorePayload());
        $this->advisingExtractor->shouldReceive('extractAdvisingData')->andThrow(new RuntimeException('portal lambat'));

        $grades = $this->resolver()->resolve('token');

        $this->assertSame('skipped', $grades['sources']['krs_detail']);
        $this->assertNull($grades['summary']['official']);
        $this->assertSame(KhsExtractor::SOURCE_SCORE, $grades['sources']['khs']);
    }

    public function test_a_krs_detail_failure_keeps_the_score_rows(): void
    {
        $this->scoreReturns($this->scorePayload());
        $this->advisingReturns(90001);
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andThrow(PortalScrapingException::dataExtractionFailed('KHS', 'timeout'));

        $grades = $this->resolver()->resolve('token');

        $this->assertSame('failed', $grades['sources']['krs_detail']);
        $this->assertSame('90001', $grades['sources']['krs_id']);
        $this->assertNull($grades['summary']['official']);
        $this->assertSame('computed', $grades['summary']['source']);
    }

    public function test_a_different_official_summary_is_flagged_and_still_shown(): void
    {
        $krs = $this->krsPayload();
        $krs['cumulative']['ipk'] = 3.2;
        $krs['cumulative']['sks_total'] = 22;

        $this->scoreReturns($this->scorePayload());
        $this->advisingReturns(90001);
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andReturn($krs);

        $summary = $this->resolver()->resolve('token')['summary'];

        $this->assertTrue($summary['mismatch']);
        $this->assertSame(['ipk', 'sks_total'], $summary['mismatch_fields']);
        $this->assertSame(3.2, $summary['ipk']);
        $this->assertSame(22, $summary['sks_passed']);
        $this->assertSame(3.1, $summary['computed']['ipk']);
    }

    public function test_an_official_ipk_without_computed_grades_is_a_mismatch(): void
    {
        $score = $this->scorePayload();
        $score['semesters'] = [$score['semesters'][0]];

        $this->scoreReturns($score);
        $this->advisingReturns(90001);
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andReturn($this->krsPayload());

        $summary = $this->resolver()->resolve('token')['summary'];

        $this->assertTrue($summary['mismatch']);
        $this->assertContains('ipk', $summary['mismatch_fields']);
    }

    public function test_rows_that_differ_between_both_pages_are_counted(): void
    {
        $krs = $this->krsPayload();
        $krs['semesters'][1]['courses'][0]['letter'] = 'B';
        $krs['semesters'][1]['courses'][0]['weight'] = 3.0;

        $this->scoreReturns($this->scorePayload());
        $this->advisingReturns(90001);
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andReturn($krs);

        $this->assertSame(['compared' => 9, 'mismatched' => 1], $this->resolver()->resolve('token')['sources']['cross_check']);
    }

    public function test_an_expired_session_on_the_score_page_is_rethrown(): void
    {
        $this->khsExtractor->shouldReceive('extractKhsData')->andThrow(PortalAuthenticationException::sessionExpired());
        $this->advisingExtractor->shouldNotReceive('extractAdvisingData');

        $this->expectException(PortalAuthenticationException::class);

        $this->resolver()->resolve('token');
    }

    public function test_an_expired_session_on_the_advising_page_is_rethrown(): void
    {
        $this->scoreReturns($this->scorePayload());
        $this->advisingExtractor->shouldReceive('extractAdvisingData')->andThrow(PortalAuthenticationException::sessionExpired());

        $this->expectException(PortalAuthenticationException::class);

        $this->resolver()->resolve('token');
    }

    public function test_the_score_error_is_rethrown_when_no_source_has_grades(): void
    {
        $error = PortalScrapingException::dataExtractionFailed('KHS', 'timeout');
        $this->khsExtractor->shouldReceive('extractKhsData')->andThrow($error);
        $this->advisingReturns(null);

        try {
            $this->resolver()->resolve('token');
            $this->fail('An exception was expected.');
        } catch (PortalScrapingException $exception) {
            $this->assertSame($error, $exception);
        }
    }

    public function test_a_readable_but_empty_score_page_is_a_student_without_grades_yet(): void
    {
        // A first-semester student: the score page loads but has no KHS, and
        // there is no earlier KRS to read an official summary from.
        $this->scoreReturns(['source' => KhsExtractor::SOURCE_SCORE, 'found' => false, 'semesters' => [], 'cumulative' => null, 'transcript_print_url' => null]);
        $this->advisingReturns(null);

        $grades = $this->resolver()->resolve('token');

        $this->assertFalse($grades['found']);
        $this->assertSame([], $grades['semesters']);
        $this->assertSame([], $grades['transcript']);
        $this->assertNull($grades['summary']['ipk']);
        $this->assertSame(0, $grades['summary']['sks_passed']);
        $this->assertSame([], $grades['summary']['computed']['d_courses']);
        $this->assertSame('empty', $grades['sources']['score_page']);
        $this->assertSame('skipped', $grades['sources']['krs_detail']);
        $this->assertFalse($grades['sources']['is_partial']);
    }

    public function test_an_empty_score_page_with_a_failed_krs_detail_page_is_still_a_failure(): void
    {
        $this->scoreReturns(['source' => KhsExtractor::SOURCE_SCORE, 'found' => false, 'semesters' => [], 'cumulative' => null, 'transcript_print_url' => null]);
        $this->advisingReturns('90001');
        $this->khsExtractor->shouldReceive('extractKrsGradesData')->andThrow(PortalScrapingException::dataExtractionFailed('KRS', 'timeout'));

        $this->expectException(PortalScrapingException::class);

        $this->resolver()->resolve('token');
    }

    public function test_a_cached_source_marks_the_result_as_served_from_cache(): void
    {
        $score = $this->scorePayload();
        $score['_sync'] = ['data_origin' => 'server_cache'];

        $this->scoreReturns($score);
        $this->advisingReturns(null);

        $this->assertSame('server_cache', $this->resolver()->resolve('token')['_sync']['data_origin']);
    }

    private function resolver(): PortalGradesResolver
    {
        return new PortalGradesResolver($this->khsExtractor, $this->advisingExtractor, new AcademicProgressCalculator);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function scoreReturns(array $payload): void
    {
        $this->khsExtractor->shouldReceive('extractKhsData')->with('token')->andReturn($payload);
    }

    /**
     * Cross-builder contract: the real advising and KHS extractors over the
     * shared fixtures of one synthetic student. The latest KRS id comes from
     * the advising page, its viewkrs page carries the same KHS as the score
     * page, and the official summary matches MyCIC's own computation.
     */
    public function test_the_real_extractors_agree_on_the_shared_fixtures(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);
        $fixtures = [
            PortalEndpointRegistry::url('student_advising') => 'student_advising.html',
            PortalEndpointRegistry::url('sipandai_score') => 'sipandai_score.html',
            PortalEndpointRegistry::url('student_krs_detail', ['id' => '90004']) => 'student_krs_detail.html',
        ];
        $page = static fn (string $url): string => (string) file_get_contents(base_path('tests/Fixtures/portal/'.$fixtures[$url]));

        /** @var AdvisingExtractor&MockInterface $advising */
        $advising = Mockery::mock(AdvisingExtractor::class, [$sessionManager])->makePartial()->shouldAllowMockingProtectedMethods();
        $advising->shouldReceive('get')->once()->andReturnUsing($page);

        /** @var KhsExtractor&MockInterface $khs */
        $khs = Mockery::mock(KhsExtractor::class, [$sessionManager])->makePartial()->shouldAllowMockingProtectedMethods();
        $khs->shouldReceive('get')->twice()->andReturnUsing($page);

        $grades = (new PortalGradesResolver($khs, $advising, new AcademicProgressCalculator))->resolve('token');

        $this->assertSame([
            'khs' => KhsExtractor::SOURCE_SCORE,
            'is_partial' => false,
            'score_page' => 'ok',
            'krs_detail' => 'ok',
            'krs_id' => '90004',
            'cross_check' => ['compared' => 9, 'mismatched' => 0],
        ], $grades['sources']);
        $this->assertFalse($grades['summary']['mismatch']);
        $this->assertSame('official', $grades['summary']['source']);
        $this->assertSame(3.1, $grades['summary']['ipk']);
        $this->assertSame(20, $grades['summary']['sks_passed']);
        $this->assertSame(['target_sks' => 144, 'remaining_sks' => 124], array_intersect_key($grades['summary']['progress'], array_flip(['target_sks', 'remaining_sks'])));
        $this->assertSame(['T'], $grades['summary']['computed']['excluded_semesters']);
    }

    /**
     * Live flow on the sanitized raw pages: advising gives its real return
     * shape (int `latest_krs_id` plus the history), the real KhsExtractor
     * parses both raw pages through masterminds/html5, and the official
     * viewkrs summary equals MyCIC's own computation.
     */
    public function test_the_raw_pages_give_an_official_summary_equal_to_the_computed_one(): void
    {
        $this->advisingExtractor->shouldReceive('extractAdvisingData')->with('token')->andReturn($this->advisingHistoryPayload(90004));

        $grades = (new PortalGradesResolver($this->rawKhsExtractor($requestedUrls), $this->advisingExtractor, new AcademicProgressCalculator))->resolve('token');
        $summary = $grades['summary'];
        $computed = $summary['computed'];

        $this->assertSame([
            PortalEndpointRegistry::url('sipandai_score'),
            PortalEndpointRegistry::url('student_krs_detail', ['id' => '90004']),
        ], $requestedUrls);
        $this->assertTrue($grades['found']);
        $this->assertSame([
            'khs' => KhsExtractor::SOURCE_SCORE,
            'is_partial' => false,
            'score_page' => 'ok',
            'krs_detail' => 'ok',
            'krs_id' => '90004',
            'cross_check' => ['compared' => 16, 'mismatched' => 0],
        ], $grades['sources']);
        $this->assertSame([3, 2, 1, 'T'], array_column($grades['semesters'], 'semester'));
        $this->assertFalse($summary['mismatch']);
        $this->assertSame([], $summary['mismatch_fields']);
        $this->assertSame('official', $summary['source']);
        $this->assertSame(3.21, $summary['official']['ipk']);
        $this->assertSame(3.21, $computed['ipk']);
        $this->assertSame(3.21, $summary['ipk']);
        $this->assertSame(42, $summary['official']['sks_total']);
        $this->assertSame(42, $computed['sks_total']);
        $this->assertSame(42, $summary['sks_passed']);
        $this->assertSame(135.0, $summary['official']['quality_points_total']);
        $this->assertSame(135.0, $computed['quality_points_total']);
        $this->assertSame($summary['official']['letter_counts'], $computed['letter_counts']);
        $this->assertSame(['A' => 7, 'B' => 5, 'C' => 4, 'D' => 0, 'E' => 0], $computed['letter_counts']);
        $this->assertSame('Sangat Memuaskan', $summary['predicate_estimate']);
        $this->assertSame(['target_sks' => 144, 'remaining_sks' => 102, 'target_sks_source' => 'official'], array_intersect_key($summary['progress'], array_flip(['target_sks', 'remaining_sks', 'target_sks_source'])));
        $this->assertSame(['T'], $computed['excluded_semesters']);
        $this->assertSame([1, 2], array_column($computed['ips_series'], 'semester'));
        $this->assertSame([3.11, 3.29], array_column($computed['ips_series'], 'ips'));
        $this->assertSame([3.11, 3.29], array_column($computed['ips_series'], 'ips_computed'));
        $this->assertSame([false, false], array_column($computed['ips_series'], 'ips_mismatch'));
        $this->assertSame([true, true], array_column($computed['ips_series'], 'is_complete'));
        $this->assertSame(['semester' => 2, 'ips' => 3.29, 'previous_semester' => 1, 'previous_ips' => 3.11, 'delta' => 0.18], $computed['latest_ips']);
        $this->assertCount(16, $grades['transcript']);
        $this->assertSame(PortalEndpointRegistry::url('sipandai_transcript_print'), $grades['transcript_print_url']);
    }

    public function test_without_a_latest_krs_id_any_history_row_gives_the_official_summary(): void
    {
        $advising = $this->advisingHistoryPayload(null);
        $advising['krs_history'] = [['krs_id' => 90001, 'academic_year' => '2024/2025', 'date' => null, 'semester' => 'T', 'semester_is_regular' => false, 'semester_label' => 'Semester T']];
        $this->advisingExtractor->shouldReceive('extractAdvisingData')->with('token')->andReturn($advising);

        $grades = (new PortalGradesResolver($this->rawKhsExtractor($requestedUrls, '90001'), $this->advisingExtractor, new AcademicProgressCalculator))->resolve('token');

        $this->assertSame(PortalEndpointRegistry::url('student_krs_detail', ['id' => '90001']), $requestedUrls[1]);
        $this->assertSame('ok', $grades['sources']['krs_detail']);
        $this->assertSame('90001', $grades['sources']['krs_id']);
        $this->assertSame('official', $grades['summary']['source']);
        $this->assertFalse($grades['summary']['mismatch']);
    }

    public function test_history_rows_without_a_usable_id_skip_the_official_summary(): void
    {
        $advising = $this->advisingHistoryPayload(null);
        $advising['krs_history'] = [['krs_id' => 0, 'semester' => 'T', 'semester_is_regular' => false], 'not-a-row', ['krs_id' => '0']];

        $this->scoreReturns($this->scorePayload());
        $this->advisingExtractor->shouldReceive('extractAdvisingData')->with('token')->andReturn($advising);
        $this->khsExtractor->shouldNotReceive('extractKrsGradesData');

        $this->assertSame('skipped', $this->resolver()->resolve('token')['sources']['krs_detail']);
    }

    /**
     * Real KhsExtractor over the sanitized raw pages; only get() is stubbed
     * and it still runs validateResponse like the network get()
     *
     * @param  list<string>|null  $requestedUrls
     */
    private function rawKhsExtractor(?array &$requestedUrls, string $krsId = '90004'): KhsExtractor
    {
        $pages = [
            PortalEndpointRegistry::url('sipandai_score') => 'sipandai_score_raw.html',
            PortalEndpointRegistry::url('student_krs_detail', ['id' => $krsId]) => 'student_krs_grades_raw.html',
        ];

        foreach ($pages as $fixture) {
            $this->assertFixtureParsesLikeLivePortal('tests/Fixtures/portal/'.$fixture);
        }

        $requestedUrls = [];
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        /** @var KhsExtractor&MockInterface $extractor */
        $extractor = Mockery::mock(KhsExtractor::class, [$sessionManager])->makePartial()->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturnUsing(function (string $url) use ($extractor, $pages, &$requestedUrls): string {
            $requestedUrls[] = $url;
            $html = (string) file_get_contents(base_path('tests/Fixtures/portal/'.$pages[$url]));
            (fn () => $this->validateResponse($html, $url))->call($extractor);

            return $html;
        });

        return $extractor;
    }

    /**
     * AdvisingExtractor's return shape for the synthetic student of the raw fixtures
     *
     * @return array<string, mixed>
     */
    private function advisingHistoryPayload(?int $latestKrsId): array
    {
        return [
            'found' => true,
            'student' => ['photo_path' => null, 'program' => null, 'cohort' => null, 'nim' => '20990000001', 'name' => 'Mahasiswa Contoh', 'status' => null],
            'dpa' => ['name' => 'Dosen Pembimbing, M.Kom.', 'nidn' => null, 'phone' => '081200000000', 'whatsapp' => null, 'email' => 'dosen@example.test'],
            'krs_history' => [
                ['krs_id' => 90004, 'academic_year' => '2026/2027', 'date' => '2026-09-14', 'semester' => 3, 'semester_is_regular' => true, 'semester_label' => 'Semester 3'],
                ['krs_id' => 90003, 'academic_year' => '2025/2026', 'date' => '2026-03-02', 'semester' => 2, 'semester_is_regular' => true, 'semester_label' => 'Semester 2'],
                ['krs_id' => 90002, 'academic_year' => '2025/2026', 'date' => '2025-09-22', 'semester' => 1, 'semester_is_regular' => true, 'semester_label' => 'Semester 1'],
                ['krs_id' => 90001, 'academic_year' => '2024/2025', 'date' => '2026-02-23', 'semester' => 'T', 'semester_is_regular' => false, 'semester_label' => 'Semester T'],
            ],
            'latest_krs_id' => $latestKrsId,
            'latest_semester_number' => $latestKrsId === null ? null : 3,
            'krs_add_action_detected' => false,
            'extracted_at' => now()->toISOString(),
            '_sync' => ['data_origin' => 'upstream', 'requested_fresh' => false, 'synced_at' => now()->toISOString()],
        ];
    }

    private function advisingReturns(int|string|null $latestKrsId): void
    {
        $this->advisingExtractor->shouldReceive('extractAdvisingData')->with('token')->andReturn([
            'found' => true,
            'krs_history' => [],
            'latest_krs_id' => $latestKrsId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scorePayload(): array
    {
        return [
            'source' => KhsExtractor::SOURCE_SCORE,
            ...$this->parseFixture('tests/Fixtures/portal/sipandai_score.html'),
            'extracted_at' => now()->toISOString(),
            '_sync' => ['data_origin' => 'upstream'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function krsPayload(): array
    {
        return [
            'source' => KhsExtractor::SOURCE_KRS_DETAIL,
            'krs_id' => '90001',
            ...$this->parseFixture('tests/Fixtures/portal/student_krs_grades.html'),
            'extracted_at' => now()->toISOString(),
            '_sync' => ['data_origin' => 'upstream'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFixture(string $fixture): array
    {
        $parser = new KhsExtractor(Mockery::mock(PortalSessionManager::class));

        return $parser->parseKhsHtml((string) file_get_contents(base_path($fixture)));
    }
}
