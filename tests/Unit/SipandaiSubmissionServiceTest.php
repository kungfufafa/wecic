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
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
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
            'file_url' => 'https://portal.invalid/portal/files/tugasmhs/9195-UTS-20990000001-785.pdf',
            'file_name' => '9195-UTS-20990000001-785.pdf',
            'delete_url' => 'https://portal.invalid/portal/sipandai/?mod=sipandai&act=jobdelujian&id=131828',
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
            ->willReturn(CookieJar::fromArray([], 'portal.invalid'));

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
            ->willReturn(CookieJar::fromArray([], 'portal.invalid'));

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
            ->willReturn(CookieJar::fromArray([], 'portal.invalid'));

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

    public function test_submit_exam_refuses_when_the_portal_renders_no_upload_form(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-exam-direct'));
        $history = [];
        $attachment = UploadedFile::fake()->create('jawaban.pdf', 128, 'application/pdf');

        $this->fakePortal($service, [
            new Response(200, [], <<<'HTML'
            <html>
                <body>
                    <div class="alert alert-warning">Upload jawaban belum dibuka oleh jadwal portal.</div>
                    <p>Halaman ini sengaja tidak merender form upload sampai jam ujian dibuka.</p>
                </body>
            </html>
            HTML),
        ], $history);

        $result = $service->submitExam('token-exam-direct', 8833, $attachment);

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertFalse($result['success']);
        $this->assertSame('upload_unavailable', $result['data']['status']);
        $this->assertSame('Portal belum membuka form upload untuk ujian ini.', $result['message']);
    }

    public function test_submit_exam_refuses_a_closed_exam_instead_of_posting_its_hidden_fields(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-exam-closed'));
        $history = [];
        $attachment = UploadedFile::fake()->create('UTS-TTE_MAHASISWACONTOH.pdf', 128, 'application/pdf');

        $this->fakePortal($service, [
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
        ], $history);

        $result = $service->submitExam('token-exam-closed', 9186, $attachment, [
            'data[name]' => 'Jawaban UTS Teknologi dan trend E-commerce',
        ]);

        $this->assertCount(1, $history);
        $this->assertFalse($result['success']);
        $this->assertSame('upload_unavailable', $result['data']['status']);
        $this->assertSame('Waktu ujian sudah berakhir; portal tidak menerima jawaban lagi.', $result['message']);
    }

    public function test_submit_exam_posts_the_portal_hidden_fields_and_ignores_overrides_for_them(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-exam-open'));
        $history = [];
        $attachment = UploadedFile::fake()->create('UTS-TTE_MAHASISWACONTOH.pdf', 128, 'application/pdf');

        $this->fakePortal($service, [
            new Response(200, [], $this->openExamFormHtml(9186, 'UTS')),
            new Response(200, [], '<html><body><a href="?mod=sipandai&act=sendujian&id=9186"><b>Data sudah disimpan</b></a></body></html>'),
            new Response(200, [], $this->openExamFormHtml(9186, 'UTS')),
        ], $history);

        $result = $service->submitExam('token-exam-open', 9186, $attachment, [
            'data[name]' => 'Jawaban UTS Teknologi dan trend E-commerce',
            'data[tipe]' => 'UAS',
            'data[id]' => '1',
        ]);

        $this->assertSame('POST', $history[1]['request']->getMethod());
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), (string) $history[1]['request']->getUri());

        $submittedBody = (string) $history[1]['request']->getBody();

        $this->assertMatchesRegularExpression('/name="data\[id\]"\s+Content-Length: \d+\s+9186/', $submittedBody);
        $this->assertMatchesRegularExpression('/name="data\[tipe\]"\s+Content-Length: \d+\s+UTS/', $submittedBody);
        $this->assertStringNotContainsString('UAS', $submittedBody);
        $this->assertStringContainsString('Jawaban UTS Teknologi dan trend E-commerce', $submittedBody);
        $this->assertStringContainsString('filename="UTS-TTE_MAHASISWACONTOH.pdf"', $submittedBody);
        $this->assertTrue($result['success']);
        $this->assertSame('uploaded', $result['data']['status']);
        $this->assertFalse($result['data']['direct_submit_fallback']);
    }

    public function test_submit_exam_confirms_an_unclear_reply_from_a_new_answer_row_of_the_open_exam(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-exam-verify'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->openExamFormHtml(9195, 'UAS', $this->examAnswerRowHtml('UTS', 131828))),
            new Response(200, [], '<html><body><p>Ujian Akhir Semester</p></body></html>'),
            new Response(200, [], $this->openExamFormHtml(9195, 'UAS', $this->examAnswerRowHtml('UTS', 131828).$this->examAnswerRowHtml('UAS', 131900))),
        ], $history);

        $result = $service->submitExam('token-exam-verify', 9195, UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'));

        $this->assertCount(3, $history);
        $this->assertTrue($result['success']);
        $this->assertSame('uploaded', $result['data']['status']);
        $this->assertTrue($result['data']['verified']);
        $this->assertSame([131900], array_column($result['data']['submission_records'], 'submission_id'));
    }

    public function test_submit_exam_reports_an_unclear_reply_as_not_uploaded_when_only_the_earlier_exam_is_listed(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-exam-missing'));
        $history = [];
        $formWithUtsAnswer = $this->openExamFormHtml(9195, 'UAS', $this->examAnswerRowHtml('UTS', 131828));

        $this->fakePortal($service, [
            new Response(200, [], $formWithUtsAnswer),
            new Response(200, [], '<html><body><p>Ujian Akhir Semester</p></body></html>'),
            new Response(200, [], $formWithUtsAnswer),
        ], $history);

        $result = $service->submitExam('token-exam-missing', 9195, UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'));

        $this->assertFalse($result['success']);
        $this->assertSame('not_uploaded', $result['data']['status']);
        $this->assertSame('Jawaban ujian belum masuk ke portal.', $result['message']);
    }

    public function test_submit_exam_checks_the_exam_page_when_the_upload_request_gets_no_reply(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-exam-timeout'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->openExamFormHtml(9195, 'UAS')),
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', PortalEndpointRegistry::url('exam_submit'))),
            new Response(200, [], '<html><body><p>Halaman ujian sedang sibuk.</p></body></html>'),
        ], $history);

        $result = $service->submitExam('token-exam-timeout', 9195, UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'));

        $this->assertCount(3, $history);
        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['data']['status']);
        $this->assertTrue($result['data']['transport_error']);
        $this->assertSame('Status upload ujian belum pasti. Cek halaman ujian sebelum mengunggah ulang.', $result['message']);
    }

    public function test_determine_upload_status_does_not_read_links_on_a_redisplayed_page_as_success(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));
        $html = (string) file_get_contents(base_path('tests/Fixtures/portal/sipandai_course_detail.html'));

        $status = $this->invokeNonPublicMethod($service, 'determineUploadStatus', [$html, []]);

        $this->assertFalse($status['success']);
        $this->assertSame('unknown', $status['code']);
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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=8810',
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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=9184',
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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=9195',
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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=9197',
        ]);

        $this->assertSame('file', $detail['related_links'][0]['type']);
        $this->assertTrue($detail['related_links'][0]['downloadable']);
        $this->assertSame('Download soal', $detail['related_links'][0]['text']);
        $this->assertSame('https://portal.invalid/portal/files/soal/9197-UTS-tgs-591.pdf', $detail['related_links'][0]['url']);
    }

    public function test_download_campus_file_rejects_portal_write_endpoints_before_any_request(): void
    {
        config(['portal.base_url' => 'https://portal.invalid']);

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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=jobdel&id=30095&idx=29833&pertemuan=6',
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=jobdelujian&id=9184',
            'https://portal.invalid/portal/?mod=student&act=kesesuaian&id=1&jawab=1',
            'https://portal.invalid/portal/?mod=logout',
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
        config(['portal.base_url' => 'https://portal.invalid', 'portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')
            ->with('token-download')
            ->willReturn(CookieJar::fromArray([], 'portal.invalid'));

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

        $absolute = $service->downloadCampusFile('token-download', 'https://portal.invalid/portal/files/soal/9197-UTS-tgs-591.pdf');
        $relative = $service->downloadCampusFile('token-download', 'portal/files/soal/9197-UTS-tgs-591.pdf');

        $this->assertCount(2, $history);
        $this->assertSame('https://portal.invalid/portal/files/soal/9197-UTS-tgs-591.pdf', (string) $history[0]['request']->getUri());
        $this->assertSame('https://portal.invalid/portal/files/soal/9197-UTS-tgs-591.pdf', (string) $history[1]['request']->getUri());
        $this->assertSame('%PDF-absolute', $absolute['contents']);
        $this->assertSame('9197-UTS-tgs-591.pdf', $absolute['filename']);
        $this->assertSame('%PDF-relative', $relative['contents']);
    }

    public function test_download_campus_file_encodes_spaces_in_kolokium_file_names(): void
    {
        config(['portal.base_url' => 'https://portal.invalid', 'portal.request_delay' => 0]);

        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')
            ->with('token-download')
            ->willReturn(CookieJar::fromArray([], 'portal.invalid'));

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
            'https://portal.invalid/portal/files/skripsi/kolokium/TUGAS%20AKHIR-SIDANG-20990000001-KOLOKIUM-ab12c.pdf',
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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=8833',
        ]);

        $this->assertSame('not_uploaded', $detail['status']);
        $this->assertFalse($detail['form']['available']);
        $this->assertTrue($detail['form']['available_via_direct_submit']);
        $this->assertSame('file', $detail['form']['file_field']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['form']['action_url']);
        $this->assertSame('https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=8833', $detail['form']['get_url']);
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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=8833',
        ]);

        $this->assertSame('https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=8833', $detail['metadata_url']);
        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $detail['submit_url']);
        $this->assertSame('https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=8833', $detail['form']['get_url']);
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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=send',
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
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=8810',
            8810,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('already_uploaded', $result['data']['status']);
    }

    public function test_extract_exam_detail_is_not_uploaded_when_the_page_only_offers_the_question_file(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            $this->openExamFormHtml(9197, 'UTS', '', '<a href="../files/soal/9197-UTS-tgs-591.pdf">Download soal</a>'),
            9197,
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=9197',
        ]);

        $this->assertSame('not_uploaded', $detail['status']);
        $this->assertSame([], $detail['submissions']);
        $this->assertTrue($detail['form']['available']);
    }

    public function test_extract_exam_detail_ignores_the_uts_answer_on_a_uas_page(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            $this->openExamFormHtml(9195, 'UAS', $this->examAnswerRowHtml('UTS', 131828)),
            9195,
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=9195',
        ]);

        $this->assertSame('UAS', $detail['exam_type']);
        $this->assertSame('not_uploaded', $detail['status']);
        $this->assertSame([], $detail['submissions']);
        $this->assertNull($detail['latest_submission']);
    }

    public function test_extract_exam_detail_is_uploaded_once_the_uas_answer_is_listed(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            $this->openExamFormHtml(9195, 'UAS', $this->examAnswerRowHtml('UTS', 131828).$this->examAnswerRowHtml('UAS', 131900)),
            9195,
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=9195',
        ]);

        $this->assertSame('uploaded', $detail['status']);
        $this->assertSame([131900], array_column($detail['submissions'], 'submission_id'));
        $this->assertSame(131900, $detail['latest_submission']['submission_id']);
    }

    public function test_extract_exam_detail_offers_only_the_fields_a_student_fills_in(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            $this->openExamFormHtml(9186, 'UTS'),
            9186,
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=9186',
        ]);

        $this->assertSame(['data[name]'], $detail['form']['editable_fields']);
        $this->assertSame('UTS', $detail['form']['fields']['data[tipe]']);
    }

    public function test_extract_exam_list_keeps_the_full_course_code_from_a_code_only_column(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $exams = $this->invokeNonPublicMethod($service, 'extractExamList', [
            new Crawler(<<<'HTML'
            <html>
                <body>
                    <table>
                        <thead><tr><th>No</th><th>Kode MK</th><th>Mata Kuliah</th><th>Jadwal Ujian</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr><td>1</td><td>SI033017</td><td>Statistik Inferensial</td><td>Senin, 18 Mei 2026 09:00</td><td>Terjadwal</td></tr>
                        </tbody>
                    </table>
                </body>
            </html>
            HTML),
        ]);

        $this->assertSame('SI033017', $exams[0]['course_code']);
        $this->assertSame('Statistik Inferensial', $exams[0]['course_name']);
    }

    public function test_exam_submission_records_are_not_kept_or_shared_without_a_known_student(): void
    {
        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSession')->willReturn(null);
        $service = new SipandaiSubmissionService($sessionManager);

        ExamSubmissionRecord::query()->create([
            'username' => null,
            'exam_id' => 9195,
            'record_key' => 'anonymous-row',
            'submission_id' => 131828,
            'file_name' => '9195-UTS-20990000001-785.pdf',
            'source' => 'detail',
            'metadata' => [],
            'last_seen_at' => now(),
        ]);

        $this->invokeNonPublicMethod($service, 'rememberExamSubmissionRecords', [
            'token-without-owner',
            9195,
            [['exam_id' => 9195, 'submission_id' => 131900, 'file_name' => '9195-UAS-20990000002-1.pdf']],
            'detail',
        ]);

        $this->assertSame(1, ExamSubmissionRecord::query()->count());
        $this->assertSame([], $this->invokeNonPublicMethod($service, 'storedExamSubmissionRecords', ['token-without-owner', 9195]));
    }

    public function test_inspect_assignment_ignores_question_files_and_download_icons(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-inspect'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->assignmentPageHtml(<<<'HTML'
                <img src="controllers/assets/images/download.png">
                <a href="../files/latihan/ae614c557843b1df326cb29c57225459-6-lat-fa8.pdf">Download soal tugas</a>
                <form method="post" action="?mod=sipandai&act=sent" enctype="multipart/form-data">
                    <input type="file" name="data[tugas]" accept=".pdf">
                    <small>Format .pdf, maks. 10 MB</small>
                </form>
            HTML)),
        ], $history);

        $state = $service->inspectAssignmentSubmissionState('token-inspect', 9198, 33317, 6);

        $this->assertSame('not_uploaded', $state['status']);
        $this->assertNull($state['file_info']);
        $this->assertNull($state['delete_payload']);
    }

    public function test_inspect_assignment_ignores_the_students_uploads_for_other_meetings(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-inspect'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->assignmentPageHtml(<<<'HTML'
                <table><tr>
                    <td><a href="../files/tugasmhs/9198-2-20990000001-a11.pdf">Open File</a></td>
                    <td><a href="?mod=sipandai&amp;act=jobdel&amp;id=126001&amp;idx=33001&amp;pertemuan=2">Hapus</a></td>
                </tr></table>
                <form method="post" action="?mod=sipandai&act=sent" enctype="multipart/form-data">
                    <input type="file" name="data[tugas]">
                </form>
            HTML)),
        ], $history);

        $state = $service->inspectAssignmentSubmissionState('token-inspect', 9198, 33317, 6);

        $this->assertSame('not_uploaded', $state['status']);
        $this->assertNull($state['delete_payload']);
    }

    public function test_inspect_assignment_reads_this_meetings_submission_and_delete_link(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-inspect'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->assignmentPageHtml(<<<'HTML'
                <table>
                    <tr>
                        <td><a href="../files/tugasmhs/9198-2-20990000001-a11.pdf">Open File</a></td>
                        <td><a href="?mod=sipandai&amp;act=jobdel&amp;id=126001&amp;idx=33001&amp;pertemuan=2">Hapus</a></td>
                    </tr>
                    <tr>
                        <td><a href="../files/tugasmhs/9198-6-20990000001-a14.pdf">Open File</a></td>
                        <td><a href="?mod=sipandai&amp;act=jobdel&amp;id=126663&amp;idx=33317&amp;pertemuan=6">Hapus</a></td>
                    </tr>
                </table>
            HTML)),
        ], $history);

        $state = $service->inspectAssignmentSubmissionState('token-inspect', 9198, 33317, 6);

        $this->assertSame('uploaded', $state['status']);
        $this->assertSame(['id' => '126663', 'idx' => '33317', 'pertemuan' => '6'], $state['delete_payload']);
        $this->assertSame('../files/tugasmhs/9198-6-20990000001-a14.pdf', $state['file_info']['href']);
    }

    public function test_submit_assignment_reports_a_closed_upload_without_posting(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-closed-task'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->assignmentPageHtml('<b>Batas waktu pengumpulan tugas sudah berakhir.</b>')),
        ], $history);

        $result = $service->submitAssignment('token-closed-task', 9198, 33317, 6, UploadedFile::fake()->create('tugas.pdf', 64, 'application/pdf'));

        $this->assertCount(1, $history);
        $this->assertFalse($result['success']);
        $this->assertSame('upload_unavailable', $result['data']['status']);
        $this->assertStringNotContainsString('input[type="file"]', $result['message']);
    }

    public function test_submit_assignment_reports_an_upload_without_reply_as_unknown(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-slow-task'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->assignmentPageHtml('<form method="post" action="?mod=sipandai&act=sent" enctype="multipart/form-data"><input type="file" name="data[tugas]"></form>')),
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sent')),
        ], $history);

        $result = $service->submitAssignment('token-slow-task', 9198, 33317, 6, UploadedFile::fake()->create('tugas.pdf', 64, 'application/pdf'));

        $this->assertCount(2, $history);
        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['data']['status']);
        $this->assertTrue($result['data']['transport_error']);
    }

    public function test_extract_exam_detail_does_not_read_links_that_only_mention_the_answer_as_a_sent_answer(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $detail = $this->invokeNonPublicMethod($service, 'extractExamDetail', [
            $this->openExamFormHtml(9195, 'UAS', '', <<<'HTML'
                <a href="#form-upload">Upload Jawaban Ujian</a>
                <a href="?mod=sipandai&amp;act=sendujian&amp;id=9195">Kirim Jawaban Ujian</a>
                <a href="../files/soal/9195-UAS-soal-12.pdf">Lihat Jawaban Soal</a>
                HTML),
            9195,
            'https://portal.invalid/portal/sipandai/?mod=sipandai&act=sendujian&id=9195',
        ]);

        $this->assertSame('not_uploaded', $detail['status']);
        $this->assertSame([], $detail['submissions']);
        $this->assertNull($detail['latest_submission']);
        $this->assertTrue($detail['form']['available']);
    }

    public function test_submit_exam_keeps_an_upload_without_reply_unverified_while_the_page_still_shows_the_form(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-exam-slow'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->openExamFormHtml(9195, 'UAS', $this->examAnswerRowHtml('UTS', 131828))),
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', PortalEndpointRegistry::url('exam_submit'))),
            new Response(200, [], $this->openExamFormHtml(9195, 'UAS', $this->examAnswerRowHtml('UTS', 131828))),
        ], $history);

        $result = $service->submitExam('token-exam-slow', 9195, UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'));

        $this->assertCount(3, $history);
        $this->assertFalse($result['success']);
        $this->assertSame('unknown', $result['data']['status']);
        $this->assertTrue($result['data']['transport_error']);
        $this->assertSame('Status upload ujian belum pasti. Cek halaman ujian sebelum mengunggah ulang.', $result['message']);
    }

    public function test_submit_exam_confirms_an_upload_without_reply_once_the_answer_is_listed(): void
    {
        config(['portal.request_delay' => 0]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-exam-slow'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, [], $this->openExamFormHtml(9195, 'UAS')),
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', PortalEndpointRegistry::url('exam_submit'))),
            new Response(200, [], $this->openExamFormHtml(9195, 'UAS', $this->examAnswerRowHtml('UAS', 131900))),
        ], $history);

        $result = $service->submitExam('token-exam-slow', 9195, UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'));

        $this->assertTrue($result['success']);
        $this->assertSame('uploaded', $result['data']['status']);
        $this->assertSame([131900], array_column($result['data']['submission_records'], 'submission_id'));
    }

    public function test_inspect_assignment_matches_the_delete_link_on_its_meeting_and_uses_idx_only_between_several(): void
    {
        config(['portal.request_delay' => 0]);

        $inspect = function (string $rows): array {
            $token = 'token-inspect-'.md5($rows);
            $service = new SipandaiSubmissionService($this->sessionManagerFor($token));
            $history = [];
            $this->fakePortal($service, [new Response(200, [], $this->assignmentPageHtml("<table>{$rows}</table>"))], $history);

            return $service->inspectAssignmentSubmissionState($token, 9198, 33317, 6);
        };
        $deleteRow = fn (int $submissionId, int $idx, int $meeting): string => sprintf(
            '<tr><td><a href="?mod=sipandai&amp;act=jobdel&amp;id=%d&amp;idx=%d&amp;pertemuan=%d">Hapus</a></td></tr>',
            $submissionId,
            $idx,
            $meeting
        );

        $single = $inspect($deleteRow(126001, 33001, 2).$deleteRow(126663, 40001, 6));
        $several = $inspect($deleteRow(126660, 40001, 6).$deleteRow(126663, 33317, 6));
        $ambiguous = $inspect($deleteRow(126660, 40001, 6).$deleteRow(126661, 40002, 6));

        $this->assertSame('uploaded', $single['status']);
        $this->assertSame(['id' => '126663', 'idx' => '40001', 'pertemuan' => '6'], $single['delete_payload']);
        $this->assertSame(['id' => '126663', 'idx' => '33317', 'pertemuan' => '6'], $several['delete_payload']);
        $this->assertNull($ambiguous['delete_payload']);
    }

    public function test_stream_campus_file_hands_over_the_body_with_its_own_download_timeouts(): void
    {
        config([
            'portal.base_url' => 'https://portal.invalid',
            'portal.request_delay' => 0,
            'portal.scraping.download_timeout' => 240,
            'portal.scraping.download_read_timeout' => 45,
        ]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-stream'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, ['Content-Type' => 'application/vnd.ms-powerpoint', 'Content-Length' => '13'], '%PPT-lecture1'),
        ], $history);

        $download = $service->streamCampusFile('token-stream', 'portal/files/modul/9198-6-deck.ppt');

        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['options']['stream']);
        $this->assertSame(240, $history[0]['options']['timeout']);
        $this->assertSame(45, $history[0]['options']['read_timeout']);
        $this->assertSame('9198-6-deck.ppt', $download['filename']);
        $this->assertSame('application/vnd.ms-powerpoint', $download['content_type']);
        $this->assertSame(13, $download['size']);
        $this->assertSame('%PPT-lecture1', (string) $download['stream']);
    }

    public function test_stream_campus_file_refuses_files_above_the_download_cap_and_portal_pages(): void
    {
        config([
            'portal.base_url' => 'https://portal.invalid',
            'portal.request_delay' => 0,
            'portal.scraping.download_max_bytes' => 1024,
        ]);

        $service = new SipandaiSubmissionService($this->sessionManagerFor('token-stream'));
        $history = [];

        $this->fakePortal($service, [
            new Response(200, ['Content-Type' => 'video/mp4', 'Content-Length' => '4096'], str_repeat('x', 4096)),
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><head><title>SIPANDAI</title></head><body>File tidak tersedia.</body></html>'),
        ], $history);

        foreach (['portal/files/modul/rekaman.mp4', 'portal/files/modul/hilang.pdf'] as $path) {
            try {
                $service->streamCampusFile('token-stream', $path);
                $this->fail("The download should be refused: {$path}");
            } catch (PortalScrapingException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(2, $history);
    }

    private function sessionManagerFor(string $token): PortalSessionManager
    {
        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')
            ->with($token)
            ->willReturn(CookieJar::fromArray([], 'portal.invalid'));
        $sessionManager->method('getSession')->willReturn(['username' => '20990000001']);

        return $sessionManager;
    }

    /**
     * @param  list<Response|\Throwable>  $responses
     * @param  array<int, mixed>  $history
     */
    private function fakePortal(SipandaiSubmissionService $service, array $responses, array &$history): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $this->setNonPublicProperty($service, 'client', new Client([
            'handler' => $stack,
        ]));
    }

    private function openExamFormHtml(int $examId, string $examType, string $answerRows = '', string $extra = ''): string
    {
        return <<<HTML
            <html>
                <body>
                    <h3>Upload Jawaban Ujian</h3>
                    {$extra}
                    <form method="post" action="?mod=sipandai&act=sentujian" enctype="multipart/form-data">
                        <input type="hidden" name="data[id]" value="{$examId}" />
                        <input type="hidden" name="data[tipe]" value="{$examType}" />
                        <input type="hidden" name="data[kelas]" value="TEORI" />
                        <input type="text" name="data[name]" size="70" maxlength="70" />
                        <input type="file" name="file" />
                    </form>
                    <table>
                        <tr><td>No.</td><td>Tgl. Upload</td><td>Ujian</td><td>Keterangan</td><td>Jawaban</td></tr>
                        {$answerRows}
                    </table>
                </body>
            </html>
            HTML;
    }

    private function examAnswerRowHtml(string $examType, int $submissionId): string
    {
        return <<<HTML
            <tr class="altrow">
                <td align="center">1.</td>
                <td>Rabu, 20 Mei 2026</td>
                <td>{$examType} / Reguler</td>
                <td>-</td>
                <td align="center">
                    <a href="../files/tugasmhs/9195-{$examType}-20990000001-{$submissionId}.pdf" target="_blank">Open Jawaban Ujian</a>
                    |
                    <a href="?mod=sipandai&amp;act=jobdelujian&amp;id={$submissionId}">Hapus Jawaban</a>
                </td>
            </tr>
            HTML;
    }

    private function assignmentPageHtml(string $content): string
    {
        return <<<HTML
            <html>
                <head><title>SIPANDAI</title></head>
                <body>
                    <a href="?mod=logout"><i class="bi bi-download"></i> Keluar</a>
                    <div class="card"><div class="content">{$content}</div></div>
                </body>
            </html>
            HTML;
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
