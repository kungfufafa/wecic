<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

/**
 * Live portal pages reach the extractors as raw server HTML, which
 * DomCrawler parses with PHP's HTML5 parser: like a browser, it adds tbody
 * and moves stray tags out of tables. Every case runs on a page with a
 * doctype and on one without (quirks mode) and, where it differs, on the
 * browser-normalized markup the old fixtures were saved as.
 */
class PortalScrapingServiceTableHelpersTest extends TestCase
{
    private const PERWALIAN_HISTORY_RAW = '<table class="table table-hover table-striped"> <tr><b> <td><b>T.A</b></td> <td><b>Tanggal</b></td> <td><b>Semester</b></td> <td><b>Aksi</b></td> </b> </tr>'
        .' <tr> <td valign="top"> 2026/2027 </td> <td valign="top"> Senin, 01 September 2026 </td> <td valign="top"> 1 </td>'
        .' <td valign="top"><a href="?mod=student&act=viewkrs&id=90001"><button type="button"><font>Lihat KRS</font></button></a></td> </tr>'
        .' <tr class=altrow> <td valign=top> 2025/2026 </td> <td valign=top> Senin,&nbsp;02 Februari 2026 </td> <td valign=top> T </td>'
        .' <td valign=top><a href="?mod=student&act=viewkrs&id=90002"><button type=button><font>Lihat KRS</font></button></a></td> </tr>'
        .' </table>';

    private const PERWALIAN_HISTORY_NORMALIZED = '<b> </b><table class="table table-hover table-striped"><tbody><tr> <td><b>T.A</b></td> <td><b>Tanggal</b></td> <td><b>Semester</b></td> <td><b>Aksi</b></td>  </tr>'
        .' <tr> <td valign="top"> 2026/2027 </td> <td valign="top"> Senin, 01 September 2026 </td> <td valign="top"> 1 </td>'
        .' <td valign="top"><a href="?mod=student&amp;act=viewkrs&amp;id=90001"><button type="button"><font>Lihat KRS</font></button></a></td> </tr>'
        .' <tr class="altrow"> <td valign="top"> 2025/2026 </td> <td valign="top"> Senin,&nbsp;02 Februari 2026 </td> <td valign="top"> T </td>'
        .' <td valign="top"><a href="?mod=student&amp;act=viewkrs&amp;id=90002"><button type="button"><font>Lihat KRS</font></button></a></td> </tr>'
        .' </tbody></table>';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parserProvider(): array
    {
        return [
            'raw page with doctype' => ['html5'],
            'page without doctype (quirks mode)' => ['quirks'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function perwalianHistoryProvider(): array
    {
        return [
            'raw markup, with doctype' => ['html5', self::PERWALIAN_HISTORY_RAW],
            'raw markup, quirks mode' => ['quirks', self::PERWALIAN_HISTORY_RAW],
            'browser-normalized markup, with doctype' => ['html5', self::PERWALIAN_HISTORY_NORMALIZED],
            'browser-normalized markup, quirks mode' => ['quirks', self::PERWALIAN_HISTORY_NORMALIZED],
        ];
    }

    #[DataProvider('perwalianHistoryProvider')]
    public function test_header_cells_wrapped_in_bold_inside_a_row_without_tbody_are_found(string $parser, string $markup): void
    {
        $service = $this->service();
        $table = $this->page($parser, $markup)->filter('table');

        $rows = $service->call('tableRows', $table);

        $this->assertCount(3, $rows);
        $this->assertSame(['T.A', 'Tanggal', 'Semester', 'Aksi'], $this->rowTexts($service, $rows[0]));
        $this->assertSame(['2026/2027', 'Senin, 01 September 2026', '1', 'Lihat KRS'], $this->rowTexts($service, $rows[1]));
        $this->assertSame(['2025/2026', 'Senin, 02 Februari 2026', 'T', 'Lihat KRS'], $this->rowTexts($service, $rows[2]));
        $this->assertSame('altrow', $rows[2]->attr('class'));
        $this->assertSame(1, $rows[1]->filter('a[href*="viewkrs"]')->count());
    }

    public function test_the_parser_moves_the_raw_bold_wrapper_out_of_the_table(): void
    {
        $service = $this->service();
        $page = $this->page('html5', self::PERWALIAN_HISTORY_RAW);
        $header = $service->call('tableRows', $page->filter('table'))[0];

        $this->assertSame(4, $header->children('td, th')->count());
        $this->assertSame('tr', $service->call('rowCells', $header)[0]->getNode(0)->parentNode->nodeName);
        $this->assertCount(4, $service->call('rowCells', $header));
        $this->assertSame('b', $page->filter('body > *')->first()->nodeName());
    }

    #[DataProvider('parserProvider')]
    public function test_rows_are_found_with_and_without_table_sections(string $parser): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<table id="bare"><tr><td>a1</td></tr><tr><td>a2</td></tr></table>'
            .'<table id="sections"><thead><tr><th>Kode</th></tr></thead><tbody><tr><td>b1</td></tr><tr><td>b2</td></tr></tbody>'
            .'<tfoot><tr><td>Total</td></tr></tfoot></table>');

        $bare = $service->call('tableRows', $crawler->filter('#bare'));
        $sections = $service->call('tableRows', $crawler->filter('#sections'));

        $this->assertSame(['a1', 'a2'], array_map(fn (Crawler $row): string => $service->call('cellText', $row), $bare));
        $this->assertSame(['Kode', 'b1', 'b2', 'Total'], array_map(fn (Crawler $row): string => $service->call('cellText', $row), $sections));
        $this->assertSame('th', $service->call('rowCells', $sections[0])[0]->nodeName());
        $this->assertCount(2, $service->call('tableRows', $crawler->filter('#sections tbody')));
    }

    #[DataProvider('parserProvider')]
    public function test_rows_and_cells_of_nested_tables_do_not_leak_into_the_outer_table(string $parser): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<table id="outer"><tr><td>Judul</td><td><table id="inner"><tr><td>i1</td><td>i2</td></tr>'
            .'<tr><td>i3</td><td>i4</td></tr></table></td><td>Akhir</td></tr><tr><td colspan="3">Bawah</td></tr></table>');

        $outerRows = $service->call('tableRows', $crawler->filter('#outer'));
        $innerRows = $service->call('tableRows', $crawler->filter('#inner'));

        $this->assertCount(2, $outerRows);
        $this->assertCount(3, $service->call('rowCells', $outerRows[0]));
        $this->assertSame(['Judul', 'i1i2i3i4', 'Akhir'], $this->rowTexts($service, $outerRows[0]));
        $this->assertSame(['Bawah'], $this->rowTexts($service, $outerRows[1]));
        $this->assertCount(2, $innerRows);
        $this->assertSame(['i1', 'i2'], $this->rowTexts($service, $innerRows[0]));
        $this->assertSame(['i3', 'i4'], $this->rowTexts($service, $innerRows[1]));
    }

    #[DataProvider('parserProvider')]
    public function test_cells_wrapped_in_font_center_span_anchor_and_form_tags_belong_to_their_row(string $parser): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<table><tr><font size=2><center><td>Satu</td><td>Dua</td></center></font>'
            .'<a href="#"><td>Tiga</td></a><span><th>Empat</th></span></tr>'
            .'<form method=post action="?mod=student&act=x"><tr><td>Lima</td><td><input type=hidden name=txt_id value=90001>Enam</td></tr></form>'
            .'<tr><td>Tujuh</td></tr></table>');

        $rows = $service->call('tableRows', $crawler->filter('table'));

        $this->assertCount(3, $rows);
        $this->assertSame(['Satu', 'Dua', 'Tiga', 'Empat'], $this->rowTexts($service, $rows[0]));
        $this->assertSame(['Lima', 'Enam'], $this->rowTexts($service, $rows[1]));
        $this->assertSame(1, $rows[1]->filter('input[name="txt_id"]')->count());
        $this->assertSame(['Tujuh'], $this->rowTexts($service, $rows[2]));
    }

    #[DataProvider('parserProvider')]
    public function test_colspan_cells_unquoted_attributes_and_unclosed_tags_keep_their_row(string $parser): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<table class=table><tr><td>Kode<td>Mata Kuliah<td>SKS</tr>'
            .'<tr><td>KD001<td>Contoh&nbsp;Satu<td>3'
            .'<tr><td colspan=2 align=right><b>Total SKS</b><td><b>3</b></table>');

        $rows = $service->call('tableRows', $crawler->filter('table'));
        $footer = $service->call('rowCells', $rows[2]);

        $this->assertCount(3, $rows);
        $this->assertSame(['Kode', 'Mata Kuliah', 'SKS'], $this->rowTexts($service, $rows[0]));
        $this->assertSame(['KD001', 'Contoh Satu', '3'], $this->rowTexts($service, $rows[1]));
        $this->assertCount(2, $footer);
        $this->assertSame('2', $footer[0]->attr('colspan'));
        $this->assertSame(['Total SKS', '3'], $this->rowTexts($service, $rows[2]));
    }

    public function test_cell_text_normalizes_entities_non_breaking_spaces_and_whitespace(): void
    {
        $service = $this->service();
        $crawler = $this->page('html5', "<table><tr><td>\n  Rp.&nbsp;1.500.000,-\u{00A0}\t<br>\n Lunas &amp; Valid </td><td></td></tr></table>");
        $cells = $service->call('rowCells', $service->call('tableRows', $crawler->filter('table'))[0]);

        $this->assertSame('Rp. 1.500.000,- Lunas & Valid', $service->call('cellText', $cells[0]));
        $this->assertSame('', $service->call('cellText', $cells[1]));
        $this->assertSame('', $service->call('cellText', new Crawler));
    }

    public function test_empty_crawlers_and_non_table_scopes_yield_nothing(): void
    {
        $service = $this->service();
        $crawler = $this->page('html5', '<div id="scope"><table><tr><td>a</td></tr></table></div>');

        $this->assertSame([], $service->call('tableRows', new Crawler));
        $this->assertSame([], $service->call('rowCells', new Crawler));
        $this->assertSame([], $service->call('tableRows', $crawler->filter('#scope')));
        $this->assertSame([], $service->call('tableRows', $crawler->filter('#missing')));
        $this->assertNull($service->call('findTableByHeaders', new Crawler, ['Kode']));
    }

    #[DataProvider('perwalianHistoryProvider')]
    public function test_it_finds_the_perwalian_history_table_after_the_label_tables(string $parser, string $markup): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<div class="content"><table class="table"><tr><td>Program Studi</td><td>:</td><td>Contoh</td></tr>'
            .'<tr><td>Semester</td><td>:</td><td>1</td></tr></table>'.$markup.'</div>');

        $table = $service->call('findTableByHeaders', $crawler->filter('.content'), ['t. a', 'SEMESTER', 'Aksi']);

        $this->assertInstanceOf(Crawler::class, $table);
        $this->assertSame('T.A', $service->call('cellText', $service->call('rowCells', $service->call('tableRows', $table)[0])[0]));
    }

    #[DataProvider('parserProvider')]
    public function test_a_layout_table_wrapping_the_wanted_table_is_skipped(string $parser): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<table id="layout"><tr><td><center>'
            .'<table id="wanted"><tr><th>Kode</th><th>Nama Mata Kuliah</th><th>SKS</th></tr><tr><td>KD001</td><td>Contoh</td><td>3</td></tr></table>'
            .'</center></td></tr></table>');

        $table = $service->call('findTableByHeaders', $crawler, ['Kode', 'SKS']);

        $this->assertSame('wanted', $table?->attr('id'));
    }

    #[DataProvider('parserProvider')]
    public function test_headers_may_sit_in_any_of_the_first_three_rows_with_cells(string $parser): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<table id="fourth"><tr><td colspan="2">KRS Semester 1</td></tr><tr><td>Catatan</td></tr><tr><td>Catatan</td></tr>'
            .'<tr><td>Kode</td><td>SKS</td></tr></table>'
            .'<table id="third"><tr></tr><tr><td colspan="2"><b>Kartu Rencana Studi</b></td></tr><tr></tr><tr><td>Catatan</td></tr>'
            .'<tr><td> kode </td><td>s k s</td></tr></table>');

        $table = $service->call('findTableByHeaders', $crawler, ['KODE', 'sks']);

        $this->assertSame('third', $table?->attr('id'));
    }

    #[DataProvider('parserProvider')]
    public function test_labels_match_whole_cells_and_every_label_is_required(string $parser): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<table id="partial"><tr><td>Kode Mata Kuliah</td><td>SKS</td></tr></table>'
            .'<table id="missing"><tr><td>Kode</td><td>Nama</td></tr></table>');

        $this->assertNull($service->call('findTableByHeaders', $crawler, ['Kode', 'SKS']));
        $this->assertSame('partial', $service->call('findTableByHeaders', $crawler, ['kodematakuliah', 'SKS'])?->attr('id'));
        $this->assertNull($service->call('findTableByHeaders', $crawler, []));
        $this->assertNull($service->call('findTableByHeaders', $crawler, [' ', '.']));
    }

    #[DataProvider('parserProvider')]
    public function test_the_scope_itself_can_be_the_table_and_the_first_match_wins(string $parser): void
    {
        $service = $this->service();
        $crawler = $this->page($parser, '<table id="first"><tr><td>No.</td><td>Tagihan</td></tr></table>'
            .'<table id="second"><tr><td>No</td><td>Tagihan</td></tr></table>');

        $this->assertSame('first', $service->call('findTableByHeaders', $crawler, ['no', 'TAGIHAN'])?->attr('id'));
        $this->assertSame('second', $service->call('findTableByHeaders', $crawler->filter('#second'), ['No.', 'Tagihan'])?->attr('id'));
    }

    /**
     * @return list<string>
     */
    private function rowTexts(object $service, Crawler $row): array
    {
        return array_map(fn (Crawler $cell): string => $service->call('cellText', $cell), $service->call('rowCells', $row));
    }

    private function page(string $parser, string $body): Crawler
    {
        $document = '<html><head><title>Portal</title></head><body>'.$body.'</body></html>';

        return new Crawler($parser === 'html5' ? "<!doctype html>\n".$document : $document);
    }

    private function service(): object
    {
        return new class(Mockery::mock(PortalSessionManager::class)) extends PortalScrapingService
        {
            public function call(string $method, mixed ...$arguments): mixed
            {
                return $this->{$method}(...$arguments);
            }
        };
    }
}
