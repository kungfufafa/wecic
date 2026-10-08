<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PortalOperationStage;
use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalOperationTracker;
use App\Services\SipandaiSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPortalAssignmentUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $formValues
     */
    public function __construct(
        private readonly string $operationId,
        private readonly string $sessionId,
        private readonly string $campusSessionToken,
        private readonly int $courseId,
        private readonly int $discussionId,
        private readonly int $meetingNumber,
        private readonly string $storedFilePath,
        private readonly string $originalName,
        private readonly string $mimeType,
        private readonly array $formValues = []
    ) {
        $this->onQueue('campus-uploads');
    }

    public function handle(
        SipandaiSubmissionService $submissionService,
        PortalOperationTracker $tracker,
        PortalBroadcastService $broadcast,
        PortalDatasetRepository $datasets
    ): void {
        $lock = Cache::lock('portal_upload_'.$this->operationId, 600);

        if (! $lock->get()) {
            $broadcast->uploadProgress(
                $this->sessionId,
                $this->campusSessionToken,
                $this->operationId,
                PortalOperationStage::Failed,
                'Upload sedang diproses di proses lain.',
                nextStep: 'Tunggu status upload sebelumnya selesai.'
            );

            return;
        }

        try {
            $tracker->start($this->operationId, 'assignment_upload');

            $uploadedFile = new UploadedFile(
                Storage::path($this->storedFilePath),
                $this->originalName,
                $this->mimeType,
                null,
                true
            );

            $result = $submissionService->submitAssignment(
                $this->campusSessionToken,
                $this->courseId,
                $this->discussionId,
                $this->meetingNumber,
                $uploadedFile,
                $this->formValues,
                function (PortalOperationStage $stage, string $message, int $percent) use ($tracker, $broadcast): void {
                    $tracker->progress($this->operationId, $stage, $message, $percent);
                    $broadcast->uploadProgress($this->sessionId, $this->campusSessionToken, $this->operationId, $stage, $message, percent: $percent);
                }
            );

            if (($result['success'] ?? false) !== true) {
                $message = (string) ($result['message'] ?? 'Upload tugas gagal diproses portal.');
                $tracker->progress($this->operationId, PortalOperationStage::Failed, $message, 100, nextStep: 'Periksa file PDF lalu coba unggah ulang.');
                $broadcast->uploadProgress(
                    $this->sessionId,
                    $this->campusSessionToken,
                    $this->operationId,
                    PortalOperationStage::Failed,
                    $message,
                    nextStep: 'Periksa file PDF lalu coba unggah ulang.'
                );

                return;
            }

            $datasets->invalidate(
                $this->campusSessionToken,
                ['assignments', 'dashboard', 'class_detail', 'meeting_detail'],
                ['class_detail', 'meeting_detail']
            );
            $this->resyncMeetingDetail($datasets);

            $broadcast->dataInvalidated(
                $this->sessionId,
                $this->campusSessionToken,
                ['/api/assignment/pending'],
                ['dashboard-pending-assignments', 'meeting-show'],
                'Data tugas diperbarui setelah upload.'
            );
        } catch (\Throwable $throwable) {
            $tracker->progress(
                $this->operationId,
                PortalOperationStage::Failed,
                'Upload tugas gagal.',
                100,
                $throwable->getMessage(),
                'Coba unggah ulang saat koneksi stabil.'
            );
            $broadcast->uploadProgress(
                $this->sessionId,
                $this->campusSessionToken,
                $this->operationId,
                PortalOperationStage::Failed,
                'Upload tugas gagal.',
                $throwable->getMessage(),
                'Coba unggah ulang saat koneksi stabil.'
            );

            throw $throwable;
        } finally {
            Storage::delete($this->storedFilePath);
            $lock->release();
        }
    }

    /**
     * Rebuild the meeting the student uploaded from, so the open meeting page
     * shows the new submission as soon as it re-reads its snapshot.
     */
    private function resyncMeetingDetail(PortalDatasetRepository $datasets): void
    {
        try {
            $datasets->sync($this->campusSessionToken, 'meeting_detail', [
                'meeting_id' => (string) $this->discussionId,
                'courses_id' => (string) $this->courseId,
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to re-sync meeting detail after assignment upload', [
                'courses_id' => $this->courseId,
                'meeting_id' => $this->discussionId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
