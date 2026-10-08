<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PortalOperationStage;
use App\Events\PortalUploadProgressed;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Services\PortalActionProjector;
use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalOperationTracker;
use App\Services\SipandaiSubmissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
        Cache::flush();

        parent::tearDown();
    }

    public function test_assignment_upload_job_broadcasts_progress_and_completion(): void
    {
        Event::fake([PortalUploadProgressed::class]);
        Storage::fake();

        $storedPath = 'portal-uploads/task.pdf';
        Storage::put($storedPath, 'pdf-content');

        $fileInfo = ['href' => 'files/task.pdf', 'text' => 'task.pdf'];
        $deletePayload = ['id' => '126663', 'idx' => '29833', 'pertemuan' => '2'];

        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock) use ($fileInfo, $deletePayload): void {
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
                        'data' => ['status' => 'uploaded'],
                    ];
                });
            $mock->shouldReceive('inspectAssignmentSubmissionState')
                ->once()
                ->with('campus-token', 9000, 29833, 2)
                ->andReturn(['status' => 'uploaded', 'file_info' => $fileInfo, 'delete_payload' => $deletePayload]);
        });

        $datasets = Mockery::mock(PortalDatasetRepository::class);
        $datasets->shouldReceive('invalidate')
            ->once()
            ->with('campus-token', ['assignments', 'dashboard', 'class_detail', 'meeting_detail'], ['class_detail', 'meeting_detail']);
        $datasets->shouldNotReceive('sync');

        $projector = Mockery::mock(PortalActionProjector::class);
        $projector->shouldReceive('recordAssignmentUploaded')
            ->once()
            ->with('campus-token', 9000, 29833, 2, $fileInfo, $deletePayload);

        $tracker = app(PortalOperationTracker::class);

        $this->makeJob($storedPath)->handle(
            $submissionService,
            $tracker,
            app(PortalBroadcastService::class),
            $datasets,
            $projector
        );

        Event::assertDispatched(
            PortalUploadProgressed::class,
            fn (PortalUploadProgressed $event): bool => $event->payload['status'] === 'completed'
                && in_array('/api/assignment/pending', $event->payload['cache_keys'], true)
                && in_array('meeting-show', $event->payload['sync_keys'], true)
        );
        $this->assertSame('completed', $tracker->get('op-assignment-1')['stage']);
        Storage::assertMissing($storedPath);
    }

    public function test_inconclusive_portal_response_is_verified_on_the_assignment_page(): void
    {
        Event::fake([PortalUploadProgressed::class]);
        Storage::fake();

        $storedPath = 'portal-uploads/task.pdf';
        Storage::put($storedPath, 'pdf-content');

        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAssignment')->once()->andReturn([
                'success' => false,
                'message' => 'Upload status could not be determined. Please verify manually.',
                'data' => ['status' => 'unknown'],
            ]);
            $mock->shouldReceive('inspectAssignmentSubmissionState')->once()->andReturn([
                'status' => 'uploaded',
                'file_info' => null,
                'delete_payload' => null,
            ]);
        });

        $datasets = Mockery::mock(PortalDatasetRepository::class);
        $datasets->shouldReceive('invalidate')->once();

        $projector = Mockery::mock(PortalActionProjector::class);
        $projector->shouldReceive('recordAssignmentUploaded')->once();

        $tracker = app(PortalOperationTracker::class);

        $this->makeJob($storedPath)->handle($submissionService, $tracker, app(PortalBroadcastService::class), $datasets, $projector);

        $this->assertSame('completed', $tracker->get('op-assignment-1')['stage']);
    }

    public function test_rejected_upload_reports_failure_without_projecting(): void
    {
        Event::fake([PortalUploadProgressed::class]);
        Storage::fake();

        $storedPath = 'portal-uploads/task.pdf';
        Storage::put($storedPath, 'pdf-content');

        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitAssignment')->once()->andReturn([
                'success' => false,
                'message' => 'Upload failed.',
                'data' => ['status' => 'upload_failed'],
            ]);
            $mock->shouldNotReceive('inspectAssignmentSubmissionState');
        });

        $datasets = Mockery::mock(PortalDatasetRepository::class);
        $datasets->shouldNotReceive('invalidate');

        $projector = Mockery::mock(PortalActionProjector::class);
        $projector->shouldNotReceive('recordAssignmentUploaded');

        $tracker = app(PortalOperationTracker::class);

        $this->makeJob($storedPath)->handle($submissionService, $tracker, app(PortalBroadcastService::class), $datasets, $projector);

        $this->assertSame('failed', $tracker->get('op-assignment-1')['stage']);
        Event::assertDispatched(
            PortalUploadProgressed::class,
            fn (PortalUploadProgressed $event): bool => $event->payload['status'] === 'failed'
        );
        Storage::assertMissing($storedPath);
    }

    public function test_assignment_upload_job_is_never_retried(): void
    {
        $this->assertSame(1, $this->makeJob('portal-uploads/task.pdf')->tries);
    }

    private function makeJob(string $storedPath): ProcessPortalAssignmentUploadJob
    {
        return new ProcessPortalAssignmentUploadJob(
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
    }
}
