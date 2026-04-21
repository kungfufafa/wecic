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
}
