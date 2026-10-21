<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\AdvisingExtractor;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Tests\TestCase;
use Tests\Traits\InteractsWithPortalFixtures;

class AdvisingExtractorTest extends TestCase
{
    use InteractsWithPortalFixtures;

    private const FIXTURE = 'tests/Fixtures/portal/student_advising.html';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_extracts_the_dpa_and_krs_history_from_the_perwalian_page(): void
    {
        /** @var AdvisingExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(AdvisingExtractor::class, self::FIXTURE);

        $advising = $extractor->extractAdvisingData('fake-session-token');

        $this->assertTrue($advising['found']);
        $this->assertSame([
            'photo_path' => null,
            'program' => 'SISTEM INFORMASI - S1',
            'cohort' => '2025',
            'nim' => '20990000001',
            'name' => 'Mahasiswa Contoh',
            'status' => 'Aktif',
        ], $advising['student']);
        $this->assertSame([
            'name' => 'Dosen Pembimbing, M.Kom.',
            'nidn' => null,
            'phone' => '081200000000',
            'whatsapp' => '6281200000000',
            'email' => 'dosen@example.test',
        ], $advising['dpa']);
        $this->assertSame([
            ['krs_id' => 90004, 'academic_year' => '2026/2027', 'date' => '2026-09-23', 'semester' => 3, 'semester_is_regular' => true, 'semester_label' => 'Semester 3'],
            ['krs_id' => 90003, 'academic_year' => '2025/2026', 'date' => '2026-03-10', 'semester' => 2, 'semester_is_regular' => true, 'semester_label' => 'Semester 2'],
            ['krs_id' => 90001, 'academic_year' => '2025/2026', 'date' => '2025-09-25', 'semester' => 1, 'semester_is_regular' => true, 'semester_label' => 'Semester 1'],
            ['krs_id' => 90002, 'academic_year' => '2024/2025', 'date' => '2026-02-27', 'semester' => 'T', 'semester_is_regular' => false, 'semester_label' => 'Semester T (non-reguler, makna belum diketahui)'],
        ], $advising['krs_history']);
        $this->assertSame(90004, $advising['latest_krs_id']);
        $this->assertSame(3, $advising['latest_semester_number']);
        $this->assertFalse($advising['krs_add_action_detected']);
    }

    public function test_it_does_not_keep_birth_data_or_gender(): void
    {
        /** @var AdvisingExtractor $extractor */
        $extractor = $this->makeExtractorWithFixture(AdvisingExtractor::class, self::FIXTURE);

        $encoded = (string) json_encode($extractor->extractAdvisingData('fake-session-token'));

        $this->assertStringNotContainsString('Kota Contoh', $encoded);
        $this->assertStringNotContainsString('01-01-2005', $encoded);
        $this->assertStringNotContainsString('Perempuan', $encoded);
    }

    public function test_latest_krs_is_the_highest_numeric_semester_not_the_newest_date(): void
    {
        $html = $this->replaceOnce(
            $this->fixtureHtml(),
            "<td valign=\"top\"> 2024/2025 </td>\n<td valign=\"top\">Jumat, 27 Februari 2026</td>",
            "<td valign=\"top\"> 2026/2027 </td>\n<td valign=\"top\">Kamis, 01 Oktober 2026</td>"
        );
        $html = $this->replaceOnce($html, 'act=viewkrs&id=90002', 'act=viewkrs&id=90005');

        $advising = $this->makeExtractorWithHtml($html)->extractAdvisingData('fake-session-token');

        $lastRow = $advising['krs_history'][3];
        $this->assertSame(['krs_id' => 90005, 'date' => '2026-10-01', 'semester' => 'T'], [
            'krs_id' => $lastRow['krs_id'],
            'date' => $lastRow['date'],
            'semester' => $lastRow['semester'],
        ]);
        $this->assertSame(90004, $advising['latest_krs_id']);
        $this->assertSame(3, $advising['latest_semester_number']);
    }

    public function test_latest_krs_does_not_depend_on_row_order(): void
    {
        $html = $this->replaceOnce($this->fixtureHtml(), 'act=viewkrs&id=90004', 'act=viewkrs&id=TMP');
        $html = $this->replaceOnce($html, 'act=viewkrs&id=90003', 'act=viewkrs&id=90004');
        $html = $this->replaceOnce($html, 'act=viewkrs&id=TMP', 'act=viewkrs&id=90003');
        $html = $this->replaceOnce($html, '<td align="center" valign="top">3</td>', '<td align="center" valign="top">SEM2</td>');
        $html = $this->replaceOnce($html, '<td align="center" valign="top">2</td>', '<td align="center" valign="top">3</td>');
        $html = $this->replaceOnce($html, '<td align="center" valign="top">SEM2</td>', '<td align="center" valign="top">2</td>');

        $advising = $this->makeExtractorWithHtml($html)->extractAdvisingData('fake-session-token');

        $this->assertSame([2, 3, 1, 'T'], array_column($advising['krs_history'], 'semester'));
        $this->assertSame(90004, $advising['latest_krs_id']);
    }

    public function test_only_numeric_viewkrs_ids_are_kept(): void
    {
        $html = $this->replaceOnce($this->fixtureHtml(), 'act=viewkrs&id=90001', 'act=viewkrs&id=90001abc');

        $advising = $this->makeExtractorWithHtml($html)->extractAdvisingData('fake-session-token');

        $this->assertSame([90004, 90003, 90002], array_column($advising['krs_history'], 'krs_id'));
    }

    public function test_history_with_only_irregular_semesters_has_no_latest_krs(): void
    {
        $html = $this->replaceOnce($this->fixtureHtml(), '<td align="center" valign="top">3</td>', '<td align="center" valign="top">T</td>');
        $html = $this->replaceOnce($html, '<td align="center" valign="top">2</td>', '<td align="center" valign="top">T</td>');
        $html = $this->replaceOnce($html, '<td align="center" valign="top">1</td>', '<td align="center" valign="top"></td>');

        $advising = $this->makeExtractorWithHtml($html)->extractAdvisingData('fake-session-token');

        $this->assertNull($advising['latest_krs_id']);
        $this->assertNull($advising['latest_semester_number']);
        $this->assertNull($advising['krs_history'][2]['semester']);
        $this->assertFalse($advising['krs_history'][2]['semester_is_regular']);
    }

    public function test_dpa_placeholders_become_null(): void
    {
        $html = $this->replaceOnce($this->fixtureHtml(), '<td> Dosen Pembimbing, M.Kom.</td>', '<td> - </td>');
        $html = (string) preg_replace('/<a href="\/cdn-cgi\/l\/email-protection"[^>]*>.*?<\/a>/s', '-', $html);

        $advising = $this->makeExtractorWithHtml($html)->extractAdvisingData('fake-session-token');

        $this->assertSame(['name' => null, 'nidn' => null, 'phone' => '081200000000', 'whatsapp' => '6281200000000', 'email' => null], $advising['dpa']);

        $html = $this->replaceOnce($html, '<td> 081200000000</td>', '<td>-</td>');

        $advising = $this->makeExtractorWithHtml($html, 'other-session-token')->extractAdvisingData('other-session-token');

        $this->assertNull($advising['dpa']);
        $this->assertTrue($advising['found']);
    }

    public function test_a_real_photo_becomes_a_campus_file_path(): void
    {
        $html = $this->replaceOnce($this->fixtureHtml(), '../files/fotostudent/nopic.gif', '../files/fotostudent/20990000001.jpg');

        $advising = $this->makeExtractorWithHtml($html)->extractAdvisingData('fake-session-token');

        $this->assertSame('files/fotostudent/20990000001.jpg', $advising['student']['photo_path']);
    }

    public function test_a_filled_add_slot_is_reported(): void
    {
        $html = $this->replaceOnce(
            $this->fixtureHtml(),
            "<div class=\"linkadd\">\n</div>",
            "<div class=\"linkadd\">\n<a href=\"?mod=student&act=addkrs\"><button type=\"button\">Tambah KRS</button></a>\n</div>"
        );

        $advising = $this->makeExtractorWithHtml($html)->extractAdvisingData('fake-session-token');

        $this->assertTrue($advising['krs_add_action_detected']);
    }

    public function test_empty_portal_shell_is_not_found(): void
    {
        $html = (string) preg_replace(
            '/(<div class="content">\s*<div class="container-fluid">).*?(\s*<\/div>\s*<\/div>\s*<footer)/s',
            '$1$2',
            $this->fixtureHtml()
        );
        $this->assertStringNotContainsString('Histori KRS', $html);

        $advising = $this->makeExtractorWithHtml($html)->extractAdvisingData('fake-session-token');

        $this->assertFalse($advising['found']);
        $this->assertNull($advising['dpa']);
        $this->assertSame([], $advising['krs_history']);
        $this->assertNull($advising['latest_krs_id']);
    }

    public function test_login_page_throws(): void
    {
        $extractor = $this->makeExtractorWithHtml(
            '<!doctype html><html><head><title>MyUCIC - Login</title></head><body>'
            .'<form method="post" action="?mod=login&act=loginact"><input type="text" name="txt_username">'
            .'<input type="password" name="txt_password"></form></body></html>'
        );

        $this->expectException(PortalScrapingException::class);

        $extractor->extractAdvisingData('fake-session-token');
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

    private function makeExtractorWithHtml(string $html, string $sessionToken = 'fake-session-token'): AdvisingExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->with($sessionToken)->andReturn(new CookieJar);

        /** @var AdvisingExtractor&\Mockery\MockInterface $extractor */
        $extractor = Mockery::mock(AdvisingExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')
            ->with(Mockery::on(static fn (string $url): bool => str_contains($url, 'act=perwalian')))
            ->andReturn($html);

        return $extractor;
    }
}
