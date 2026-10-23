<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PortalOperationStage;
use App\Exceptions\PortalException;
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
 *
 * Whenever the portal's reply leaves the outcome open, the assignment page
 * decides it, and a result that is still unclear is reported as such: asking
 * the student to upload again could store the file twice.
 *
 * A process that dies before the job settles the upload (a killed PHP-FPM
 * worker, a deploy) leaves its operation, target lock and stored file to
 * `portal:recover-uploads`.
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
     * Portal verdicts that say the file was refused and why; re-uploading
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
        private readonly int $courseId,
        private readonly int $discussionId,
        private readonly int $meetingNumber,
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
        PortalDatasetRepository $datasets,
        PortalActionProjector $projector
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

            $result = $submissionService->submitAssignment(
                $this->campusSessionToken,
                $this->courseId,
                $this->discussionId,
                $this->meetingNumber,
                $uploadedFile,
                $this->formValues,
                fn (PortalOperationStage $stage, string $message, int $percent) => $this->reportStage($tracker, $broadcast, $stage, $message, $percent)
            );
        } catch (\Throwable $throwable) {
            Log::warning('SIPANDAI assignment upload failed before the portal stored it', [
                'operation_id' => $this->operationId,
                'courses_id' => $this->courseId,
                'meeting_id' => $this->discussionId,
                'error' => $throwable->getMessage(),
            ]);

            PortalException::indicatesExpiredSession($throwable)
                ? $this->reportFailure($tracker, $broadcast, 'Sesi portal berakhir sebelum file terkirim.', 'Masuk lagi, lalu unggah ulang tugasnya.')
                : $this->reportFailure($tracker, $broadcast, 'Upload tugas gagal.', 'Coba unggah ulang saat koneksi stabil.');

            $this->cleanUp();

            throw $throwable;
        }

        try {
            $this->settle($result, $submissionService, $tracker, $broadcast, $datasets, $projector);
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * An upload POST that got no reply may still be running on the portal: a
     * page read right after it can confirm the file, but a page without it
     * proves nothing yet, so that outcome stays "belum pasti".
     *
     * @param  array<string, mixed>  $result
     */
    private function settle(
        array $result,
        SipandaiSubmissionService $submissionService,
        PortalOperationTracker $tracker,
        PortalBroadcastService $broadcast,
        PortalDatasetRepository $datasets,
        PortalActionProjector $projector
    ): void {
        $status = (string) ($result['data']['status'] ?? '');
        $portalAccepted = ($result['success'] ?? false) === true;
        $replyMissing = ($result['data']['transport_error'] ?? false) === true;

        if (! $portalAccepted && $status !== 'unknown') {
            $this->reportFailure(
                $tracker,
                $broadcast,
                (string) ($result['message'] ?? 'Upload tugas ditolak portal.'),
                in_array($status, self::FINAL_REFUSALS, true)
                    ? 'Muat ulang halaman pertemuan untuk melihat status terbaru.'
                    : 'Periksa file PDF lalu coba unggah ulang.'
            );

            return;
        }

        $submissionState = $this->inspectSubmission($submissionService);
        $pageStatus = $submissionState['status'] ?? null;

        if ($pageStatus === 'uploaded' || ($portalAccepted && $pageStatus !== 'not_uploaded')) {
            $this->recordUpload($datasets, $projector, $submissionState);
            $this->reportCompleted($tracker, $broadcast);

            return;
        }

        if (! $portalAccepted && ! $replyMissing && $pageStatus === 'not_uploaded') {
            $this->reportFailure(
                $tracker,
                $broadcast,
                'File tugas belum masuk ke portal.',
                'Cek halaman pertemuan, lalu unggah ulang bila file belum ada.'
            );

            return;
        }

        $this->reportUnverified(
            $tracker,
            $broadcast,
            'Status upload tugas belum pasti.',
            $replyMissing
                ? 'Portal tidak menjawab saat file dikirim dan mungkin masih memprosesnya. Cek halaman pertemuan beberapa menit lagi sebelum mengunggah ulang.'
                : 'Cek halaman pertemuan sebelum mengunggah ulang, supaya file tidak terkirim dua kali.'
        );
    }

    /**
     * Local bookkeeping after the portal has the file. A failure here is
     * logged and never turns a stored upload into a reported failure.
     *
     * @param  array<string, mixed>|null  $submissionState
     */
    private function recordUpload(PortalDatasetRepository $datasets, PortalActionProjector $projector, ?array $submissionState): void
    {
        try {
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
        } catch (\Throwable $throwable) {
            Log::warning('Failed to record SIPANDAI assignment upload locally', [
                'operation_id' => $this->operationId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * The assignments dataset feeds the pending list, the action centre and
     * the nav badges: all of them drop the uploaded task right away.
     */
    private function reportCompleted(PortalOperationTracker $tracker, PortalBroadcastService $broadcast): void
    {
        $realtimeKeys = PortalDatasetRepository::realtimeKeys('assignments', 'dashboard');
        $cacheKeys = [
            ...$realtimeKeys['cache_keys'],
            sprintf('/api/meeting/%d?courses_id=%d', $this->discussionId, $this->courseId),
        ];
        $syncKeys = [...$realtimeKeys['sync_keys'], 'meeting-show'];

        try {
            $tracker->progress($this->operationId, PortalOperationStage::Completed, 'Tugas terkirim ke portal.', 100, cacheKeys: $cacheKeys, syncKeys: $syncKeys);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to store SIPANDAI assignment upload status', ['operation_id' => $this->operationId, 'error' => $throwable->getMessage()]);
        }

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

    /**
     * Progress is informational: a failed write must not abort an upload
     * the portal may already have stored.
     */
    private function reportStage(PortalOperationTracker $tracker, PortalBroadcastService $broadcast, PortalOperationStage $stage, string $message, int $percent): void
    {
        try {
            $tracker->progress($this->operationId, $stage, $message, $percent);
        } catch (\Throwable $throwable) {
            Log::warning('Failed to store SIPANDAI assignment upload progress', ['operation_id' => $this->operationId, 'error' => $throwable->getMessage()]);
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
            Log::warning('Failed to store SIPANDAI assignment upload status', ['operation_id' => $this->operationId, 'error' => $throwable->getMessage()]);
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
     * the meeting page before uploading again, never to simply retry.
     */
    private function reportUnverified(PortalOperationTracker $tracker, PortalBroadcastService $broadcast, string $message, string $nextStep): void
    {
        $this->reportFailure($tracker, $broadcast, $message, $nextStep, PortalOperationStage::Unverified);
    }

    /**
     * Drops the stored file and frees the assignment for the next upload.
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
