<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class AnnouncementExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const PAGE_ONE = 'tests/Fixtures/portal/student_announcement_page1.html';

    private const PAGE_TWO = 'tests/Fixtures/portal/student_announcement_page2.html';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_the_three_announcements_of_the_first_page(): void
    {
        $data = $this->extractFixture(self::PAGE_ONE);

        $this->assertCount(3, $data['items']);
        $this->assertSame([
            'Pengisian Her-Registrasi dan KRS Semester Ganjil 2026/2027',
            'Pendaftaran Semester Pendek 2026',
            'Perbaikan Data Mahasiswa di Mycic dan PDDIKTI 2026',
        ], array_column($data['items'], 'title'));

        $first = $data['items'][0];
        $this->assertSame('2026-09-17', $first['published_date']);
        $this->assertSame('2026-09-17T00:00:00+07:00', $first['published_at']);
        $this->assertSame('Kamis, 17 September 2026', $first['published_label']);
        $this->assertSame('Staf BAAK', $first['publisher']);
        $this->assertSame('Biro Administrasi Akademik dan Kemahasiswaan', $first['publisher_unit']);
        $this->assertSame('BAAK', $first['publisher_unit_short']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $first['id']);
        $this->assertSame(['2026-09-17', '2026-07-20', '2026-07-02'], array_column($data['items'], 'published_date'));
        $this->assertSame('Kamis, 02 Juli 2026', $data['items'][2]['published_label']);
    }

    public function test_it_ignores_the_sidebar_navbar_and_footer(): void
    {
        $data = $this->extractFixture(self::PAGE_ONE);
        $encoded = (string) json_encode($data['items']);

        $this->assertStringNotContainsString('SiPANDAI', $encoded);
        $this->assertStringNotContainsString('loginact', $encoded);
        $this->assertStringNotContainsString('Panduan_Her-registrasi', $encoded);
        $this->assertStringNotContainsString('Pusat Data', $encoded);

        foreach ($data['items'] as $item) {
            $this->assertNotContains('2026-10-20', array_column($item['deadlines'], 'date'));
        }
    }

    public function test_it_categorizes_announcements_by_keywords(): void
    {
        $this->assertSame(['krs', 'semester_pendek', 'akademik'], array_column($this->extractFixture(self::PAGE_ONE)['items'], 'category'));
        $this->assertSame(['surat', 'surat', 'surat'], array_column($this->extractFixture(self::PAGE_TWO)['items'], 'category'));
        $this->assertSame('KRS & Her-Registrasi', $this->extractFixture(self::PAGE_ONE)['items'][0]['category_label']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function categoryProvider(): array
    {
        return [
            'payment title' => ['Pembayaran UKT Tahap 2', 'Mohon segera diselesaikan.', 'pembayaran'],
            'scholarship' => ['Pendaftaran Beasiswa Prestasi', 'Berkas dikumpulkan di kampus.', 'pembayaran'],
            'exam schedule' => ['Jadwal UTS Semester Ganjil', 'Silakan cek jadwal.', 'akademik'],
            'short semester abbreviation' => ['Pendaftaran SP Gelombang 2', 'Isi formulir.', 'semester_pendek'],
            'letter' => ['Legalisir Ijazah', 'Datang ke BAAK.', 'surat'],
            'falls back to the body' => ['Informasi Penting', 'Pengisian KRS dibuka minggu depan.', 'krs'],
            'general' => ['Libur Hari Raya', 'Kampus tutup selama libur.', 'umum'],
        ];
    }

    #[DataProvider('categoryProvider')]
    public function test_it_categorizes_custom_announcements(string $title, string $body, string $expected): void
    {
        $item = $this->extractHtml($this->pageWithRows($this->row($title, '<p>'.$body.'</p>')))['items'][0];

        $this->assertSame($expected, $item['category']);
    }

    public function test_nested_tables_do_not_add_items(): void
    {
        $data = $this->extractFixture(self::PAGE_TWO);

        $this->assertCount(3, $data['items']);
        $this->assertSame('Surat Pengantar Observasi atau Magang', $data['items'][0]['title']);
        $this->assertStringContainsString('<table><tr><td>', $data['items'][0]['body_html']);
        $this->assertStringContainsString('Perubahan Format Surat Keterangan Masih Kuliah', $data['items'][0]['body_text']);
        $this->assertSame('Format Surat Keterangan Masih Kuliah', $data['items'][2]['title']);
    }

    public function test_announcements_with_the_same_title_and_day_keep_distinct_ids(): void
    {
        $items = $this->extractFixture(self::PAGE_TWO)['items'];

        $this->assertSame($items[0]['title'], $items[1]['title']);
        $this->assertSame($items[0]['published_date'], $items[1]['published_date']);
        $this->assertNotSame($items[0]['id'], $items[1]['id']);
        $this->assertSame($items[0]['id'], $this->extractFixture(self::PAGE_TWO)['items'][0]['id']);
    }

    public function test_it_decodes_cloudflare_emails_in_the_body(): void
    {
        $item = $this->extractFixture(self::PAGE_TWO)['items'][0];

        $this->assertSame(['baak@example.test'], $item['emails']);
        $this->assertStringContainsString('ke email baak@example.test jika sudah', $item['body_text']);
        $this->assertStringContainsString('<a href="mailto:baak@example.test">baak@example.test</a>', $item['body_html']);
        $this->assertStringNotContainsString('email protected', $item['body_text']);

        foreach (['cdn-cgi', '__cf_email__', 'data-cfemail', 'email protected'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $item['body_html']);
        }
    }

    public function test_it_linkifies_plain_urls_and_lists_them(): void
    {
        $items = $this->extractFixture(self::PAGE_ONE)['items'];

        $this->assertSame([[
            'label' => 'Formulir Google',
            'url' => 'https://forms.gle/ContohFormulirSP01',
            'is_file' => false,
            'is_campus_file' => false,
            'kind' => 'form',
        ]], $items[1]['links']);
        $this->assertStringContainsString(
            '<a href="https://forms.gle/ContohFormulirSP01" target="_blank" rel="noopener noreferrer">https://forms.gle/ContohFormulirSP01</a>',
            $items[1]['body_html']
        );
        $this->assertSame('https://forms.gle/ContohFormulirPDM02', $items[2]['links'][0]['url']);
        $this->assertSame([], $items[0]['links']);
    }

    public function test_it_builds_clean_plain_text_and_steps(): void
    {
        $item = $this->extractFixture(self::PAGE_ONE)['items'][0];
        $lines = explode("\n", $item['body_text']);

        $this->assertSame('Selamat Sore Mahasiswa dan Mahasiswi', $lines[0]);
        $this->assertStringContainsString('Periode Her-Registrasi dan KRS 17 s/d 19 September 2026, ada beberapa tahapan', $lines[1]);
        $this->assertSame('5. Mahasiswa melakukan Her-registrasi (perbaikan data diri > memilih status mahasiswa aktif/cuti)', $lines[6]);
        $this->assertSame('Terima kasih atas perhatiannya🙏', end($lines));
        $this->assertStringNotContainsString("\u{00A0}", $item['body_text']);
        $this->assertStringNotContainsString('  ', $item['body_text']);
        $this->assertStringNotContainsString('&nbsp;', $item['body_text']);
        $this->assertStringStartsWith('Selamat Sore Mahasiswa dan Mahasiswi Berikut BAAK', $item['excerpt']);

        $this->assertCount(8, $item['steps']);
        $this->assertSame(['number' => 1, 'text' => 'Mahasiswa sudah melunasi UKT tahap ke 1 tahun berjalan dan mendapatkan kwitansi dari Keuangan'], $item['steps'][0]);
        $this->assertSame(8, $item['steps'][7]['number']);
        $this->assertSame([], $this->extractFixture(self::PAGE_ONE)['items'][1]['steps']);
    }

    public function test_it_extracts_deadlines_from_partial_ranges_and_numeric_dates(): void
    {
        $items = $this->extractFixture(self::PAGE_ONE)['items'];

        $this->assertSame([[
            'date' => '2026-09-19',
            'start' => '2026-09-17',
            'type' => 'period',
            'context' => 'Berikut BAAK sampaikan Mata Kuliah Tawar Semester Ganjil 2026-2027 Periode Her-Registrasi dan KRS 17 s/d 19 September 2026, ada beberapa tahapan sebelum mengisi KRS sebagai Berikut :',
            'is_future' => false,
        ]], $items[0]['deadlines']);

        $this->assertSame([
            ['2026-07-20', '2026-08-16', 'period'],
            [null, '2026-08-19', 'date'],
            ['2026-08-20', '2026-08-29', 'period'],
        ], array_map(static fn (array $deadline): array => [$deadline['start'], $deadline['date'], $deadline['type']], $items[1]['deadlines']));

        $this->assertSame([['2026-07-02', '2026-07-18']], array_map(
            static fn (array $deadline): array => [$deadline['start'], $deadline['date']],
            $items[2]['deadlines']
        ));
    }

    public function test_deadline_is_future_follows_the_current_day_in_jakarta(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-19 23:30:00', 'Asia/Jakarta'));
        $this->assertTrue($this->extractFixture(self::PAGE_ONE)['items'][0]['deadlines'][0]['is_future']);

        $this->travelTo(CarbonImmutable::parse('2026-09-20 00:05:00', 'Asia/Jakarta'));
        $this->assertFalse($this->extractFixture(self::PAGE_ONE)['items'][0]['deadlines'][0]['is_future']);
    }

    public function test_it_extracts_cross_month_and_cross_year_ranges_and_explicit_deadlines(): void
    {
        $body = '<p>Pengisian KRS 28&nbsp;s/d 3 Februari 2027<br> Libur 20 Desember&nbsp; - 5 Januari 2027<br>'
            .' Pembayaran paling lambat Jumat, 30 Oktober 2026<br> Rapat 2 November 2026</p>';
        $item = $this->extractHtml($this->pageWithRows($this->row('Info Penting', $body)))['items'][0];

        $this->assertSame([
            [null, '2026-10-30', 'deadline', true],
            [null, '2026-11-02', 'date', true],
            ['2026-12-20', '2027-01-05', 'period', true],
            ['2027-01-28', '2027-02-03', 'period', true],
        ], array_map(
            static fn (array $deadline): array => [$deadline['start'], $deadline['date'], $deadline['type'], $deadline['is_future']],
            $item['deadlines']
        ));
    }

    public function test_it_reads_the_expired_payment_notice(): void
    {
        $notice = $this->extractFixture(self::PAGE_ONE)['notice'];

        $this->assertNotNull($notice);
        $this->assertSame('payment', $notice['kind']);
        $this->assertSame(['2024-06-24'], $notice['dates']);
        $this->assertFalse($notice['is_future']);
        $this->assertStringStartsWith('Kewajiban Pembayaran Kuliah Tahap ke 4 Tahun Berjalan', $notice['text']);
    }

    public function test_it_parses_paging_and_rebuilds_the_page_links(): void
    {
        $first = $this->extractFixture(self::PAGE_ONE)['paging'];
        $second = $this->extractFixture(self::PAGE_TWO, 2)['paging'];

        $this->assertSame([
            'page' => 1,
            'per_page' => 3,
            'from' => 1,
            'to' => 3,
            'total' => 84,
            'last_page' => 28,
            'has_previous' => false,
            'has_next' => true,
            'previous_page' => null,
            'next_page' => 2,
            'previous_url' => null,
            'next_url' => PortalEndpointRegistry::url('student_announcement', ['page' => 2]),
            'out_of_range' => false,
        ], $first);

        $this->assertSame(4, $second['from']);
        $this->assertSame(6, $second['to']);
        $this->assertSame(1, $second['previous_page']);
        $this->assertSame(3, $second['next_page']);
        $this->assertSame(PortalEndpointRegistry::url('student_announcement'), $second['previous_url']);
        $this->assertStringContainsString('act=pengumuman&page=3', (string) $second['next_url']);
        $this->assertStringNotContainsString('act=dashboard', (string) json_encode([$first, $second]));
    }

    /**
     * @return array<string, array{int, int, int, int, int|null, int|null, bool}>
     */
    public static function pagingProvider(): array
    {
        return [
            'one full page' => [3, 1, 3, 1, 1, 3, false],
            'one item past a full page' => [4, 1, 3, 2, 1, 3, true],
            'last page with one item' => [7, 3, 1, 3, 7, 7, false],
            'last page with two items' => [8, 3, 2, 3, 7, 8, false],
            'middle page' => [84, 14, 3, 28, 40, 42, true],
        ];
    }

    #[DataProvider('pagingProvider')]
    public function test_paging_math(int $total, int $page, int $rows, int $lastPage, ?int $from, ?int $to, bool $hasNext): void
    {
        $html = $this->pageWithRows(
            implode('', array_map(fn (int $index): string => $this->row('Pengumuman '.$index, '<p>Isi '.$index.'</p>'), range(1, $rows))),
            sprintf('[ %d-%d of %d ]', ($page - 1) * 3 + 1, ($page - 1) * 3 + 1, $total)
        );
        $paging = $this->extractHtml($html, $page)['paging'];

        $this->assertSame($lastPage, $paging['last_page']);
        $this->assertSame($from, $paging['from']);
        $this->assertSame($to, $paging['to']);
        $this->assertSame($hasNext, $paging['has_next']);
        $this->assertSame($total, $paging['total']);
    }

    public function test_a_page_past_the_last_one_is_empty(): void
    {
        $html = $this->pageWithRows($this->row('Pengumuman Ulang', '<p>Isi</p>'), '[ 88-88 of 84 ]');
        $data = $this->extractHtml($html, 30);

        $this->assertSame([], $data['items']);
        $this->assertTrue($data['paging']['out_of_range']);
        $this->assertNull($data['paging']['from']);
        $this->assertFalse($data['paging']['has_next']);
        $this->assertSame(29, $data['paging']['previous_page']);
    }

    public function test_an_empty_announcement_list(): void
    {
        $data = $this->extractHtml($this->pageWithRows('', '[ 0-0 of 0 ]'));

        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['paging']['total']);
        $this->assertSame(1, $data['paging']['last_page']);
        $this->assertNull($data['paging']['from']);
        $this->assertFalse($data['paging']['has_next']);
    }

    public function test_missing_paging_is_reported_as_unknown(): void
    {
        $html = (string) preg_replace('~<div class="paging">.*?</div>~s', '', $this->pageWithRows($this->row('Satu', '<p>Isi</p>')));
        $paging = $this->extractHtml($html)['paging'];

        $this->assertNull($paging['total']);
        $this->assertNull($paging['last_page']);
        $this->assertFalse($paging['has_next']);
        $this->assertSame(1, $paging['from']);
    }

    public function test_it_sanitizes_the_body_html(): void
    {
        $body = '<script>alert(1)</script><style>p{color:red}</style>'
            .'<p onclick="steal()" style="color:red" class="lead">Baca <a href="javascript:alert(1)">ini</a> dan '
            .'<a href="../files/Panduan_KRS.pdf" onmouseover="x()">Panduan KRS</a> atau <a href="?mod=student&amp;act=jobdel&amp;id=90001">hapus</a></p>'
            .'<h1>Judul Besar</h1><div>Baris biasa</div><img src="../files/poster.png" alt="Poster">'
            .'<iframe src="https://example.test/embed"></iframe><form action="?mod=student&amp;act=addskkm"><input name="x"></form>'
            .'<table><tbody><tr><td colspan="2" width="50%">Sel</td></tr></tbody></table><p>&nbsp;</p>';
        $item = $this->extractHtml($this->pageWithRows($this->row('Panduan', $body)))['items'][0];
        $html = $item['body_html'];
        $baseUrl = rtrim((string) config('portal.base_url'), '/');

        foreach (['<script', 'alert', '<style', 'onclick', 'onmouseover', 'style=', 'class=', 'javascript:', '<img', '<iframe', '<form', '<input', 'jobdel', 'width=', '<h1', '<div', '<tbody'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        $this->assertStringContainsString('<a href="'.$baseUrl.'/portal/files/Panduan_KRS.pdf" target="_blank" rel="noopener noreferrer">Panduan KRS</a>', $html);
        $this->assertStringContainsString('Baca ini dan', $html);
        $this->assertStringContainsString('atau hapus</p>', $html);
        $this->assertStringContainsString('<p><strong>Judul Besar</strong></p><p>Baris biasa</p>', $html);
        $this->assertStringContainsString('<table><tr><td colspan="2">Sel</td></tr></table>', $html);
        $this->assertStringEndsWith('</table>', $html);

        $this->assertSame([
            [
                'label' => 'Panduan KRS',
                'url' => $baseUrl.'/portal/files/Panduan_KRS.pdf',
                'is_file' => true,
                'is_campus_file' => true,
                'kind' => 'file',
            ],
            [
                'label' => 'Poster',
                'url' => $baseUrl.'/portal/files/poster.png',
                'is_file' => true,
                'is_campus_file' => false,
                'kind' => 'file',
            ],
        ], $item['links']);
        $this->assertStringNotContainsString('alert', $item['body_text']);
        $this->assertStringContainsString('Judul Besar', $item['body_text']);
    }

    public function test_it_linkifies_emails_and_trims_url_punctuation(): void
    {
        $body = '<p>Kirim ke dosen@example.test atau buka www.example.test/info. Lihat (https://example.test/jadwal).</p>';
        $item = $this->extractHtml($this->pageWithRows($this->row('Kontak', $body)))['items'][0];

        $this->assertSame(['dosen@example.test'], $item['emails']);
        $this->assertSame(['https://www.example.test/info', 'https://example.test/jadwal'], array_column($item['links'], 'url'));
        $this->assertSame(['example.test', 'example.test'], array_column($item['links'], 'label'));
        $this->assertStringContainsString('<a href="mailto:dosen@example.test">dosen@example.test</a>', $item['body_html']);
        $this->assertStringContainsString('>www.example.test/info</a>. Lihat (<a href="https://example.test/jadwal"', $item['body_html']);
        $this->assertStringContainsString('>https://example.test/jadwal</a>).</p>', $item['body_html']);
    }

    public function test_the_login_page_is_rejected(): void
    {
        $extractor = $this->extractorForPages([1 => '<!doctype html><html><head><title>Login</title></head><body><form method="post">'
            .'<input type="text" name="txt_username"><input type="password" name="txt_password"></form></body></html>']);

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionCode(403);

        $extractor->extractAnnouncementsData('fake-session-token');
    }

    public function test_an_unrecognized_page_is_rejected(): void
    {
        $shell = (string) preg_replace('~<div class="content">.*</div>\s*<footer~s', '<div class="content"></div><footer', $this->fixture(self::PAGE_ONE));
        $extractor = $this->extractorForPages([1 => $shell]);

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionMessage('announcement list not found');

        $extractor->extractAnnouncementsData('fake-session-token');
    }

    public function test_each_page_is_cached_separately(): void
    {
        $extractor = $this->extractorForPages([1 => $this->fixture(self::PAGE_ONE), 2 => $this->fixture(self::PAGE_TWO)], once: true);

        $first = $extractor->extractAnnouncementsData('fake-session-token');
        $cached = $extractor->extractAnnouncementsData('fake-session-token');
        $second = $extractor->extractAnnouncementsData('fake-session-token', 2);

        $this->assertSame($first['items'], $cached['items']);
        $this->assertSame('server_cache', $cached['_sync']['data_origin']);
        $this->assertSame('Surat Pengantar Observasi atau Magang', $second['items'][0]['title']);
        $this->assertSame(2, $second['paging']['page']);
    }

    public function test_a_page_below_one_is_treated_as_the_first_page(): void
    {
        $data = $this->makeExtractorWithFixture(AnnouncementExtractor::class, self::PAGE_ONE)
            ->extractAnnouncementsData('fake-session-token', 0);

        $this->assertSame(1, $data['paging']['page']);
        $this->assertSame(1, $data['paging']['from']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFixture(string $fixture, int $page = 1): array
    {
        /** @var AnnouncementExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(AnnouncementExtractor::class, $fixture);

        return $extractor->extractAnnouncementsData('fake-session-token-'.md5($fixture.microtime()), $page);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractHtml(string $html, int $page = 1): array
    {
        return $this->extractorForPages([$page => $html])->extractAnnouncementsData('fake-session-token-'.md5($html.microtime()), $page);
    }

    /**
     * @param  array<int, string>  $htmlByPage
     */
    private function extractorForPages(array $htmlByPage, bool $once = false): AnnouncementExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(AnnouncementExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        foreach ($htmlByPage as $page => $html) {
            $expectation = $extractor->shouldReceive('get')
                ->with(PortalEndpointRegistry::url('student_announcement', $page > 1 ? ['page' => $page] : []))
                ->andReturn($html);

            if ($once) {
                $expectation->once();
            }
        }

        return $extractor;
    }

    private function fixture(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    /**
     * The first fixture page with its announcement rows (and optionally its paging label) replaced
     */
    private function pageWithRows(string $rows, ?string $pagingLabel = null): string
    {
        $html = (string) preg_replace('~<tbody><tr>.*?</tbody></table>~s', '<tbody>'.$rows.'</tbody></table>', $this->fixture(self::PAGE_ONE), 1);

        return $pagingLabel === null ? $html : str_replace('[ 1-1 of 84 ]', $pagingLabel, $html);
    }

    private function row(string $title, string $body, string $meta = 'Senin, 05 Oktober 2026, Dipublish oleh : Staf BAAK (Biro Administrasi Akademik dan Kemahasiswaan)'): string
    {
        return "<tr>\n<td align=\"justify\">\n<b><h5>{$title}</h5></b> {$meta}<br>\n<hr>\n{$body}\n</td>\n</tr>\n";
    }
}
