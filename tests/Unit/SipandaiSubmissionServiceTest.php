<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Models\ExamSubmissionRecord;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class SipandaiSubmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exam_submission_records_are_deduplicated_by_record_key(): void
    {
        $this->assertTrue(Schema::hasTable('exam_submission_records'));

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSession')
            ->with('token-exam-records')
            ->willReturn([
                'username' => '20990000001',
            ]);

        $service = new SipandaiSubmissionService($sessionManager);
        $records = [[
            'exam_id' => 9195,
            'submission_id' => 131828,
            'file_url' => 'https://my.cic.ac.id/portal/files/tugasmhs/9195-UTS-20990000001-785.pdf',
            'file_name' => '9195-UTS-20990000001-785.pdf',
            'delete_url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdelujian&id=131828',
            'uploaded_at_label' => 'Rabu, 20 Mei 2026',
            'exam_type' => 'UTS / Reguler',
            'description' => '-',
        ]];

        $this->invokeNonPublicMethod($service, 'rememberExamSubmissionRecords', [
            'token-exam-records',
            9195,
            $records,
            'detail',
        ]);

        $this->invokeNonPublicMethod($service, 'rememberExamSubmissionRecords', [
            'token-exam-records',
            9195,
            $records,
            'upload_refresh',
        ]);

        $this->assertSame(1, ExamSubmissionRecord::query()->count());
        $this->assertSame('upload_refresh', ExamSubmissionRecord::query()->firstOrFail()->source);
    }

    public function test_submit_attendance_falls_back_to_direct_submit_when_qr_page_has_no_form(): void
    {
        config(['portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->expects($this->once())
            ->method('getSessionCookieJar')
            ->with('token-123')
            ->willReturn(CookieJar::fromArray([], 'my.cic.ac.id'));

        $service = new SipandaiSubmissionService($sessionManager);
        $history = [];

        $handler = new MockHandler([
            new Response(200, [], $this->attendanceScannerPageHtml()),
            new Response(200, [], $this->successfulAttendanceResponseHtml()),
        ]);

        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => $stack,
        ]));

        $result = $service->submitAttendance('token-123', 8837, 30216, 9, [
            'kode' => '35533429',
        ]);

        $this->assertCount(2, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('POST', $history[1]['request']->getMethod());
        $this->assertStringContainsString('act=discussabsen', (string) $history[1]['request']->getUri());
        parse_str((string) $history[1]['request']->getBody(), $submittedPayload);

        $this->assertSame('35533429', $submittedPayload['qrcode']);
        $this->assertSame('35533429', $submittedPayload['kode']);
        $this->assertTrue($result['success']);
        $this->assertSame('marked', $result['data']['status']);
    }

    public function test_submit_attendance_falls_back_to_actabsen_when_qr_page_has_no_redirect_script(): void
    {
        config(['portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->expects($this->once())
            ->method('getSessionCookieJar')
            ->with('token-fallback')
            ->willReturn(CookieJar::fromArray([], 'my.cic.ac.id'));

        $service = new SipandaiSubmissionService($sessionManager);
        $history = [];

        $htmlWithoutScript = '<html><head><title>Absensi</title></head><body><div class="container"><h3>ABSEN</h3></div></body></html>';

        $handler = new MockHandler([
            new Response(200, [], $htmlWithoutScript),
            new Response(200, [], $this->successfulAttendanceResponseHtml()),
        ]);

        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => $stack,
        ]));

        $result = $service->submitAttendance('token-fallback', 8837, 30216, 9, [
            'kode' => '35533429',
        ]);

        $this->assertCount(2, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('POST', $history[1]['request']->getMethod());
        $this->assertSame(
            PortalEndpointRegistry::url('attendance_submit', [
                'modulesid' => 30216,
                'courseid' => 8837,
            ]),
            (string) $history[1]['request']->getUri()
        );
        parse_str((string) $history[1]['request']->getBody(), $submittedPayload);

        $this->assertSame([
            'cmd' => 'Simpan',
            'pertemuan' => '9',
            'kode' => '35533429',
        ], $submittedPayload);
        $this->assertTrue($result['success']);
        $this->assertSame('marked', $result['data']['status']);
    }

    public function test_submit_attendance_accepts_short_success_response_from_direct_submit(): void
    {
        config(['portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->expects($this->once())
            ->method('getSessionCookieJar')
            ->with('token-short-response')
            ->willReturn(CookieJar::fromArray([], 'my.cic.ac.id'));

        $service = new SipandaiSubmissionService($sessionManager);

        $handler = new MockHandler([
            new Response(200, [], $this->attendanceScannerPageHtml()),
            new Response(200, [], '<div>Absensi berhasil</div>'),
        ]);

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => HandlerStack::create($handler),
        ]));

        $result = $service->submitAttendance('token-short-response', 8837, 30216, 9, [
            'kode' => '35533429',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('marked', $result['data']['status']);
    }

    public function test_submit_exam_posts_directly_to_sentujian_when_form_is_unavailable(): void
    {
        config(['portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->expects($this->once())
            ->method('getSessionCookieJar')
            ->with('token-exam-direct')
            ->willReturn(CookieJar::fromArray([], 'my.cic.ac.id'));

        $service = new SipandaiSubmissionService($sessionManager);
        $history = [];
        $attachment = UploadedFile::fake()->create('jawaban.pdf', 128, 'application/pdf');

        $handler = new MockHandler([
            new Response(200, [], <<<'HTML'
            <html>
                <body>
                    <div class="alert alert-warning">Upload jawaban belum dibuka oleh jadwal portal.</div>
                    <p>Halaman ini sengaja tidak merender form upload sampai jam ujian dibuka.</p>
                </body>
            </html>
            HTML),
            new Response(200, [], '<html><body><div class="alert alert-success">Data sudah disimpan.</div></body></html>'),
        ]);

        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => $stack,
        ]));

        $result = $service->submitExam('token-exam-direct', 8833, $attachment);

        $this->assertCount(2, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('POST', $history[1]['request']->getMethod());
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), (string) $history[1]['request']->getUri());

        $submittedBody = (string) $history[1]['request']->getBody();

        $this->assertStringContainsString('name="data[id]"', $submittedBody);
        $this->assertStringContainsString('8833', $submittedBody);
        $this->assertStringContainsString('name="cmd"', $submittedBody);
        $this->assertStringContainsString('Upload', $submittedBody);
        $this->assertStringContainsString('name="file"', $submittedBody);
        $this->assertStringContainsString('filename="jawaban.pdf"', $submittedBody);
        $this->assertTrue($result['success']);
        $this->assertSame('uploaded', $result['data']['status']);
        $this->assertTrue($result['data']['direct_submit_fallback']);
        $this->assertSame(PortalEndpointRegistry::url('exam_upload_form', ['id' => 8833]), $result['data']['metadata_url']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $result['data']['submit_url']);
    }

    public function test_submit_exam_uses_sendujian_hidden_fields_when_file_input_is_missing(): void
    {
        config(['portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->expects($this->once())
            ->method('getSessionCookieJar')
            ->with('token-exam-closed')
            ->willReturn(CookieJar::fromArray([], 'my.cic.ac.id'));

        $service = new SipandaiSubmissionService($sessionManager);
        $history = [];
        $attachment = UploadedFile::fake()->create('UTS-TTE_MAHASISWACONTOH.pdf', 128, 'application/pdf');

        $handler = new MockHandler([
            new Response(200, [], <<<'HTML'
            <html>
                <body>
                    <form method="post" action="?mod=sipandai&act=sentujian" enctype="multipart/form-data">
                        <input type="hidden" name="data[id]" value="9186" />
                        <input type="hidden" name="data[tipe]" value="UTS" />
                        <input type="hidden" name="data[kelas]" value="TEORI" />
                        <input type="text" name="data[name]" size="70" maxlength="70" />
                        <span>Waktu Ujian Sudah Berakhir Untuk Mata Kuliah Diatas</span>
                    </form>
                </body>
            </html>
            HTML),
            new Response(200, [], '<html><body><a href="?mod=sipandai&act=sendujian&id=9186"><b>Data sudah disimpan</b></a></body></html>'),
        ]);

        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => $stack,
        ]));

        $result = $service->submitExam('token-exam-closed', 9186, $attachment, [
            'data[name]' => 'Jawaban UTS Teknologi dan trend E-commerce',
        ]);

        $this->assertCount(2, $history);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), (string) $history[1]['request']->getUri());

        $submittedBody = (string) $history[1]['request']->getBody();

        $this->assertStringContainsString('name="data[id]"', $submittedBody);
        $this->assertStringContainsString('9186', $submittedBody);
        $this->assertStringContainsString('name="data[tipe]"', $submittedBody);
        $this->assertStringContainsString('UTS', $submittedBody);
        $this->assertStringContainsString('name="data[kelas]"', $submittedBody);
        $this->assertStringContainsString('TEORI', $submittedBody);
        $this->assertStringContainsString('name="data[name]"', $submittedBody);
        $this->assertStringContainsString('Jawaban UTS Teknologi dan trend E-commerce', $submittedBody);
        $this->assertStringContainsString('filename="UTS-TTE_MAHASISWACONTOH.pdf"', $submittedBody);
        $this->assertTrue($result['success']);
        $this->assertSame('uploaded', $result['data']['status']);
        $this->assertTrue($result['data']['direct_submit_fallback']);
        $this->assertContains('data[id]', $result['data']['sent_fields']);
        $this->assertContains('data[tipe]', $result['data']['sent_fields']);
        $this->assertContains('data[kelas]', $result['data']['sent_fields']);
        $this->assertSame(PortalEndpointRegistry::url('exam_upload_form', ['id' => 9186]), $result['data']['metadata_url']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $result['data']['submit_url']);
    }

    public function test_determine_upload_status_prioritizes_failure_phrases(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineUploadStatus', [
            '<div class="alert alert-danger">File tidak berhasil diupload.</div>',
            ['File tidak berhasil diupload.'],
        ]);

        $this->assertFalse($status['success']);
        $this->assertSame('upload_failed', $status['code']);
    }

    public function test_determine_upload_status_accepts_success_message_from_alerts(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineUploadStatus', [
            '<html><body></body></html>',
            ['Upload berhasil'],
        ]);

        $this->assertTrue($status['success']);
        $this->assertSame('uploaded', $status['code']);
    }

    public function test_determine_upload_status_ignores_page_labels_and_scripts_when_alert_reports_success(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $html = <<<'HTML'
            <html><head><style>.error { color: red; }</style><script>function onError() { console.error('failed'); }</script></head>
            <body>
                <div class="alert alert-success">Data sudah disimpan</div>
                <table><tr><th>Batas Waktu</th><td>12-10-2026 23:59</td></tr><tr><th>Ukuran file</th><td>Maks. 10MB</td></tr></table>
            </body></html>
            HTML;

        $status = $this->invokeNonPublicMethod($service, 'determineUploadStatus', [$html, ['Data sudah disimpan']]);

        $this->assertTrue($status['success']);
        $this->assertSame('uploaded', $status['code']);
    }

    public function test_determine_upload_status_does_not_read_deadline_label_as_failure(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineUploadStatus', [
            '<html><body><h4>Batas Waktu: 12-10-2026</h4><p>Tugas berhasil diupload.</p></body></html>',
            [],
        ]);

        $this->assertTrue($status['success']);
    }

    public function test_determine_attendance_status_treats_already_recorded_attendance_as_success(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineAttendanceStatus', [
            '<html><body><div class="alert alert-danger">Anda sudah melakukan absensi pada pertemuan ini.</div></body></html>',
            ['Anda sudah melakukan absensi pada pertemuan ini.'],
        ]);

        $this->assertTrue($status['success']);
        $this->assertSame('already_marked', $status['code']);
    }

    public function test_determine_attendance_status_detects_data_kelas_tidak_ditemukan(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineAttendanceStatus', [
            '<html><body><div class="alert alert-danger">Maaf, Data Kelas tidak ditemukan..! Pastikan Code Unix Sesuai Dengan Kelas Yang Diambil</div></body></html>',
            ['Maaf, Data Kelas tidak ditemukan..! Pastikan Code Unix Sesuai Dengan Kelas Yang Diambil'],
        ]);

        $this->assertFalse($status['success']);
        $this->assertSame('invalid_code', $status['code']);
        $this->assertStringContainsString('Data kelas tidak ditemukan', $status['message']);
    }

    public function test_determine_attendance_status_detects_waktu_absen_berakhir(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineAttendanceStatus', [
            '<html><body><div class="alert alert-warning">Waktu absen berakhir pada jam 17:59 Wib</div></body></html>',
            ['Waktu absen berakhir pada jam 17:59 Wib'],
        ]);

        $this->assertFalse($status['success']);
        $this->assertSame('expired', $status['code']);
        $this->assertStringContainsString('sudah ditutup', $status['message']);
    }

    public function test_determine_delete_status_does_not_assume_success_when_target_is_missing(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineDeleteStatus', [
            '<html><body><h1>Daftar Tugas</h1><a href="?mod=sipandai&act=jobdel&id=999&idx=30095&pertemuan=6">Hapus</a></body></html>',
            [],
            126663,
        ]);

        $this->assertTrue($status['success']);
        $this->assertSame('deleted', $status['code']);
    }

    public function test_determine_delete_status_stays_unknown_when_target_is_still_present(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineDeleteStatus', [
            '<html><body><h1>Daftar Tugas</h1><a href="?mod=sipandai&act=jobdel&id=126663&idx=30095&pertemuan=6">Hapus</a></body></html>',
            [],
            126663,
        ]);

        $this->assertFalse($status['success']);
        $this->assertSame('unknown', $status['code']);
    }

    public function test_build_attendance_payload_keeps_meeting_number_for_direct_submit(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $payload = $this->invokeNonPublicMethod($service, 'buildAttendancePayload', [
            8,
            ['kode' => '35533429'],
        ]);

        $this->assertSame('Simpan', $payload['cmd']);
        $this->assertSame('35533429', $payload['kode']);
        $this->assertSame('8', $payload['pertemuan']);
    }

    public function test_build_attendance_url_uses_qr_validation_page_identifiers(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $url = $this->invokeNonPublicMethod($service, 'buildAttendanceUrl', [
            8837,
            30728,
            8,
        ]);

        $this->assertSame(
            PortalEndpointRegistry::url('qr_attendance_page', [
                'course_id' => 8837,
                'id' => 30728,
                'pertemuan' => 8,
            ]),
            $url
        );
    }

    public function test_determine_attendance_status_accepts_success_alert_with_empty_qr_validation_table(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $status = $this->invokeNonPublicMethod($service, 'determineAttendanceStatus', [
            $this->successfulAttendanceResponseHtml(),
            [],
        ]);

        $this->assertTrue($status['success']);
        $this->assertSame('marked', $status['code']);
    }

    public function test_extract_exam_list_keeps_scheduled_rows_without_upload_links(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $exams = $this->invokeNonPublicMethod($service, 'extractExamList', [
            new Crawler(<<<'HTML'
            <html>
                <body>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Kode MK</th>
                                <th>Mata Kuliah</th>
                                <th>Jadwal Ujian</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>IF101</td>
                                <td>Algoritma</td>
                                <td>Senin, 18 Mei 2026 09:00</td>
                                <td>Terjadwal</td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>IF102</td>
                                <td>Basis Data</td>
                                <td>Selasa, 19 Mei 2026 13:00</td>
                                <td>Terjadwal</td>
                            </tr>
                        </tbody>
                    </table>
                </body>
            </html>
            HTML),
        ]);

        $this->assertCount(2, $exams);
        $this->assertNull($exams[0]['id']);
        $this->assertSame('IF101', $exams[0]['course_code']);
        $this->assertSame('Algoritma', $exams[0]['course_name']);
        $this->assertSame('Senin, 18 Mei 2026 09:00', $exams[0]['schedule']);
        $this->assertSame('Terjadwal', $exams[0]['status']);
        $this->assertNull($exams[0]['upload_url']);
    }

    public function test_extract_exam_list_reads_upload_link_ids_when_available(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $exams = $this->invokeNonPublicMethod($service, 'extractExamList', [
            new Crawler(<<<'HTML'
            <html>
                <body>
                    <table>
                        <tr>
                            <td>No</td>
                            <td>Kode MK</td>
                            <td>Mata Kuliah</td>
                            <td>Jadwal Ujian</td>
                            <td>Status</td>
                            <td>Aksi</td>
                        </tr>
                        <tr>
                            <td>1</td>
                            <td>IF101</td>
                            <td>Algoritma</td>
                            <td>Senin, 18 Mei 2026 09:00</td>
                            <td>Belum Upload</td>
                            <td><a href="?mod=sipandai&amp;act=sendujian&amp;id=8833">Upload</a></td>
                        </tr>
                    </table>
                </body>
            </html>
            HTML),
        ]);

        $this->assertCount(1, $exams);
        $this->assertSame(8833, $exams[0]['id']);
        $this->assertSame('IF101', $exams[0]['course_code']);
        $this->assertSame('Algoritma', $exams[0]['course_name']);
        $this->assertSame('Belum Upload', $exams[0]['status']);
        $this->assertSame('?mod=sipandai&act=sendujian&id=8833', $exams[0]['upload_url']);
    }

    public function test_extract_exam_list_skips_student_metadata_and_splits_joined_course_text(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $exams = $this->invokeNonPublicMethod($service, 'extractExamList', [
            new Crawler(<<<'HTML'
            <html>
                <body>
                    <table>
                        <tr><td>Program Studi</td><td>:</td><td>SISTEM INFORMASI - S1</td></tr>
                        <tr><td>Th Akademik</td><td>:</td><td>2025/2026</td></tr>
                        <tr><td>Semester</td><td>:</td><td>GENAP</td></tr>
                        <tr><td>N I M</td><td>:</td><td>20990000001</td></tr>
                        <tr><td>Nama</td><td>:</td><td>Mahasiswa Contoh</td></tr>
                    </table>
                    <table>
                        <tr>
                            <td>I043016Rekayasa Perangkat Lunak : Agile Scrum Introduction</td>
                            <td>SI043016Rekayasa Perangkat Lunak : Agile Scrum Introduction</td>
                            <td>Dosen Contoh Dua, MMSI. Senin, 18 Mei 2026 08:00:00 Wib sd 12:00:00 Wib Ujian : TEORI</td>
                        </tr>
                        <tr>
                            <td>SI043020Machine Learning</td>
                            <td>SI043020Machine Learning</td>
                            <td>Dosen Contoh Satu, M.Kom. Senin, 18 Mei 2026 13:00:00 Wib sd 17:00:00 Wib Ujian : TEORI</td>
                        </tr>
                    </table>
                </body>
            </html>
            HTML),
        ]);

        $this->assertCount(2, $exams);
        $this->assertSame('SI043016', $exams[0]['course_code']);
        $this->assertSame('Rekayasa Perangkat Lunak : Agile Scrum Introduction', $exams[0]['course_name']);
        $this->assertStringContainsString('Senin, 18 Mei 2026 08:00:00', $exams[0]['schedule']);
        $this->assertSame('Terjadwal', $exams[0]['status']);
        $this->assertSame('SI043020', $exams[1]['course_code']);
        $this->assertSame('Machine Learning', $exams[1]['course_name']);
    }

    public function test_extract_exam_detail_marks_uploaded_when_sendujian_page_lists_existing_submission(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            <<<'HTML'
            <html>
                <body>
                    <form action="?mod=sipandai&act=sendujian&id=8810" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="8810">
                        <input type="file" name="file">
                    </form>
                    <a href="/storage/jawaban-8810.zip">Open Jawaban</a>
                    <a href="?mod=sipandai&act=sendujian&id=8810&hapus=1">Hapus Jawaban</a>
                </body>
            </html>
            HTML,
            8810,
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=8810',
        ]);

        $this->assertSame('uploaded', $detail['status']);
        $this->assertTrue($detail['form']['available']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['form']['post_url']);
    }

    public function test_extract_exam_detail_marks_upload_unavailable_when_submission_already_exists_without_form(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            <<<'HTML'
            <html>
                <body>
                    <table>
                        <tr>
                            <td>Rabu, 20 Mei 2026</td>
                            <td>UTS / Reguler</td>
                            <td>Jawaban UTS</td>
                            <td><a href="../files/tugasmhs/9184-UTS-20990000001-abc.pdf">Open Jawaban Ujian</a></td>
                        </tr>
                    </table>
                </body>
            </html>
            HTML,
            9184,
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=9184',
        ]);

        $this->assertSame('uploaded', $detail['status']);
        $this->assertFalse($detail['form']['available']);
        $this->assertTrue($detail['form']['available_via_direct_submit']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['form']['post_url']);
        $this->assertSame('9184', $detail['form']['fields']['data[id]']);
    }

    public function test_extract_exam_detail_captures_exam_delete_submission_id_from_uploaded_row(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            <<<'HTML'
            <html>
                <body>
                    <table>
                        <tr>
                            <td>No.</td>
                            <td>Tgl. Upload</td>
                            <td>Ujian</td>
                            <td>Keterangan</td>
                            <td>Jawaban</td>
                        </tr>
                        <tr class="altrow">
                            <td align="center">1.</td>
                            <td>Rabu, 20 Mei 2026</td>
                            <td>UTS / Reguler</td>
                            <td>-</td>
                            <td align="center">
                                <a href="../files/tugasmhs/9195-UTS-20990000001-785.pdf" class="btn btn-success btn-sm" target="_blank">
                                    Open Jawaban Ujian
                                </a>
                                |
                                <a href="?mod=sipandai&amp;act=jobdelujian&amp;id=131828" class="btn btn-danger btn-sm">
                                    Hapus Jawaban
                                </a>
                            </td>
                        </tr>
                    </table>
                </body>
            </html>
            HTML,
            9195,
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=9195',
        ]);

        $this->assertSame(131828, $detail['submissions'][0]['submission_id']);
        $this->assertSame(131828, $detail['latest_submission']['submission_id']);
        $this->assertSame('9195-UTS-20990000001-785.pdf', $detail['submissions'][0]['file_name']);
        $this->assertSame('Rabu, 20 Mei 2026', $detail['submissions'][0]['uploaded_at_label']);
        $this->assertStringContainsString('act=jobdelujian', $detail['submissions'][0]['delete_url']);
    }

    public function test_extract_exam_detail_collects_downloadable_exam_file_links(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            <<<'HTML'
            <html>
                <body>
                    <h3>Ujian Tengah Semester</h3>
                    <iframe src="portal/files/soal/9197-UTS-tgs-591.pdf"></iframe>
                    <a href="portal/files/soal/9197-UTS-tgs-591.pdf">Download soal</a>
                </body>
            </html>
            HTML,
            9197,
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=9197',
        ]);

        $this->assertSame('file', $detail['related_links'][0]['type']);
        $this->assertTrue($detail['related_links'][0]['downloadable']);
        $this->assertSame('Download soal', $detail['related_links'][0]['text']);
        $this->assertSame('https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf', $detail['related_links'][0]['url']);
    }

    public function test_download_campus_file_rejects_portal_write_endpoints_before_any_request(): void
    {
        config(['portal.base_url' => 'https://my.cic.ac.id']);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->expects($this->never())->method('getSessionCookieJar');

        $service = new SipandaiSubmissionService($sessionManager);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push(Middleware::history($history));

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => $stack,
        ]));

        $maliciousPaths = [
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdel&id=30095&idx=29833&pertemuan=6',
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdelujian&id=9184',
            'https://my.cic.ac.id/portal/?mod=student&act=kesesuaian&id=1&jawab=1',
            'https://my.cic.ac.id/portal/?mod=logout',
            'files/../sipandai/?mod=sipandai&act=jobdel&id=30095&idx=29833&pertemuan=6',
            'files/%2e%2e/sipandai/index.php',
            'files\\..\\sipandai\\index.php',
        ];

        foreach ($maliciousPaths as $path) {
            try {
                $service->downloadCampusFile('token-download', $path);
                $this->fail("Download path should be rejected: {$path}");
            } catch (PortalScrapingException $exception) {
                $this->assertSame(422, $exception->getCode());
            }
        }

        $this->assertSame([], $history);
    }

    public function test_download_campus_file_serves_exam_soal_from_absolute_and_relative_paths(): void
    {
        config(['portal.base_url' => 'https://my.cic.ac.id', 'portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')
            ->with('token-download')
            ->willReturn(CookieJar::fromArray([], 'my.cic.ac.id'));

        $service = new SipandaiSubmissionService($sessionManager);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-absolute'),
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-relative'),
        ]));
        $stack->push(Middleware::history($history));

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => $stack,
        ]));

        $absolute = $service->downloadCampusFile('token-download', 'https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf');
        $relative = $service->downloadCampusFile('token-download', 'portal/files/soal/9197-UTS-tgs-591.pdf');

        $this->assertCount(2, $history);
        $this->assertSame('https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf', (string) $history[0]['request']->getUri());
        $this->assertSame('https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf', (string) $history[1]['request']->getUri());
        $this->assertSame('%PDF-absolute', $absolute['contents']);
        $this->assertSame('9197-UTS-tgs-591.pdf', $absolute['filename']);
        $this->assertSame('%PDF-relative', $relative['contents']);
    }

    public function test_download_campus_file_encodes_spaces_in_kolokium_file_names(): void
    {
        config(['portal.base_url' => 'https://my.cic.ac.id', 'portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')
            ->with('token-download')
            ->willReturn(CookieJar::fromArray([], 'my.cic.ac.id'));

        $service = new SipandaiSubmissionService($sessionManager);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-kolokium'),
        ]));
        $stack->push(Middleware::history($history));

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => $stack,
        ]));

        $download = $service->downloadCampusFile(
            'token-download',
            '../files/skripsi/kolokium/TUGAS AKHIR-SIDANG-20990000001-KOLOKIUM-ab12c.pdf'
        );

        $this->assertCount(1, $history);
        $this->assertSame(
            'https://my.cic.ac.id/portal/files/skripsi/kolokium/TUGAS%20AKHIR-SIDANG-20990000001-KOLOKIUM-ab12c.pdf',
            (string) $history[0]['request']->getUri()
        );
        $this->assertSame('TUGAS AKHIR-SIDANG-20990000001-KOLOKIUM-ab12c.pdf', $download['filename']);
    }

    public function test_extract_exam_form_meta_prefers_form_with_file_input(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $formMeta = $this->invokeNonPublicMethod($service, 'extractExamFormMeta', [
            <<<'HTML'
            <html>
                <body>
                    <form action="?mod=search" method="get">
                        <input type="text" name="q" value="algoritma">
                    </form>
                    <form action="?mod=sipandai&act=sendujian&id=8833" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="8833">
                        <input type="hidden" name="token" value="abc123">
                        <input type="file" name="jawaban">
                    </form>
                </body>
            </html>
            HTML,
        ]);

        $this->assertSame('?mod=sipandai&act=sendujian&id=8833', $formMeta['action']);
        $this->assertSame('jawaban', $formMeta['file_field']);
        $this->assertSame('8833', $formMeta['fields']['id']);
        $this->assertSame('abc123', $formMeta['fields']['token']);
        $this->assertTrue($formMeta['has_upload_form']);
    }

    public function test_extract_exam_form_meta_prefers_sendujian_form_without_file_input(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $formMeta = $this->invokeNonPublicMethod($service, 'extractExamFormMeta', [
            <<<'HTML'
            <html>
                <body>
                    <form action="?mod=search" method="get">
                        <input type="text" name="q" value="algoritma">
                    </form>
                    <form method="post" action="?mod=sipandai&act=sentujian" enctype="multipart/form-data">
                        <input type="hidden" name="data[id]" value="9186" />
                        <input type="hidden" name="data[tipe]" value="UTS" />
                        <input type="hidden" name="data[kelas]" value="TEORI" />
                        <input type="text" name="data[name]" size="70" maxlength="70" />
                    </form>
                </body>
            </html>
            HTML,
        ]);

        $this->assertSame('?mod=sipandai&act=sentujian', $formMeta['action']);
        $this->assertNull($formMeta['file_field']);
        $this->assertSame('9186', $formMeta['fields']['data[id]']);
        $this->assertSame('UTS', $formMeta['fields']['data[tipe]']);
        $this->assertSame('TEORI', $formMeta['fields']['data[kelas]']);
        $this->assertFalse($formMeta['has_upload_form']);
    }

    public function test_extract_exam_form_meta_marks_missing_form_as_unavailable(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $formMeta = $this->invokeNonPublicMethod($service, 'extractExamFormMeta', [
            '<html><body><div class="alert alert-warning">Upload jawaban sudah ditutup.</div></body></html>',
        ]);

        $this->assertNull($formMeta['file_field']);
        $this->assertSame([], $formMeta['fields']);
        $this->assertFalse($formMeta['has_upload_form']);
    }

    public function test_extract_exam_detail_marks_upload_unavailable_when_upload_form_is_not_rendered(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            <<<'HTML'
            <html>
                <body>
                    <div class="alert alert-warning">Upload jawaban belum dibuka.</div>
                    <p>Soal dan lampiran bisa dilihat di halaman ini.</p>
                </body>
            </html>
            HTML,
            8833,
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=8833',
        ]);

        $this->assertSame('not_uploaded', $detail['status']);
        $this->assertFalse($detail['form']['available']);
        $this->assertTrue($detail['form']['available_via_direct_submit']);
        $this->assertSame('file', $detail['form']['file_field']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['form']['action_url']);
        $this->assertSame('https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=8833', $detail['form']['get_url']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['form']['post_url']);
        $this->assertSame('8833', $detail['form']['fields']['data[id]']);
    }

    public function test_extract_exam_detail_exposes_distinct_get_and_post_urls(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            <<<'HTML'
            <html>
                <body>
                    <form action="?mod=sipandai&act=sendujian&id=8833" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="data[id]" value="8833" />
                        <input type="file" name="file" />
                    </form>
                </body>
            </html>
            HTML,
            8833,
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=8833',
        ]);

        $this->assertSame('https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=8833', $detail['metadata_url']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['submit_url']);
        $this->assertSame('https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=8833', $detail['form']['get_url']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['form']['post_url']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['form']['action_url']);
    }

    public function test_log_scraping_replaces_raw_session_token_with_fingerprint(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $service = new class($this->createMock(PortalSessionManager::class)) extends SipandaiSubmissionService
        {
            public function writeLog(): void
            {
                $this->logScraping('token leak check', [
                    'session_token' => 'raw-campus-token',
                    'username' => '20990000001',
                    'course_id' => 8837,
                ]);
            }
        };

        $service->writeLog();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Campus scraping: token leak check'
                    && ! array_key_exists('session_token', $context)
                    && ! array_key_exists('username', $context)
                    && isset($context['session_token_fingerprint'])
                    && isset($context['username_fingerprint'])
                    && $context['course_id'] === 8837;
            })
            ->once();
    }

    public function test_submission_action_rejects_external_absolute_url(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $this->expectException(\App\Exceptions\PortalScrapingException::class);

        $this->invokeNonPublicMethod($service, 'resolveActionUrl', [
            'https://evil.example.test/upload',
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=send',
        ]);
    }

    public function test_build_exam_unavailable_result_marks_existing_submission_as_already_uploaded(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $result = $this->invokeNonPublicMethod($service, 'buildExamUnavailableResultPayload', [
            <<<'HTML'
            <html>
                <body>
                    <a href="/storage/jawaban-8810.zip">Open Jawaban</a>
                    <a href="?mod=sipandai&act=sendujian&id=8810&hapus=1">Hapus Jawaban</a>
                </body>
            </html>
            HTML,
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=8810',
            8810,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('already_uploaded', $result['data']['status']);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokeNonPublicMethod(object $instance, string $method, array $arguments): mixed
    {
        $reflectionMethod = new \ReflectionMethod($instance, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($instance, $arguments);
    }

    private function setNonPublicProperty(object $instance, string $property, mixed $value): void
    {
        $reflectionProperty = new \ReflectionProperty(PortalScrapingService::class, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($instance, $value);
    }

    private function attendanceScannerPageHtml(): string
    {
        return <<<'HTML'
        <html>
            <head><title>Absensi Perkuliahan dengan QR Code</title></head>
            <body>
                <div class="container">
                    <h3>ABSEN PERTEMUAN 9</h3>
                    <p>Halaman scanner QR memakai JavaScript redirect ke discussabsen.</p>
                    <script>
                        var redirect = '../../?mod=sipandai&act=discussabsen&id=30216&courses_id=8837';
                        console.log(redirect);
                    </script>
                </div>
            </body>
        </html>
        HTML;
    }

    private function successfulAttendanceResponseHtml(): string
    {
        return <<<'HTML'
        <html>
            <body>
                <div class="card">
                    <div class="header">
                        <h4 class="title">Hasil Validasi Kode Unix Absensi</h4>
                    </div>
                    <div class="content table-responsive table-full-width">
                        <table class="table table-hover table-striped">
                            <tr><td>Nama Dosen</td><td>:</td><td><b>Dosen Contoh Dua, MMSI.</b></td></tr>
                        </table>
                    </div>
                </div>
                <div class="card">
                    <div class="header">
                        <h4 class="title">Hasil Validasi QR Code</h4>
                    </div>
                    <table class="table table-bordered">
                        <tr><td colspan="3"></td></tr>
                    </table>
                    <div class="alert alert-danger">
                        <center>
                            <strong>Sukses Malakukan Absensi Perkulliahan..!</strong><br>
                            <i>Silahkan cek di daftar kehadiran</i>
                        </center>
                    </div>
                </div>
            </body>
        </html>
        HTML;
    }
}
