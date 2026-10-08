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
use Illuminate\Support\Facades\Storage;

/**
 * Sends an exam file to SIPANDAI, right after the HTTP response (see
 * ProcessPortalAssignmentUploadJob).
 */
class ProcessPortalExamUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload POST is not idempotent, so it is never retried.
     */
    public int $tries = 1;

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

        $this->extendTimeLimit();

        try {
            $tracker->progress($this->operationId, PortalOperationStage::Connecting, 'Menghubungkan ke portal SIPANDAI.', 10);
            $broadcast->uploadProgress($this->sessionId, $this->campusSessionToken, $this->operationId, PortalOperationStage::Connecting, 'Menghubungkan ke portal SIPANDAI.', percent: 10);

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

            // The exam detail reads the stored submission records right away;
            // the exam list re-syncs in the background on its next read.
            $datasets->invalidate($this->campusSessionToken, ['exams']);

            $cacheKeys = ['/api/exam', '/api/exam/'.$this->examId];
            $syncKeys = ['exam-index', 'exam-show'];

            $tracker->progress($this->operationId, PortalOperationStage::Completed, 'Jawaban ujian terkirim ke portal.', 100, cacheKeys: $cacheKeys, syncKeys: $syncKeys);
            $broadcast->uploadProgress(
                $this->sessionId,
                $this->campusSessionToken,
                $this->operationId,
                PortalOperationStage::Completed,
                'Jawaban ujian terkirim ke portal.',
                percent: 100,
                cacheKeys: $cacheKeys,
                syncKeys: $syncKeys
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

    /**
     * Running after the response, the job shares PHP-FPM's execution limit.
     * Console processes (queue workers, tests) manage their own limits.
     */
    private function extendTimeLimit(): void
    {
        if (! app()->runningInConsole() && function_exists('set_time_limit')) {
            @set_time_limit((int) config('portal.actions.upload_time_limit', 180));
        }
    }
}
