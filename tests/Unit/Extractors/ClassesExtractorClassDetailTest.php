<?php

declare(strict_types=1);

namespace Tests\Unit\Extractors;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalArchiveGuard;
use App\Services\PortalSessionManager;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Tests\TestCase;

/**
 * The SIPANDAI class page (act=download) as the live portal sends it.
 */
class ClassesExtractorClassDetailTest extends TestCase
{
    private const FIXTURE = 'tests/Fixtures/portal/sipandai_course_detail.html';

    /**
     * Cloudflare encoding of dosen.empat@example.test with key 0x5a
     */
    private const ENCODED_EMAIL = '5a3e35293f34743f372a3b2e1a3f223b372a363f742e3f292e';

    private const PARAMS = ['id' => '1234', 'dosen_id' => '5678', 'course_id' => '8987'];

    /**
     * @var list<string>
     */
    private array $requestedUrls = [];

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_real_class_page_is_class_info_and_found(): void
    {
        $detail = $this->extractor($this->fixture())->extractClassDetail('fake-session-token', self::PARAMS);

        $this->assertSame('class_info', $detail['content_type']);
        $this->assertTrue($detail['found']);
        $this->assertSame('Enterprise Resource Planning', $detail['course_info']['name']);
    }

    public function test_the_average_assignment_score_is_read_from_its_colspan_row(): void
    {
        $history = $this->extractor($this->fixture())->extractClassDetail('fake-session-token', self::PARAMS)['assignment_history'];

        $this->assertCount(3, $history['assignments']);
        $this->assertSame('0', $history['average_score']);
    }

    public function test_a_class_page_without_an_average_row_has_no_average(): void
    {
        $html = (string) preg_replace('~<tr>\s*<td align="right" colspan="4">\s*<h6> RATA-RATA NILAI TUGAS </h6>.*?</tr>~s', '', $this->fixture(), 1, $count);
        $this->assertSame(1, $count);

        $history = $this->extractor($html)->extractClassDetail('fake-session-token', self::PARAMS)['assignment_history'];

        $this->assertCount(3, $history['assignments']);
        $this->assertNull($history['average_score']);
    }

    public function test_the_empty_page_shell_of_an_unknown_class_is_not_found_and_not_cached(): void
    {
        $fixture = $this->fixture();
        $shell = substr($fixture, 0, (int) strpos($fixture, '<div class="app-wrapper">'))
            .'<div class="app-wrapper"><div class="app-content"><h4 class="title"><a href="?mod=sipandai&act=dashboardmahasiswa">Beranda</a></h4>'
            .'<table class="table"><tr><td></td></tr></table></div></div></body></html>';
        $extractor = $this->extractor($shell);

        $detail = $extractor->extractClassDetail('fake-session-token', self::PARAMS);
        $extractor->extractClassDetail('fake-session-token', self::PARAMS);

        $this->assertFalse($detail['found'], 'The page meta and sidebar name a lecturer, so only parsed content counts.');
        $this->assertCount(2, $this->requestedUrls, 'An empty shell is asked for again, never served from the cache.');
    }

    public function test_a_page_shell_that_prints_its_info_template_with_empty_values_is_not_found(): void
    {
        $fixture = $this->fixture();
        $shell = substr($fixture, 0, (int) strpos($fixture, '<div class="app-wrapper">'))
            .'<div class="app-wrapper"><div class="app-content"><div class="card">'
            .'<div class="header"><h4 class="title"><a href="?mod=sipandai&act=dashboardmahasiswa">Beranda </a> >Join Kelas > </h4></div>'
            .'<div class="content table-responsive table-full-width"><table class="table">'
            .'<tr><td width="40%">Nama Dosen</td><td>:</td><td><b></b></td></tr>'
            .'<tr><td>Nomor Kontak Dosen</td><td>:</td><td><b> / </b></td></tr>'
            .'<tr><td>Nama Kelas</td><td>:</td><td><b></b></td></tr>'
            .'<tr><td>Nama Mata Kuliah</td><td>:</td><td><b> -  -  SKS </b></td></tr>'
            .'</table></div></div></div></div></body></html>';
        $extractor = $this->extractor($shell);

        $detail = $extractor->extractClassDetail('fake-session-token', self::PARAMS);
        $extractor->extractClassDetail('fake-session-token', self::PARAMS);

        $this->assertSame('', $detail['course_info']['code']);
        $this->assertFalse($detail['found'], 'The " -  -  SKS" template of an empty info row names no course.');
        $this->assertCount(2, $this->requestedUrls, 'The empty shell is never served from the cache.');
    }

    public function test_a_course_known_only_by_its_name_is_still_found(): void
    {
        $html = str_replace(
            '<td><b>SIE053004 - Enterprise Resource Planning - 3 SKS </b></td>',
            '<td><b>Enterprise Resource Planning</b></td>',
            $this->fixture()
        );
        $html = (string) preg_replace('~<div class="row">\s*<div class="col-md-12">\s*<div class="card">\s*<div class="header">\s*<h6 class="title">Presensi.*$~s', '</body></html>', $html, 1, $count);
        $this->assertSame(1, $count);

        $detail = $this->extractor($html)->extractClassDetail('fake-session-token', self::PARAMS);

        $this->assertSame([], $detail['attendance_info']['meetings']);
        $this->assertSame([], $detail['course_materials']);
        $this->assertSame('Enterprise Resource Planning', $detail['course_info']['name']);
        $this->assertTrue($detail['found']);
    }

    public function test_the_archive_never_trusts_the_empty_page_shell(): void
    {
        $guard = app(PortalArchiveGuard::class);
        $real = $this->extractor($this->fixture())->extractClassDetail('fake-session-token', self::PARAMS);
        unset($real['_sync']);
        $shell = [...$real, 'course_info' => ['name' => ''], 'course_materials' => [], 'attendance_info' => ['meetings' => []], 'found' => false];
        $cachedBeforeTheFlag = $real;
        unset($cachedBeforeTheFlag['found']);

        $this->assertTrue($guard->isReadable('class_detail', $real));
        $this->assertFalse($guard->isReadable('class_detail', $shell));
        $this->assertTrue($guard->isReadable('class_detail', $cachedBeforeTheFlag));
        $this->assertSame('requires', $guard->inspect('class_detail', $real, $shell)[0]['rule'] ?? null);
    }

    public function test_a_cloudflare_protected_lecturer_email_on_the_class_page_is_decoded(): void
    {
        $html = str_replace(
            '<td><b>081200000005 / dosen.empat@example.test</b></td>',
            '<td><b>081200000005 / <a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="'.self::ENCODED_EMAIL.'">[email&#160;protected]</a></b></td>',
            $this->fixture()
        );

        $lecturer = $this->extractor($html)->extractClassDetail('fake-session-token', self::PARAMS)['lecturer_info'];

        $this->assertSame('Dosen Contoh Empat, M.Kom.', $lecturer['name']);
        $this->assertSame('081200000005 / dosen.empat@example.test', $lecturer['contact_info']);
        $this->assertSame('081200000005', $lecturer['contact']);
        $this->assertSame('dosen.empat@example.test', $lecturer['email']);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(base_path(self::FIXTURE));
    }

    private function extractor(string $html): ClassesExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(ClassesExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $extractor->shouldReceive('get')->andReturnUsing(function (string $url) use ($html): string {
            $this->requestedUrls[] = $url;

            return $html;
        });

        return $extractor;
    }
}
