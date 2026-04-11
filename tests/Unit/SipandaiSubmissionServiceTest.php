<?php

declare(strict_types=1);

namespace Tests\Unit;

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
use Tests\TestCase;

class SipandaiSubmissionServiceTest extends TestCase
{
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

    public function test_resolve_exam_submit_url_uses_sentujian_for_sendujian_forms(): void
    {
        $service = new SipandaiSubmissionService($this->createMock(PortalSessionManager::class));

        $actionUrl = $this->invokeNonPublicMethod($service, 'resolveExamSubmitUrl', [
            '?mod=sipandai&act=sendujian&id=8833',
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=sendujian&id=8833',
        ]);

        $this->assertSame(PortalEndpointRegistry::url('exam_submit'), $actionUrl);
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
                            <tr><td>Nama Dosen</td><td>:</td><td><b>Lena Magdalena, MMSI.</b></td></tr>
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
