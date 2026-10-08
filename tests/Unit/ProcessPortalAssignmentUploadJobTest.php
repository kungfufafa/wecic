<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PortalOperationStage;
use App\Events\PortalDataInvalidated;
use App\Events\PortalUploadProgressed;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalOperationTracker;
use App\Services\SipandaiSubmissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcessPortalAssignmentUploadJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Storage::deleteDirectory('portal-uploads');

        parent::tearDown();
    }

    public function test_assignment_upload_job_broadcasts_progress_and_completion(): void
    {
        Event::fake([PortalUploadProgressed::class, PortalDataInvalidated::class]);
        Storage::fake();

        $storedPath = 'portal-uploads/task.pdf';
        Storage::put($storedPath, 'pdf-content');

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('submitAssignment')
                    ->once()
                    ->andReturnUsing(function (
                        string $sessionToken,
                        int $courseId,
                        int $discussionId,
                        int $meetingNumber,
                        UploadedFile $attachment,
                        array $overrides,
                        ?callable $onStage = null
                    ): array {
                        $onStage?->__invoke(PortalOperationStage::FileReceived, 'File tugas diterima server.', 20);
                        $onStage?->__invoke(PortalOperationStage::SubmissionSaved, 'Submission tugas tersimpan.', 100);

                        return [
                            'success' => true,
                            'message' => 'Uploaded',
                            'data' => [],
                        ];
                    });
            })
        );

        $job = new ProcessPortalAssignmentUploadJob(
            'op-assignment-1',
            'laravel-session',
            'campus-token',
            9000,
            29833,
            2,
            $storedPath,
            'task.pdf',
            'application/pdf'
        );

        $datasets = Mockery::mock(PortalDatasetRepository::class);
        $datasets->shouldReceive('invalidate')
            ->once()
            ->with('campus-token', ['assignments', 'dashboard', 'class_detail', 'meeting_detail'], ['class_detail', 'meeting_detail']);
        $datasets->shouldReceive('sync')
            ->once()
            ->with('campus-token', 'meeting_detail', ['meeting_id' => '29833', 'courses_id' => '9000'])
            ->andReturn([]);

        $job->handle(
            app(SipandaiSubmissionService::class),
            app(PortalOperationTracker::class),
            app(PortalBroadcastService::class),
            $datasets
        );

        Event::assertDispatched(PortalUploadProgressed::class);
        Event::assertDispatched(PortalDataInvalidated::class);
    }
}
