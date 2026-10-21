<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class PortalScrapingServiceHelpersTest extends TestCase
{
    private const DOSEN_EMAIL_HEX = '5a3e35293f341a3f223b372a363f742e3f292e';

    private const DOSEN_EMAIL_HEX_ALT = '3c58534f59527c59445d514c50591248594f48';

    private const BAAK_EMAIL_HEX = '214340404a614459404c514d440f55445255';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function singleDateProvider(): array
    {
        return [
            'day name, day month year' => ['Selasa, 20 Oktober 2026', '2026-10-20'],
            'hari prefix inside a sentence' => ['Pembayaran paling lambat pada Hari Senin, 24 Juni 2024, Jika tidak', '2024-06-24'],
            'upper case month' => ['17 SEPTEMBER 2026', '2026-09-17'],
            'mixed case month' => ['5 oKtObEr 2026', '2026-10-05'],
            'abbreviation okt' => ['5 Okt 2026', '2026-10-05'],
            'abbreviation agt with dot' => ['3 Agt. 2026', '2026-08-03'],
            'abbreviation sept' => ['12 Sept 2026', '2026-09-12'],
            'abbreviation nop' => ['9 Nop 2026', '2026-11-09'],
            'abbreviation des' => ['1 Des 2026', '2026-12-01'],
            'pebruari spelling' => ['14 Pebruari 2027', '2027-02-14'],
            'dash separated month name' => ['07-Jan-2026', '2026-01-07'],
            'jumat with typographic apostrophe' => ['Jum’at, 2 Oktober 2026', '2026-10-02'],
            'dd-mm-yyyy' => ['Batas 02-07-2026', '2026-07-02'],
            'dd/mm/yyyy' => ['18/07/2026', '2026-07-18'],
            'yyyy-mm-dd' => ['2026-07-02', '2026-07-02'],
            'non breaking spaces' => ["17\u{00A0}September\u{00A0}2026", '2026-09-17'],
            'nbsp entity' => ['17&nbsp;September&nbsp;2026', '2026-09-17'],
            'range returns its start' => ['KRS 17 s/d 19 September 2026', '2026-09-17'],
            'published meta line' => ['Kamis, 17 September 2026, Dipublish oleh : Staf BAAK (BAAK)', '2026-09-17'],
        ];
    }

    #[DataProvider('singleDateProvider')]
    public function test_it_parses_indonesian_dates(string $text, string $expected): void
    {
        $date = $this->service()->call('parseIndonesianDate', $text);

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame($expected.' 00:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Jakarta', $date->getTimezone()->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDateProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \u{00A0} "],
            'no date' => ['Tidak ada tanggal di sini'],
            'impossible day' => ['31 Februari 2026'],
            'impossible numeric day' => ['32/01/2026'],
            'impossible numeric month' => ['12/13/2026'],
            'unknown month' => ['12 Brumaire 2026'],
            'academic year is not a date' => ['Semester Ganjil 2026-2027'],
            'day without year' => ['17 September'],
        ];
    }

    #[DataProvider('invalidDateProvider')]
    public function test_it_returns_null_for_text_without_a_valid_date(string $text): void
    {
        $this->assertNull($this->service()->call('parseIndonesianDate', $text));
        $this->assertNull($this->service()->call('parseIndonesianDateRange', $text));
        $this->assertSame([], $this->service()->call('extractIndonesianDates', $text));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function dateRangeProvider(): array
    {
        return [
            'full range with s.d.' => ['dari Tanggal 17 September 2026 s.d. 31 Oktober 2026)', '2026-09-17', '2026-10-31'],
            'full range with sd' => ['17 September 2026 sd 31 Oktober 2026', '2026-09-17', '2026-10-31'],
            'full range with s/d' => ['17 September 2026 s/d 31 Oktober 2026', '2026-09-17', '2026-10-31'],
            'full range with dash' => ['17 September 2026 - 31 Oktober 2026', '2026-09-17', '2026-10-31'],
            'full range with en dash' => ['17 September 2026 – 31 Oktober 2026', '2026-09-17', '2026-10-31'],
            'full range with sampai dengan' => ['17 September 2026 sampai dengan 31 Oktober 2026', '2026-09-17', '2026-10-31'],
            'full range with hingga' => ['17 September 2026 hingga 31 Oktober 2026', '2026-09-17', '2026-10-31'],
            'full range with day names' => ['Kamis, 17 September 2026 S.D. Hari Sabtu, 31 Oktober 2026', '2026-09-17', '2026-10-31'],
            'numeric range' => ['Periode Pengisian Data Dukung PDM 02/07/2026 s/d 18/07/2026', '2026-07-02', '2026-07-18'],
            'numeric dash range' => ['02-07-2026 - 18-07-2026', '2026-07-02', '2026-07-18'],
            'day only start with nbsp' => ["Her-Registrasi dan KRS 17\u{00A0}s/d 19 September 2026, ada", '2026-09-17', '2026-09-19'],
            'day only start with nbsp entity' => ['KRS 17&nbsp;s/d 19 September 2026', '2026-09-17', '2026-09-19'],
            'day only start with dash' => ['16-20 Oktober 2026', '2026-10-16', '2026-10-20'],
            'day and month start with nbsp' => ["dari tanggal 20 Juli\u{00A0} - 16 Agustus 2026", '2026-07-20', '2026-08-16'],
            'day and month start without year' => ['20 Agustus - 29 Agustus 2026', '2026-08-20', '2026-08-29'],
            'day only start crossing a month' => ['28 s/d 3 Oktober 2026', '2026-09-28', '2026-10-03'],
            'day only start crossing a year' => ['30 s/d 2 Januari 2027', '2026-12-30', '2027-01-02'],
            'day and month start crossing a year' => ['28 Desember - 3 Januari 2027', '2026-12-28', '2027-01-03'],
            'single day range' => ['5 s/d 5 Oktober 2026', '2026-10-05', '2026-10-05'],
        ];
    }

    #[DataProvider('dateRangeProvider')]
    public function test_it_parses_indonesian_date_ranges(string $text, string $start, string $end): void
    {
        $range = $this->service()->call('parseIndonesianDateRange', $text);

        $this->assertIsArray($range);
        $this->assertSame($start, $range['start']->toDateString());
        $this->assertSame($end, $range['end']->toDateString());
        $this->assertSame('Asia/Jakarta', $range['start']->getTimezone()->getName());
        $this->assertSame('Asia/Jakarta', $range['end']->getTimezone()->getName());
    }

    public function test_a_single_date_is_not_a_range(): void
    {
        $this->assertNull($this->service()->call('parseIndonesianDateRange', 'Kamis, 17 September 2026'));
    }

    public function test_ordinals_and_times_before_a_date_do_not_start_a_range(): void
    {
        $service = $this->service();

        $this->assertNull($service->call('parseIndonesianDateRange', 'Pembayaran Tahap 4 - 24 Juni 2024'));
        $this->assertSame('2024-06-24', $service->call('parseIndonesianDate', 'Pembayaran Tahap 4 - 24 Juni 2024')->toDateString());
        $this->assertNull($service->call('parseIndonesianDateRange', 'Pukul 08.30 - 12 Oktober 2026'));
        $this->assertNull($service->call('parseIndonesianDateRange', 'Pertemuan ke 3 - 12 Oktober 2026'));
    }

    public function test_an_impossible_inherited_start_date_drops_the_range(): void
    {
        $service = $this->service();

        $this->assertNull($service->call('parseIndonesianDateRange', '31 s/d 5 Oktober 2026'));
        $this->assertSame('2026-10-05', $service->call('parseIndonesianDate', '31 s/d 5 Oktober 2026')->toDateString());
    }

    public function test_it_extracts_every_distinct_date_from_free_text_in_reading_order(): void
    {
        $text = 'Pendaftaran Semester Pendek (SP) dari tanggal 20 Juli&nbsp; - 16 Agustus 2026 '
            ."Pengisian KRS dan Pembayaran SP Tanggal 19 Agustus 2026\n"
            .'Waktu Pelaksanaan SP dimulai Tanggal 20 Agustus - 29 Agustus 2026. '
            .'Semester Ganjil 2026-2027, ingat lagi tanggal 19/08/2026.';

        $dates = $this->service()->call('extractIndonesianDates', $text);

        $this->assertSame(
            ['2026-07-20', '2026-08-16', '2026-08-19', '2026-08-20', '2026-08-29'],
            array_map(static fn (CarbonImmutable $date): string => $date->toDateString(), $dates)
        );
        $this->assertContainsOnlyInstancesOf(CarbonImmutable::class, $dates);
    }

    public function test_it_extracts_every_range_from_free_text(): void
    {
        $text = 'Periode 1: 01/07/2026 - 05/07/2026, Periode 2: 6 s.d. 10 Juli 2026, bayar 12 Juli 2026.';

        $ranges = $this->service()->call('extractIndonesianDateRanges', $text);

        $this->assertSame(
            [['2026-07-01', '2026-07-05'], ['2026-07-06', '2026-07-10']],
            array_map(static fn (array $range): array => [$range['start']->toDateString(), $range['end']->toDateString()], $ranges)
        );
    }

    public function test_campus_file_paths_are_relative_to_the_portal_and_only_for_static_files(): void
    {
        $service = $this->service();

        $this->assertSame('files/skkm/Pedoman_SKKM_2024.pdf', $service->call('campusFilePath', '../files/skkm/Pedoman_SKKM_2024.pdf'));
        $this->assertSame('files/Panduan_Contoh.pdf', $service->call('campusFilePath', 'https://my.cic.ac.id/portal/files/Panduan_Contoh.pdf'));
        $this->assertSame('files/fotostudent/foto contoh.jpg', $service->call('campusFilePath', '../files/fotostudent/foto%20contoh.jpg'));
        $this->assertNull($service->call('campusFilePath', '?mod=student&act=delskkm&id=1'));
        $this->assertNull($service->call('campusFilePath', '../files/../mahasiswa/index.php'));
        $this->assertNull($service->call('campusFilePath', 'https://files.example.test/files/skkm/a.pdf'));
        $this->assertNull($service->call('campusFilePath', ''));
    }

    public function test_it_decodes_cloudflare_email_hex(): void
    {
        $service = $this->service();

        $this->assertSame('dosen@example.test', $service->call('decodeCloudflareEmail', self::DOSEN_EMAIL_HEX));
        $this->assertSame('dosen@example.test', $service->call('decodeCloudflareEmail', self::DOSEN_EMAIL_HEX_ALT));
        $this->assertNull($service->call('decodeCloudflareEmail', 'zz'));
        $this->assertNull($service->call('decodeCloudflareEmail', '5a3'));
        $this->assertNull($service->call('decodeCloudflareEmail', '5a37'));
        $this->assertNull($service->call('decodeCloudflareEmail', ''));
    }

    public function test_it_extracts_cloudflare_email_from_an_obfuscated_anchor(): void
    {
        $crawler = new Crawler('<table><tr><td>Email</td><td>:</td><td><a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="'
            .self::DOSEN_EMAIL_HEX.'">[email&#160;protected]</a></td></tr></table>');

        $this->assertSame('dosen@example.test', $this->service()->call('extractCloudflareEmail', $crawler->filter('td')->eq(2)));
    }

    public function test_it_extracts_cloudflare_email_from_a_span_inside_a_protected_link(): void
    {
        $crawler = new Crawler('<p>kirim data ke email&nbsp;<a href="/cdn-cgi/l/email-protection#'.self::DOSEN_EMAIL_HEX_ALT.'">'
            .'<span class="__cf_email__" data-cfemail="'.self::DOSEN_EMAIL_HEX.'">[email&nbsp;protected]</span></a>&nbsp;jika sudah</p>');

        $service = $this->service();

        $this->assertSame('dosen@example.test', $service->call('extractCloudflareEmail', $crawler->filter('p')));
        $this->assertSame(['dosen@example.test'], $service->call('extractCloudflareEmails', $crawler->filter('p')));
        $this->assertSame('dosen@example.test', $service->call('extractCloudflareEmail', $crawler->filter('span')));
    }

    public function test_it_falls_back_to_the_protected_link_fragment(): void
    {
        $crawler = new Crawler('<div><a href="https://my.cic.ac.id/cdn-cgi/l/email-protection#'.self::BAAK_EMAIL_HEX.'">Kirim email</a></div>');

        $this->assertSame('baak@example.test', $this->service()->call('extractCloudflareEmail', $crawler->filter('div')));
    }

    public function test_it_lists_distinct_cloudflare_emails_in_document_order(): void
    {
        $crawler = new Crawler('<td>'
            .'<a href="/cdn-cgi/l/email-protection#'.self::BAAK_EMAIL_HEX.'">BAAK</a>'
            .'<a href="/cdn-cgi/l/email-protection#'.self::DOSEN_EMAIL_HEX_ALT.'"><span class="__cf_email__" data-cfemail="'.self::DOSEN_EMAIL_HEX.'">[email protected]</span></a>'
            .'<span data-cfemail="'.self::BAAK_EMAIL_HEX.'">[email protected]</span>'
            .'<span data-cfemail="zz">[email protected]</span>'
            .'</td>');

        $this->assertSame(
            ['baak@example.test', 'dosen@example.test'],
            $this->service()->call('extractCloudflareEmails', $crawler->filter('td'))
        );
    }

    public function test_it_returns_no_cloudflare_email_when_none_is_present(): void
    {
        $crawler = new Crawler('<div><a href="mailto:someone@example.test">someone@example.test</a></div>');
        $service = $this->service();

        $this->assertNull($service->call('extractCloudflareEmail', $crawler->filter('div')));
        $this->assertSame([], $service->call('extractCloudflareEmails', $crawler->filter('div')));
        $this->assertNull($service->call('extractCloudflareEmail', $crawler->filter('section')));
    }

    public function test_main_content_excludes_the_portal_sidebar_and_navbar(): void
    {
        $crawler = new Crawler($this->studentPage('<div class="card"><p>Isi halaman</p></div>'));

        $content = $this->service()->call('mainContent', $crawler);

        $this->assertSame(1, $content->count());
        $this->assertStringContainsString('Isi halaman', $content->text());
        $this->assertSame(0, $content->filter('form[action*="loginact.php"]')->count());
        $this->assertSame(0, $content->filter('input[name="id"]')->count());
        $this->assertSame(0, $content->filter('.sidebar, .navbar-brand, .footer')->count());
        $this->assertStringNotContainsString('Reg & KRS Online', $content->text());
        $this->assertStringNotContainsString('SEMESTER GANJIL', $content->text());
    }

    public function test_main_content_returns_the_outer_content_when_contents_are_nested(): void
    {
        $crawler = new Crawler($this->studentPage(
            '<div class="card"><div class="content table-responsive table-full-width"><table><tr><td>Satu</td></tr></table></div>'
            .'<div class="content table-responsive table-full-width"><table><tr><td>Dua</td></tr></table></div></div>'
        ));

        $content = $this->service()->call('mainContent', $crawler);

        $this->assertSame(1, $content->count());
        $this->assertSame(2, $content->filter('.content.table-responsive')->count());
    }

    public function test_main_content_uses_the_sipandai_app_content(): void
    {
        $crawler = new Crawler(
            '<html><body><header class="app-header"><div id="app-sidepanel" class="app-sidepanel">'
            .'<div class="app-branding"> SiPANDAI 2.0<br> Senin, 05 Januari 2026 </div>'
            .'<a class="nav-link" href="?mod=sipandai&amp;act=score">Kartu Hasil Studi</a></div></header>'
            .'<div class="app-wrapper"><div class="app-content pt-3 p-md-3 p-lg-4"><div class="container-xl">'
            .'<div class="content table-responsive table-full-width"><table><tr><td>Satu</td></tr></table></div>'
            .'<div class="content table-responsive table-full-width"><table><tr><td>Dua</td></tr></table></div>'
            .'</div></div></div></body></html>'
        );

        $content = $this->service()->call('mainContent', $crawler);

        $this->assertSame(2, $content->filter('.content')->count());
        $this->assertStringNotContainsString('Kartu Hasil Studi', $content->text());
        $this->assertStringNotContainsString('SiPANDAI 2.0', $content->text());
    }

    public function test_main_content_falls_back_to_content_then_body(): void
    {
        $service = $this->service();

        $withContent = $service->call('mainContent', new Crawler('<html><body><nav>Menu</nav><div class="content"><p>Isi</p></div></body></html>'));
        $bodyOnly = $service->call('mainContent', new Crawler('<html><body><p>Hanya body</p></body></html>'));

        $this->assertSame('Isi', $withContent->text());
        $this->assertSame('body', $bodyOnly->nodeName());
        $this->assertSame('Hanya body', $bodyOnly->text());
    }

    public function test_it_extracts_an_expired_payment_notice_from_the_announcement_header(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));

        $crawler = new Crawler($this->studentPage(
            '<div class="card "><div class="header"><h4 class="title"><b>Pengumuman Universitas Catur Insan Cendekia</b></h4>'
            ."<p class=\"category\"></p>\n<marquee>\n<h4>\n<font color=\"red\">\n"
            .'<b>Kewajiban Pembayaran Kuliah Tahap ke 4 Tahun Berjalan, Pembayaran paling lambat pada Hari Senin, 24 Juni 2024, '
            .'Jika tidak melakukan kewajiban pembayaran tahap ke 4 pada tahun berjalan, akan dilakukan pemblokiran sistem MyCIC.</b>. </font></h4>'
            ."\n</marquee>\n</div></div>"
        ));
        $service = $this->service();

        $notice = $service->call('extractPortalNotice', $service->call('mainContent', $crawler), 'auto');

        $this->assertSame([
            'text' => 'Kewajiban Pembayaran Kuliah Tahap ke 4 Tahun Berjalan, Pembayaran paling lambat pada Hari Senin, 24 Juni 2024, '
                .'Jika tidak melakukan kewajiban pembayaran tahap ke 4 pada tahun berjalan, akan dilakukan pemblokiran sistem MyCIC.',
            'dates' => ['2024-06-24'],
            'is_future' => false,
            'kind' => 'payment',
        ], $notice);
    }

    public function test_it_extracts_the_krs_period_notice_with_a_future_end_date(): void
    {
        $html = '<table><tr><td colspan="3"><marquee><font color="red"> (Periode Perwalian KRS dan Perbaikan KRS Online T.A 2026/2027 '
            .'Semester GANJIL dari Tanggal 17 September 2026 s.d. 31 Oktober 2026) silahkan click menu KRS Online. </font></marquee></td></tr></table>';
        $service = $this->service();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
        $notice = $service->call('extractPortalNotice', new Crawler($html), 'krs_period');

        $this->assertSame('krs_period', $notice['kind']);
        $this->assertSame(['2026-09-17', '2026-10-31'], $notice['dates']);
        $this->assertTrue($notice['is_future']);
        $this->assertStringStartsWith('(Periode Perwalian KRS', $notice['text']);
        $this->assertStringEndsWith('menu KRS Online.', $notice['text']);

        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:30:00', 'Asia/Jakarta'));
        $this->assertTrue($service->call('extractPortalNotice', new Crawler($html), 'krs_period')['is_future']);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:05:00', 'Asia/Jakarta'));
        $this->assertFalse($service->call('extractPortalNotice', new Crawler($html), 'krs_period')['is_future']);
    }

    public function test_a_notice_without_dates_is_never_future(): void
    {
        $notice = $this->service()->call(
            'extractPortalNotice',
            new Crawler('<div><marquee><font color="red">Pastikan Status Absensi Anda sudah benar.</font></marquee></div>'),
            'auto'
        );

        $this->assertSame([
            'text' => 'Pastikan Status Absensi Anda sudah benar.',
            'dates' => [],
            'is_future' => false,
            'kind' => 'attendance',
        ], $notice);
    }

    public function test_it_skips_empty_marquees_and_classifies_general_notices(): void
    {
        $notice = $this->service()->call(
            'extractPortalNotice',
            new Crawler("<div><marquee> \u{00A0} </marquee><marquee><h1><font color=\"red\">Selamat mengerjakan ujian UAS.</font></h1></marquee></div>"),
            'auto'
        );

        $this->assertSame('Selamat mengerjakan ujian UAS.', $notice['text']);
        $this->assertSame('general', $notice['kind']);
    }

    public function test_it_returns_no_notice_without_a_marquee(): void
    {
        $service = $this->service();

        $this->assertNull($service->call('extractPortalNotice', new Crawler('<div><p>Tidak ada teks berjalan</p></div>'), 'general'));
        $this->assertNull($service->call('extractPortalNotice', new Crawler('<div><marquee>   </marquee></div>'), 'general'));
        $this->assertNull($service->call('extractPortalNotice', (new Crawler('<div></div>'))->filter('section'), 'general'));
    }

    public function test_notices_in_the_sidebar_are_ignored_when_scoped_to_main_content(): void
    {
        $html = str_replace(
            '<ul class="nav">',
            '<marquee>Pembayaran tahap 1 paling lambat 30 Oktober 2026</marquee><ul class="nav">',
            $this->studentPage('<div class="card"><p>Tanpa teks berjalan</p></div>')
        );
        $service = $this->service();

        $this->assertNull($service->call('extractPortalNotice', $service->call('mainContent', new Crawler($html)), 'auto'));
    }

    private function studentPage(string $content): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><title>Portal Mahasiswa</title></head>
<body>
 <div class="wrapper">
<div class="sidebar" data-color="purple" data-image="../assets/img/sidebar-5.jpg">
<div class="sidebar-wrapper">
<div class="logo">
<a href="" class="simple-text"> Senin, 05 Januari 2026 </a>
</div>
<ul class="nav">
<li class="active">
<a href="?mod=student&amp;act=dashboard">
<i class="pe-7s-home"></i>
<p>Dashboard</p>
</a>
</li>
<li>
<a href="?mod=student&amp;act=pengumuman">
<i class="pe-7s-speaker"></i>
<p>Pengumuman</p>
</a>
</li>
<li>
<a href="?mod=student&amp;act=credit">
<i class="pe-7s-news-paper"></i>
<p>Reg &amp; KRS Online</p>
</a>
</li>
<li>
<i class="pe-7s-alarm"></i>
<form class="login100-form validate-form" method="post" action="../sipandai/?mod=login&amp;act=loginact.php">
<input type="hidden" name="id" value="">
<input type="hidden" name="p" value="">
<input type="hidden" name="who" value="">
<input type="hidden" name="ak" value="">
<p>
<input style="font-size: 11pt; color: white; font-weight:" class="btn app-btn-primary w-100 theme-btn mx-auto" type="submit" value="SiPANDAI"></p>
</form>
</li>
<li>
<a href="?mod=student&amp;act=skkm">
<i class="pe-7s-map-2"></i>
<p>SIREKA (SKKM)</p>
</a>
</li>
</ul>
</div>
</div>
<div class="main-panel">
<nav class="navbar navbar-default navbar-fixed">
<div class="container-fluid">
<div class="navbar-header">
<a class="navbar-brand" href="#">PORTAL MyCIC T.A 2026/2027 SEMESTER GANJIL</a>
</div>
</div>
</nav>
<div class="content">
<div class="container-fluid">
{$content}
</div>
</div>
<footer class="footer">
<div class="container-fluid">
<p class="copyright pull-right"> © <a href="https://www.cic.ac.id">Universitas Catur Insan Cendekia - Pusat Data &amp; Informasi.</a></p>
</div>
</footer>
</div>
</div>
</body>
</html>
HTML;
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
