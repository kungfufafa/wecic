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
}
