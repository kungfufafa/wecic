<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PortalOperationStage;
use App\Services\PortalBroadcastService;
use App\Services\PortalOperationTracker;
use App\Services\SipandaiSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ProcessPortalExamUploadJob implements ShouldQueue
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
        private readonly int $examId,
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
        PortalBroadcastService $broadcast
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
            $tracker->start($this->operationId, 'exam_upload');

            $uploadedFile = new UploadedFile(
                Storage::path($this->storedFilePath),
                $this->originalName,
                $this->mimeType,
                null,
                true
            );

            $result = $submissionService->submitExam(
                $this->campusSessionToken,
                $this->examId,
                $uploadedFile,
                $this->formValues,
                function (PortalOperationStage $stage, string $message, int $percent) use ($tracker, $broadcast): void {
                    $tracker->progress($this->operationId, $stage, $message, $percent);
                    $broadcast->uploadProgress($this->sessionId, $this->campusSessionToken, $this->operationId, $stage, $message, percent: $percent);
                }
            );

            if (($result['success'] ?? false) !== true) {
                $message = (string) ($result['message'] ?? 'Upload ujian gagal diproses portal.');
                $tracker->progress($this->operationId, PortalOperationStage::Failed, $message, 100, nextStep: 'Periksa file lalu coba unggah ulang.');
                $broadcast->uploadProgress(
                    $this->sessionId,
                    $this->campusSessionToken,
                    $this->operationId,
                    PortalOperationStage::Failed,
                    $message,
                    nextStep: 'Periksa file lalu coba unggah ulang.'
                );

                return;
            }

            $broadcast->dataInvalidated(
                $this->sessionId,
                $this->campusSessionToken,
                ['/api/exam', '/api/exam/'.$this->examId],
                ['exam-index', 'exam-show'],
                'Data ujian diperbarui setelah upload.'
            );
        } catch (\Throwable $throwable) {
            $tracker->progress(
                $this->operationId,
                PortalOperationStage::Failed,
                'Upload ujian gagal.',
                100,
                $throwable->getMessage(),
                'Coba unggah ulang saat koneksi stabil.'
            );
            $broadcast->uploadProgress(
                $this->sessionId,
                $this->campusSessionToken,
                $this->operationId,
                PortalOperationStage::Failed,
                'Upload ujian gagal.',
                $throwable->getMessage(),
                'Coba unggah ulang saat koneksi stabil.'
            );

            throw $throwable;
        } finally {
            Storage::delete($this->storedFilePath);
            $lock->release();
        }
    }
}
