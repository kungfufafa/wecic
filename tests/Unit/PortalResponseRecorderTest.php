<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalResponseRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

/**
 * F114: real portal answers to write actions are kept only sanitized, only
 * behind the debug switch, and never in production unless allowed.
 */
class PortalResponseRecorderTest extends TestCase
{
    private const NIM = '20231234567';

    private const COOKIE = 'q1w2e3r4t5y6u7i8o9p0';

    private const SESSION_TOKEN = 'mycic-session-token-0123456789abcdef';

    private string $capturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capturePath = storage_path('framework/testing/portal-captures-'.Str::random(12));
        config([
            'portal.debug.record_action_responses' => false,
            'portal.debug.allow_in_production' => false,
            'portal.debug.capture_path' => $this->capturePath,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->capturePath);

        parent::tearDown();
    }

    public function test_the_student_name_is_replaced_in_text_and_attributes_keeping_its_case(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <div class="user" title="Budi Santoso">BUDI SANTOSO</div>
            <p>Selamat datang, Budi   Santoso. <i>budi santoso</i></p>
            HTML);

        $this->assertStringNotContainsStringIgnoringCase('budi', $sanitized);
        $this->assertStringNotContainsStringIgnoringCase('santoso', $sanitized);
        $this->assertStringContainsString('title="Mahasiswa Uji"', $sanitized);
        $this->assertStringContainsString('>MAHASISWA UJI</div>', $sanitized);
        $this->assertStringContainsString('Selamat datang, Mahasiswa Uji.', $sanitized);
        $this->assertStringContainsString('<i>mahasiswa uji</i>', $sanitized);
    }

    public function test_the_nim_and_username_are_replaced_without_touching_longer_numbers(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <span class="nim">20231234567</span>
            <input type="text" name="nim" value="20231234567" readonly>
            <a href="../files/tugasmhs/9195-UTS-20231234567-785.pdf">Open Jawaban Ujian</a>
            <p>Nomor tagihan 120231234567890</p>
            HTML);

        $this->assertStringNotContainsString('>20231234567<', $sanitized);
        $this->assertStringContainsString('<span class="nim">'.PortalResponseRecorder::NIM_PLACEHOLDER.'</span>', $sanitized);
        $this->assertStringContainsString('value="'.PortalResponseRecorder::NIM_PLACEHOLDER.'"', $sanitized);
        $this->assertStringContainsString('9195-UTS-'.PortalResponseRecorder::NIM_PLACEHOLDER.'-785.pdf', $sanitized);
        $this->assertStringContainsString('120231234567890', $sanitized);
    }

    public function test_email_addresses_become_numbered_placeholders_the_same_address_always_the_same(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <a href="mailto:budi.santoso@student.cic.ac.id">budi.santoso@student.cic.ac.id</a>
            <a href="mailto:dosen.a@cic.ac.id">Dosen.A@cic.ac.id</a>
            <a href="mailto:dosen.b@cic.ac.id">dosen.b@cic.ac.id</a>
            <a href="?mod=sipandai&amp;to=dosen.a%40cic.ac.id">kirim</a>
            <img src="logo@2x.png">
            HTML);

        $this->assertStringNotContainsString('cic.ac.id', $sanitized);
        $this->assertSame(2, substr_count($sanitized, PortalResponseRecorder::STUDENT_EMAIL_PLACEHOLDER));
        $this->assertSame(2, substr_count($sanitized, 'surel1@example.test'));
        $this->assertSame(2, substr_count($sanitized, 'surel2@example.test'));
        $this->assertStringContainsString('to=surel1%40example.test', $sanitized);
        $this->assertStringContainsString('src="logo@2x.png"', $sanitized);
    }

    public function test_cloudflare_protected_email_addresses_are_replaced_and_still_decode(): void
    {
        $hex = $this->cloudflareHex('dosen.c@cic.ac.id', 0x5A);

        $sanitized = $this->sanitize(<<<HTML
            <a href="/cdn-cgi/l/email-protection#{$hex}"><span class="__cf_email__" data-cfemail="{$hex}">[email&#160;protected]</span></a>
            HTML);

        preg_match('/data-cfemail="([0-9a-f]+)"/', $sanitized, $attribute);
        preg_match('/email-protection#([0-9a-f]+)"/', $sanitized, $link);

        $this->assertStringNotContainsString($hex, $sanitized);
        $this->assertSame('surel1@example.test', $this->decodeCloudflareHex($attribute[1]));
        $this->assertSame('surel1@example.test', $this->decodeCloudflareHex($link[1]));
        $this->assertSame('5a', substr($attribute[1], 0, 2));
    }

    public function test_phone_numbers_become_numbered_placeholders_keeping_their_prefix(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <p>HP: 0812-3456-7890</p>
            <a href="https://wa.me/6281234567890">WhatsApp</a>
            <p>Dosen: +62 857 1111 2222, kantor 0857.1111.2222</p>
            <p>Biaya Rp 1.081.250, tanggal 08-12-2024, kode 0812345</p>
            HTML);

        $this->assertStringNotContainsString('3456', $sanitized);
        $this->assertStringNotContainsString('1111', $sanitized);
        $this->assertStringContainsString('HP: 081200000001', $sanitized);
        $this->assertStringContainsString('wa.me/6281200000001', $sanitized);
        $this->assertStringContainsString('Dosen: +6281200000002, kantor 081200000002', $sanitized);
        $this->assertStringContainsString('Biaya Rp 1.081.250, tanggal 08-12-2024, kode 0812345', $sanitized);
    }

    public function test_numbered_placeholders_already_in_the_page_are_not_handed_out_again(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <p>surel1@example.test, 081200000001, 081200000003</p>
            <p>dosen.z@cic.ac.id, 0857-2222-3333, 0857-4444-5555</p>
            HTML);

        $this->assertStringContainsString('<p>surel1@example.test, 081200000001, 081200000003</p>', $sanitized);
        $this->assertStringContainsString('<p>surel2@example.test, 081200000002, 081200000004</p>', $sanitized);
    }

    public function test_session_cookies_and_ids_are_replaced_wherever_they_appear(): void
    {
        $cookie = self::COOKIE;
        $token = self::SESSION_TOKEN;

        $sanitized = $this->sanitize(<<<HTML
            <form action="?mod=sipandai&amp;act=sentujian&amp;PHPSESSID={$cookie}" method="post"></form>
            <script>document.cookie = "PHPSESSID={$cookie}; path=/";</script>
            <a href="?mod=sipandai&amp;sid=abcdef0123456789">lanjut</a>
            <p>{$token}</p>
            <a href="?mod=sipandai&amp;act=actabsen&amp;modulesid=30216&amp;courseid=8837">absen</a>
            HTML);

        $this->assertStringNotContainsString($cookie, $sanitized);
        $this->assertStringNotContainsString($token, $sanitized);
        $this->assertStringNotContainsString('abcdef0123456789', $sanitized);
        $this->assertStringContainsString('PHPSESSID='.PortalResponseRecorder::COOKIE_PLACEHOLDER.'" method="post"', $sanitized);
        $this->assertStringContainsString('"PHPSESSID='.PortalResponseRecorder::COOKIE_PLACEHOLDER.'; path=/"', $sanitized);
        $this->assertStringContainsString('sid='.PortalResponseRecorder::COOKIE_PLACEHOLDER, $sanitized);
        $this->assertStringContainsString('<p>'.PortalResponseRecorder::COOKIE_PLACEHOLDER.'</p>', $sanitized);
        $this->assertStringContainsString('modulesid=30216&amp;courseid=8837', $sanitized);
    }

    public function test_csrf_and_hidden_tokens_are_replaced_and_the_portals_own_hidden_fields_are_kept(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <head>
                <meta name="csrf-token" content="a8f5f167f44f4964e6c998dee827110c">
                <script nonce="r4nd0mN0nce12345">var csrf_token = "Zx81kLp0Qr7Ty3Uv"; var settings = {"_token": 'Ab12Cd34Ef56'};</script>
            </head>
            <form method="post" action="?mod=sipandai&amp;act=sentujian&amp;token=f00dfeed1234">
                <input type="hidden" name="_token" value="abc">
                <input type=hidden name=signature value=00aa11bb22cc33dd44ee>
                <input type="hidden" name="key" value="9f86d081884c7d659a2feaa0c55ad015">
                <input type="hidden" name="data[id]" value="9195">
                <input type="hidden" name="data[tipe]" value="UAS">
                <input type="hidden" name="jenis" value="UTS / Reguler">
                <input type="hidden" name="pertemuan" value="6">
                <input type="file" name="file">
                <div data-token="secret-value-1234"></div>
            </form>
            HTML);

        foreach (['a8f5f167f44f4964e6c998dee827110c', 'r4nd0mN0nce12345', 'Zx81kLp0Qr7Ty3Uv', 'Ab12Cd34Ef56', 'f00dfeed1234', 'value="abc"', '00aa11bb22cc33dd44ee', '9f86d081884c7d659a2feaa0c55ad015', 'secret-value-1234'] as $secret) {
            $this->assertStringNotContainsString($secret, $sanitized);
        }

        $token = PortalResponseRecorder::TOKEN_PLACEHOLDER;
        $this->assertStringContainsString('<meta name="csrf-token" content="'.$token.'">', $sanitized);
        $this->assertStringContainsString('<script nonce="'.$token.'">var csrf_token = "'.$token.'"; var settings = {"_token": \''.$token.'\'};</script>', $sanitized);
        $this->assertStringContainsString('act=sentujian&amp;token='.$token.'"', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="_token" value="'.$token.'">', $sanitized);
        $this->assertStringContainsString('<input type=hidden name=signature value='.$token.'>', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="key" value="'.$token.'">', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="data[id]" value="9195">', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="data[tipe]" value="UAS">', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="jenis" value="UTS / Reguler">', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="pertemuan" value="6">', $sanitized);
        $this->assertStringContainsString('<div data-token="'.$token.'"></div>', $sanitized);
    }

    public function test_uploaded_file_names_are_replaced_in_every_spelling_keeping_their_extension(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <p>File Tugas Akhir Budi.pdf berhasil diunggah.</p>
            <a href="download.php?f=Tugas%20Akhir%20Budi.pdf">unduh</a>
            <a href="../files/upload/Tugas_Akhir_Budi.pdf">lihat</a>
            <p>Lampiran: laporan-2.docx</p>
            HTML, uploadedFileNames: ['Tugas Akhir Budi.pdf', 'C:\\fakepath\\laporan-2.docx']);

        $this->assertStringNotContainsString('Tugas Akhir', $sanitized);
        $this->assertStringNotContainsString('Tugas_Akhir', $sanitized);
        $this->assertStringNotContainsString('laporan-2', $sanitized);
        $this->assertStringContainsString('File berkas-unggahan.pdf berhasil diunggah.', $sanitized);
        $this->assertStringContainsString('download.php?f=berkas-unggahan.pdf', $sanitized);
        $this->assertStringContainsString('../files/upload/berkas-unggahan.pdf', $sanitized);
        $this->assertStringContainsString('Lampiran: berkas-unggahan-2.docx', $sanitized);
    }

    public function test_every_other_user_info_value_is_replaced_by_its_key(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <table class="table">
                <tr><td>Program Studi</td><td>:</td><td>Teknik Informatika</td></tr>
                <tr><td>Alamat</td><td>:</td><td>Jl. Kesambi No. 202, Cirebon</td></tr>
                <tr><td>Semester</td><td>:</td><td>5</td></tr>
                <tr><td>Kelas</td><td>:</td><td>Kelas Pagi &amp; Sore</td></tr>
                <tr><td>Tahun</td><td>:</td><td>2023</td></tr>
            </table>
            HTML, userInfo: [
            'department' => 'Teknik Informatika',
            'program_studi' => 'Teknik Informatika',
            'profile' => ['address' => 'Jl. Kesambi No. 202, Cirebon'],
            'semester' => '5',
            'class' => 'Kelas Pagi & Sore',
            'angkatan' => 2023,
        ]);

        $this->assertStringNotContainsString('Teknik Informatika', $sanitized);
        $this->assertStringNotContainsString('Kesambi', $sanitized);
        $this->assertStringContainsString('<td>DATA_MAHASISWA_DEPARTMENT</td>', $sanitized);
        $this->assertStringContainsString('<td>DATA_MAHASISWA_PROFILE_ADDRESS</td>', $sanitized);
        $this->assertStringContainsString('<td>DATA_MAHASISWA_CLASS</td>', $sanitized);
        $this->assertStringContainsString('<td>5</td>', $sanitized, 'Values too short to be identifying stay, or every 5 on the page would go.');
        $this->assertStringContainsString('<td>2023</td>', $sanitized);
    }

    public function test_the_markup_structure_is_kept_and_the_same_page_always_sanitizes_the_same_way(): void
    {
        $html = $this->fullPortalPage();

        $first = $this->sanitize($html, ['Tugas Akhir Budi.pdf']);
        $second = $this->sanitize($html, ['Tugas Akhir Budi.pdf']);

        $this->assertSame($first, $second);
        $this->assertSame($this->markupSkeleton($html), $this->markupSkeleton($first));
        $this->assertSame(
            (new Crawler($html))->filter('*')->count(),
            (new Crawler($first))->filter('*')->count()
        );
        $this->assertSame(1, (new Crawler($first))->filter('form input[type="file"][name="file"]')->count());

        foreach (['Budi', 'BUDI', self::NIM, self::COOKIE, 'budi.santoso@', 'dosen.a@', '3456-7890', 'Teknik Informatika', 'a8f5f167'] as $leak) {
            $this->assertStringNotContainsString($leak, $first);
        }
    }

    public function test_attributes_written_right_after_a_closing_quote_are_sanitized(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <form method="post" action="?mod=sipandai&amp;act=sent">
                <input type="text" name="nama"value="Budi Santoso" readonly>
                <input type="text" name='nim'value='20231234567'>
                <input type="hidden" name="_token"value="Zx81kLp0Qr7Ty3UvAb12">
                <input type="hidden" name="data[id]"value="9195">
            </form>
            <a class="x"href="mailto:dosen@cic.ac.id">Dosen</a>
            HTML);

        foreach (['Budi', self::NIM, 'Zx81kLp0Qr7Ty3UvAb12', 'dosen@cic.ac.id'] as $leak) {
            $this->assertStringNotContainsString($leak, $sanitized);
        }

        $this->assertStringContainsString('<input type="text" name="nama"value="Mahasiswa Uji" readonly>', $sanitized);
        $this->assertStringContainsString('<input type="text" name=\'nim\'value=\''.PortalResponseRecorder::NIM_PLACEHOLDER.'\'>', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="_token"value="'.PortalResponseRecorder::TOKEN_PLACEHOLDER.'">', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="data[id]"value="9195">', $sanitized);
        $this->assertStringContainsString('<a class="x"href="mailto:surel1@example.test">Dosen</a>', $sanitized);
    }

    public function test_a_tag_whose_attributes_cannot_be_parsed_is_still_sanitized(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <span title="Budi Santoso (20231234567)>Profil</span>
            <div data-x = data-owner="budi.santoso@student.cic.ac.id" class="card" title="20231234567">x</div>
            HTML);

        $this->assertStringNotContainsString('Budi', $sanitized);
        $this->assertStringNotContainsString(self::NIM, $sanitized);
        $this->assertStringNotContainsString('budi.santoso@', $sanitized);
        $this->assertStringContainsString('<span title="Mahasiswa Uji ('.PortalResponseRecorder::NIM_PLACEHOLDER.')>Profil</span>', $sanitized);
        $this->assertStringContainsString('<div data-x = data-owner="'.PortalResponseRecorder::STUDENT_EMAIL_PLACEHOLDER.'" class="card" title="'.PortalResponseRecorder::NIM_PLACEHOLDER.'">x</div>', $sanitized);
    }

    public function test_a_short_uploaded_file_name_leaves_the_portals_own_file_names_alone(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <p>File 1.pdf tersimpan.</p>
            <a href="../files/tugasmhs/9195-UAS-20231234567-131901.pdf">Open Jawaban Ujian</a>
            <a href="?mod=sipandai&amp;act=jobdelujian&amp;id=131901">Hapus Jawaban</a>
            <a href="../files/tugasmhs/9198-6-20231234567-a14.pdf">Open File</a>
            <a href="../files/tugasmhs/rekap_a14.pdf">Rekap</a>
            <a href="download.php?f=1.pdf">unduh</a>
            <a href="download.php?f=Lampiran%20a14.pdf">unduh</a>
            HTML, uploadedFileNames: ['1.pdf', 'a14.pdf']);

        $this->assertStringContainsString('<p>File berkas-unggahan.pdf tersimpan.</p>', $sanitized);
        $this->assertStringContainsString('../files/tugasmhs/9195-UAS-'.PortalResponseRecorder::NIM_PLACEHOLDER.'-131901.pdf', $sanitized);
        $this->assertStringContainsString('act=jobdelujian&amp;id=131901', $sanitized);
        $this->assertStringContainsString('../files/tugasmhs/9198-6-'.PortalResponseRecorder::NIM_PLACEHOLDER.'-a14.pdf', $sanitized);
        $this->assertStringContainsString('../files/tugasmhs/rekap_a14.pdf', $sanitized);
        $this->assertStringContainsString('download.php?f=berkas-unggahan.pdf', $sanitized);
        $this->assertStringContainsString('download.php?f=Lampiran%20berkas-unggahan-2.pdf', $sanitized);
    }

    public function test_names_nims_and_file_names_after_a_percent_escape_are_replaced(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <a href="download.php?f=Tugas%20Budi%20Santoso.pdf">Tugas Budi Santoso</a>
            <a href="download.php?f=Tugas%2020231234567.pdf">unduh</a>
            <a href="download.php?f=Revisi%20Laporan%20Akhir.docx">unduh</a>
            <p>Nomor tagihan 120231234567890</p>
            HTML, uploadedFileNames: ['Laporan Akhir.docx']);

        $this->assertStringNotContainsString('Budi', $sanitized);
        $this->assertStringNotContainsString('Santoso', $sanitized);
        $this->assertStringNotContainsString('%20'.self::NIM, $sanitized);
        $this->assertStringNotContainsString('Laporan', $sanitized);
        $this->assertStringContainsString('f=Tugas%20Mahasiswa%20Uji.pdf">Tugas Mahasiswa Uji</a>', $sanitized);
        $this->assertStringContainsString('f=Tugas%20'.PortalResponseRecorder::NIM_PLACEHOLDER.'.pdf', $sanitized);
        $this->assertStringContainsString('f=Revisi%20berkas-unggahan.docx', $sanitized);
        $this->assertStringContainsString('Nomor tagihan 120231234567890', $sanitized);
    }

    public function test_a_name_word_on_its_own_or_split_by_tags_is_replaced(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <p>Selamat datang, Budi!</p>
            <div class="user"><b>BUDI</b> SANTOSO</div>
            <p>santoso mengunggah tugas pada Agustus.</p>
            <p>Dosen: Budiman Santosa</p>
            HTML);

        $this->assertStringNotContainsString('Budi!', $sanitized);
        $this->assertStringNotContainsStringIgnoringCase('santoso', $sanitized);
        $this->assertStringContainsString('<p>Selamat datang, Mahasiswa!</p>', $sanitized);
        $this->assertStringContainsString('<b>MAHASISWA</b> UJI', $sanitized);
        $this->assertStringContainsString('<p>uji mengunggah tugas pada Agustus.</p>', $sanitized);
        $this->assertStringContainsString('<p>Dosen: Budiman Santosa</p>', $sanitized, 'Only whole words go: longer names that start with a name word stay.');
    }

    public function test_name_words_the_portal_uses_as_page_words_are_kept(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <div class="user">Juni Tugas Lestari</div>
            <tr><td>Rabu, 17 Juni 2026</td><td>Tugas pertemuan 6</td><td>Lestari</td></tr>
            HTML, userInfo: ['name' => 'Juni Tugas Lestari', 'student_id' => self::NIM]);

        $this->assertStringContainsString('<div class="user">Mahasiswa Uji</div>', $sanitized);
        $this->assertStringContainsString('<td>Rabu, 17 Juni 2026</td><td>Tugas pertemuan 6</td><td>Uji</td>', $sanitized);
    }

    public function test_tokens_scripts_assign_under_any_token_name_are_replaced(): void
    {
        $sanitized = $this->sanitize(<<<'HTML'
            <script>
                var M = {"cfg": {"sesskey":"aB3dE5fG7h", "apikey": "k3yV4lu3x9Q", "api_key": 'Zq9Wx8Ec7R', "client_secret": "s3cr3tV4lu3", "signature": "5ig9a7ur3x"}};
                var courses_id = '8837', pertemuan = "6";
            </script>
            <a href="?mod=sipandai&amp;sesskey=aB3dE5fG7h&amp;signature=5ig9a7ur3x&amp;apikey=k3yV4lu3x9Q&amp;id=9195">lanjut</a>
            <input type="hidden" name="apikey" value="k3yV4lu3x9Q">
            HTML);

        foreach (['aB3dE5fG7h', 'k3yV4lu3x9Q', 'Zq9Wx8Ec7R', 's3cr3tV4lu3', '5ig9a7ur3x'] as $secret) {
            $this->assertStringNotContainsString($secret, $sanitized);
        }

        $token = PortalResponseRecorder::TOKEN_PLACEHOLDER;
        $this->assertStringContainsString('{"sesskey":"'.$token.'", "apikey": "'.$token.'", "api_key": \''.$token.'\', "client_secret": "'.$token.'", "signature": "'.$token.'"}', $sanitized);
        $this->assertStringContainsString('var courses_id = \'8837\', pertemuan = "6";', $sanitized);
        $this->assertStringContainsString('sesskey='.$token.'&amp;signature='.$token.'&amp;apikey='.$token.'&amp;id=9195', $sanitized);
        $this->assertStringContainsString('<input type="hidden" name="apikey" value="'.$token.'">', $sanitized);
    }

    public function test_the_context_is_built_from_the_stored_campus_session(): void
    {
        $context = app(PortalResponseRecorder::class)->contextFromSession($this->campusSession(), ['jawaban.pdf']);

        $this->assertSame(['Budi Santoso'], $context['names']);
        $this->assertSame([self::NIM, self::NIM], $context['nims']);
        $this->assertSame(['budi.santoso@student.cic.ac.id'], $context['emails']);
        $this->assertSame(['0812-3456-7890'], $context['phones']);
        $this->assertSame(['department' => 'Teknik Informatika'], $context['user_info']);
        $this->assertSame(['jawaban.pdf'], $context['uploaded_file_names']);
        $this->assertSame([self::COOKIE, self::SESSION_TOKEN], $context['secrets']);
        $this->assertSame([], $context['lecturers'], 'No class list is stored for this session.');
        $this->assertSame([
            'names' => [],
            'nims' => [],
            'emails' => [],
            'phones' => [],
            'user_info' => [],
            'uploaded_file_names' => [],
            'secrets' => [],
            'lecturers' => [],
        ], app(PortalResponseRecorder::class)->contextFromSession(null));
    }

    public function test_nothing_is_recorded_while_the_debug_switch_is_off(): void
    {
        $recorder = app(PortalResponseRecorder::class);

        $this->assertFalse($recorder->isEnabled());
        $this->assertNull($recorder->record('exam_upload', $this->fullPortalPage(), $this->campusSession()));
        $this->assertDirectoryDoesNotExist($this->capturePath);
    }

    public function test_a_recorded_response_is_stored_sanitized_under_its_action_and_capture_time(): void
    {
        config(['portal.debug.record_action_responses' => true]);
        $this->travelTo(Carbon::parse('2026-10-23 09:30:15'));
        $recorder = app(PortalResponseRecorder::class);

        $path = $recorder->record('exam_upload', $this->fullPortalPage(), $this->campusSession(), ['Tugas Akhir Budi.pdf']);

        $this->assertTrue($recorder->isEnabled());
        $this->assertNotNull($path);
        $this->assertSame($this->capturePath.DIRECTORY_SEPARATOR.'exam_upload', dirname($path));
        $this->assertMatchesRegularExpression('/^2026-10-23_093015_[0-9a-f]{8}\.html$/', basename($path));

        $stored = File::get($path);
        $this->assertSame($recorder->sanitize($this->fullPortalPage(), $recorder->contextFromSession($this->campusSession(), ['Tugas Akhir Budi.pdf'])), $stored);
        $this->assertStringContainsString(PortalResponseRecorder::NAME_PLACEHOLDER, $stored);
        $this->assertStringContainsString('berkas-unggahan.pdf', $stored);
        $this->assertStringNotContainsString(self::NIM, $stored);
        $this->assertStringNotContainsString(self::COOKIE, $stored);
    }

    public function test_nothing_is_recorded_without_a_campus_session(): void
    {
        config(['portal.debug.record_action_responses' => true]);
        Log::spy();

        $path = app(PortalResponseRecorder::class)->record('assignment_upload', '<p>Data tugas Budi Santoso ('.self::NIM.') tersimpan</p>', null, ['Tugas Budi.pdf']);

        $this->assertNull($path);
        $this->assertDirectoryDoesNotExist($this->capturePath);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Portal action response not recorded: no student name and NIM to sanitize it with'
                && $context === ['action' => 'assignment_upload', 'reason' => 'no_session', 'missing' => ['name', 'nim']])
            ->once();
    }

    public function test_nothing_is_recorded_when_the_session_lacks_the_students_name_or_nim(): void
    {
        config(['portal.debug.record_action_responses' => true]);
        Log::spy();
        $recorder = app(PortalResponseRecorder::class);

        $withoutName = $this->campusSession();
        unset($withoutName['user_info']['name']);
        $withoutNim = $this->campusSession();
        unset($withoutNim['username'], $withoutNim['user_info']['student_id']);
        $withBlankName = $this->campusSession();
        $withBlankName['user_info']['name'] = ' - ';

        $this->assertNull($recorder->record('exam_upload', $this->fullPortalPage(), $withoutName));
        $this->assertNull($recorder->record('exam_upload', $this->fullPortalPage(), $withoutNim));
        $this->assertNull($recorder->record('exam_upload', $this->fullPortalPage(), $withBlankName));
        $this->assertDirectoryDoesNotExist($this->capturePath);

        $this->assertSame(['name'], $recorder->missingIdentity($recorder->contextFromSession($withoutName)));
        $this->assertSame(['nim'], $recorder->missingIdentity($recorder->contextFromSession($withoutNim)));
        $this->assertSame(['name'], $recorder->missingIdentity($recorder->contextFromSession($withBlankName)));
        $this->assertSame([], $recorder->missingIdentity($recorder->contextFromSession($this->campusSession())));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Portal action response not recorded: no student name and NIM to sanitize it with'
                && $context['reason'] === 'incomplete_session')
            ->times(3);
    }

    public function test_the_action_name_cannot_leave_the_capture_directory(): void
    {
        config(['portal.debug.record_action_responses' => true]);

        $path = app(PortalResponseRecorder::class)->record('../../Evil Action', '<html><body>ok</body></html>', $this->campusSession());

        $this->assertNotNull($path);
        $this->assertSame($this->capturePath.DIRECTORY_SEPARATOR.'evil_action', dirname($path));
    }

    public function test_an_empty_response_is_not_recorded(): void
    {
        config(['portal.debug.record_action_responses' => true]);

        $this->assertNull(app(PortalResponseRecorder::class)->record('exam_upload', ''));
        $this->assertDirectoryDoesNotExist($this->capturePath);
    }

    public function test_production_refuses_to_record_unless_it_is_allowed(): void
    {
        config(['portal.debug.record_action_responses' => true]);
        $this->app['env'] = 'production';
        $recorder = app(PortalResponseRecorder::class);

        $this->assertFalse($recorder->isEnabled());
        $this->assertNull($recorder->record('attendance_submit', $this->fullPortalPage(), $this->campusSession()));
        $this->assertDirectoryDoesNotExist($this->capturePath);

        config(['portal.debug.allow_in_production' => true]);

        $this->assertTrue($recorder->isEnabled());
        $path = $recorder->record('attendance_submit', $this->fullPortalPage(), $this->campusSession());
        $this->assertNotNull($path);
        $this->assertFileExists($path);
    }

    public function test_a_recording_that_fails_is_logged_and_never_throws(): void
    {
        File::ensureDirectoryExists(dirname($this->capturePath));
        File::put($this->capturePath, 'not a directory');
        config(['portal.debug.record_action_responses' => true]);
        Log::spy();

        try {
            $this->assertNull(app(PortalResponseRecorder::class)->record('exam_upload', $this->fullPortalPage(), $this->campusSession()));
        } finally {
            File::delete($this->capturePath);
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Failed to record a portal action response' && $context['action'] === 'exam_upload')
            ->once();
    }

    /**
     * @param  list<string>  $uploadedFileNames
     * @param  array<string, mixed>|null  $userInfo
     */
    private function sanitize(string $html, array $uploadedFileNames = [], ?array $userInfo = null): string
    {
        $recorder = app(PortalResponseRecorder::class);
        $session = $this->campusSession();

        if ($userInfo !== null) {
            $session['user_info'] = $userInfo;
        }

        return $recorder->sanitize($html, $recorder->contextFromSession($session, $uploadedFileNames));
    }

    /**
     * @return array<string, mixed>
     */
    private function campusSession(): array
    {
        return [
            'token' => self::SESSION_TOKEN,
            'username' => self::NIM,
            'user_info' => [
                'name' => 'Budi Santoso',
                'student_id' => self::NIM,
                'email' => 'budi.santoso@student.cic.ac.id',
                'phone' => '0812-3456-7890',
                'department' => 'Teknik Informatika',
            ],
            'cookies' => [
                ['Name' => 'PHPSESSID', 'Value' => self::COOKIE, 'Domain' => 'portal.invalid', 'Path' => '/'],
                ['Name' => 'lang', 'Value' => 'id', 'Domain' => 'portal.invalid', 'Path' => '/'],
            ],
        ];
    }

    private function fullPortalPage(): string
    {
        $cookie = self::COOKIE;
        $nim = self::NIM;

        return <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <title>SIPANDAI</title>
                <meta name="csrf-token" content="a8f5f167f44f4964e6c998dee827110c">
                <style>.nim { font-weight: bold; }</style>
                <script>var redirect = '../../?mod=sipandai&act=discussabsen&id=30216&courses_id=8837'; document.cookie = "PHPSESSID={$cookie}";</script>
            </head>
            <body>
                <!-- login sebagai Budi Santoso ({$nim}) -->
                <div class="user">BUDI SANTOSO <span class="nim">{$nim}</span></div>
                <p>Teknik Informatika</p>
                <a href="mailto:budi.santoso@student.cic.ac.id">budi.santoso@student.cic.ac.id</a>
                <a href="mailto:dosen.a@cic.ac.id">dosen.a@cic.ac.id</a>
                <p>HP: 0812-3456-7890</p>
                <form method="post" action="?mod=sipandai&amp;act=sentujian" enctype="multipart/form-data">
                    <input type="hidden" name="data[id]" value="9195" />
                    <input type="hidden" name="data[tipe]" value="UAS" />
                    <input type="text" name="data[name]" size="70" maxlength="70" />
                    <input type="file" name="file" />
                </form>
                <table>
                    <tr><td>Rabu, 20 Mei 2026</td><td><a href="../files/tugasmhs/9195-UAS-{$nim}-131900.pdf">Tugas Akhir Budi.pdf</a></td></tr>
                </table>
            </body>
            </html>
            HTML;
    }

    /**
     * Tag names and attribute names in document order: what sanitizing
     * must never change.
     *
     * @return list<string>
     */
    private function markupSkeleton(string $html): array
    {
        preg_match_all('/<\/?([A-Za-z][A-Za-z0-9-]*)((?:\s+[^\s=>\/]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?)*)\s*\/?>/', $html, $tags, PREG_SET_ORDER);

        return array_map(static function (array $tag): string {
            preg_match_all('/\s([^\s=>\/]+)(?:\s*=)?/', $tag[2], $attributes);

            return strtolower($tag[1]).'['.implode(',', $attributes[1]).']';
        }, $tags);
    }

    private function cloudflareHex(string $email, int $key): string
    {
        $hex = sprintf('%02x', $key);

        foreach (str_split($email) as $character) {
            $hex .= sprintf('%02x', ord($character) ^ $key);
        }

        return $hex;
    }

    private function decodeCloudflareHex(string $hex): string
    {
        $key = hexdec(substr($hex, 0, 2));
        $email = '';

        for ($offset = 2; $offset < strlen($hex); $offset += 2) {
            $email .= chr(hexdec(substr($hex, $offset, 2)) ^ $key);
        }

        return $email;
    }
}
