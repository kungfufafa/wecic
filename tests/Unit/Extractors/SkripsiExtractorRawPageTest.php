<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalException;
use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\SkripsiExtractor;
use App\Services\PortalEndpointRegistry;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

/**
 * SiMASITA (act=simasita) and e-Skripsi (act=skripsi) as the live portal
 * sends them (K14 sanitized, synthetic identity): "label | : | value" rows
 * with the spaced "N I M" label, requirement lists inside `td[colspan=3]`,
 * SOP links with a `<button title>` inside `<a>` after an empty `<a href=''>`,
 * and the status `<h3>` in a `table.table-hover` outside the cards.
 */
class SkripsiExtractorRawPageTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const SIMASITA = 'tests/Fixtures/portal/student_simasita_raw.html';

    private const SKRIPSI = 'tests/Fixtures/portal/student_skripsi_raw.html';

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

    public function test_both_pages_are_read_and_merged(): void
    {
        $data = $this->extract([self::SIMASITA, self::SKRIPSI], $requestedUrls);

        $this->assertSame([PortalEndpointRegistry::url('student_simasita'), PortalEndpointRegistry::url('student_skripsi')], $requestedUrls);
        $this->assertTrue($data['found']);
        $this->assertSame('Sistem Layanan e-Skripsi Universitas Catur Insan Cendekia (SiMASITA)', $data['title']);
        $this->assertSame(SkripsiExtractor::STATE_NOT_ENROLLED, $data['status']['state']);
        $this->assertArrayHasKey('extracted_at', $data);
    }

    public function test_identity_rows_read_the_spaced_nim_label_and_split_program_and_level(): void
    {
        $this->assertSame([
            'faculty' => 'FAKULTAS TEKNOLOGI INFORMASI',
            'program' => 'SISTEM INFORMASI',
            'program_raw' => 'SISTEM INFORMASI - Strata S1',
            'jenjang' => 'S1',
            'nim' => '200100099',
            'name' => 'MAHASISWA UJI',
        ], $this->simasita()['student']);
    }

    public function test_requirement_lists_are_split_by_their_heading(): void
    {
        $requirements = $this->simasita()['requirements'];

        $this->assertCount(6, $requirements['thesis']);
        $this->assertSame('Maksimal 2 Nilai D', $requirements['thesis'][3], 'The &nbsp; is a plain space.');
        $this->assertSame([
            'Lembar pengesahan yang ditandatangani pembimbing',
            'Naskah skripsi hardcopy dan softcopy',
            'Jadwal ujian akhir dari program studi',
        ], $requirements['exam']);
    }

    public function test_thresholds_are_read_from_the_requirement_text(): void
    {
        $data = $this->simasita();

        $this->assertSame(['min_sks' => ['D3' => 90, 'S1' => 130], 'min_ipk' => 2.75, 'max_d' => 2, 'allow_e' => false], $data['thresholds']);
        $this->assertSame('portal', $data['threshold_source']);
        $this->assertSame(['portal'], array_values(array_unique($data['threshold_sources'])));
    }

    public function test_thresholds_the_portal_does_not_state_fall_back_to_the_config(): void
    {
        config()->set('portal.thesis.default_thresholds', ['min_sks' => ['D3' => 88, 'S1' => 118], 'min_ipk' => 2.25, 'max_d' => 1, 'allow_e' => false]);
        $fixture = $this->variant(self::SIMASITA, [
            'Telah menempuh minimal 90 SKS untuk D3 dan minimal 130 SKS untuk S1' => 'Telah menempuh jumlah SKS sesuai ketentuan program studi',
            'Maksimal 2 Nilai&nbsp;D' => 'Nilai D sesuai ketentuan',
        ]);

        $data = $this->makeExtractorWithRawFixture(SkripsiExtractor::class, $fixture)->parseSimasitaPage((string) file_get_contents($fixture));

        $this->assertSame(['min_sks' => ['D3' => 88, 'S1' => 118], 'min_ipk' => 2.75, 'max_d' => 1, 'allow_e' => false], $data['thresholds']);
        $this->assertSame('partial', $data['threshold_source']);
        $this->assertSame('default', $data['threshold_sources']['min_sks_s1']);
        $this->assertSame('portal', $data['threshold_sources']['min_ipk']);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function footerNavigationInsideTheContent(): array
    {
        $footerLinks = [
            "                                Home\n                            </a>\n                        </li>" => "Home</a></li>\n                        <li><a href=\"?mod=student&act=dokumen\">Dokumen</a></li>",
        ];

        return [
            'footer inside the page content' => [[
                "            </div>\n        </div>\n\n        <footer class=\"footer\">" => "            </div>\n\n        <footer class=\"footer\">",
                ...$footerLinks,
            ]],
            'footer inside the requirements card' => [[
                "                                </table>\n                            </div>\n                        </div>\n                    </div>\n                </div>\n            </div>\n        </div>\n\n        <footer class=\"footer\">" => "                                </table>\n\n        <footer class=\"footer\">",
                ...$footerLinks,
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    #[DataProvider('footerNavigationInsideTheContent')]
    public function test_the_portal_footer_navigation_never_becomes_a_requirement(array $replacements): void
    {
        $fixture = $this->variant(self::SIMASITA, $replacements);

        $requirements = $this->makeExtractorWithRawFixture(SkripsiExtractor::class, $fixture)
            ->parseSimasitaPage((string) file_get_contents($fixture))['requirements'];

        $this->assertCount(6, $requirements['thesis']);
        $this->assertSame([
            'Lembar pengesahan yang ditandatangani pembimbing',
            'Naskah skripsi hardcopy dan softcopy',
            'Jadwal ujian akhir dari program studi',
        ], $requirements['exam']);
    }

    public function test_sop_documents_are_campus_file_paths_and_the_empty_link_is_skipped(): void
    {
        $this->assertSame([
            ['label' => 'SOP Mekanisme Proses Penyelesaian Tugas Akhir', 'path' => 'files/SOP_Mekanisme_proses_penyelesaian_tugas_akhir.pdf', 'filename' => 'SOP_Mekanisme_proses_penyelesaian_tugas_akhir.pdf'],
            ['label' => 'Panduan Penulisan Skripsi', 'path' => 'files/Panduan_Penulisan_Skripsi.pdf', 'filename' => 'Panduan_Penulisan_Skripsi.pdf'],
        ], $this->simasita()['documents']);
    }

    public function test_a_student_without_a_thesis_is_not_enrolled(): void
    {
        $status = $this->makeExtractorWithRawFixture(SkripsiExtractor::class, self::SKRIPSI)
            ->parseSkripsiStatusPage((string) file_get_contents(base_path(self::SKRIPSI)));

        $this->assertSame([
            'state' => SkripsiExtractor::STATE_NOT_ENROLLED,
            'message' => 'Anda Tidak Mengambil Mata Kuliah Skripsi Pada Semester Ini',
            'raw_sections' => [],
            'available' => true,
        ], $status);
    }

    public function test_an_active_thesis_page_is_captured_without_values_of_form_fields_or_action_links(): void
    {
        $active = $this->variant(self::SKRIPSI, [
            '<tr><td><center><h3>Anda Tidak Mengambil Mata Kuliah Skripsi Pada Semester Ini</h3></center></td></tr>' => '',
            '                        <table class="table table-hover">' => <<<'HTML'
                        <div class="card">
                            <div class="header"><h4 class="title">Data Skripsi</h4></div>
                            <div class="content">
                                <table class="table">
                                    <tr><td>Judul</td><td>:</td><td>Judul Uji Coba</td></tr>
                                    <tr><td>Pembimbing 1</td><td>:</td><td>Dosen Contoh Alfa</td></tr>
                                    <tr><td width=200>N I M</td><td>:</td><td>200100099</td></tr>
                                </table>
                                <form method="post" action="?mod=student&act=uploadskripsi"><input type="hidden" name="id_skripsi" value="secret-77"><input type="file" name="berkas"></form>
                                <a href="?mod=student&act=hapusskripsi&id=77">Hapus</a>
                                <a href="../files/skripsi/kolokium/PROPOSAL-UJI.pdf">Proposal</a>
                            </div>
                        </div>
                        <table class="table table-hover">
HTML,
        ]);

        $status = $this->makeExtractorWithRawFixture(SkripsiExtractor::class, $active)->parseSkripsiStatusPage((string) file_get_contents($active));

        $this->assertSame(SkripsiExtractor::STATE_ACTIVE_UNPARSED, $status['state']);
        $section = collect($status['raw_sections'])->firstWhere('title', 'Data Skripsi');
        $this->assertSame([['label' => 'Judul', 'value' => 'Judul Uji Coba'], ['label' => 'Pembimbing 1', 'value' => 'Dosen Contoh Alfa']], $section['fields']);
        $this->assertSame(['id_skripsi', 'berkas'], $section['form_fields']);
        $this->assertContains(['label' => 'Proposal', 'path' => 'files/skripsi/kolokium/PROPOSAL-UJI.pdf'], $section['links']);
        $this->assertContains(['label' => 'Hapus', 'path' => null], $section['links']);
        $this->assertStringNotContainsString('secret-77', (string) json_encode($status));
        $this->assertStringNotContainsString('hapusskripsi', (string) json_encode($status));
    }

    public function test_a_failing_status_page_keeps_the_requirements(): void
    {
        $data = $this->extract([self::SIMASITA, PortalScrapingException::invalidResponse('skripsi', 'timeout')]);

        $this->assertTrue($data['found']);
        $this->assertSame(['state' => SkripsiExtractor::STATE_UNKNOWN, 'message' => null, 'raw_sections' => [], 'available' => false], $data['status']);
    }

    public function test_an_expired_session_on_the_status_page_is_rethrown(): void
    {
        try {
            $this->extract([self::SIMASITA, $this->loginFixture()]);
            $this->fail('An expired session must reach the student.');
        } catch (PortalScrapingException $e) {
            $this->assertTrue(PortalException::indicatesExpiredSession($e));
        }
    }

    public function test_an_empty_shell_is_not_found_and_is_not_cached(): void
    {
        $shell = $this->variant(self::SKRIPSI, [
            '<tr><td><center><h3>Anda Tidak Mengambil Mata Kuliah Skripsi Pada Semester Ini</h3></center></td></tr>' => '',
        ]);
        $empty = $this->emptyShell($shell);

        $first = $this->extract([$empty, $empty]);
        $this->assertFalse($first['found']);
        $this->assertSame(SkripsiExtractor::STATE_UNKNOWN, $first['status']['state']);

        // Not cached: the next read asks the portal again.
        $requestedUrls = [];
        $this->extract([self::SIMASITA, self::SKRIPSI], $requestedUrls);
        $this->assertCount(2, $requestedUrls);
    }

    /**
     * @param  array{0: string|\Throwable, 1: string|\Throwable}  $fixtures
     * @param  list<string>|null  $requestedUrls
     * @return array<string, mixed>
     */
    private function extract(array $fixtures, ?array &$requestedUrls = null): array
    {
        /** @var SkripsiExtractor $extractor */
        $extractor = $this->makeExtractorWithRawFixtures(SkripsiExtractor::class, [
            PortalEndpointRegistry::url('student_simasita') => $fixtures[0],
            PortalEndpointRegistry::url('student_skripsi') => $fixtures[1],
        ], $requestedUrls);

        return $extractor->extractSkripsiData('fake-session-token');
    }

    /**
     * @return array<string, mixed>
     */
    private function simasita(): array
    {
        return $this->makeExtractorWithRawFixture(SkripsiExtractor::class, self::SIMASITA)
            ->parseSimasitaPage((string) file_get_contents(base_path(self::SIMASITA)));
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function variant(string $fixture, array $replacements): string
    {
        $html = (string) file_get_contents(base_path($fixture));

        foreach ($replacements as $search => $replace) {
            $this->assertStringContainsString($search, $html);
            $html = str_replace($search, $replace, $html);
        }

        return $this->temporaryFixture($html);
    }

    private function emptyShell(string $fixture): string
    {
        $html = (string) file_get_contents($fixture);
        $start = (int) strpos($html, '        <div class="content">');
        $end = (int) strpos($html, '        <footer class="footer">');

        return $this->temporaryFixture(substr($html, 0, $start).'        <div class="content"><div class="container-fluid"></div></div>'."\n".substr($html, $end));
    }

    private function loginFixture(): string
    {
        return $this->temporaryFixture('<!doctype html><html><head><title>Login MyUCIC</title></head><body><form method="post" action="?mod=loginact">'
            .'<input type="text" name="txt_username"><input type="password" name="txt_password"></form></body></html>');
    }

    private function temporaryFixture(string $html): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'skripsi-raw-');
        file_put_contents($path, $html);
        $this->temporaryFixtures[] = $path;

        return $path;
    }
}
