<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\SkkmExtractor;
use App\Services\PortalEndpointRegistry;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * The SIREKA (SKKM) page as the live portal sends it (K14 sanitized copy):
 * an empty list, the small-box counters and the total lines below the table,
 * parsed by DomCrawler's HTML5 parser like a live response. Filled lists are
 * written in the portal's raw table style (rows without tbody, header cells
 * as `<td><b>`, cells wrapped in b/font/form, a nested action table).
 */
class SkkmExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const RAW_FIXTURE = 'tests/Fixtures/portal/student_skkm_raw.html';

    private const NORMALIZED_FIXTURE = 'tests/Fixtures/portal/student_skkm.html';

    private const EMPTY_TABLE_BODY = '<thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Kegiatan</th>
                            <th>Kegiatan</th>
                            <th>Tingkat</th>
                            <th>Jenis</th>
                            <th>Nilai</th>
                            <th>Status</th>
                            <th>Lampiran</th>
                            <th>Aksi</th>
                        </tr>
                        </thead>
                        <tbody>
                                                </tbody>';

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

    public function test_the_raw_page_is_requested_once_and_its_empty_list_is_found(): void
    {
        /** @var SkkmExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(SkkmExtractor::class, self::RAW_FIXTURE, $requestedUrls);

        $data = $extractor->extractSkkmData('fake-session-token');

        $this->assertSame([PortalEndpointRegistry::url('student_skkm')], $requestedUrls);
        $this->assertTrue($data['found']);
        $this->assertSame([], $data['entries']);
        $this->assertSame(['total' => 0, 'valid' => 0, 'invalid' => 0, 'pending' => 0, 'unknown' => 0], $data['summary']['entry_counts']);
        $this->assertNull($data['summary']['stat_counts_match_entries']);
        $this->assertSame([], $data['summary']['points_by_activity']);
    }

    public function test_the_raw_counters_totals_and_graduation_status_are_read(): void
    {
        $data = $this->extractRaw();

        $this->assertSame(['valid' => 0, 'invalid' => 0, 'pending' => 0], $data['stats']['stat_counts']);
        $this->assertSame(['valid' => 0, 'invalid' => 0, 'pending' => 0], $data['stats']['total_points']);
        $this->assertSame(['text' => 'Belum Memenuhi Kelulusan Nilai SKKM', 'is_met' => false], $data['graduation_status']);
        $this->assertSame(0, $data['summary']['valid_points']);
        $this->assertSame(0, $data['summary']['potential_points']);
        $this->assertSame('portal_totals', $data['summary']['points_source']);
    }

    public function test_the_raw_notice_guide_and_add_link_come_from_the_content(): void
    {
        $data = $this->extractRaw();

        $this->assertSame('skkm', $data['notice']['kind']);
        $this->assertStringStartsWith('Silakan Konsultasi dengan Dosen Pembimbing Akademik', $data['notice']['text']);
        $this->assertSame([], $data['notice']['dates']);
        $this->assertSame('files/skkm/Pedoman_SKKM_2024.pdf', $data['guide_path']);
        $this->assertSame(2024, $data['guide_year']);
        $this->assertTrue($data['add_available']);
        $this->assertNull($data['message']);
    }

    public function test_the_sidebar_skkm_link_is_not_an_add_link(): void
    {
        $html = str_replace(
            '<a href="?mod=student&act=addskkm" class="btn btn-primary">Tambah',
            '<a href="?mod=student&act=skkm" class="btn btn-primary">Tambah',
            $this->fixtureHtml(self::RAW_FIXTURE)
        );

        $this->assertFalse($this->extractRawHtml($html)['add_available']);
    }

    public function test_the_raw_page_reads_like_its_browser_normalized_copy(): void
    {
        $normalized = $this->makeExtractorWithFixture(SkkmExtractor::class, self::NORMALIZED_FIXTURE)
            ->extractSkkmData('normalized-session-token');

        $this->assertSame($this->withoutVolatileKeys($normalized), $this->withoutVolatileKeys($this->extractRaw()));
    }

    public function test_raw_rows_without_tbody_and_with_wrapped_cells_become_entries(): void
    {
        $data = $this->extractRawHtml($this->filledRawPage($this->rawEntryTable()));

        $this->assertTrue($data['found']);
        $this->assertCount(3, $data['entries']);
        $this->assertSame([
            'no' => 1,
            'skkm_id' => null,
            'name' => 'Kegiatan Contoh Satu',
            'activity' => 'UCIC Values',
            'level' => 'UCIC',
            'type' => 'Bina Keluarga Kampus (UCIC)',
            'points' => 10,
            'points_label' => '10',
            'status' => 'valid',
            'status_label' => 'Valid',
            'has_attachment' => true,
            'attachment_path' => 'files/skkm/SKKM_90001_bukti.pdf',
            'has_actions' => false,
            'action_types' => [],
        ], $data['entries'][0]);

        $this->assertSame([2, 'Kegiatan Contoh Dua', 'invalid', 3, '90002', ['delete']], [
            $data['entries'][1]['no'],
            $data['entries'][1]['name'],
            $data['entries'][1]['status'],
            $data['entries'][1]['points'],
            $data['entries'][1]['skkm_id'],
            $data['entries'][1]['action_types'],
        ]);
        $this->assertSame('files/skkm/SKKM_90002_bukti.pdf', $data['entries'][1]['attachment_path']);

        $this->assertSame([3, 'Kegiatan Contoh Tiga', 'pending', 5, '90003', ['edit', 'delete']], [
            $data['entries'][2]['no'],
            $data['entries'][2]['name'],
            $data['entries'][2]['status'],
            $data['entries'][2]['points'],
            $data['entries'][2]['skkm_id'],
            $data['entries'][2]['action_types'],
        ]);
        $this->assertTrue($data['entries'][2]['has_attachment']);
        $this->assertNull($data['entries'][2]['attachment_path']);
    }

    public function test_raw_entries_agree_with_the_page_counters_and_totals(): void
    {
        $data = $this->extractRawHtml($this->filledRawPage($this->rawEntryTable()));

        $this->assertSame(['valid' => 1, 'invalid' => 1, 'pending' => 1], $data['stats']['stat_counts']);
        $this->assertSame(['valid' => 10, 'invalid' => 3, 'pending' => 5], $data['stats']['total_points']);
        $this->assertSame(['total' => 3, 'valid' => 1, 'invalid' => 1, 'pending' => 1, 'unknown' => 0], $data['summary']['entry_counts']);
        $this->assertTrue($data['summary']['stat_counts_match_entries']);
        $this->assertSame(15, $data['summary']['potential_points']);
        $this->assertSame([10, 3, 5], array_map(
            static fn (array $activity): int|float => $activity['valid_points'] + $activity['invalid_points'] + $activity['pending_points'],
            $data['summary']['points_by_activity']
        ));
    }

    public function test_a_layout_table_around_the_raw_list_is_skipped(): void
    {
        $wrapped = '<table width="100%"><tr><td>'.$this->rawEntryTable().'</td></tr></table>';

        $data = $this->extractRawHtml($this->filledRawPage($wrapped));

        $this->assertTrue($data['found']);
        $this->assertSame(['Kegiatan Contoh Satu', 'Kegiatan Contoh Dua', 'Kegiatan Contoh Tiga'], array_column($data['entries'], 'name'));
        $this->assertTrue($data['summary']['stat_counts_match_entries']);
    }

    public function test_a_raw_placeholder_row_is_not_an_entry(): void
    {
        $html = str_replace(
            "<tbody>\n                                                </tbody>",
            "<tbody>\n<tr><td colspan=9 align=center>Belum ada data</td></tr>\n</tbody>",
            $this->fixtureHtml(self::RAW_FIXTURE)
        );
        $this->assertStringContainsString('Belum ada data', $html);

        $data = $this->extractRawHtml($html);

        $this->assertTrue($data['found']);
        $this->assertSame([], $data['entries']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRaw(): array
    {
        /** @var SkkmExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(SkkmExtractor::class, self::RAW_FIXTURE);

        return $extractor->extractSkkmData('fake-session-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawHtml(string $html): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'skkm-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        /** @var SkkmExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixture(SkkmExtractor::class, $path);

        return $extractor->extractSkkmData('variant-session-token');
    }

    /**
     * The raw fixture with its empty table replaced and with counters and
     * totals that match the synthetic entries (1 valid = 10, 1 invalid = 3,
     * 1 pending = 5)
     */
    private function filledRawPage(string $table): string
    {
        $html = $this->fixtureHtml(self::RAW_FIXTURE);
        $tableStart = strpos($html, '<table class="table table-bordered table-striped">');
        $tableEnd = strpos($html, '</table>');
        $this->assertNotFalse($tableStart);
        $this->assertNotFalse($tableEnd);
        $this->assertStringContainsString(self::EMPTY_TABLE_BODY, $html);

        $html = substr($html, 0, $tableStart).$table.substr($html, $tableEnd + strlen('</table>'));

        foreach (['skkm valid' => 1, 'skkm tidak valid' => 1, 'skkm belum divalidasi' => 1] as $label => $count) {
            $html = (string) preg_replace(
                '#(<h2>\s*)0(\s*</h2>\s*<p class="text-uppercase">'.$label.'</p>)#',
                '${1}'.$count.'${2}',
                $html,
                1,
                $replaced
            );
            $this->assertSame(1, $replaced, $label);
        }

        foreach (['Total poin belum divalidasi:' => 5, 'Total Poin tidak valid:' => 3, 'Total Poin valid:' => 10] as $label => $points) {
            $html = (string) preg_replace('#('.preg_quote($label, '#').'\s*)0(\s)#', '${1}'.$points.'${2}', $html, 1, $replaced);
            $this->assertSame(1, $replaced, $label);
        }

        return $html;
    }

    /**
     * Entry table in the portal's raw style: no thead/tbody, header cells as
     * `<td><b>` inside a stray `<b>`, unquoted attributes, a row inside a
     * form, a row inside `<font>`, a nested action table and a repeated
     * th-only header row at the end
     */
    private function rawEntryTable(): string
    {
        return <<<'HTML'
<table class="table table-bordered table-striped">
<tr><b>
<td><b>No</b></td> <td><b>Nama Kegiatan</b></td> <td><b>Kegiatan</b></td> <td><b>Tingkat</b></td> <td><b>Jenis</b></td> <td><b>Nilai</b></td> <td><b>Status</b></td> <td><b>Lampiran</b></td> <td><b>Aksi</b></td>
</b> </tr>
<tr>
<td valign=top>1</td>
<td><font size=2>Kegiatan Contoh Satu</font></td>
<td>UCIC Values</td>
<td>UCIC</td>
<td>Bina Keluarga Kampus (UCIC)</td>
<td>10</td>
<td><span class="label label-success">Valid</span></td>
<td><a href="../files/skkm/SKKM_90001_bukti.pdf" target=_blank>Lihat Bukti</a></td>
<td></td>
</tr>
<form method=post action="?mod=student&act=skkm">
<tr>
<td valign=top>2</td>
<td>Kegiatan Contoh Dua</td>
<td>Organisasi/Kepanitiaan</td>
<td>Internal Kampus</td>
<td>Anggota ORMAWA</td>
<td>3</td>
<td>Tidak Valid</td>
<td><a href="../files/skkm/SKKM_90002_bukti.pdf" target=_blank>Lihat Bukti</a></td>
<td><a href="?mod=student&act=delskkm&id=90002" onclick="return confirm('Yakin akan menghapus data ini?')">Hapus</a></td>
</tr>
</form>
<tr><font color=black>
<td valign=top>3</td>
<td>Kegiatan Contoh Tiga</td>
<td>Kegiatan Lainnya (Tri Dharma Pendidikan, Penelitian dan Pengabdian)</td>
<td>Wilayah/Provinsi</td>
<td>Peserta Seminar/Workshop</td>
<td>5&nbsp;</td>
<td><b>Belum Divalidasi</b></td>
<td><a href="?mod=student&act=viewlampiran&id=90003" target=_blank>Lihat Bukti</a></td>
<td><table><tr><td><a href="?mod=student&act=editskkm&id=90003">Ubah</a></td><td><a href="?mod=student&act=delskkm&id=90003">Hapus</a></td></tr></table></td>
</font></tr>
<tr><th>No</th><th>Nama Kegiatan</th><th>Kegiatan</th><th>Tingkat</th><th>Jenis</th><th>Nilai</th><th>Status</th><th>Lampiran</th><th>Aksi</th></tr>
</table>
HTML;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutVolatileKeys(array $data): array
    {
        unset($data['extracted_at'], $data['_sync']);

        return $data;
    }

    private function fixtureHtml(string $fixture): string
    {
        return (string) file_get_contents(base_path($fixture));
    }
}
