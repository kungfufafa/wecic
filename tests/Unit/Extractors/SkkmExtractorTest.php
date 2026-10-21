<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\SkkmExtractor;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class SkkmExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const EMPTY_FIXTURE = 'tests/Fixtures/portal/student_skkm.html';

    private const FILLED_FIXTURE = 'tests/Fixtures/portal/student_skkm_filled.html';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_reads_the_empty_list_from_the_crawled_page(): void
    {
        /** @var SkkmExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(SkkmExtractor::class, self::EMPTY_FIXTURE);

        $data = $extractor->extractSkkmData('fake-session-token');

        $this->assertTrue($data['found']);
        $this->assertSame(['valid' => 0, 'invalid' => 0, 'pending' => 0], $data['stats']['stat_counts']);
        $this->assertSame(['valid' => 0, 'invalid' => 0, 'pending' => 0], $data['stats']['total_points']);
        $this->assertNull($data['stats']['required_points']);
        $this->assertNull($data['stats']['required_points_source']);
        $this->assertSame(['text' => 'Belum Memenuhi Kelulusan Nilai SKKM', 'is_met' => false], $data['graduation_status']);
        $this->assertSame([], $data['entries']);
        $this->assertSame('files/skkm/Pedoman_SKKM_2024.pdf', $data['guide_path']);
        $this->assertSame(2024, $data['guide_year']);
        $this->assertTrue($data['add_available']);
        $this->assertNull($data['message']);
        $this->assertArrayHasKey('extracted_at', $data);
    }

    public function test_it_keeps_the_portal_instruction_as_an_skkm_notice(): void
    {
        $data = $this->extractFixture(self::EMPTY_FIXTURE);

        $this->assertNotNull($data['notice']);
        $this->assertSame('skkm', $data['notice']['kind']);
        $this->assertStringStartsWith('Silakan Konsultasi dengan Dosen Pembimbing Akademik', $data['notice']['text']);
        $this->assertSame([], $data['notice']['dates']);
        $this->assertFalse($data['notice']['is_future']);
    }

    public function test_empty_list_summary_uses_portal_totals_and_cannot_verify_stat_counts(): void
    {
        $summary = $this->extractFixture(self::EMPTY_FIXTURE)['summary'];

        $this->assertSame(0, $summary['valid_points']);
        $this->assertSame(0, $summary['pending_points']);
        $this->assertSame(0, $summary['invalid_points']);
        $this->assertSame(0, $summary['potential_points']);
        $this->assertSame('portal_totals', $summary['points_source']);
        $this->assertNull($summary['remaining_points']);
        $this->assertNull($summary['progress_percent']);
        $this->assertSame(['total' => 0, 'valid' => 0, 'invalid' => 0, 'pending' => 0, 'unknown' => 0], $summary['entry_counts']);
        $this->assertNull($summary['stat_counts_match_entries']);
        $this->assertSame([], $summary['points_by_activity']);
    }

    public function test_it_maps_entries_by_header_label(): void
    {
        $entries = $this->extractFixture(self::FILLED_FIXTURE)['entries'];

        $this->assertCount(3, $entries);
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
        ], $entries[0]);

        $this->assertSame('Kegiatan Contoh Dua', $entries[1]['name']);
        $this->assertSame('Organisasi/Kepanitiaan', $entries[1]['activity']);
        $this->assertSame('Internal Kampus', $entries[1]['level']);
        $this->assertSame('Anggota ORMAWA', $entries[1]['type']);
        $this->assertSame(3, $entries[1]['points']);
        $this->assertSame('invalid', $entries[1]['status']);
        $this->assertSame('Tidak Valid', $entries[1]['status_label']);

        $this->assertSame('pending', $entries[2]['status']);
        $this->assertSame('Belum Divalidasi', $entries[2]['status_label']);
        $this->assertSame(5, $entries[2]['points']);
    }

    public function test_it_reads_row_actions_without_exposing_their_links(): void
    {
        $data = $this->extractFixture(self::FILLED_FIXTURE);
        [$valid, $invalid, $pending] = $data['entries'];

        $this->assertFalse($valid['has_actions']);
        $this->assertTrue($invalid['has_actions']);
        $this->assertSame(['delete'], $invalid['action_types']);
        $this->assertSame('90002', $invalid['skkm_id']);
        $this->assertTrue($pending['has_actions']);
        $this->assertSame(['edit', 'delete'], $pending['action_types']);
        $this->assertSame('90003', $pending['skkm_id']);

        $payload = (string) json_encode($data);

        foreach (['act=delskkm', 'act=editskkm', 'act=viewlampiran', 'act=addskkm', 'confirm(', 'loginact', 'href'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    public function test_only_static_campus_files_become_attachment_paths(): void
    {
        $entries = $this->extractFixture(self::FILLED_FIXTURE)['entries'];

        $this->assertSame('files/skkm/SKKM_90002_bukti.pdf', $entries[1]['attachment_path']);
        $this->assertTrue($entries[2]['has_attachment']);
        $this->assertNull($entries[2]['attachment_path']);
    }

    public function test_hero_points_come_from_portal_totals_not_stat_counts(): void
    {
        $data = $this->extractFixture(self::FILLED_FIXTURE);

        $this->assertSame(['valid' => 1, 'invalid' => 1, 'pending' => 1], $data['stats']['stat_counts']);
        $this->assertSame(['valid' => 10, 'invalid' => 3, 'pending' => 5], $data['stats']['total_points']);
        $this->assertSame(10, $data['summary']['valid_points']);
        $this->assertSame(5, $data['summary']['pending_points']);
        $this->assertSame(3, $data['summary']['invalid_points']);
        $this->assertSame(15, $data['summary']['potential_points']);
        $this->assertSame('portal_totals', $data['summary']['points_source']);
    }

    public function test_hero_points_ignore_entry_sums_when_portal_totals_differ(): void
    {
        $html = str_replace('Total Poin valid: 10', 'Total Poin valid: 12', $this->fixture(self::FILLED_FIXTURE));

        $this->assertSame(12, $this->extractHtml($html)['summary']['valid_points']);
    }

    public function test_summary_counts_entries_and_groups_points_by_activity(): void
    {
        $summary = $this->extractFixture(self::FILLED_FIXTURE)['summary'];

        $this->assertSame(['total' => 3, 'valid' => 1, 'invalid' => 1, 'pending' => 1, 'unknown' => 0], $summary['entry_counts']);
        $this->assertTrue($summary['stat_counts_match_entries']);
        $this->assertSame([
            ['activity' => 'UCIC Values', 'entries' => 1, 'valid_points' => 10, 'pending_points' => 0, 'invalid_points' => 0],
            ['activity' => 'Organisasi/Kepanitiaan', 'entries' => 1, 'valid_points' => 0, 'pending_points' => 0, 'invalid_points' => 3],
            ['activity' => 'Kegiatan Lainnya (Tri Dharma Pendidikan, Penelitian dan Pengabdian)', 'entries' => 1, 'valid_points' => 0, 'pending_points' => 5, 'invalid_points' => 0],
        ], $summary['points_by_activity']);
    }

    public function test_stat_counts_that_differ_from_the_rows_are_flagged(): void
    {
        $html = preg_replace(
            '/<h2> 1 <\/h2>(\s*<p class="text-uppercase">skkm valid<\/p>)/',
            '<h2> 10 </h2>$1',
            $this->fixture(self::FILLED_FIXTURE)
        );

        $data = $this->extractHtml((string) $html);

        $this->assertSame(10, $data['stats']['stat_counts']['valid']);
        $this->assertFalse($data['summary']['stat_counts_match_entries']);
    }

    public function test_summary_falls_back_to_entry_points_without_portal_totals(): void
    {
        $html = (string) preg_replace('/<p>\s*<strong>\s*Total [^<]+<\/strong>\s*<\/p>/i', '', $this->fixture(self::FILLED_FIXTURE));

        $data = $this->extractHtml($html);

        $this->assertSame(['valid' => null, 'invalid' => null, 'pending' => null], $data['stats']['total_points']);
        $this->assertSame(10, $data['summary']['valid_points']);
        $this->assertSame(15, $data['summary']['potential_points']);
        $this->assertSame('entries', $data['summary']['points_source']);
    }

    /**
     * @return array<string, array{string, string, string, int|float}>
     */
    public static function totalVariantProvider(): array
    {
        return [
            'upper case with spaced colon' => ['Total Poin valid: 10', 'TOTAL POIN VALID : 12', 'valid', 12],
            'point spelling and decimal comma' => ['Total Poin valid: 10', 'Total Point valid: 7,5', 'valid', 7.5],
            'lower case invalid' => ['Total Poin tidak valid: 3', 'total poin tidak valid: 4', 'invalid', 4],
            'yang belum divalidasi' => ['Total poin belum divalidasi: 5', 'Total Poin yang belum divalidasi: 6', 'pending', 6],
        ];
    }

    #[DataProvider('totalVariantProvider')]
    public function test_total_points_regex_tolerates_portal_wording(string $search, string $replace, string $status, int|float $expected): void
    {
        $html = str_replace($search, $replace, $this->fixture(self::FILLED_FIXTURE));

        $this->assertSame($expected, $this->extractHtml($html)['stats']['total_points'][$status]);
    }

    /**
     * @return array<string, array{string, string|null, bool|null}>
     */
    public static function graduationStatusProvider(): array
    {
        return [
            'belum memenuhi from the crawl' => ['Belum Memenuhi Kelulusan Nilai SKKM', 'Belum Memenuhi Kelulusan Nilai SKKM', false],
            'synthetic positive' => ['Memenuhi Kelulusan Nilai SKKM', 'Memenuhi Kelulusan Nilai SKKM', true],
            'tidak memenuhi' => ['Tidak Memenuhi Syarat', 'Tidak Memenuhi Syarat', false],
            'lower case positive' => ['sudah memenuhi', 'sudah memenuhi', true],
            'unknown wording' => ['Lulus', 'Lulus', null],
            'empty status' => ['', null, null],
        ];
    }

    #[DataProvider('graduationStatusProvider')]
    public function test_graduation_status_rule(string $portalText, ?string $expectedText, ?bool $expectedIsMet): void
    {
        $html = str_replace(
            'Status Kelulusan SKKM: Belum Memenuhi Kelulusan Nilai SKKM ',
            'Status Kelulusan SKKM: '.$portalText.' ',
            $this->fixture(self::EMPTY_FIXTURE)
        );

        $this->assertSame(['text' => $expectedText, 'is_met' => $expectedIsMet], $this->extractHtml($html)['graduation_status']);
    }

    public function test_missing_graduation_status_is_null(): void
    {
        $html = str_replace('Status Kelulusan SKKM: Belum Memenuhi Kelulusan Nilai SKKM', '', $this->fixture(self::EMPTY_FIXTURE));

        $this->assertSame(['text' => null, 'is_met' => null], $this->extractHtml($html)['graduation_status']);
    }

    public function test_required_points_stated_on_the_page_drive_progress(): void
    {
        $html = str_replace(
            '<strong>Status Kelulusan SKKM:',
            '<strong>Minimal poin SKKM untuk kelulusan: 100 poin</strong>
</p>
<p>
<strong>Status Kelulusan SKKM:',
            $this->fixture(self::FILLED_FIXTURE)
        );

        $data = $this->extractHtml($html);

        $this->assertSame(100, $data['stats']['required_points']);
        $this->assertSame('portal', $data['stats']['required_points_source']);
        $this->assertSame(90, $data['summary']['remaining_points']);
        $this->assertSame(10.0, $data['summary']['progress_percent']);
    }

    public function test_required_points_fall_back_to_config(): void
    {
        config(['portal.skkm.required_points' => 40]);

        $data = $this->extractFixture(self::FILLED_FIXTURE);

        $this->assertSame(40, $data['stats']['required_points']);
        $this->assertSame('config', $data['stats']['required_points_source']);
        $this->assertSame(30, $data['summary']['remaining_points']);
        $this->assertSame(25.0, $data['summary']['progress_percent']);
    }

    public function test_progress_is_capped_once_the_target_is_reached(): void
    {
        config(['portal.skkm.required_points' => 8]);

        $summary = $this->extractFixture(self::FILLED_FIXTURE)['summary'];

        $this->assertSame(0, $summary['remaining_points']);
        $this->assertSame(100.0, $summary['progress_percent']);
    }

    public function test_entry_names_mentioning_numbers_do_not_become_the_target(): void
    {
        $html = str_replace('Kegiatan Contoh Satu', 'Lomba target 100 poin', $this->fixture(self::FILLED_FIXTURE));

        $this->assertNull($this->extractHtml($html)['stats']['required_points']);
    }

    public function test_columns_are_mapped_by_label_when_the_portal_reorders_them(): void
    {
        $html = str_replace(
            ["<th>Nilai</th>\n<th>Status</th>", "<td>10</td>\n<td><span class=\"label label-success\">Valid</span></td>"],
            ["<th>Status</th>\n<th>Nilai</th>", "<td><span class=\"label label-success\">Valid</span></td>\n<td>10</td>"],
            $this->fixture(self::FILLED_FIXTURE)
        );

        $entry = $this->extractHtml($html)['entries'][0];

        $this->assertSame(10, $entry['points']);
        $this->assertSame('valid', $entry['status']);
    }

    public function test_placeholder_rows_are_not_entries(): void
    {
        $html = str_replace(
            "<tbody>\n</tbody>",
            "<tbody>\n<tr><td colspan=\"9\" class=\"text-center\">Belum ada data</td></tr>\n</tbody>",
            $this->fixture(self::EMPTY_FIXTURE)
        );

        $data = $this->extractHtml($html);

        $this->assertTrue($data['found']);
        $this->assertSame([], $data['entries']);
    }

    public function test_unrecognised_status_is_kept_raw(): void
    {
        $html = str_replace('<td>Tidak Valid</td>', '<td>Revisi</td>', $this->fixture(self::FILLED_FIXTURE));

        $entry = $this->extractHtml($html)['entries'][1];

        $this->assertSame('unknown', $entry['status']);
        $this->assertSame('Revisi', $entry['status_label']);
    }

    public function test_it_reads_the_portal_message_after_an_action(): void
    {
        $html = str_replace(
            "<strong style=\"margin-top: 4px;\" id=\"message\">\n</strong>",
            '<strong style="margin-top: 4px;" id="message">Data berhasil disimpan</strong>',
            $this->fixture(self::EMPTY_FIXTURE)
        );

        $this->assertSame('Data berhasil disimpan', $this->extractHtml($html)['message']);
    }

    public function test_empty_portal_shell_is_not_found_and_ignores_the_sidebar_and_navbar(): void
    {
        $fixture = $this->fixture(self::EMPTY_FIXTURE);
        $contentStart = strpos($fixture, '<div class="content">');
        $footerStart = strpos($fixture, '<footer class="footer">');
        $this->assertNotFalse($contentStart);
        $this->assertNotFalse($footerStart);

        $shell = substr($fixture, 0, $contentStart)
            ."<div class=\"content\">\n<div class=\"container-fluid\">\n</div>\n</div>\n"
            .substr($fixture, $footerStart);

        $data = $this->extractHtml($shell);

        $this->assertFalse($data['found']);
        $this->assertSame([], $data['entries']);
        $this->assertSame(['valid' => null, 'invalid' => null, 'pending' => null], $data['stats']['stat_counts']);
        $this->assertSame(['valid' => null, 'invalid' => null, 'pending' => null], $data['stats']['total_points']);
        $this->assertSame(['text' => null, 'is_met' => null], $data['graduation_status']);
        $this->assertNull($data['guide_path']);
        $this->assertNull($data['guide_year']);
        $this->assertFalse($data['add_available']);
        $this->assertNull($data['notice']);
        $this->assertNull($data['summary']['valid_points']);
        $this->assertNull($data['summary']['points_source']);
    }

    public function test_found_pages_are_cached_but_an_empty_shell_is_not(): void
    {
        $found = $this->extractorReturning($this->fixture(self::EMPTY_FIXTURE), 1);
        $found->extractSkkmData('found-session');
        $found->extractSkkmData('found-session');

        $fixture = $this->fixture(self::EMPTY_FIXTURE);
        $shell = substr($fixture, 0, (int) strpos($fixture, '<section class="content">')).'</div></div></div></body></html>';
        $notFound = $this->extractorReturning($shell, 2);

        $this->assertFalse($notFound->extractSkkmData('shell-session')['found']);
        $this->assertFalse($notFound->extractSkkmData('shell-session')['found']);
    }

    public function test_login_page_is_not_parsed_as_an_empty_list(): void
    {
        $extractor = $this->extractorReturning(
            '<html><head><title>Login MyCIC</title></head><body><form method="post" action="?mod=login">'
            .'<input type="text" name="txt_username"><input type="password" name="txt_password">'
            .'<input type="submit" value="Login"></form></body></html>'
        );

        $this->expectException(PortalScrapingException::class);

        $extractor->extractSkkmData('expired-session');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFixture(string $fixture): array
    {
        return $this->extractHtml($this->fixture($fixture));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractHtml(string $html): array
    {
        return $this->extractorReturning($html)->extractSkkmData('fake-session-token');
    }

    private function fixture(string $fixture): string
    {
        return (string) file_get_contents(base_path($fixture));
    }

    private function extractorReturning(string $html, ?int $expectedRequests = null): SkkmExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        /** @var SkkmExtractor&\Mockery\MockInterface $extractor */
        $extractor = Mockery::mock(SkkmExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $expectation = $extractor->shouldReceive('get')->andReturn($html);

        if ($expectedRequests !== null) {
            $expectation->times($expectedRequests);
        }

        return $extractor;
    }
}
