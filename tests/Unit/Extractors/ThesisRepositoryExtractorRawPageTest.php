<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalException;
use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\ThesisRepositoryExtractor;
use App\Services\PortalEndpointRegistry;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The thesis repository (act=repositori) as the live portal sends it (K14:
 * synthetic names, NIMs and titles in the portal's raw page shell): one
 * record is four rows without tbody, the empty search form sits directly in
 * the table, `altrow` marks every second main row only, a label cell can be
 * wrapped in `<b>`, and the paging line is "[ 1-100 of <total> ]".
 */
class ThesisRepositoryExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const PAGE_ONE = 'tests/Fixtures/portal/student_repository_page1_raw.html';

    private const LAST_PAGE = 'tests/Fixtures/portal/student_repository_last_raw.html';

    /**
     * @var list<string>
     */
    private array $temporaryFixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFixtures as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_four_raw_rows_become_one_record_mapped_by_label(): void
    {
        $page = $this->pageOne();

        $this->assertTrue($page['found']);
        $this->assertCount(4, $page['records']);
        $this->assertSame([
            'no' => 1,
            'academic_year' => '2023/2024',
            'program' => 'SISTEM INFORMASI',
            'nim' => '190100001',
            'author' => 'MAHASISWA CONTOH SATU',
            'title' => 'SISTEM INFORMASI PERSEDIAAN BARANG BERBASIS WEB PADA TOKO CONTOH',
            'advisor_1' => 'Dosen Contoh Alfa, S.Kom., M.Kom.',
            'advisor_2' => 'Dosen Contoh Beta, S.T., M.T.',
            'file_path' => 'files/skripsi/kolokium/TUGAS AKHIR-SIDANG-190100001-KOLOKIUM-a1b2c.pdf',
            'page' => 1,
        ], array_diff_key($page['records'][0], ['record_key' => true]));
    }

    public function test_the_nim_and_author_are_split_at_the_line_break_and_text_is_normalized(): void
    {
        $record = $this->pageOne()['records'][1];

        $this->assertSame('190200002', $record['nim']);
        // "&nbsp;" in the name and "&amp;" in the title come back as plain text.
        $this->assertSame('Mahasiswa Contoh Dua', $record['author']);
        $this->assertSame('Klasifikasi Citra Daun Menggunakan Convolutional Neural Network & Augmentasi Data', $record['title']);
        // The "Pembimbing 1" label cell is wrapped in <b> in the raw row.
        $this->assertSame('Dosen Contoh Gamma, M.Kom.', $record['advisor_1']);
    }

    public function test_a_missing_second_advisor_or_pdf_is_null(): void
    {
        $records = $this->pageOne()['records'];

        $this->assertNull($records[1]['advisor_2'], 'A "-" placeholder is no advisor.');
        $this->assertNull($records[3]['advisor_2'], 'An empty cell is no advisor.');
        $this->assertNull($records[2]['file_path']);
        $this->assertNotNull($records[2]['record_key'], 'A record without a PDF still gets a key.');
    }

    public function test_record_keys_prefer_the_pdf_and_fall_back_to_nim_year_and_title(): void
    {
        $records = $this->pageOne()['records'];

        $this->assertSame(sha1($records[0]['file_path']), $records[0]['record_key']);
        $this->assertSame(
            sha1('180100003|2022/2023|'.mb_strtolower('Rancang Bangun Aplikasi Monitoring Kehadiran Berbasis Android')),
            $records[2]['record_key']
        );
        $this->assertCount(4, array_unique(array_column($records, 'record_key')));
    }

    public function test_paging_reads_the_total_and_the_next_link(): void
    {
        $this->assertSame([
            'page' => 1,
            'per_page' => 100,
            'total' => 212,
            'last_page' => 3,
            'has_next' => true,
            'next_page' => 2,
        ], $this->pageOne()['paging']);

        $last = $this->extractor(self::LAST_PAGE)->parseRepositoryPage((string) file_get_contents(base_path(self::LAST_PAGE)), 3);

        $this->assertFalse($last['paging']['has_next'], 'Next is plain text on the last page.');
        $this->assertNull($last['paging']['next_page']);
    }

    public function test_page_one_is_requested_from_the_registry_url(): void
    {
        $extractor = $this->makeExtractorWithRawFixture(ThesisRepositoryExtractor::class, self::PAGE_ONE, $requestedUrls);

        $page = $extractor->extractRepositoryPage('fake-session-token', 1);

        $this->assertSame([PortalEndpointRegistry::url('student_repository')], $requestedUrls);
        $this->assertCount(4, $page['records']);
        $this->assertSame(
            PortalEndpointRegistry::url('student_repository', ['page' => 2]),
            $extractor->repositoryPageUrl(2)
        );
    }

    public function test_the_walk_stops_at_a_page_without_next_and_dedupes_records(): void
    {
        /** @var ThesisRepositoryExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [
            $this->url(1) => self::PAGE_ONE,
            $this->url(2) => self::LAST_PAGE,
        ], $requestedUrls);

        $index = $extractor->extractRepositoryIndex('fake-session-token');

        // The total promises 3 pages, but page 2 has no Next link: page 3 is never requested.
        $this->assertSame([$this->url(1), $this->url(2)], $requestedUrls);
        $this->assertTrue($index['found']);
        $this->assertTrue($index['complete']);
        $this->assertFalse($index['reused_snapshot']);
        $this->assertSame(2, $index['pages_loaded']);
        $this->assertSame(212, $index['total']);
        // Page 2 repeats the first record of page 1 (same PDF): kept once.
        $this->assertSame(5, $index['record_count']);
        $this->assertCount(5, array_unique(array_column($index['records'], 'record_key')));
        $this->assertSame($index['records'][0]['record_key'], $index['first_record_key']);
        $this->assertSame(1, $index['records'][0]['page']);
        $this->assertSame('KOMPUTERISASI AKUNTANSI', $index['records'][4]['program']);
    }

    public function test_without_a_readable_total_the_walk_follows_the_next_links(): void
    {
        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [
            $this->url(1) => $this->pageWithoutTotal(1),
            $this->url(2) => self::LAST_PAGE,
        ], $requestedUrls);

        $index = $extractor->extractRepositoryIndex('fake-session-token');

        $this->assertSame([$this->url(1), $this->url(2)], $requestedUrls);
        $this->assertNull($index['total']);
        $this->assertSame(2, $index['pages_loaded']);
        $this->assertSame(5, $index['record_count']);
        $this->assertTrue($index['complete']);
    }

    public function test_a_walk_cut_off_at_the_page_limit_without_a_total_is_incomplete(): void
    {
        $fixtures = [];

        for ($page = 1; $page <= 31; $page++) {
            $fixtures[$this->url($page)] = $this->pageWithoutTotal($page);
        }

        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, $fixtures, $requestedUrls);

        $index = $extractor->extractRepositoryIndex('fake-session-token');

        // Page 30 still links to page 31, which the walk never asks for.
        $this->assertCount(30, $requestedUrls);
        $this->assertSame(30, $index['pages_loaded']);
        $this->assertFalse($index['complete']);
    }

    public function test_an_unchanged_repository_reuses_the_previous_index_after_page_one(): void
    {
        $first = $this->pageOne();
        $previous = [
            'records' => [...$first['records'], ['record_key' => 'older', 'title' => 'Skripsi lama dari halaman 2']],
            'total' => 212,
            'complete' => true,
            'pages_loaded' => 3,
            'first_record_key' => $first['records'][0]['record_key'],
        ];

        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [$this->url(1) => self::PAGE_ONE], $requestedUrls);

        $index = $extractor->extractRepositoryIndex('fake-session-token', $previous);

        $this->assertSame([$this->url(1)], $requestedUrls);
        $this->assertTrue($index['reused_snapshot']);
        $this->assertSame($previous['records'], $index['records']);
        $this->assertSame(3, $index['pages_loaded']);
        $this->assertTrue($index['complete']);
    }

    public function test_a_changed_total_or_an_incomplete_previous_index_walks_again(): void
    {
        $first = $this->pageOne();
        $base = ['records' => $first['records'], 'complete' => true, 'pages_loaded' => 3, 'first_record_key' => $first['records'][0]['record_key']];

        foreach ([[...$base, 'total' => 211], [...$base, 'total' => 212, 'complete' => false]] as $previous) {
            // Each case starts without the pages the previous one cached.
            Cache::flush();
            $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [
                $this->url(1) => self::PAGE_ONE,
                $this->url(2) => self::LAST_PAGE,
            ], $requestedUrls);

            $index = $extractor->extractRepositoryIndex('fake-session-token', $previous);

            $this->assertSame([$this->url(1), $this->url(2)], $requestedUrls);
            $this->assertFalse($index['reused_snapshot']);
        }
    }

    public function test_a_failing_later_page_keeps_what_was_read_and_marks_the_index_incomplete(): void
    {
        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [
            $this->url(1) => self::PAGE_ONE,
            $this->url(2) => PortalScrapingException::invalidResponse($this->url(2), 'timeout'),
        ]);

        $index = $extractor->extractRepositoryIndex('fake-session-token');

        $this->assertFalse($index['complete']);
        $this->assertSame(4, $index['record_count']);
        $this->assertSame(1, $index['pages_loaded']);
    }

    public function test_a_later_page_without_the_table_marks_the_index_incomplete(): void
    {
        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [
            $this->url(1) => self::PAGE_ONE,
            $this->url(2) => $this->emptyShellFixture(),
        ]);

        $index = $extractor->extractRepositoryIndex('fake-session-token');

        $this->assertFalse($index['complete']);
        $this->assertSame(4, $index['record_count']);
    }

    public function test_an_expired_session_on_a_later_page_is_rethrown(): void
    {
        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [
            $this->url(1) => self::PAGE_ONE,
            $this->url(2) => PortalScrapingException::pageNotAccessible($this->url(2)),
        ]);

        try {
            $extractor->extractRepositoryIndex('fake-session-token');
            $this->fail('An expired session must reach the student.');
        } catch (PortalScrapingException $e) {
            $this->assertTrue(PortalException::indicatesExpiredSession($e));
        }
    }

    public function test_an_empty_page_one_never_replaces_the_index(): void
    {
        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [$this->url(1) => $this->emptyShellFixture()]);

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionCode(422);

        $extractor->extractRepositoryIndex('fake-session-token');
    }

    public function test_the_login_page_is_an_expired_session(): void
    {
        $extractor = $this->makeExtractorWithRawFixtures(ThesisRepositoryExtractor::class, [$this->url(1) => $this->loginFixture()]);

        try {
            $extractor->extractRepositoryIndex('fake-session-token');
            $this->fail('The login form must not be parsed as an empty repository.');
        } catch (PortalScrapingException $e) {
            $this->assertTrue(PortalException::indicatesExpiredSession($e));
        }
    }

    /**
     * @return array{found: bool, records: list<array<string, mixed>>, paging: array<string, mixed>, extracted_at: string}
     */
    private function pageOne(): array
    {
        return $this->extractor(self::PAGE_ONE)->parseRepositoryPage((string) file_get_contents(base_path(self::PAGE_ONE)));
    }

    private function extractor(string $fixture): ThesisRepositoryExtractor
    {
        /** @var ThesisRepositoryExtractor */
        return $this->makeExtractorWithRawFixture(ThesisRepositoryExtractor::class, $fixture);
    }

    private function url(int $page): string
    {
        return PortalEndpointRegistry::url('student_repository', $page > 1 ? ['page' => $page] : []);
    }

    /**
     * Page 1 with its content replaced by an empty container (Quirk #5 shell)
     */
    private function emptyShellFixture(): string
    {
        $html = (string) file_get_contents(base_path(self::PAGE_ONE));
        $start = (int) strpos($html, '        <div class="content">');
        $end = (int) strpos($html, '        <footer class="footer">');

        return $this->temporaryFixture(substr($html, 0, $start).'        <div class="content"><div class="container-fluid"></div></div>'."\n".substr($html, $end));
    }

    /**
     * Page 1 as page `$page`, with a paging line MyCIC cannot read a total
     * from and a Next link to the following page
     */
    private function pageWithoutTotal(int $page): string
    {
        $html = str_replace(
            ['[ 1-100 of 212 ]', 'act=repositori&page=2">Next'],
            ['Menampilkan 1-100 dari 212', 'act=repositori&page='.($page + 1).'">Next'],
            (string) file_get_contents(base_path(self::PAGE_ONE))
        );

        return $this->temporaryFixture($html);
    }

    private function loginFixture(): string
    {
        return $this->temporaryFixture('<!doctype html><html><head><title>Login MyUCIC</title></head><body><form method="post" action="?mod=loginact">'
            .'<input type="text" name="txt_username"><input type="password" name="txt_password"></form></body></html>');
    }

    private function temporaryFixture(string $html): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'repositori-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        return $path;
    }
}
