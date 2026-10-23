<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalResponseRecorder;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * F114: with portal.debug.record_action_responses on, the portal's answer
 * to every write action and every page read to verify one is kept
 * sanitized; nothing else is, and recording never changes an action.
 */
class SipandaiSubmissionRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-recording';

    private const NIM = '20231234567';

    private string $capturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capturePath = storage_path('framework/testing/portal-captures-'.Str::random(12));
        config([
            'portal.request_delay' => 0,
            'portal.debug.record_action_responses' => true,
            'portal.debug.allow_in_production' => false,
            'portal.debug.capture_path' => $this->capturePath,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->capturePath);

        parent::tearDown();
    }

    public function test_an_assignment_delete_and_the_page_read_to_verify_it_are_recorded_sanitized(): void
    {
        $service = $this->service([
            new Response(200, [], $this->page('<div class="alert alert-success">Data tugas Budi Santoso ('.self::NIM.') dihapus.</div>')),
            new Response(200, [], $this->page('<form method="post" action="?mod=sipandai&amp;act=sent" enctype="multipart/form-data"><input type="file" name="data[tugas]"></form>')),
        ]);

        $service->deleteAssignment(self::TOKEN, 126663, 33317, 6);
        $state = $service->inspectAssignmentSubmissionState(self::TOKEN, 9198, 33317, 6);

        $this->assertSame('not_uploaded', $state['status']);
        $this->assertSame(['assignment_delete', 'assignment_delete_verify'], $this->recordedActions());

        $reply = $this->recorded('assignment_delete')[0];
        $this->assertStringContainsString('Data tugas Mahasiswa Uji ('.PortalResponseRecorder::NIM_PLACEHOLDER.') dihapus.', $reply);
        $this->assertStringNotContainsString(self::NIM, $reply);
        $this->assertStringContainsString('<input type="file" name="data[tugas]">', $this->recorded('assignment_delete_verify')[0]);
    }

    public function test_an_assignment_upload_reply_is_recorded_without_the_uploaded_file_name_and_its_form_read_is_not(): void
    {
        $service = $this->service([
            new Response(200, [], $this->page('<form method="post" action="?mod=sipandai&amp;act=sent" enctype="multipart/form-data"><input type="hidden" name="pertemuan" value="6"><input type="file" name="data[tugas]"></form>')),
            new Response(200, [], $this->page('<div class="alert">File Tugas Budi Santoso.pdf tersimpan.</div>')),
            new Response(200, [], $this->page('<a href="../files/tugasmhs/9198-6-'.self::NIM.'-a14.pdf">Open File</a> <a href="?mod=sipandai&amp;act=jobdel&amp;id=126663&amp;idx=33317&amp;pertemuan=6">Hapus</a>')),
        ]);

        $service->submitAssignment(self::TOKEN, 9198, 33317, 6, UploadedFile::fake()->create('Tugas Budi Santoso.pdf', 64, 'application/pdf'));
        $state = $service->inspectAssignmentSubmissionState(self::TOKEN, 9198, 33317, 6);

        $this->assertSame('uploaded', $state['status']);
        $this->assertSame(['assignment_upload', 'assignment_upload_verify'], $this->recordedActions());
        $this->assertCount(1, $this->recorded('assignment_upload'));
        $this->assertStringContainsString('File berkas-unggahan.pdf tersimpan.', $this->recorded('assignment_upload')[0]);
        $this->assertStringContainsString('9198-6-'.PortalResponseRecorder::NIM_PLACEHOLDER.'-a14.pdf', $this->recorded('assignment_upload_verify')[0]);
    }

    public function test_an_attendance_reply_is_recorded_and_the_scanner_page_read_before_it_is_not(): void
    {
        $service = $this->service([
            new Response(200, [], '<html><head><title>Absensi</title></head><body><div class="container"><h3>ABSEN PERTEMUAN 9</h3><p>Pindai kode QR.</p></div></body></html>'),
            new Response(200, [], $this->page('<div class="alert alert-success">Sukses Malakukan Absensi Perkulliahan..! BUDI SANTOSO</div>')),
        ]);

        $result = $service->submitAttendance(self::TOKEN, 8837, 30216, 9, ['kode' => '35533429']);

        $this->assertTrue($result['success']);
        $this->assertSame(['attendance_submit'], $this->recordedActions());
        $this->assertCount(1, $this->recorded('attendance_submit'));
        $this->assertStringContainsString('Perkulliahan..! MAHASISWA UJI', $this->recorded('attendance_submit')[0]);
    }

    public function test_an_exam_upload_reply_and_the_sendujian_page_read_to_verify_it_are_recorded(): void
    {
        $service = $this->service([
            new Response(200, [], $this->examForm()),
            new Response(200, [], '<html><body><p>Ujian Akhir Semester</p></body></html>'),
            new Response(200, [], $this->examForm('<tr><td>Rabu, 20 Mei 2026</td><td>UAS / Reguler</td><td>-</td><td><a href="../files/tugasmhs/9195-UAS-'.self::NIM.'-131900.pdf">Open Jawaban Ujian</a> | <a href="?mod=sipandai&amp;act=jobdelujian&amp;id=131900">Hapus Jawaban</a></td></tr>')),
        ]);

        $result = $service->submitExam(self::TOKEN, 9195, UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['verified']);
        $this->assertSame(['exam_upload', 'exam_upload_verify'], $this->recordedActions());
        $this->assertStringContainsString('Ujian Akhir Semester', $this->recorded('exam_upload')[0]);
        $this->assertStringContainsString('9195-UAS-'.PortalResponseRecorder::NIM_PLACEHOLDER.'-131900.pdf', $this->recorded('exam_upload_verify')[0]);
    }

    public function test_the_page_re_read_after_an_accepted_exam_upload_is_recorded_as_its_verification(): void
    {
        $service = $this->service([
            new Response(200, [], $this->examForm()),
            new Response(200, [], $this->page('<div class="alert alert-success">Upload berhasil disimpan.</div>')),
            new Response(200, [], $this->examForm('<tr><td>Rabu, 20 Mei 2026</td><td>UAS / Reguler</td><td>-</td><td><a href="../files/tugasmhs/9195-UAS-'.self::NIM.'-131900.pdf">Open Jawaban Ujian</a></td></tr>')),
        ]);

        $result = $service->submitExam(self::TOKEN, 9195, UploadedFile::fake()->create('uas.pdf', 64, 'application/pdf'));

        $this->assertTrue($result['success']);
        $this->assertSame(['exam_upload', 'exam_upload_verify'], $this->recordedActions());
        $this->assertStringContainsString('Upload berhasil disimpan.', $this->recorded('exam_upload')[0]);
    }

    public function test_an_error_reply_to_a_write_action_is_recorded_too(): void
    {
        $service = $this->service([
            new Response(500, [], '<html><head><title>Portal</title></head><body>Fatal error di baris 12 untuk '.self::NIM.'</body></html>'),
        ]);

        try {
            $service->deleteAssignment(self::TOKEN, 126663, 33317, 6);
            $this->fail('A 500 reply should fail the delete.');
        } catch (ServerException) {
        }

        $this->assertSame(['assignment_delete'], $this->recordedActions());
        $this->assertStringContainsString('Fatal error di baris 12 untuk '.PortalResponseRecorder::NIM_PLACEHOLDER, $this->recorded('assignment_delete')[0]);
    }

    public function test_a_page_read_outside_a_write_action_is_not_recorded(): void
    {
        $service = $this->service([
            new Response(200, [], $this->page('<form method="post" action="?mod=sipandai&amp;act=sent" enctype="multipart/form-data"><input type="file" name="data[tugas]"></form>')),
        ]);

        $service->inspectAssignmentSubmissionState(self::TOKEN, 9198, 33317, 6);

        $this->assertDirectoryDoesNotExist($this->capturePath);
    }

    public function test_a_reply_is_still_recorded_sanitized_when_the_session_ends_during_the_action(): void
    {
        $sessionEnded = false;
        $service = $this->service([
            new Response(200, [], $this->page('<form method="post" action="?mod=sipandai&amp;act=sent" enctype="multipart/form-data"><input type="hidden" name="pertemuan" value="6"><input type="file" name="data[tugas]"></form>')),
            function () use (&$sessionEnded): Response {
                $sessionEnded = true;

                return new Response(200, [], $this->page('<div class="alert">Tugas Budi Santoso ('.self::NIM.') tersimpan: Laporan Budi.pdf</div>'));
            },
        ], function () use (&$sessionEnded): ?array {
            return $sessionEnded ? null : $this->campusSession();
        });

        $service->submitAssignment(self::TOKEN, 9198, 33317, 6, UploadedFile::fake()->create('Laporan Budi.pdf', 64, 'application/pdf'));

        $this->assertTrue($sessionEnded);
        $this->assertSame(['assignment_upload'], $this->recordedActions());
        $this->assertStringContainsString('Tugas Mahasiswa Uji ('.PortalResponseRecorder::NIM_PLACEHOLDER.') tersimpan: berkas-unggahan.pdf', $this->recorded('assignment_upload')[0]);
    }

    public function test_nothing_is_recorded_without_the_campus_session_and_the_action_is_unchanged(): void
    {
        Log::spy();
        $service = $this->service([
            new Response(200, [], '<html><head><title>Absensi</title></head><body><div class="container"><h3>ABSEN PERTEMUAN 9</h3><p>Pindai kode QR.</p></div></body></html>'),
            new Response(200, [], $this->page('<div class="alert alert-success">Sukses Malakukan Absensi Perkulliahan..! BUDI SANTOSO '.self::NIM.'</div>')),
        ], static fn (): ?array => null);

        $result = $service->submitAttendance(self::TOKEN, 8837, 30216, 9, ['kode' => '35533429']);

        $this->assertTrue($result['success']);
        $this->assertSame('marked', $result['data']['status']);
        $this->assertDirectoryDoesNotExist($this->capturePath);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Portal action response not recorded: no student name and NIM to sanitize it with'
                && $context['action'] === 'attendance_submit'
                && $context['reason'] === 'no_session')
            ->once();
    }

    public function test_nothing_is_recorded_while_the_debug_switch_is_off(): void
    {
        config(['portal.debug.record_action_responses' => false]);
        $this->mock(PortalResponseRecorder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isEnabled')->andReturnFalse();
            $mock->shouldNotReceive('record');
        });
        $service = $this->service([
            new Response(200, [], $this->page('<div class="alert alert-success">Data tugas dihapus.</div>')),
        ]);

        $service->deleteAssignment(self::TOKEN, 126663, 33317, 6);

        $this->assertDirectoryDoesNotExist($this->capturePath);
    }

    public function test_a_recording_that_fails_never_changes_the_actions_result(): void
    {
        $this->mock(PortalResponseRecorder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isEnabled')->andReturnTrue();
            $mock->shouldReceive('record')->andThrow(new RuntimeException('disk full'));
        });
        Log::spy();
        $service = $this->service([
            new Response(200, [], '<html><head><title>Absensi</title></head><body><div class="container"><h3>ABSEN PERTEMUAN 9</h3><p>Pindai kode QR.</p></div></body></html>'),
            new Response(200, [], $this->page('<div class="alert alert-success">Sukses Malakukan Absensi Perkulliahan..!</div>')),
        ]);

        $result = $service->submitAttendance(self::TOKEN, 8837, 30216, 9, ['kode' => '35533429']);

        $this->assertTrue($result['success']);
        $this->assertSame('marked', $result['data']['status']);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Failed to record a portal action response'
                && $context === ['action' => 'attendance_submit', 'error' => 'disk full'])
            ->once();
    }

    /**
     * @param  list<Response|callable(): Response>  $responses
     * @param  (callable(): (array<string, mixed>|null))|null  $session  What the session manager answers for the session, each time it is asked.
     */
    private function service(array $responses, ?callable $session = null): SipandaiSubmissionService
    {
        $sessionManager = $this->createMock(PortalSessionManager::class);
        $sessionManager->method('getSessionCookieJar')->with(self::TOKEN)->willReturn(CookieJar::fromArray([], 'portal.invalid'));
        $sessionManager->method('getSession')->with(self::TOKEN)->willReturnCallback($session ?? fn (): array => $this->campusSession());

        $service = new SipandaiSubmissionService($sessionManager);
        $property = new \ReflectionProperty(PortalScrapingService::class, 'client');
        $property->setValue($service, new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    private function campusSession(): array
    {
        return [
            'token' => self::TOKEN,
            'username' => self::NIM,
            'user_info' => ['name' => 'Budi Santoso', 'student_id' => self::NIM],
        ];
    }

    /**
     * @return list<string>
     */
    private function recordedActions(): array
    {
        if (! File::isDirectory($this->capturePath)) {
            return [];
        }

        $actions = array_map('basename', File::directories($this->capturePath));
        sort($actions);

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function recorded(string $action): array
    {
        return array_map(
            static fn (\SplFileInfo $file): string => (string) file_get_contents($file->getPathname()),
            File::files($this->capturePath.DIRECTORY_SEPARATOR.$action)
        );
    }

    private function examForm(string $answerRows = ''): string
    {
        return <<<HTML
            <html>
                <body>
                    <h3>Upload Jawaban Ujian</h3>
                    <form method="post" action="?mod=sipandai&act=sentujian" enctype="multipart/form-data">
                        <input type="hidden" name="data[id]" value="9195" />
                        <input type="hidden" name="data[tipe]" value="UAS" />
                        <input type="hidden" name="data[kelas]" value="TEORI" />
                        <input type="file" name="file" />
                    </form>
                    <table>
                        <tr><td>Tgl. Upload</td><td>Ujian</td><td>Keterangan</td><td>Jawaban</td></tr>
                        {$answerRows}
                    </table>
                </body>
            </html>
            HTML;
    }

    private function page(string $content): string
    {
        return <<<HTML
            <html>
                <head><title>SIPANDAI</title></head>
                <body>
                    <a href="?mod=logout">Keluar</a>
                    <div class="card"><div class="content">{$content}</div></div>
                </body>
            </html>
            HTML;
    }
}
