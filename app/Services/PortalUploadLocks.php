<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalOperationStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One upload per student and target at a time, whoever sends it: the upload
 * endpoints and the outbox flush (PortalOutboxSender). A second upload of
 * the same target (another tab, a re-rendered page, a queued copy) would
 * otherwise send the file twice. The lock is released by the upload job when
 * it finishes; a lock whose upload has stopped reporting is taken over
 * instead of blocking the student until it expires.
 */
class PortalUploadLocks
{
    /**
     * How long an upload keeps its target locked when its job never gets to
     * release it: the job's own time limit with room to spare. A job that
     * stopped reporting progress frees its target sooner (see isStale).
     */
    private const LOCK_SECONDS = 600;

    public function __construct(
        private readonly PortalDataOwner $owner,
        private readonly PortalOperationTracker $tracker
    ) {}

    public static function assignmentTarget(int $courseId, int $discussionId, int $meetingNumber): string
    {
        return sprintf('assignment:%d:%d:%d', $courseId, $discussionId, $meetingNumber);
    }

    public static function examTarget(int $examId): string
    {
        return 'exam:'.$examId;
    }

    /**
     * @return array{key: string, owner: string}|null null while another upload of the target runs
     */
    public function acquire(string $sessionToken, string $target, string $operationId): ?array
    {
        $key = $this->key($sessionToken, $target);
        $lock = Cache::lock($key, self::LOCK_SECONDS);

        if (! $lock->get()) {
            if (! $this->isStale($key)) {
                return null;
            }

            Log::warning('Releasing the upload lock of an upload that stopped reporting', [
                'target' => $target,
                'operation_id' => Cache::get($key.':operation'),
            ]);
            $lock->forceRelease();

            if (! $lock->get()) {
                return null;
            }
        }

        Cache::put($key.':operation', $operationId, self::LOCK_SECONDS);

        return ['key' => $key, 'owner' => $lock->owner()];
    }

    /**
     * @param  array{key: string, owner: string}|null  $lock
     */
    public function release(?array $lock): void
    {
        if ($lock !== null) {
            Cache::restoreLock($lock['key'], $lock['owner'])->release();
        }
    }

    /**
     * The operation of the upload holding the target, if one does.
     */
    public function runningOperationId(string $sessionToken, string $target): ?string
    {
        $operationId = Cache::get($this->key($sessionToken, $target).':operation');

        return is_string($operationId) ? $operationId : null;
    }

    private function key(string $sessionToken, string $target): string
    {
        return 'portal_upload:'.md5($this->owner->key($sessionToken)).':'.$target;
    }

    /**
     * The upload holding the lock has finished, or has not reported progress
     * for longer than an upload job may run: its process died (killed
     * worker, out of memory) before the job could free the target. An
     * upload whose state cannot be read keeps its lock until it expires.
     */
    private function isStale(string $key): bool
    {
        $operationId = Cache::get($key.':operation');
        $operation = is_string($operationId) ? $this->tracker->get($operationId) : null;

        if ($operation === null) {
            return false;
        }

        if (PortalOperationStage::tryFrom((string) ($operation['stage'] ?? ''))?->isFinal() === true) {
            return true;
        }

        try {
            $lastProgressAt = Carbon::parse((string) ($operation['updated_at'] ?? ''));
        } catch (\Throwable) {
            return false;
        }

        return $lastProgressAt->lt(now()->subSeconds(max(60, (int) config('portal.actions.upload_time_limit', 180))));
    }
}
