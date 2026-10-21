<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\AdvisingExtractor;
use App\Services\Extractors\KrsDetailExtractor;
use App\Services\Extractors\KrsExtractor;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class KrsDetailExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURE = 'tests/Fixtures/portal/student_krs_detail.html';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_the_krs_part_of_the_viewkrs_page(): void
    {
        /** @var KrsDetailExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(KrsDetailExtractor::class, self::FIXTURE);

        $detail = $extractor->extractKrsDetailData('fake-session-token', 90004);

        $this->assertTrue($detail['found']);
        $this->assertSame(90004, $detail['krs_id']);
        $this->assertSame([
            'academic_year' => '2026/2027',
            'semester' => 3,
            'semester_is_regular' => true,
            'semester_label' => 'Semester 3',
            'date' => '2026-09-23',
            'nim' => '20990000001',
            'name' => 'Mahasiswa Contoh',
        ], $detail['header']);
        $this->assertTrue($detail['counts_as_study_load']);
        $this->assertSame('pending_dpa', $detail['status']['code']);
        $this->assertStringStartsWith('Segera menghubungi Dosen DPA', (string) $detail['status']['raw_text']);
        $this->assertSame(['courses' => 6, 'sks' => 14, 'source' => 'portal', 'consistent' => true], $detail['totals']);
        $this->assertTrue($detail['print_available']);
        $this->assertTrue($detail['krs_id_confirmed']);
    }

    public function test_courses_keep_only_the_code_text_and_the_mk_status(): void
    {
        /** @var KrsDetailExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(KrsDetailExtractor::class, self::FIXTURE);

        $courses = $extractor->extractKrsDetailData('fake-session-token', 90004)['courses'];

        $this->assertSame(['MK5001', 'MK5002', 'MK3001', 'MK3002', 'MK3003', 'MK1004'], array_column($courses, 'code'));
        $this->assertSame(
            ['code' => 'MK5001', 'name' => 'Mata Kuliah Lima Satu', 'sks' => 2, 'semester' => 5, 'status' => 'Baru', 'is_retake' => false],
            $courses[0]
        );
        $this->assertSame('Mata Kuliah Tiga Satu', $courses[2]['name']);
        $this->assertSame('Mata Kuliah Tiga Dua (Teori & Praktik)', $courses[3]['name']);
        $this->assertSame(
            ['code' => 'MK1004', 'name' => 'Mata Kuliah Empat', 'sks' => 2, 'semester' => 1, 'status' => 'Ulang', 'is_retake' => true],
            $courses[5]
        );
        $this->assertStringNotContainsString('9100', (string) json_encode($courses));
    }

    public function test_khs_tables_on_the_same_page_are_ignored(): void
    {
        /** @var KrsDetailExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(KrsDetailExtractor::class, self::FIXTURE);

        $detail = $extractor->extractKrsDetailData('fake-session-token', 90004);
        $encoded = (string) json_encode($detail);

        foreach (['MK2001', 'MK1001', 'Indeks Prestasi', 'Kumulatif'] as $khsValue) {
            $this->assertStringNotContainsString($khsValue, $encoded);
        }
    }

    public function test_semester_t_krs_is_not_a_study_load_and_empty_semesters_are_null(): void
    {
        $html = $this->replaceOnce($this->fixtureHtml(), "<td>KRS Semester</td><td>:</td>\n<td>3</td>", "<td>KRS Semester</td><td>:</td>\n<td>T</td>");
        $html = $this->replaceOnce(
            $html,
            "<td>Mata Kuliah Lima Dua</td>\n<td align=\"center\">2</td>\n<td align=\"center\">5</td>",
            "<td>Mata Kuliah Lima Dua</td>\n<td align=\"center\">2</td>\n<td align=\"center\"></td>"
        );

        $detail = $this->makeExtractorWithHtml($html, 90004)->extractKrsDetailData('fake-session-token', 90004);

        $this->assertSame('T', $detail['header']['semester']);
        $this->assertFalse($detail['header']['semester_is_regular']);
        $this->assertSame('Semester T (konversi)', $detail['header']['semester_label']);
        $this->assertFalse($detail['counts_as_study_load']);
        $this->assertNull($detail['courses'][1]['semester']);
        $this->assertSame(14, $detail['totals']['sks']);
    }

    public function test_a_page_for_another_krs_is_flagged(): void
    {
        $detail = $this->makeExtractorWithHtml($this->fixtureHtml(), 90003)->extractKrsDetailData('fake-session-token', 90003);

        $this->assertTrue($detail['found']);
        $this->assertSame(90003, $detail['krs_id']);
        $this->assertFalse($detail['krs_id_confirmed']);
    }

    public function test_the_krs_part_can_be_parsed_from_a_crawler_without_a_request(): void
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $extractor = new KrsDetailExtractor($sessionManager);

        $detail = $extractor->parseKrsDetailPage(new Crawler($this->fixtureHtml()), 90004);

        $this->assertTrue($detail['found']);
        $this->assertCount(6, $detail['courses']);
        $this->assertSame(3, $detail['header']['semester']);
    }

    public function test_unknown_krs_id_renders_an_empty_shell(): void
    {
        $html = (string) preg_replace(
            '/(<div class="content">\s*<div class="container-fluid">).*?(\s*<\/div>\s*<\/div>\s*<footer)/s',
            '$1$2',
            $this->fixtureHtml()
        );
        $this->assertStringNotContainsString('KRS Semester', $html);

        $detail = $this->makeExtractorWithHtml($html, 99999)->extractKrsDetailData('fake-session-token', 99999);

        $this->assertFalse($detail['found']);
        $this->assertSame([], $detail['courses']);
        $this->assertNull($detail['krs_id_confirmed']);
        $this->assertSame('unknown', $detail['status']['code']);
    }

    public function test_login_page_throws(): void
    {
        $extractor = $this->makeExtractorWithHtml(
            '<!doctype html><html><head><title>MyUCIC - Login</title></head><body>'
            .'<form method="post" action="?mod=login&act=loginact"><input type="text" name="txt_username">'
            .'<input type="password" name="txt_password"></form></body></html>',
            90004
        );

        $this->expectException(PortalScrapingException::class);

        $extractor->extractKrsDetailData('fake-session-token', 90004);
    }

    /**
     * The grades parser reuses this fixture, so its KHS part must add up:
     * mutu = sks x bobot, card totals and IPS, and a cumulative card built
     * from the best attempt of every course (a retaken E is replaced). It is
     * the same KHS as sipandai_score.html, as on the live portal.
     */
    public function test_fixture_khs_part_is_internally_consistent(): void
    {
        $crawler = new Crawler($this->fixtureHtml());
        $weights = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, 'E' => 0];
        $bestAttempts = [];
        $cards = 0;

        $crawler->filter('table')->each(function (Crawler $table) use ($weights, &$bestAttempts, &$cards): void {
            $header = $table->filter('tr')->first()->text('');

            if (! str_contains($header, 'BOBOT')) {
                return;
            }

            $cards++;
            $sks = 0;
            $mutu = 0;
            $footer = [];

            $table->filter('tr')->slice(1)->each(function (Crawler $row) use ($weights, &$sks, &$mutu, &$bestAttempts, &$footer): void {
                $cells = array_map(
                    static fn (string $text): string => trim(str_replace("\u{00A0}", ' ', $text)),
                    $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text(''))
                );

                if (count($cells) !== 8) {
                    $footer[] = $cells;

                    return;
                }

                $this->assertSame((int) $cells[2] * $weights[$cells[5]], (int) $cells[7]);
                $this->assertSame($weights[$cells[5]], (int) $cells[6]);
                $sks += (int) $cells[2];
                $mutu += (int) $cells[7];

                if (! isset($bestAttempts[$cells[0]]) || $weights[$cells[5]] >= $weights[$bestAttempts[$cells[0]]['grade']]) {
                    $bestAttempts[$cells[0]] = ['sks' => (int) $cells[2], 'grade' => $cells[5], 'mutu' => (int) $cells[7]];
                }
            });

            $this->assertSame((string) $sks, $footer[0][1]);
            $this->assertSame((string) $mutu, $footer[0][3]);
            $this->assertSame($sks === 0 ? '0' : number_format($mutu / $sks, 2), $footer[1][1]);
        });

        $this->assertSame(4, $cards);

        $totalSks = array_sum(array_column($bestAttempts, 'sks'));
        $totalMutu = array_sum(array_column($bestAttempts, 'mutu'));
        $gradeCounts = array_merge(array_fill_keys(array_keys($weights), 0), array_count_values(array_column($bestAttempts, 'grade')));
        $summary = $crawler->filterXPath('//td[contains(., "I.P. Kumulatif")]')->text('');

        $this->assertStringContainsString('Total Nilai Mutu : '.$totalMutu, $summary);
        $this->assertStringContainsString('SKS Terkumpul : '.$totalSks, $summary);
        $this->assertStringContainsString('Sisa SKS yang harus ditempuh : '.(144 - $totalSks), $summary);
        $this->assertStringContainsString('I.P. Kumulatif : '.number_format($totalMutu / $totalSks, 2), $summary);

        foreach ($gradeCounts as $grade => $count) {
            $this->assertStringContainsString('Nilai '.$grade.' : '.$count, $summary);
        }
    }

    /**
     * Cross-builder contract: the latest KRS id of the advising page opens
     * this viewkrs page, whose courses are the current KRS of act=credit, and
     * both pages name the same DPA.
     */
    public function test_the_krs_fixtures_describe_one_current_krs(): void
    {
        /** @var AdvisingExtractor $advisingExtractor */
        $advisingExtractor = $this->makeExtractorWithFixture(AdvisingExtractor::class, 'tests/Fixtures/portal/student_advising.html');
        /** @var KrsExtractor $krsExtractor */
        $krsExtractor = $this->makeExtractorWithFixture(KrsExtractor::class, 'tests/Fixtures/portal/student_credit.html');

        $advising = $advisingExtractor->extractAdvisingData('fake-session-token');
        $krs = $krsExtractor->extractKrsData('fake-session-token');
        $detail = $this->makeExtractorWithHtml($this->fixtureHtml(), (int) $advising['latest_krs_id'])
            ->extractKrsDetailData('fake-session-token', (int) $advising['latest_krs_id']);

        $this->assertTrue($detail['krs_id_confirmed']);
        $this->assertSame($advising['latest_semester_number'], $detail['header']['semester']);
        $this->assertSame(
            array_map(static fn (array $course): array => [$course['code'], $course['sks'], $course['semester']], $krs['courses']),
            array_map(static fn (array $course): array => [$course['code'], $course['sks'], $course['semester']], $detail['courses'])
        );
        $this->assertSame($krs['totals']['sks'], $detail['totals']['sks']);
        $this->assertSame($krs['status']['code'], $detail['status']['code']);
        $this->assertSame($advising['dpa'], $krs['dpa']);
    }

    private function fixtureHtml(): string
    {
        return (string) file_get_contents(base_path(self::FIXTURE));
    }

    private function replaceOnce(string $html, string $search, string $replace): string
    {
        $this->assertSame(1, substr_count($html, $search), 'Fixture snippet not found exactly once: '.$search);

        return str_replace($search, $replace, $html);
    }

    private function makeExtractorWithHtml(string $html, int $krsId): KrsDetailExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        /** @var KrsDetailExtractor&\Mockery\MockInterface $extractor */
        $extractor = Mockery::mock(KrsDetailExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->once()
            ->with(Mockery::on(static fn (string $url): bool => str_contains($url, 'act=viewkrs') && str_contains($url, 'id='.$krsId)))
            ->andReturn($html);

        return $extractor;
    }
}
