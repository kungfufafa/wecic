<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\KhsExtractor;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class KhsExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_every_semester_from_the_score_page_in_page_order(): void
    {
        $data = $this->scoreExtractor()->extractKhsData('fake-session-token');

        $this->assertSame(KhsExtractor::SOURCE_SCORE, $data['source']);
        $this->assertTrue($data['found']);
        $this->assertSame([3, 2, 1, 'T'], array_column($data['semesters'], 'semester'));
        $this->assertSame(['KHS Semester : 3', 'KHS Semester : 2', 'KHS Semester : 1', 'KHS Semester : T'], array_column($data['semesters'], 'label'));
        $this->assertSame([true, true, true, false], array_column($data['semesters'], 'is_regular'));
    }

    public function test_it_maps_the_ten_score_columns_by_header(): void
    {
        $semester = $this->scoreExtractor()->extractKhsData('fake-session-token')['semesters'][1];

        $this->assertSame(['absen', 'tugas', 'uts', 'uas'], $semester['columns']);
        $this->assertSame('graded', $semester['status']);
        $this->assertSame(5, $semester['course_count']);
        $this->assertSame(5, $semester['graded_count']);
        $this->assertSame(12, $semester['total_sks']);
        $this->assertSame(39.0, $semester['total_quality_points']);
        $this->assertSame(3.25, $semester['ips']);
        $this->assertSame([
            'code' => 'MK2001',
            'name' => 'Mata Kuliah Lima',
            'sks' => 3,
            'components' => ['absen' => 95.0, 'tugas' => 90.0, 'uts' => 92.0, 'uas' => 94.0],
            'score' => null,
            'letter' => 'A',
            'weight' => 4.0,
            'quality_points' => 12.0,
            'is_graded' => true,
        ], $semester['courses'][0]);
    }

    public function test_it_cleans_course_names_and_trailing_non_breaking_spaces(): void
    {
        $semesters = $this->scoreExtractor()->extractKhsData('fake-session-token')['semesters'];

        $this->assertSame('Mata Kuliah Enam : Pengantar', $semesters[1]['courses'][1]['name']);
        $this->assertSame('Mata Kuliah Delapan & Praktikum', $semesters[1]['courses'][4]['name']);
        $this->assertSame('Mata Kuliah Empat', $semesters[2]['courses'][3]['name']);
        $this->assertSame('E', $semesters[2]['courses'][2]['letter']);
        $this->assertSame(0.0, $semesters[2]['courses'][2]['weight']);
        $this->assertTrue($semesters[2]['courses'][2]['is_graded']);
    }

    public function test_quality_points_equal_sks_times_weight_on_every_row(): void
    {
        foreach (['tests/Fixtures/portal/sipandai_score.html', 'tests/Fixtures/portal/student_krs_grades.html'] as $fixture) {
            $semesters = $this->parse((string) file_get_contents(base_path($fixture)))['semesters'];

            foreach ($semesters as $semester) {
                foreach ($semester['courses'] as $course) {
                    $this->assertSame($course['sks'] * $course['weight'], $course['quality_points'], $fixture.' '.$course['code']);
                }
            }
        }
    }

    public function test_an_empty_card_with_ips_zero_has_no_ips(): void
    {
        $semesters = $this->scoreExtractor()->extractKhsData('fake-session-token')['semesters'];

        $this->assertSame('empty', $semesters[0]['status']);
        $this->assertSame([], $semesters[0]['courses']);
        $this->assertSame(0, $semesters[0]['total_sks']);
        $this->assertNull($semesters[0]['ips']);

        $this->assertSame('T', $semesters[3]['semester']);
        $this->assertFalse($semesters[3]['is_regular']);
        $this->assertNull($semesters[3]['ips']);
    }

    public function test_the_score_page_links_the_transcript_and_has_no_cumulative_summary(): void
    {
        $data = $this->scoreExtractor()->extractKhsData('fake-session-token');

        $this->assertNull($data['cumulative']);
        $this->assertSame(PortalEndpointRegistry::url('sipandai_transcript_print'), $data['transcript_print_url']);
    }

    public function test_the_student_identity_card_and_page_chrome_are_not_parsed(): void
    {
        $data = $this->scoreExtractor()->extractKhsData('fake-session-token');
        $encoded = (string) json_encode($data['semesters']);

        $this->assertCount(4, $data['semesters']);
        $this->assertStringNotContainsString('20990000001', $encoded);
        $this->assertStringNotContainsString('Mahasiswa Contoh', $encoded);
        $this->assertStringNotContainsString('Lorem ipsum', $encoded);
    }

    public function test_it_extracts_the_viewkrs_khs_and_the_official_cumulative_summary(): void
    {
        $extractor = $this->makeExtractorWithFixture(KhsExtractor::class, 'tests/Fixtures/portal/student_krs_grades.html');

        $data = $extractor->extractKrsGradesData('fake-session-token', '90004');

        $this->assertSame(KhsExtractor::SOURCE_KRS_DETAIL, $data['source']);
        $this->assertSame('90004', $data['krs_id']);
        $this->assertSame([3, 2, 1, 'T'], array_column($data['semesters'], 'semester'));
        $this->assertSame('Kartu Hasil Studi (KHS) Semester : 2', $data['semesters'][1]['label']);
        $this->assertSame(['uts', 'uas'], $data['semesters'][1]['columns']);
        $this->assertSame(['uts' => 92.0, 'uas' => 94.0], $data['semesters'][1]['courses'][0]['components']);
        $this->assertSame(3.25, $data['semesters'][1]['ips']);
        $this->assertSame(2.3, $data['semesters'][2]['ips']);
        $this->assertNull($data['transcript_print_url']);
        $this->assertSame([
            'ipk' => 3.1,
            'sks_total' => 20,
            'quality_points_total' => 62.0,
            'sks_remaining' => 124,
            'target_sks' => 144,
            'letter_counts' => ['A' => 3, 'B' => 3, 'C' => 1, 'D' => 1, 'E' => 0],
            'fields' => [
                'total_nilai_mutu' => 62.0,
                'sks_terkumpul' => 20.0,
                'sisa_sks_yang_harus_ditempuh' => 124.0,
                'i_p_kumulatif' => 3.1,
                'nilai_a' => 3.0,
                'nilai_b' => 3.0,
                'nilai_c' => 1.0,
                'nilai_d' => 1.0,
                'nilai_e' => 0.0,
            ],
        ], $data['cumulative']);
    }

    public function test_the_krs_course_table_and_cumulative_card_are_not_read_as_khs(): void
    {
        $data = $this->parse((string) file_get_contents(base_path('tests/Fixtures/portal/student_krs_grades.html')));
        $codes = array_merge(...array_map(static fn (array $semester): array => array_column($semester['courses'], 'code'), $data['semesters']));

        $this->assertCount(4, $data['semesters']);
        $this->assertNotContains('MK3001', $codes);
        $this->assertNull($data['semesters'][3]['ips']);
        $this->assertSame(0, $data['semesters'][3]['total_sks']);
    }

    public function test_it_requests_the_viewkrs_page_of_the_given_krs_id(): void
    {
        $extractor = $this->mockExtractor();
        $extractor->shouldReceive('get')
            ->once()
            ->with(PortalEndpointRegistry::url('student_krs_detail', ['id' => '90004']))
            ->andReturn((string) file_get_contents(base_path('tests/Fixtures/portal/student_krs_grades.html')));

        $data = $extractor->extractKrsGradesData('fake-session-token', '90004');

        $this->assertTrue($data['found']);
    }

    public function test_a_non_numeric_krs_id_is_rejected_before_any_request(): void
    {
        $extractor = $this->mockExtractor();
        $extractor->shouldNotReceive('get');

        $this->expectException(InvalidArgumentException::class);

        $extractor->extractKrsGradesData('fake-session-token', '90001&act=delete');
    }

    public function test_it_parses_raw_server_html_with_unclosed_bold_tags_and_ignores_the_sidebar(): void
    {
        $html = <<<'HTML'
<html><body>
<div class="wrapper">
<div class="sidebar"><div class="sidebar-wrapper">
<form method="post" action="../sipandai/?mod=login&act=loginact.php"><input type="hidden" name="id" value=""><input type="submit" value="SiPANDAI"></form>
<h4>Semester : 9</h4>
<table><tr><td>KODE</td><td>BOBOT</td></tr><tr><td>SIDEBAR1</td><td>4</td></tr></table>
</div></div>
<div class="main-panel">
<div class="content">
<div class="card"><div class="header">
<h4 class="title"><b>Kartu Hasil Studi (KHS) Semester : 2<b></h4>
</div>
<div class="content table-responsive table-full-width">
<table class="table table-hover">
<tr bgcolor="#c8c1bd">
<td width="40"><b>KODE</b></td><td><b>NAMA MATA KULIAH</b></td><td><b>SKS</b></td><td><b>UTS</b></td><td><b>UAS</b></td><td><b>NILAI</b></td><td><b>BOBOT</b></td><td><b>MUTU</b></td>
</tr>
<tr><td>MK2001</td><td>Mata Kuliah Lima</td><td align="center">3</td><td>92&nbsp;</td><td>94&nbsp;</td><td>A&nbsp;</td><td>4&nbsp;</td><td>12&nbsp;</td></tr>
<tr><td colspan="2" align="right">Total SKS</td><td>3</td><td colspan="4" align="right">Jumlah Mutu</td><td>12</td></tr>
<tr><td colspan="7" align="right">
<b>Indeks Prestasi Semester : </b>
</td><td align="center"><b>4,00</b></td></tr>
</table>
</div></div>
<div class="col-md-12"><div class="card"><div class="content table-responsive table-full-width">
<table class="table table-hover"><tr><td colspan="7" align="right">
<b> Total Nilai Mutu : 12<br> SKS Terkumpul : 3<br> Sisa SKS yang harus ditempuh : 141<br> I.P. Kumulatif : 4.00<br><br> Nilai A : 1<br>
</b></td></tr></table>
</div></div></div>
</div>
</div>
</div>
</body></html>
HTML;

        $data = $this->parse($html);

        $this->assertCount(1, $data['semesters']);
        $this->assertSame(2, $data['semesters'][0]['semester']);
        $this->assertSame('MK2001', $data['semesters'][0]['courses'][0]['code']);
        $this->assertSame(4.0, $data['semesters'][0]['ips']);
        $this->assertSame(4.0, $data['cumulative']['ipk']);
        $this->assertSame(144, $data['cumulative']['target_sks']);
        $this->assertSame(['A' => 1], $data['cumulative']['letter_counts']);
    }

    public function test_it_reads_the_khs_part_of_the_raw_krs_detail_fixture(): void
    {
        $fixture = base_path('tests/Fixtures/portal/student_krs_detail.html');

        if (! is_file($fixture)) {
            $this->markTestSkipped('student_krs_detail.html belum tersedia.');
        }

        $data = $this->parse((string) file_get_contents($fixture));
        $last = $data['semesters'][array_key_last($data['semesters'])];

        $this->assertNotSame([], $data['semesters']);
        $this->assertSame('T', $last['semester']);
        $this->assertNull($last['ips']);
        $this->assertNotNull($data['cumulative']);
        $this->assertNotNull($data['cumulative']['ipk']);
        $this->assertSame($data['cumulative']['sks_total'] + $data['cumulative']['sks_remaining'], $data['cumulative']['target_sks']);

        foreach ($data['semesters'] as $semester) {
            $this->assertSame(['uts', 'uas'], $semester['columns']);

            foreach ($semester['courses'] as $course) {
                $this->assertSame($course['sks'] * $course['weight'], $course['quality_points']);
            }
        }
    }

    public function test_partial_grades_unknown_columns_and_placeholder_letters(): void
    {
        $data = $this->parse($this->khsPage('Semester : 4', ['KODE', 'NAMA MATA KULIAH', 'SKS', 'KUIS', 'UTS', 'UAS', 'NILAI', 'BOBOT', 'MUTU'], [
            ['MK4001', 'Mata Kuliah Empat Satu', '3', '80', '85', '88', 'A', '4', '12'],
            ['MK4002', 'Mata Kuliah Empat Dua', '2', '70', '75', '', '', '', ''],
            ['MK4003', 'Mata Kuliah Empat Tiga', '2', '', '', '', '-', '', ''],
            ['MK4004', 'Mata Kuliah Empat Empat', '2', '', '', '', 'T', '0', '0'],
        ], '0'));

        $semester = $data['semesters'][0];

        $this->assertSame(['kuis', 'uts', 'uas'], $semester['columns']);
        $this->assertSame('partial', $semester['status']);
        $this->assertSame(1, $semester['graded_count']);
        $this->assertSame(['kuis' => 70.0, 'uts' => 75.0, 'uas' => null], $semester['courses'][1]['components']);
        $this->assertNull($semester['courses'][1]['letter']);
        $this->assertFalse($semester['courses'][1]['is_graded']);
        $this->assertNull($semester['courses'][2]['letter']);
        $this->assertSame('T', $semester['courses'][3]['letter']);
        $this->assertFalse($semester['courses'][3]['is_graded']);
        $this->assertSame(0.0, $semester['ips']);
    }

    public function test_rows_waiting_for_grades_make_a_pending_semester_without_ips(): void
    {
        $data = $this->parse($this->khsPage('Semester : 3', ['KODE', 'NAMA MATA KULIAH', 'SKS', 'UTS', 'UAS', 'NILAI', 'BOBOT', 'MUTU'], [
            ['MK3001', 'Mata Kuliah Sembilan', '3', '', '', '', '', ''],
        ], '0'));

        $this->assertSame('pending', $data['semesters'][0]['status']);
        $this->assertNull($data['semesters'][0]['ips']);
    }

    public function test_a_numeric_final_score_column_and_letter_column_are_kept_apart(): void
    {
        $data = $this->parse($this->khsPage('Semester : 1', ['KODE', 'MATA KULIAH', 'SKS', 'NILAI AKHIR', 'HURUF', 'BOBOT', 'MUTU'], [
            ['mk1001', 'Mata Kuliah Satu', '3', '86,5', 'b+', '3,5', '10,5'],
        ], '3,50'));

        $course = $data['semesters'][0]['courses'][0];

        $this->assertSame('MK1001', $course['code']);
        $this->assertSame(86.5, $course['score']);
        $this->assertSame('B+', $course['letter']);
        $this->assertSame(3.5, $course['weight']);
        $this->assertSame(10.5, $course['quality_points']);
        $this->assertTrue($course['is_graded']);
        $this->assertSame([], $data['semesters'][0]['columns']);
        $this->assertSame(3.5, $data['semesters'][0]['ips']);
    }

    public function test_missing_weight_and_quality_points_are_derived_from_the_letter(): void
    {
        $data = $this->parse($this->khsPage('Semester : 1', ['KODE', 'NAMA MATA KULIAH', 'SKS', 'NILAI', 'BOBOT'], [
            ['MK1001', 'Mata Kuliah Satu', '3', 'B', ''],
        ], '3'));

        $course = $data['semesters'][0]['courses'][0];

        $this->assertSame(3.0, $course['weight']);
        $this->assertSame(9.0, $course['quality_points']);
    }

    public function test_an_empty_shell_is_not_found_and_not_cached(): void
    {
        $extractor = $this->extractorReturning('<!doctype html><html><body><div class="wrapper"><div class="main-panel"><div class="content"></div></div></div></body></html>', 2);

        $first = $extractor->extractKhsData('fake-session-token');
        $extractor->extractKhsData('fake-session-token');

        $this->assertFalse($first['found']);
        $this->assertSame([], $first['semesters']);
        $this->assertNull($first['cumulative']);
    }

    public function test_the_login_page_is_rejected_on_both_sources(): void
    {
        $login = '<!doctype html><html><head><title>MyUCIC - Login</title></head><body>'
            .'<form method="post" action="?mod=login&act=loginact"><input type="text" name="txt_username">'
            .'<input type="password" name="txt_password"><input type="submit" value="Login"></form></body></html>';

        foreach ([
            fn (KhsExtractor $extractor): array => $extractor->extractKhsData('fake-session-token'),
            fn (KhsExtractor $extractor): array => $extractor->extractKrsGradesData('fake-session-token', '90004'),
        ] as $extract) {
            try {
                $extract($this->extractorReturning($login, 1));
                $this->fail('The login page was parsed as a KHS page.');
            } catch (PortalScrapingException $exception) {
                $this->assertSame(403, $exception->getCode());
            }
        }
    }

    public function test_a_found_khs_is_served_from_the_resource_cache(): void
    {
        $extractor = $this->extractorReturning((string) file_get_contents(base_path('tests/Fixtures/portal/sipandai_score.html')), 1);

        $extractor->extractKhsData('fake-session-token');
        $second = $extractor->extractKhsData('fake-session-token');

        $this->assertSame('server_cache', $second['_sync']['data_origin']);
    }

    public function test_the_raw_score_page_gives_every_semester_through_the_live_parser(): void
    {
        /** @var KhsExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(KhsExtractor::class, 'tests/Fixtures/portal/sipandai_score_raw.html', $requestedUrls);

        $data = $extractor->extractKhsData('fake-session-token');
        $semesters = $data['semesters'];

        $this->assertSame([PortalEndpointRegistry::url('sipandai_score')], $requestedUrls);
        $this->assertTrue($data['found']);
        $this->assertSame([3, 2, 1, 'T'], array_column($semesters, 'semester'));
        $this->assertSame(['KHS Semester : 3', 'KHS Semester : 2', 'KHS Semester : 1', 'KHS Semester : T'], array_column($semesters, 'label'));
        $this->assertSame([true, true, true, false], array_column($semesters, 'is_regular'));
        $this->assertSame(['empty', 'graded', 'graded', 'empty'], array_column($semesters, 'status'));
        $this->assertSame([0, 9, 7, 0], array_column($semesters, 'course_count'));
        $this->assertSame([0, 9, 7, 0], array_column($semesters, 'graded_count'));
        $this->assertSame([0, 24, 18, 0], array_column($semesters, 'total_sks'));
        $this->assertSame([0.0, 79.0, 56.0, 0.0], array_column($semesters, 'total_quality_points'));
        $this->assertSame([null, 3.29, 3.11, null], array_column($semesters, 'ips'));
        $this->assertSame(['absen', 'tugas', 'uts', 'uas'], $semesters[1]['columns']);
        $this->assertSame([
            'code' => 'SIX043101',
            'name' => 'Mata Kuliah Contoh Satu : Pengantar',
            'sks' => 3,
            'components' => ['absen' => 80.0, 'tugas' => 78.0, 'uts' => 76.0, 'uas' => 80.0],
            'score' => null,
            'letter' => 'B',
            'weight' => 3.0,
            'quality_points' => 9.0,
            'is_graded' => true,
        ], $semesters[1]['courses'][0]);
        $this->assertSame('Mata Kuliah Contoh Tujuh & Praktikum', $semesters[1]['courses'][6]['name']);
        $this->assertSame('Mata Kuliah Contoh Sembilan', $semesters[1]['courses'][8]['name']);
        $this->assertSame('UCX0102101', $semesters[1]['courses'][2]['code']);
        $this->assertNull($data['cumulative']);
        $this->assertSame(PortalEndpointRegistry::url('sipandai_transcript_print'), $data['transcript_print_url']);
    }

    public function test_the_raw_score_page_identity_card_and_chrome_are_not_read_as_khs(): void
    {
        /** @var KhsExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(KhsExtractor::class, 'tests/Fixtures/portal/sipandai_score_raw.html');

        $encoded = (string) json_encode($extractor->extractKhsData('fake-session-token')['semesters']);

        $this->assertStringNotContainsString('20990000001', $encoded);
        $this->assertStringNotContainsString('Mahasiswa Contoh', $encoded);
        $this->assertStringNotContainsString('FAKULTAS CONTOH', $encoded);
        $this->assertStringNotContainsString('Lorem ipsum', $encoded);
    }

    public function test_the_raw_viewkrs_page_gives_the_khs_and_the_official_cumulative_summary(): void
    {
        /** @var KhsExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(KhsExtractor::class, 'tests/Fixtures/portal/student_krs_grades_raw.html', $requestedUrls);

        $data = $extractor->extractKrsGradesData('fake-session-token', '90004');
        $semesters = $data['semesters'];

        $this->assertSame([PortalEndpointRegistry::url('student_krs_detail', ['id' => '90004'])], $requestedUrls);
        $this->assertTrue($data['found']);
        $this->assertSame('90004', $data['krs_id']);
        $this->assertSame([3, 2, 1, 'T'], array_column($semesters, 'semester'));
        $this->assertSame('Kartu Hasil Studi (KHS) Semester : 2', $semesters[1]['label']);
        $this->assertSame(['empty', 'graded', 'graded', 'empty'], array_column($semesters, 'status'));
        $this->assertSame([0, 9, 7, 0], array_column($semesters, 'course_count'));
        $this->assertSame([0, 24, 18, 0], array_column($semesters, 'total_sks'));
        $this->assertSame([0.0, 79.0, 56.0, 0.0], array_column($semesters, 'total_quality_points'));
        $this->assertSame([null, 3.29, 3.11, null], array_column($semesters, 'ips'));
        $this->assertSame(['uts', 'uas'], $semesters[2]['columns']);
        $this->assertSame(['uts' => 89.0, 'uas' => 95.0], $semesters[2]['courses'][2]['components']);
        $this->assertNull($data['transcript_print_url']);
        $this->assertSame([
            'ipk' => 3.21,
            'sks_total' => 42,
            'quality_points_total' => 135.0,
            'sks_remaining' => 102,
            'target_sks' => 144,
            'letter_counts' => ['A' => 7, 'B' => 5, 'C' => 4, 'D' => 0, 'E' => 0],
            'fields' => [
                'total_nilai_mutu' => 135.0,
                'sks_terkumpul' => 42.0,
                'sisa_sks_yang_harus_ditempuh' => 102.0,
                'i_p_kumulatif' => 3.21,
                'nilai_a' => 7.0,
                'nilai_b' => 5.0,
                'nilai_c' => 4.0,
                'nilai_d' => 0.0,
                'nilai_e' => 0.0,
            ],
        ], $data['cumulative']);
    }

    public function test_the_raw_viewkrs_course_table_is_not_read_as_khs(): void
    {
        $data = $this->parse((string) file_get_contents(base_path('tests/Fixtures/portal/student_krs_grades_raw.html')));
        $codes = array_merge(...array_map(static fn (array $semester): array => array_column($semester['courses'], 'code'), $data['semesters']));

        $this->assertCount(4, $data['semesters']);
        $this->assertCount(16, $codes);
        $this->assertNotContains('SIX053301', $codes);
        $this->assertNotContains('SIX012310', $codes);
    }

    public function test_both_raw_pages_carry_the_same_khs_rows_and_consistent_footers(): void
    {
        $score = $this->parse((string) file_get_contents(base_path('tests/Fixtures/portal/sipandai_score_raw.html')))['semesters'];
        $viewkrs = $this->parse((string) file_get_contents(base_path('tests/Fixtures/portal/student_krs_grades_raw.html')))['semesters'];
        $rows = static fn (array $semester): array => array_map(
            static fn (array $course): array => [$course['code'], $course['name'], $course['sks'], $course['letter'], $course['weight'], $course['quality_points'], $course['components']['uts'], $course['components']['uas']],
            $semester['courses']
        );

        $this->assertSame(array_map($rows, $score), array_map($rows, $viewkrs));

        foreach ($score as $semester) {
            $sks = array_sum(array_column($semester['courses'], 'sks'));
            $qualityPoints = array_sum(array_column($semester['courses'], 'quality_points'));

            $this->assertSame($semester['total_sks'], $sks);
            $this->assertEquals($semester['total_quality_points'], $qualityPoints);

            if ($sks > 0) {
                $this->assertSame($semester['ips'], round($qualityPoints / $sks, 2));
            }
        }
    }

    public function test_wrapped_header_cells_forms_and_nested_tables_parse_like_the_portal_shows_them(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html><body><div class="wrapper"><div class="main-panel"><div class="content">
<div class="card"><div class="header"><h4 class="title"><b>Kartu Hasil Studi (KHS) Semester : 2<b></h4></div>
<table class="layout"><tr><td>
<table class="table table-hover">
<tr><td colspan="8"><center>Daftar Nilai</center></td></tr>
<tr bgcolor="#c8c1bd"><b>
<td><b>KODE</b></td><td><b>NAMA MATA KULIAH</b></td><td><b>SKS</b></td><td><b>UTS</b></td><td><b>UAS</b></td><td><b>NILAI</b></td><td><b>BOBOT</b></td><td><b>MUTU</b></td>
</b></tr>
<form method="post" action="?mod=student&act=viewkrs">
<tr><td>MK2001</td><td><font color="#000000">Mata Kuliah Lima</font></td><td>3</td><td>92&nbsp;</td><td>94&nbsp;</td><td>A&nbsp;</td><td>4&nbsp;</td><td>12&nbsp;</td></tr>
<tr><td>MK2002</td><td>Mata Kuliah Enam</td><td>2</td><td>70&nbsp;</td><td>75&nbsp;</td><td>B&nbsp;</td><td>3&nbsp;</td><td>6&nbsp;</td></tr>
</form>
<tr><td colspan="2" align="right">Total SKS</td><td>5</td><td colspan="4" align="right">Jumlah Mutu</td><td>18</td></tr>
<tr><td colspan="7" align="right"><b>Indeks Prestasi Semester : </b></td><td align="center"><b>3.60</b></td></tr>
</table>
</td></tr></table>
</div>
</div></div></div></body></html>
HTML;

        $data = $this->parse($html);

        $this->assertCount(1, $data['semesters']);
        $this->assertSame(2, $data['semesters'][0]['semester']);
        $this->assertSame(['uts', 'uas'], $data['semesters'][0]['columns']);
        $this->assertSame(['MK2001', 'MK2002'], array_column($data['semesters'][0]['courses'], 'code'));
        $this->assertSame('Mata Kuliah Lima', $data['semesters'][0]['courses'][0]['name']);
        $this->assertSame(5, $data['semesters'][0]['total_sks']);
        $this->assertSame(18.0, $data['semesters'][0]['total_quality_points']);
        $this->assertSame(3.6, $data['semesters'][0]['ips']);
    }

    private function scoreExtractor(): KhsExtractor
    {
        /** @var KhsExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(KhsExtractor::class, 'tests/Fixtures/portal/sipandai_score.html');

        return $extractor;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $html): array
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);

        return (new KhsExtractor($sessionManager))->parseKhsHtml($html);
    }

    private function extractorReturning(string $html, int $times): KhsExtractor
    {
        $extractor = $this->mockExtractor();
        $extractor->shouldReceive('get')->times($times)->andReturn($html);

        return $extractor;
    }

    private function mockExtractor(): KhsExtractor&MockInterface
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        /** @var KhsExtractor&MockInterface $extractor */
        $extractor = Mockery::mock(KhsExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        return $extractor;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function khsPage(string $title, array $headers, array $rows, string $ips): string
    {
        $headerCells = implode('', array_map(static fn (string $header): string => '<td><b>'.$header.'</b></td>', $headers));
        $bodyRows = implode("\n", array_map(
            static fn (array $row): string => '<tr>'.implode('', array_map(static fn (string $value): string => '<td>'.$value.'&nbsp;</td>', $row)).'</tr>',
            $rows
        ));
        $span = count($headers) - 1;

        return <<<HTML
<!doctype html>
<html><body><div class="wrapper"><div class="main-panel"><div class="content">
<div class="card"><div class="header"><h4 class="title">KHS {$title}</h4></div>
<div class="content table-responsive table-full-width"><table class="table table-hover">
<tr bgcolor="#c8c1bd">{$headerCells}</tr>
{$bodyRows}
<tr><td colspan="{$span}" align="right"> Indeks Prestasi Semester : </td><td align="center">{$ips}</td></tr>
</table></div></div>
</div></div></div></body></html>
HTML;
    }
}
