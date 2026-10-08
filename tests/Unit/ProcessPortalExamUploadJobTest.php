<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PortalOperationStage;
use App\Events\PortalUploadProgressed;
use App\Jobs\ProcessPortalExamUploadJob;
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

class ProcessPortalExamUploadJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Storage::deleteDirectory('portal-uploads');

        parent::tearDown();
    }

    public function test_exam_upload_job_broadcasts_progress_events(): void
    {
        Event::fake([PortalUploadProgressed::class]);
        Storage::fake();

        $storedPath = 'portal-uploads/exam.zip';
        Storage::put($storedPath, 'zip-content');

        $this->instance(
            SipandaiSubmissionService::class,
            Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('submitExam')
                    ->once()
                    ->andReturnUsing(function (
                        string $sessionToken,
                        int $examId,
                        UploadedFile $attachment,
                        array $overrides,
                        ?callable $onStage = null
                    ): array {
                        $onStage?->__invoke(PortalOperationStage::PortalUploading, 'Mengirim file ujian ke portal SIPANDAI.', 55);

                        return [
                            'success' => true,
                            'message' => 'Uploaded',
                            'data' => [],
                        ];
                    });
            })
        );

        $job = new ProcessPortalExamUploadJob(
            'op-exam-1',
            'laravel-session',
            'campus-token',
            8833,
            $storedPath,
            'exam.zip',
            'application/zip'
        );

        $datasets = Mockery::mock(PortalDatasetRepository::class);
        $datasets->shouldReceive('invalidate')->once()->with('campus-token', ['exams']);
        $datasets->shouldNotReceive('sync');

        $tracker = app(PortalOperationTracker::class);

        $job->handle(
            app(SipandaiSubmissionService::class),
            $tracker,
            app(PortalBroadcastService::class),
            $datasets
        );

        Event::assertDispatched(
            PortalUploadProgressed::class,
            fn (PortalUploadProgressed $event): bool => $event->payload['status'] === 'completed'
                && in_array('exam-index', $event->payload['sync_keys'], true)
        );
        $this->assertSame('completed', $tracker->get('op-exam-1')['stage']);
        $this->assertSame(1, $job->tries);
    }
}
