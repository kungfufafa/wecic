<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PortalOperationStage;
use App\Services\PortalActionProjector;
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
 * Sends an assignment file to SIPANDAI. Dispatched to run right after the
 * HTTP response, so the upload starts immediately instead of waiting for a
 * queue worker; progress reaches the browser through realtime events and the
 * operation tracker (polled as a fallback).
 */
class ProcessPortalAssignmentUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload POST is not idempotent: a retry after a timeout the portal
     * actually processed would submit the file twice.
     */
    public int $tries = 1;

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
        PortalDatasetRepository $datasets,
        PortalActionProjector $projector
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
            $this->reportStage($tracker, $broadcast, PortalOperationStage::Connecting, 'Menghubungkan ke portal SIPANDAI.', 10);

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
                fn (PortalOperationStage $stage, string $message, int $percent) => $this->reportStage($tracker, $broadcast, $stage, $message, $percent)
            );

            $succeeded = ($result['success'] ?? false) === true;
            $submissionState = null;

            // The response wording is matched heuristically; when it is
            // inconclusive, the assignment page tells whether the file landed.
            if ($succeeded || ($result['data']['status'] ?? null) === 'unknown') {
                $submissionState = $this->inspectSubmission($submissionService);
                $succeeded = $succeeded || ($submissionState['status'] ?? null) === 'uploaded';
            }

            if (! $succeeded) {
                $message = (string) ($result['message'] ?? 'Upload tugas gagal diproses portal.');
                $this->reportFailure($tracker, $broadcast, $message, null, 'Periksa file PDF lalu coba unggah ulang.');

                return;
            }

            $datasets->invalidate(
                $this->campusSessionToken,
                ['assignments', 'dashboard', 'class_detail', 'meeting_detail'],
                ['class_detail', 'meeting_detail']
            );
            $projector->recordAssignmentUploaded(
                $this->campusSessionToken,
                $this->courseId,
                $this->discussionId,
                $this->meetingNumber,
                $submissionState['file_info'] ?? null,
                $submissionState['delete_payload'] ?? null
            );

            $cacheKeys = [
                '/api/dashboard',
                '/api/attendance/today',
                '/api/assignment/pending',
                sprintf('/api/meeting/%d?courses_id=%d', $this->discussionId, $this->courseId),
            ];
            $syncKeys = ['dashboard-pending-assignments', 'meeting-show'];

            $tracker->progress($this->operationId, PortalOperationStage::Completed, 'Tugas terkirim ke portal.', 100, cacheKeys: $cacheKeys, syncKeys: $syncKeys);
            $broadcast->uploadProgress(
                $this->sessionId,
                $this->campusSessionToken,
                $this->operationId,
                PortalOperationStage::Completed,
                'Tugas terkirim ke portal.',
                percent: 100,
                cacheKeys: $cacheKeys,
                syncKeys: $syncKeys
            );
        } catch (\Throwable $throwable) {
            $this->reportFailure($tracker, $broadcast, 'Upload tugas gagal.', $throwable->getMessage(), 'Coba unggah ulang saat koneksi stabil.');

            throw $throwable;
        } finally {
            Storage::delete($this->storedFilePath);
            $lock->release();
        }
    }

    /**
     * @return array{status?: string, delete_payload?: array<string, string>|null, file_info?: array<string, string>|null}|null
     */
    private function inspectSubmission(SipandaiSubmissionService $submissionService): ?array
    {
        try {
            return $submissionService->inspectAssignmentSubmissionState(
                $this->campusSessionToken,
                $this->courseId,
                $this->discussionId,
                $this->meetingNumber
            );
        } catch (\Throwable $throwable) {
            Log::warning('Failed to inspect assignment page after upload', [
                'courses_id' => $this->courseId,
                'meeting_id' => $this->discussionId,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function reportStage(PortalOperationTracker $tracker, PortalBroadcastService $broadcast, PortalOperationStage $stage, string $message, int $percent): void
    {
        $tracker->progress($this->operationId, $stage, $message, $percent);
        $broadcast->uploadProgress($this->sessionId, $this->campusSessionToken, $this->operationId, $stage, $message, percent: $percent);
    }

    private function reportFailure(PortalOperationTracker $tracker, PortalBroadcastService $broadcast, string $message, ?string $detail, string $nextStep): void
    {
        $tracker->progress($this->operationId, PortalOperationStage::Failed, $message, 100, $detail, $nextStep);
        $broadcast->uploadProgress(
            $this->sessionId,
            $this->campusSessionToken,
            $this->operationId,
            PortalOperationStage::Failed,
            $message,
            $detail,
            $nextStep
        );
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
