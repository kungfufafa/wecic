<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalEndpointRegistry;
use Carbon\CarbonImmutable;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The announcement pages exactly as the live portal sends them (K14 sanitized
 * copies), parsed with DomCrawler's HTML5 parser like a live response. The
 * list table has no tbody, and announcement bodies keep the editor's markup
 * (CRLF line breaks, HTML comments, &nbsp;, h1/h3 headings, a nested table,
 * Cloudflare-protected e-mails).
 */
class AnnouncementExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const PAGE_ONE = 'tests/Fixtures/portal/student_announcement_page1_raw.html';

    private const PAGE_TWO = 'tests/Fixtures/portal/student_announcement_page2_raw.html';

    private const NORMALIZED_PAGE_ONE = 'tests/Fixtures/portal/student_announcement_page1.html';

    private const NORMALIZED_PAGE_TWO = 'tests/Fixtures/portal/student_announcement_page2.html';

    private const UNIT = 'Biro Administrasi Akademik dan Kemahasiswaan';

    private const LAST_LINE_OF_FIRST_BODY = "Terima kasih atas perhatiannya\u{1F64F}</p>";

    private const LAST_LINE_OF_SECOND_BODY = 'https://forms.gle/ContohFormulirSP01&nbsp;</p>';

    /**
     * Tags the sanitized body may contain.
     *
     * @var list<string>
     */
    private const SANITIZED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'ul', 'ol', 'li', 'a', 'table', 'tr', 'td', 'th'];

    /**
     * @var list<string>
     */
    private array $temporaryFixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFixtures as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_each_raw_page_is_requested_once_from_the_announcement_url(): void
    {
        /** @var AnnouncementExtractor $first */
        $first = $this->makeExtractorWithRawFixture(AnnouncementExtractor::class, self::PAGE_ONE, $firstUrls);
        $first->extractAnnouncementsData('raw-session-page-one');

        /** @var AnnouncementExtractor $second */
        $second = $this->makeExtractorWithRawFixture(AnnouncementExtractor::class, self::PAGE_TWO, $secondUrls);
        $second->extractAnnouncementsData('raw-session-page-two', 2);

        $this->assertSame([PortalEndpointRegistry::url('student_announcement')], $firstUrls);
        $this->assertSame([PortalEndpointRegistry::url('student_announcement', ['page' => 2])], $secondUrls);
    }

    public function test_every_announcement_of_the_raw_first_page_is_an_item(): void
    {
        $items = $this->extractRaw(self::PAGE_ONE)['items'];

        $this->assertSame([
            ['Pengisian Her-Registrasi dan KRS Semester Ganjil 2026/2027', '2026-09-17T00:00:00+07:00', 'Kamis, 17 September 2026', 'krs'],
            ['Pendaftaran Semester Pendek 2026', '2026-07-20T00:00:00+07:00', 'Senin, 20 Juli 2026', 'semester_pendek'],
            ['Perbaikan Data Mahasiswa di Mycic dan PDDIKTI 2026', '2026-07-02T00:00:00+07:00', 'Kamis, 02 Juli 2026', 'akademik'],
        ], array_map(
            static fn (array $item): array => [$item['title'], $item['published_at'], $item['published_label'], $item['category']],
            $items
        ));

        foreach ($items as $item) {
            $this->assertSame('Staf BAAK', $item['publisher']);
            $this->assertSame(self::UNIT, $item['publisher_unit']);
            $this->assertSame('BAAK', $item['publisher_unit_short']);
            $this->assertNotSame('', $item['body_text']);
            $this->assertNotSame('', $item['body_html']);
        }

        $this->assertCount(3, array_unique(array_column($items, 'id')));
    }

    public function test_raw_first_page_bodies_keep_their_lines_steps_and_links(): void
    {
        $items = $this->extractRaw(self::PAGE_ONE)['items'];
        $lines = explode("\n", $items[0]['body_text']);

        $this->assertCount(11, $lines);
        $this->assertSame('Selamat Sore Mahasiswa dan Mahasiswi UCIC', $lines[0]);
        $this->assertSame('Terima kasih atas perhatiannya🙏', $lines[10]);
        $this->assertCount(8, $items[0]['steps']);
        $this->assertSame(
            ['number' => 1, 'text' => 'Mahasiswa sudah melunasi UKT tahap ke 1 tahun berjalan dan Mendapatkan Kwitansi UCIC dari Keuangan'],
            $items[0]['steps'][0]
        );
        $this->assertSame(range(1, 8), array_column($items[0]['steps'], 'number'));
        $this->assertSame([], $items[0]['links']);

        $this->assertStringStartsWith('Semester Pendek atau Semester Antara bertujuan untuk memperbaiki nilai mata kuliah yang kurang baik', $items[1]['body_text']);
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
        $this->assertSame(['https://forms.gle/ContohFormulirPDM02'], array_column($items[2]['links'], 'url'));

        foreach ($items as $item) {
            $this->assertSame([], $item['emails']);
            $this->assertStringNotContainsString("\r", $item['body_text']);
            $this->assertStringNotContainsString("\u{00A0}", $item['body_text']);
            $this->assertStringNotContainsString('  ', $item['body_text']);
            $this->assertStringNotContainsString('TgQPHd', $item['body_text'].$item['body_html']);
        }
    }

    public function test_raw_first_page_dates_become_deadlines(): void
    {
        $items = $this->extractRaw(self::PAGE_ONE)['items'];

        $this->assertSame([
            [['2026-09-17', '2026-09-19', 'period', false]],
            [['2026-07-20', '2026-08-16', 'period', false], [null, '2026-08-19', 'date', false], ['2026-08-20', '2026-08-29', 'period', false]],
            [['2026-07-02', '2026-07-18', 'period', false]],
        ], array_map(
            static fn (array $item): array => array_map(
                static fn (array $deadline): array => [$deadline['start'], $deadline['date'], $deadline['type'], $deadline['is_future']],
                $item['deadlines']
            ),
            $items
        ));
    }

    public function test_every_announcement_of_the_raw_second_page_is_an_item(): void
    {
        $items = $this->extractRaw(self::PAGE_TWO, 2)['items'];

        $this->assertSame([
            'Surat Pengantar Observasi atau Magang',
            'Surat Pengantar Observasi atau Magang',
            'Format Surat Keterangan Masih Kuliah',
        ], array_column($items, 'title'));
        $this->assertSame(['2026-04-13', '2026-04-13', '2026-04-13'], array_column($items, 'published_date'));
        $this->assertSame(['surat', 'surat', 'surat'], array_column($items, 'category'));
        $this->assertNotSame($items[0]['id'], $items[1]['id']);

        foreach ($items as $item) {
            $this->assertSame('Senin, 13 April 2026', $item['published_label']);
            $this->assertSame('Staf BAAK', $item['publisher']);
            $this->assertSame(self::UNIT, $item['publisher_unit']);
            $this->assertSame(['dosen@example.test'], $item['emails']);
            $this->assertStringContainsString('ke email dosen@example.test jika sudah mendapatkan balasan email', $item['body_text']);
            $this->assertStringContainsString('<a href="mailto:dosen@example.test">dosen@example.test</a>', $item['body_html']);
            $this->assertSame([], $item['links']);
            $this->assertSame([], $item['deadlines']);
            $this->assertSame([], $item['steps']);
        }
    }

    public function test_the_raw_nested_table_stays_inside_its_announcement(): void
    {
        $first = $this->extractRaw(self::PAGE_TWO, 2)['items'][0];

        $this->assertStringStartsWith('<table><tr><td><p><strong>Surat Pengantar Observasi atau Magang</strong></p>', $first['body_html']);
        $this->assertStringContainsString('</td></tr><tr><td><p><strong>Perubahan Format Surat Keterangan Masih Kuliah</strong></p>', $first['body_html']);
        $this->assertStringContainsString('<p><strong>Format Surat Pengantar Observasi atau Magang</strong></p><p>NIM :</p>', $first['body_html']);
        $this->assertStringEndsWith('kalian bisa mengambilnya di BAAK.</p></td></tr></table>', $first['body_html']);
        $this->assertSame('Surat Pengantar Observasi atau Magang', explode("\n", $first['body_text'])[0]);
        $this->assertStringEndsWith('kalian bisa mengambilnya di BAAK.', $first['body_text']);
    }

    public function test_raw_paging_reads_the_total_and_rebuilds_the_page_links(): void
    {
        $first = $this->extractRaw(self::PAGE_ONE)['paging'];
        $second = $this->extractRaw(self::PAGE_TWO, 2)['paging'];

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

        $this->assertSame([
            'page' => 2,
            'per_page' => 3,
            'from' => 4,
            'to' => 6,
            'total' => 84,
            'last_page' => 28,
            'has_previous' => true,
            'has_next' => true,
            'previous_page' => 1,
            'next_page' => 3,
            'previous_url' => PortalEndpointRegistry::url('student_announcement'),
            'next_url' => PortalEndpointRegistry::url('student_announcement', ['page' => 3]),
            'out_of_range' => false,
        ], $second);
    }

    public function test_the_raw_marquee_payment_notice_is_read(): void
    {
        foreach ([[self::PAGE_ONE, 1], [self::PAGE_TWO, 2]] as [$fixture, $page]) {
            $notice = $this->extractRaw($fixture, $page)['notice'];

            $this->assertNotNull($notice);
            $this->assertSame('payment', $notice['kind']);
            $this->assertSame(['2024-06-24'], $notice['dates']);
            $this->assertFalse($notice['is_future']);
            $this->assertStringStartsWith('Kewajiban Pembayaran Kuliah Tahap ke 4 Tahun Berjalan', $notice['text']);
        }
    }

    public function test_raw_bodies_are_sanitized_and_leave_out_the_page_chrome(): void
    {
        $items = array_merge($this->extractRaw(self::PAGE_ONE)['items'], $this->extractRaw(self::PAGE_TWO, 2)['items']);

        $this->assertCount(6, $items);

        foreach ($items as $item) {
            $html = $item['body_html'];

            preg_match_all('~<\s*/?\s*([a-z0-9]+)~i', $html, $tags);
            $this->assertSame([], array_values(array_diff(array_unique(array_map('strtolower', $tags[1])), self::SANITIZED_TAGS)));
            preg_match_all('~\s([a-z\-]+)=~i', $html, $attributes);
            $this->assertSame([], array_values(array_diff(array_unique($attributes[1]), ['href', 'target', 'rel'])));
            preg_match_all('~href="([^"]*)"~', $html, $hrefs);

            foreach ($hrefs[1] as $href) {
                $this->assertMatchesRegularExpression('~^(?:https://|mailto:)~', $href);
            }

            foreach (['<!--', 'cdn-cgi', '__cf_email__', 'data-cfemail', 'email protected', '&nbsp;', 'SiPANDAI', 'loginact', 'Updated 3 minutes ago', 'Previous'] as $leftover) {
                $this->assertStringNotContainsString($leftover, $html.$item['body_text']);
            }
        }
    }

    public function test_the_raw_pages_read_like_their_browser_normalized_copies(): void
    {
        foreach ([[self::PAGE_ONE, self::NORMALIZED_PAGE_ONE, 1], [self::PAGE_TWO, self::NORMALIZED_PAGE_TWO, 2]] as [$raw, $normalized, $page]) {
            $rawData = $this->extractRaw($raw, $page);
            $normalizedData = $this->makeExtractorWithFixture(AnnouncementExtractor::class, $normalized)
                ->extractAnnouncementsData('normalized-session-'.$page, $page);

            $this->assertSame($normalizedData['paging'], $rawData['paging']);
            $this->assertSame($normalizedData['notice'], $rawData['notice']);
            $this->assertSame(
                array_map($this->structureOf(...), $normalizedData['items']),
                array_map($this->structureOf(...), $rawData['items'])
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenListProvider(): array
    {
        return [
            'stray </div> in a body closes the list container' => [self::LAST_LINE_OF_FIRST_BODY, self::LAST_LINE_OF_FIRST_BODY.'</div>'],
            'stray </table> in a body closes the list table' => [self::LAST_LINE_OF_FIRST_BODY, self::LAST_LINE_OF_FIRST_BODY.'</table>'],
            'rows wrapped in a form' => ['<table class="table table-hover table-striped">', '<table class="table table-hover table-striped"><form method="post" action="?mod=student&act=pengumuman">'],
            'cells wrapped in b' => ["<tr>\n\t\t\t\t\t\t\t\t\t<td align=\"justify\">", "<tr><b>\n\t\t\t\t\t\t\t\t\t<td align=\"justify\">"],
        ];
    }

    #[DataProvider('brokenListProvider')]
    public function test_no_raw_announcement_is_dropped_or_merged_when_the_list_markup_breaks(string $search, string $replace): void
    {
        $original = $this->extractRaw(self::PAGE_ONE);
        $html = str_replace($search, $replace, $this->fixtureHtml(self::PAGE_ONE));

        $this->assertNotSame($this->fixtureHtml(self::PAGE_ONE), $html);

        $broken = $this->extractRawHtml($html);

        $this->assertSame(array_column($original['items'], 'id'), array_column($broken['items'], 'id'));
        $this->assertSame(array_column($original['items'], 'body_html'), array_column($broken['items'], 'body_html'));
        $this->assertSame($original['paging'], $broken['paging']);
    }

    public function test_a_body_table_after_a_stray_list_table_end_stays_in_its_announcement(): void
    {
        $html = str_replace(
            self::LAST_LINE_OF_SECOND_BODY,
            self::LAST_LINE_OF_SECOND_BODY.'<table><tr><td>Jadwal SP</td><td>20 Agustus 2026</td></tr></table>',
            $this->fixtureHtml(self::PAGE_ONE)
        );
        $intact = $this->extractRawHtml($html);
        $broken = $this->extractRawHtml(str_replace(self::LAST_LINE_OF_FIRST_BODY, self::LAST_LINE_OF_FIRST_BODY.'</table>', $html));

        $this->assertCount(count($this->extractRaw(self::PAGE_ONE)['items']), $intact['items']);
        $this->assertStringContainsString('<td>Jadwal SP</td>', $intact['items'][1]['body_html']);
        $this->assertSame(array_column($intact['items'], 'id'), array_column($broken['items'], 'id'));
        $this->assertSame(array_column($intact['items'], 'body_html'), array_column($broken['items'], 'body_html'));
        $this->assertSame($intact['paging'], $broken['paging']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRaw(string $fixture, int $page = 1): array
    {
        /** @var AnnouncementExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(AnnouncementExtractor::class, $fixture);

        return $extractor->extractAnnouncementsData('raw-session-'.md5($fixture.$page.microtime()), $page);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawHtml(string $html): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'announcement-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        return $this->extractRaw($path);
    }

    private function fixtureHtml(string $fixture): string
    {
        return (string) file_get_contents(base_path($fixture));
    }

    /**
     * What both copies must agree on; the wording and synthetic values of the
     * two sanitized copies differ
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function structureOf(array $item): array
    {
        return [
            'title' => $item['title'],
            'published_at' => $item['published_at'],
            'published_label' => $item['published_label'],
            'publisher_unit' => $item['publisher_unit'],
            'category' => $item['category'],
            'links' => array_column($item['links'], 'kind'),
            'emails' => count($item['emails']),
            'deadlines' => array_map(static fn (array $deadline): array => [$deadline['start'], $deadline['date'], $deadline['type']], $item['deadlines']),
            'steps' => array_column($item['steps'], 'number'),
            'body_lines' => substr_count($item['body_text'], "\n") + 1,
            'body_tags' => preg_replace('~>[^<]*<~', '><', (string) preg_replace('~^[^<]*|[^>]*$~', '', (string) preg_replace('~<a [^>]*>~', '<a>', $item['body_html']))),
        ];
    }
}
