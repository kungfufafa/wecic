<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use App\Services\SipandaiSubmissionService;
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
                $mock->shouldReceive('downloadCampusFile')
                    ->once()
                    ->with('token-download', '../files/modul/sample.pdf', 'sample.pdf')
                    ->andReturn([
                        'filename' => 'sample.pdf',
                        'content_type' => 'application/pdf',
                        'contents' => '%PDF-test',
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?path=../files/modul/sample.pdf&name=sample.pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename="sample.pdf"', (string) $response->headers->get('content-disposition'));
        $this->assertSame('%PDF-test', $response->getContent());
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
                $mock->shouldNotReceive('downloadCampusFile');
            })
        );

        $maliciousPaths = [
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdel&id=30095&idx=29833&pertemuan=6',
            'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdelujian&id=9184',
            'https://my.cic.ac.id/portal/?mod=student&act=kesesuaian&id=1&jawab=1',
            'https://my.cic.ac.id/portal/?mod=logout',
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

    public function test_download_file_serves_absolute_exam_soal_url(): void
    {
        $soalUrl = 'https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf';

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
                $mock->shouldReceive('downloadCampusFile')
                    ->once()
                    ->with('token-download', $soalUrl, '9197-UTS-tgs-591.pdf')
                    ->andReturn([
                        'filename' => '9197-UTS-tgs-591.pdf',
                        'content_type' => 'application/pdf',
                        'contents' => '%PDF-soal',
                    ]);
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-download'])
            ->get('/api/support/download?'.http_build_query(['path' => $soalUrl, 'name' => '9197-UTS-tgs-591.pdf']));

        $response->assertOk();
        $this->assertSame('%PDF-soal', $response->getContent());
    }
}
