<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PortalOperationStage;
use App\Exceptions\PortalException;
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

/**
 * Sends an exam file to SIPANDAI, right after the HTTP response (see
 * ProcessPortalAssignmentUploadJob). The service checks the sendujian page
 * whenever the portal's reply leaves the outcome open; an outcome that is
 * still unclear is reported as such instead of asking for a re-upload. An
 * upload whose process died midway is settled by `portal:recover-uploads`.
 */
class ProcessPortalExamUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload POST is not idempotent, so it is never retried.
     */
    public int $tries = 1;

    /**
     * Portal verdicts that say the answer was refused and why; re-uploading
     * will not change them.
     */
    private const FINAL_REFUSALS = ['upload_unavailable', 'deadline_passed', 'already_uploaded'];

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
        private readonly array $formValues = [],
        private readonly ?string $targetLockKey = null,
        private readonly ?string $targetLockOwner = null
    ) {
        $this->onQueue('campus-uploads');
    }

    public function handle(
        SipandaiSubmissionService $submissionService,
        PortalOperationTracker $tracker,
        PortalBroadcastService $broadcast,
        PortalDatasetRepository $datasets
    ): void {
        $this->extendTimeLimit();

        try {
            $this->reportStage($tracker, $broadcast, PortalOperationStage::Connecting, 'Menghubungkan ke portal SIPANDAI.', 10);

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
                fn (PortalOperationStage $stage, string $message, int $percent) => $this->reportStage($tracker, $broadcast, $stage, $message, $percent)
            );
        } catch (\Throwable $throwable) {
            Log::warning('SIPANDAI exam upload failed before the portal stored it', [
                'operation_id' => $this->operationId,
                'exam_id' => $this->examId,
                'error' => $throwable->getMessage(),
            ]);

            PortalException::indicatesExpiredSession($throwable)
                ? $this->reportFailure($tracker, $broadcast, 'Sesi portal berakhir sebelum jawaban terkirim.', 'Masuk lagi, lalu unggah ulang jawabannya.')
                : $this->reportFailure($tracker, $broadcast, 'Upload ujian gagal.', 'Coba unggah ulang saat koneksi stabil.');

            $this->cleanUp();

            throw $throwable;
        }

        try {
            $this->settle($result, $tracker, $broadcast, $datasets);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An upload POST that got no reply may still be running on the portal,
     * so its unsettled outcome asks the student to wait before checking.
     *
     * @param  array<string, mixed>  $result
     */
    private function settle(array $result, PortalOperationTracker $tracker, PortalBroadcastService $broadcast, PortalDatasetRepository $datasets): void
    {
        $status = (string) ($result['data']['status'] ?? '');
        $replyMissing = ($result['data']['transport_error'] ?? false) === true;

        if (($result['success'] ?? false) === true) {
            $this->recordUpload($datasets);
            $this->reportCompleted($tracker, $broadcast);

            return;
        }

        $message = (string) ($result['message'] ?? 'Upload ujian ditolak portal.');

        match (true) {
            $status === 'unknown' => $this->reportUnverified(
                $tracker,
                $broadcast,
                'Status upload ujian belum pasti.',
                $replyMissing
                    ? 'Portal tidak menjawab saat jawaban dikirim dan mungkin masih memprosesnya. Cek halaman ujian beberapa menit lagi sebelum mengunggah ulang.'
                    : 'Cek halaman ujian sebelum mengunggah ulang, supaya jawaban tidak terkirim dua kali.'
            ),
            $status === 'not_uploaded' => $this->reportFailure(
                $tracker,
                $broadcast,
                $message,
                'Cek halaman ujian, lalu unggah ulang bila jawaban belum ada.'
            ),
            in_array($status, self::FINAL_REFUSALS, true) => $this->reportFailure(
                $tracker,
                $broadcast,
                $message,
                'Muat ulang halaman ujian untuk melihat status terbaru.'
            ),
            default => $this->reportFailure($tracker, $broadcast, $message, 'Periksa file lalu coba unggah ulang.'),
        };
    }

    /**
     * Local bookkeeping after the portal has the answer. A failure here is
     * logged and never turns a stored upload into a reported failure.
     */
    private function recordUpload(PortalDatasetRepository $datasets): void
    {
        try {
            // The exam detail reads the stored submission records right away;
            // the exam list re-syncs in the background on its next read.
            $datasets->invalidate($this->campusSessionToken, ['exams']);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to record SIPANDAI exam upload locally', [
                'operation_id' => $this->operationId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * The exams dataset also feeds the action centre and its nav badges.
     */
    private function reportCompleted(PortalOperationTracker $tracker, PortalBroadcastService $broadcast): void
    {
        $realtimeKeys = PortalDatasetRepository::realtimeKeys('exams');
        $cacheKeys = [...$realtimeKeys['cache_keys'], '/api/exam/'.$this->examId];
        $syncKeys = [...$realtimeKeys['sync_keys'], 'exam-show'];

        try {
            $tracker->progress($this->operationId, PortalOperationStage::Completed, 'Jawaban ujian terkirim ke portal.', 100, cacheKeys: $cacheKeys, syncKeys: $syncKeys);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to store SIPANDAI exam upload status', ['operation_id' => $this->operationId, 'error' => $throwable->getMessage()]);
        }

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
    }

    /**
     * Progress is informational: a failed write must not abort an upload
     * the portal may already have stored.
     */
    private function reportStage(PortalOperationTracker $tracker, PortalBroadcastService $broadcast, PortalOperationStage $stage, string $message, int $percent): void
    {
        try {
            $tracker->progress($this->operationId, $stage, $message, $percent);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to store SIPANDAI exam upload progress', ['operation_id' => $this->operationId, 'error' => $throwable->getMessage()]);
        }

        $broadcast->uploadProgress($this->sessionId, $this->campusSessionToken, $this->operationId, $stage, $message, percent: $percent);
    }

    /**
     * The message the student reads; portal or exception text stays in the log.
     */
    private function reportFailure(PortalOperationTracker $tracker, PortalBroadcastService $broadcast, string $message, string $nextStep, PortalOperationStage $stage = PortalOperationStage::Failed): void
    {
        try {
            $tracker->progress($this->operationId, $stage, $message, 100, null, $nextStep);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to store SIPANDAI exam upload status', ['operation_id' => $this->operationId, 'error' => $throwable->getMessage()]);
        }

        $broadcast->uploadProgress(
            $this->sessionId,
            $this->campusSessionToken,
            $this->operationId,
            $stage,
            $message,
            null,
            $nextStep
        );
    }

    /**
     * An outcome the portal has not settled: the student is told to check
     * the exam page before uploading again, never to simply retry.
     */
    private function reportUnverified(PortalOperationTracker $tracker, PortalBroadcastService $broadcast, string $message, string $nextStep): void
    {
        $this->reportFailure($tracker, $broadcast, $message, $nextStep, PortalOperationStage::Unverified);
    }

    /**
     * Drops the stored file and frees the exam for the next upload.
     */
    private function cleanUp(): void
    {
        Storage::delete($this->storedFilePath);

        if ($this->targetLockKey !== null && $this->targetLockOwner !== null) {
            Cache::restoreLock($this->targetLockKey, $this->targetLockOwner)->release();
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
