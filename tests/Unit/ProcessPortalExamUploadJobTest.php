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
use Illuminate\Support\Facades\Cache;
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
        Cache::flush();

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
                && in_array('dashboard-action-items', $event->payload['sync_keys'], true)
                && in_array('/api/action-items', $event->payload['cache_keys'], true)
                && in_array('/api/exam/8833', $event->payload['cache_keys'], true)
        );
        $this->assertSame('completed', $tracker->get('op-exam-1')['stage']);
        $this->assertSame(1, $job->tries);
    }

    public function test_an_unclear_exam_upload_is_not_reported_as_a_failure_to_upload_again(): void
    {
        Event::fake([PortalUploadProgressed::class]);
        Storage::fake();
        Storage::put('portal-uploads/exam.pdf', 'pdf');

        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitExam')->once()->andReturn([
                'success' => false,
                'message' => 'Status upload ujian belum pasti. Cek halaman ujian sebelum mengunggah ulang.',
                'data' => ['status' => 'unknown', 'transport_error' => true],
            ]);
        });

        $datasets = Mockery::mock(PortalDatasetRepository::class);
        $datasets->shouldNotReceive('invalidate');
        $tracker = app(PortalOperationTracker::class);

        $this->makeJob()->handle($submissionService, $tracker, app(PortalBroadcastService::class), $datasets);

        $operation = $tracker->get('op-exam-1');
        $this->assertSame('unverified', $operation['stage']);
        $this->assertSame('Status upload ujian belum pasti.', $operation['message']);
        $this->assertStringContainsString('beberapa menit lagi sebelum mengunggah ulang', $operation['next_step']);
        $this->assertNull($operation['detail']);
    }

    public function test_a_closed_exam_is_explained_without_advice_to_retry(): void
    {
        Event::fake([PortalUploadProgressed::class]);
        Storage::fake();
        Storage::put('portal-uploads/exam.pdf', 'pdf');

        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitExam')->once()->andReturn([
                'success' => false,
                'message' => 'Waktu ujian sudah berakhir; portal tidak menerima jawaban lagi.',
                'data' => ['status' => 'upload_unavailable'],
            ]);
        });

        $tracker = app(PortalOperationTracker::class);

        $this->makeJob()->handle($submissionService, $tracker, app(PortalBroadcastService::class), Mockery::mock(PortalDatasetRepository::class));

        $operation = $tracker->get('op-exam-1');
        $this->assertSame('Waktu ujian sudah berakhir; portal tidak menerima jawaban lagi.', $operation['message']);
        $this->assertStringNotContainsString('unggah ulang', $operation['next_step']);
    }

    public function test_a_stored_exam_answer_stays_reported_as_sent_when_local_bookkeeping_fails_and_frees_the_exam(): void
    {
        Event::fake([PortalUploadProgressed::class]);
        Storage::fake();
        Storage::put('portal-uploads/exam.pdf', 'pdf');

        $lock = Cache::lock('portal_upload:test-owner:exam:8833', 600);
        $this->assertTrue($lock->get());

        $submissionService = Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submitExam')->once()->andReturn(['success' => true, 'message' => 'Data tersimpan di portal.', 'data' => ['status' => 'uploaded']]);
        });

        $datasets = Mockery::mock(PortalDatasetRepository::class);
        $datasets->shouldReceive('invalidate')->once()->andThrow(new \RuntimeException('database is locked'));
        $tracker = app(PortalOperationTracker::class);

        $this->makeJob('portal_upload:test-owner:exam:8833', $lock->owner())->handle($submissionService, $tracker, app(PortalBroadcastService::class), $datasets);

        $this->assertSame('completed', $tracker->get('op-exam-1')['stage']);
        $this->assertTrue(Cache::lock('portal_upload:test-owner:exam:8833', 600)->get());
    }

    private function makeJob(?string $lockKey = null, ?string $lockOwner = null): ProcessPortalExamUploadJob
    {
        return new ProcessPortalExamUploadJob(
            'op-exam-1',
            'laravel-session',
            'campus-token',
            8833,
            'portal-uploads/exam.pdf',
            'exam.pdf',
            'application/pdf',
            [],
            $lockKey,
            $lockOwner
        );
    }
}
