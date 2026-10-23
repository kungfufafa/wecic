<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalSupportControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_download_file_requires_active_session(): void
    {
        $response = $this->getJson('/api/support/download?path=../files/modul/sample.pdf');

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('meta.campus_session_valid', false);
    }

    public function test_download_file_returns_attachment_response(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('token-download')
                    ->andReturnTrue();
            })
        );

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('streamCampusFile')
                    ->once()
                    ->with('token-download', '../files/modul/sample.pdf', 'sample.pdf')
                    ->andReturn([
                        'filename' => 'sample.pdf',
                        'content_type' => 'application/pdf',
                        'size' => 9,
                        'stream' => Utils::streamFor('%PDF-test'),
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?path=../files/modul/sample.pdf&name=sample.pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename="sample.pdf"', (string) $response->headers->get('content-disposition'));
        $this->assertSame('9', $response->headers->get('content-length'));
        $this->assertSame('%PDF-test', $response->streamedContent());
    }

    public function test_download_file_rejects_paths_outside_static_campus_files(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->with('token-download')
                    ->andReturnTrue();
            })
        );

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldNotReceive('streamCampusFile');
            })
        );

        $maliciousPaths = [
            config('portal.base_url').'/portal/sipandai/?mod=sipandai&act=jobdel&id=30095&idx=29833&pertemuan=6',
            config('portal.base_url').'/portal/sipandai/?mod=sipandai&act=jobdelujian&id=9184',
            config('portal.base_url').'/portal/?mod=student&act=kesesuaian&id=1&jawab=1',
            config('portal.base_url').'/portal/?mod=logout',
            'files/../sipandai/?mod=sipandai&act=jobdel&id=30095',
            'files/%2e%2e/sipandai/index.php',
            'files\\..\\sipandai\\index.php',
        ];

        foreach ($maliciousPaths as $path) {
            $response = $this->withSession(['campus_session_token' => 'token-download'])
                ->getJson('/api/support/download?'.http_build_query(['path' => $path]));

            $response->assertStatus(422)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('message', 'Tautan unduhan tidak valid. Hanya file materi, soal, tugas, dan dokumen kampus yang bisa diunduh.');
        }
    }

    public function test_a_file_the_portal_no_longer_serves_is_not_found_instead_of_retryable(): void
    {
        $this->mockDownloadThrowing(new ClientException(
            'Client error: 404 Not Found',
            new Request('GET', 'https://portal.invalid/portal/files/modul/sample.pdf'),
            new Response(404)
        ));

        $this->withSession(['campus_session_token' => 'token-download'])
            ->getJson('/api/support/download?path=../files/modul/sample.pdf')
            ->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'File tidak ditemukan di portal kampus. Mungkin sudah dihapus atau dipindahkan dosen.')
            ->assertJsonPath('meta.campus_session_valid', true);
    }

    public function test_a_transient_download_failure_still_asks_to_retry(): void
    {
        $this->mockDownloadThrowing(new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('GET', 'https://portal.invalid/portal/files/modul/sample.pdf')
        ));

        $this->withSession(['campus_session_token' => 'token-download'])
            ->getJson('/api/support/download?path=../files/modul/sample.pdf')
            ->assertStatus(500)
            ->assertJsonPath('message', 'Unduhan gagal. Coba lagi beberapa saat.');
    }

    public function test_download_file_serves_absolute_exam_soal_url(): void
    {
        $soalUrl = config('portal.base_url').'/portal/files/soal/9197-UTS-tgs-591.pdf';

        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('token-download')
                    ->andReturnTrue();
            })
        );

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($soalUrl): void {
                $mock->shouldReceive('streamCampusFile')
                    ->once()
                    ->with('token-download', $soalUrl, '9197-UTS-tgs-591.pdf')
                    ->andReturn([
                        'filename' => '9197-UTS-tgs-591.pdf',
                        'content_type' => 'application/pdf',
                        'size' => null,
                        'stream' => Utils::streamFor('%PDF-soal'),
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?'.http_build_query(['path' => $soalUrl, 'name' => '9197-UTS-tgs-591.pdf']));

        $response->assertOk();
        $this->assertSame('%PDF-soal', $response->streamedContent());
    }

    public function test_a_large_file_is_copied_to_the_browser_in_small_chunks_and_closed(): void
    {
        $contents = str_repeat('0123456789', 3000);
        $source = Utils::streamFor($contents);
        $reads = [];
        $closed = false;
        $stream = FnStream::decorate($source, [
            'read' => static function (int $length) use ($source, &$reads): string {
                $reads[] = $length;

                return $source->read($length);
            },
            'close' => static function () use ($source, &$closed): void {
                $closed = true;
                $source->close();
            },
        ]);
        $this->mockStream(['filename' => 'modul.pdf', 'content_type' => 'application/pdf', 'size' => strlen($contents), 'stream' => $stream]);

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?path=../files/modul/modul.pdf');

        $response->assertOk();
        $this->assertSame((string) strlen($contents), $response->headers->get('content-length'));
        $this->assertSame($contents, $response->streamedContent());
        $this->assertGreaterThan(3, count($reads));
        $this->assertLessThanOrEqual(8192, max($reads));
        $this->assertTrue($closed, 'The portal connection is released once the file is sent.');
    }

    public function test_a_file_of_unknown_size_stops_at_the_download_cap(): void
    {
        config(['portal.scraping.download_max_bytes' => 20]);
        $this->mockStream(['filename' => 'rekaman.mp4', 'content_type' => 'video/mp4', 'size' => null, 'stream' => Utils::streamFor(str_repeat('x', 50))]);

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?path=../files/modul/rekaman.mp4');

        $response->assertOk();
        $this->assertNull($response->headers->get('content-length'));
        $this->assertSame(str_repeat('x', 20), $response->streamedContent());
    }

    public function test_a_file_above_the_download_limit_is_refused_without_a_retry(): void
    {
        $this->mockDownloadThrowing(PortalScrapingException::invalidResponse(
            'https://portal.invalid/portal/files/modul/besar.pdf',
            'File is 90000000 bytes, above the 52428800 byte download limit'
        ));

        $this->withSession(['campus_session_token' => 'token-download'])
            ->getJson('/api/support/download?path=../files/modul/besar.pdf')
            ->assertStatus(422)
            ->assertJsonPath('message', 'File terlalu besar untuk diunduh lewat MyCIC. Buka langsung di portal kampus.')
            ->assertJsonPath('meta.campus_session_valid', true);
    }

    public function test_a_failed_download_opened_in_a_tab_shows_a_page_with_a_way_back(): void
    {
        $this->mockDownloadThrowing(new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('GET', 'https://portal.invalid/portal/files/modul/sample.pdf')
        ));

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?path=../files/modul/sample.pdf');

        $response->assertStatus(500);
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('content-type'));
        $response->assertSee('Unduhan gagal. Coba lagi beberapa saat.');
        $response->assertSee('href="'.e(url('/dashboard')).'"', false);
        $response->assertDontSee('"status"', false);
    }

    public function test_a_missing_file_opened_in_a_tab_shows_a_page_too(): void
    {
        $this->mockDownloadThrowing(new ClientException(
            'Client error: 404 Not Found',
            new Request('GET', 'https://portal.invalid/portal/files/modul/sample.pdf'),
            new Response(404)
        ));

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?path=../files/modul/sample.pdf');

        $response->assertNotFound();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('content-type'));
        $response->assertSee('File tidak ditemukan di portal kampus. Mungkin sudah dihapus atau dipindahkan dosen.');
    }

    public function test_an_oversized_file_opened_in_a_tab_shows_a_page(): void
    {
        $this->mockDownloadThrowing(PortalScrapingException::invalidResponse(
            'https://portal.invalid/portal/files/modul/besar.pdf',
            'File is 90000000 bytes, above the 52428800 byte download limit'
        ));

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?path=../files/modul/besar.pdf');

        $response->assertStatus(422);
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('content-type'));
        $response->assertSee('File terlalu besar untuk diunduh lewat MyCIC. Buka langsung di portal kampus.');
    }

    public function test_an_expired_session_on_a_download_opened_in_a_tab_goes_to_the_login_page(): void
    {
        $this->mockDownloadThrowing(PortalScrapingException::pageNotAccessible('https://portal.invalid/portal/files/modul/sample.pdf'));

        $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?path=../files/modul/sample.pdf')
            ->assertRedirect(route('login'));
    }

    public function test_an_expired_session_on_a_fetched_download_still_answers_json(): void
    {
        $this->mockDownloadThrowing(PortalScrapingException::pageNotAccessible('https://portal.invalid/portal/files/modul/sample.pdf'));

        $this->withSession(['campus_session_token' => 'token-download'])
            ->getJson('/api/support/download?path=../files/modul/sample.pdf')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.');
    }

    /**
     * @param  array{filename: string, content_type: string, size: int|null, stream: \Psr\Http\Message\StreamInterface}  $download
     */
    private function mockStream(array $download): void
    {
        $this->mockValidSession();

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($download): void {
                $mock->shouldReceive('streamCampusFile')->once()->andReturn($download);
            })
        );
    }

    private function mockValidSession(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('token-download')
                    ->andReturnTrue();
            })
        );
    }

    private function mockDownloadThrowing(\Throwable $throwable): void
    {
        $this->mockValidSession();

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($throwable): void {
                $mock->shouldReceive('streamCampusFile')->once()->andThrow($throwable);
            })
        );
    }
}
