<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PortalOperationStage;
use App\Services\PortalOperationTracker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PortalOperationTrackerTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_tracker_stores_and_reads_operation_progress(): void
    {
        $tracker = new PortalOperationTracker;

        $tracker->start('op-123', 'assignment_upload');
        $tracker->progress('op-123', PortalOperationStage::FileReceived, 'File diterima server.', 20);

        $snapshot = $tracker->get('op-123');

        $this->assertSame('assignment_upload', $snapshot['type']);
        $this->assertSame('file_received', $snapshot['stage']);
        $this->assertSame(20, $snapshot['percent']);
        $this->assertSame('File diterima server.', $snapshot['message']);
    }

    public function test_an_upload_keeps_its_file_and_lock_through_progress_reports(): void
    {
        $tracker = new PortalOperationTracker;
        $upload = ['file' => 'portal-uploads/op-123.pdf', 'lock_key' => 'portal_upload:owner:exam:8833', 'lock_owner' => 'lock-owner'];

        $tracker->start('op-123', 'exam_upload', 'laravel-session', 'Upload ujian diterima.', $upload);
        $tracker->progress('op-123', PortalOperationStage::PortalUploading, 'Mengirim file.', 55);

        $this->assertSame($upload, $tracker->get('op-123')['upload']);
    }

    public function test_an_upload_file_is_named_after_its_operation(): void
    {
        $tracker = new PortalOperationTracker;
        $operationId = '9b2f1c1e-5d0a-4c3b-8a43-2f6a2d1c9e10';

        $this->assertSame('portal-uploads/'.$operationId.'.pdf', $tracker->uploadFilePath($operationId, 'PDF'));
        $this->assertSame('portal-uploads/'.$operationId, $tracker->uploadFilePath($operationId, null));
        $this->assertSame('portal-uploads/'.$operationId, $tracker->uploadFilePath($operationId, '../x'));
        $this->assertSame($operationId, $tracker->operationIdForUploadFile($tracker->uploadFilePath($operationId, 'zip')));
        $this->assertSame($operationId, $tracker->operationIdForUploadFile('portal-uploads/'.$operationId));
        $this->assertNull($tracker->operationIdForUploadFile('portal-uploads/20k9HUOSspffP2V0vVWu0BKJkhtFMUiCdtCOsgCK.pdf'));
    }
}
