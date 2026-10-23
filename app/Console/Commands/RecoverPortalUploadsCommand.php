<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PortalOperationStage;
use App\Services\PortalOperationTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Settles the uploads whose process died before their job could (a killed
 * PHP-FPM worker, a deploy, a fatal error in an earlier terminating
 * callback): their operation would stay mid-stage, their target locked and
 * their file on disk.
 *
 * Every upload file is named after its operation (see
 * PortalOperationTracker::uploadFilePath). Once a file is older than an
 * upload may run and its operation stopped reporting, the operation is
 * reported as unverified (the portal may have stored the file before the
 * process died), its lock is released and the file deleted. Files of
 * finished or forgotten operations are deleted too. Scheduled every five
 * minutes.
 */
class RecoverPortalUploadsCommand extends Command
{
    protected $signature = 'portal:recover-uploads';

    protected $description = 'Settle uploads whose process stopped before finishing and delete their leftover files';

    /**
     * Room past the longest an upload may go without reporting (its time
     * limit, or one portal request) before it counts as abandoned.
     */
    public const MARGIN_SECONDS = 120;

    /**
     * What the student reads on an upload that was cut off, per operation type.
     *
     * @var array<string, array{message: string, next_step: string}>
     */
    private const UNVERIFIED_NOTICES = [
        'assignment_upload' => [
            'message' => 'Status upload tugas belum pasti.',
            'next_step' => 'Proses upload terhenti sebelum selesai. Cek halaman pertemuan sebelum mengunggah ulang, supaya file tidak terkirim dua kali.',
        ],
        'exam_upload' => [
            'message' => 'Status upload ujian belum pasti.',
            'next_step' => 'Proses upload terhenti sebelum selesai. Cek halaman ujian sebelum mengunggah ulang, supaya jawaban tidak terkirim dua kali.',
        ],
    ];

    private const FALLBACK_NOTICE = [
        'message' => 'Status upload belum pasti.',
        'next_step' => 'Proses upload terhenti sebelum selesai. Cek halaman tugas atau ujian sebelum mengunggah ulang, supaya file tidak terkirim dua kali.',
    ];

    public function handle(PortalOperationTracker $tracker): int
    {
        $abandonedBefore = now()->subSeconds(self::abandonedAfterSeconds());
        $settled = 0;
        $deleted = 0;
        $failed = 0;

        foreach (Storage::files(PortalOperationTracker::UPLOAD_DIRECTORY) as $path) {
            if (str_starts_with(basename($path), '.')) {
                continue;
            }

            try {
                $outcome = $this->recover($tracker, $path, $abandonedBefore);
            } catch (\Throwable $throwable) {
                $failed++;
                Log::warning('Failed to recover a portal upload', ['file' => $path, 'error' => $throwable->getMessage()]);

                continue;
            }

            $settled += $outcome['settled'] ? 1 : 0;
            $deleted += $outcome['deleted'] ? 1 : 0;
        }

        $this->info(sprintf('Settled %d abandoned upload(s); deleted %d leftover file(s).', $settled, $deleted));

        if ($failed > 0) {
            $this->warn(sprintf('%d upload file(s) could not be recovered; see the log.', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Seconds after which an upload that has not reported counts as abandoned.
     */
    public static function abandonedAfterSeconds(): int
    {
        return max(
            60,
            (int) config('portal.actions.upload_time_limit', 180),
            (int) config('portal.actions.upload_request_timeout', 120)
        ) + self::MARGIN_SECONDS;
    }

    /**
     * @return array{settled: bool, deleted: bool}
     */
    private function recover(PortalOperationTracker $tracker, string $path, Carbon $abandonedBefore): array
    {
        $untouched = ['settled' => false, 'deleted' => false];

        if (! $this->writtenBefore($path, $abandonedBefore)) {
            return $untouched;
        }

        $operationId = $tracker->operationIdForUploadFile($path);
        $operation = $operationId !== null ? $tracker->get($operationId) : null;
        $settled = false;

        if ($operationId !== null && $operation !== null && ! $this->isFinal($operation)) {
            if (! $this->stoppedReportingBefore($operation, $abandonedBefore)) {
                return $untouched;
            }

            $this->reportUnverified($tracker, $operationId, $operation);
            $settled = true;
        }

        if ($operation !== null) {
            $this->releaseLock($operation);
        }

        return ['settled' => $settled, 'deleted' => Storage::delete($path)];
    }

    /**
     * A file that vanished meanwhile was deleted by its own job.
     */
    private function writtenBefore(string $path, Carbon $moment): bool
    {
        try {
            return Storage::lastModified($path) < $moment->getTimestamp();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function isFinal(array $operation): bool
    {
        return PortalOperationStage::tryFrom((string) ($operation['stage'] ?? ''))?->isFinal() === true;
    }

    /**
     * An operation whose last report cannot be read counts as stopped: its
     * file is already older than any upload may run.
     *
     * @param  array<string, mixed>  $operation
     */
    private function stoppedReportingBefore(array $operation, Carbon $moment): bool
    {
        try {
            return Carbon::parse((string) ($operation['updated_at'] ?? ''))->lt($moment);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function reportUnverified(PortalOperationTracker $tracker, string $operationId, array $operation): void
    {
        $notice = self::UNVERIFIED_NOTICES[(string) ($operation['type'] ?? '')] ?? self::FALLBACK_NOTICE;

        $tracker->progress($operationId, PortalOperationStage::Unverified, $notice['message'], 100, null, $notice['next_step']);

        Log::warning('Settled a portal upload whose process stopped before finishing', [
            'operation_id' => $operationId,
            'type' => $operation['type'] ?? null,
            'stage' => $operation['stage'] ?? null,
            'updated_at' => $operation['updated_at'] ?? null,
        ]);
    }

    /**
     * Releases the target lock only while the upload still owns it: a newer
     * upload of the same target keeps its own.
     *
     * @param  array<string, mixed>  $operation
     */
    private function releaseLock(array $operation): void
    {
        $lockKey = $operation['upload']['lock_key'] ?? null;
        $lockOwner = $operation['upload']['lock_owner'] ?? null;

        if (is_string($lockKey) && $lockKey !== '' && is_string($lockOwner) && $lockOwner !== '') {
            Cache::restoreLock($lockKey, $lockOwner)->release();
        }
    }
}
