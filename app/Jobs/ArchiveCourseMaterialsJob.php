<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PortalException;
use App\Models\PortalNotification;
use App\Services\PortalBroadcastService;
use App\Services\PortalCourseArchiver;
use App\Services\PortalDataCache;
use App\Services\PortalDataOwner;
use App\Services\PortalNotificationCenter;
use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Archive every file of one class on the student's request (see
 * PortalCourseArchiver), with the queue's politeness delay, and tell the
 * student's browser how it went.
 *
 * The student was told they would hear back. When their portal session has
 * ended before the job could run, a notification says so (the browser may
 * be long closed by then), so the request never ends in silence.
 */
class ArchiveCourseMaterialsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CACHE_KEYS = ['/api/archive', '/api/archive/files'];

    public const SYNC_KEYS = ['archive-index', 'archive-files'];

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * The student, resolved when the job is queued: once the session has
     * ended its token no longer leads to them. A plain property with a
     * default (not promoted) so jobs queued before it existed still
     * unserialize.
     */
    private ?string $ownerHash = null;

    /**
     * @param  array<string, mixed>  $classParams  `id`, `dosen_id` and `course_id` of the class
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly string $campusSessionToken,
        private readonly array $classParams
    ) {
        $this->ownerHash = app(PortalDataOwner::class)->key($campusSessionToken);
        $this->onQueue('background');
    }

    public function uniqueId(): string
    {
        return md5($this->campusSessionToken.'|'.PortalDataCache::paramsHash($this->classParams));
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(
        PortalCourseArchiver $archiver,
        PortalSessionManager $sessionManager,
        PortalBroadcastService $broadcast,
        PortalDataOwner $owner
    ): void {
        if (! $sessionManager->isSessionUsable($this->campusSessionToken)) {
            $this->reportSessionEnded($broadcast, $owner);

            return;
        }

        try {
            $result = $archiver->archiveClass($this->campusSessionToken, $this->classParams);
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                $this->reportSessionEnded($broadcast, $owner);

                return;
            }

            Log::warning('Class detail could not be read for the material archive', ['error' => $throwable->getMessage()]);
            $this->announce($broadcast, 'Materi kelas belum bisa diarsipkan. Coba lagi nanti.');

            return;
        }

        $this->announce($broadcast, $result['files'] > 0
            ? "{$result['files']} berkas materi baru tersimpan di arsip WeCIC."
            : 'Semua materi kelas ini sudah ada di arsip WeCIC.');
    }

    /**
     * Kept in the student's notifications, then announced to the browser
     * that asked in case it is still open.
     */
    private function reportSessionEnded(PortalBroadcastService $broadcast, PortalDataOwner $owner): void
    {
        $ownerHash = $this->ownerHash ?? $owner->key($this->campusSessionToken);
        $message = 'Materi kelas belum diarsipkan karena sesi portal kamu sudah berakhir. Masuk lagi, lalu tekan Arsipkan sekali lagi.';

        if (str_starts_with($ownerHash, 'student_')) {
            try {
                PortalNotification::query()->create([
                    'owner_hash' => $ownerHash,
                    'type' => 'archive_class_not_run',
                    'record_key' => 'class:'.($this->classParams['id'] ?? ''),
                    'dedupe_key' => hash('sha256', 'archive_class_not_run|'.Str::uuid()),
                    'title' => 'Materi kelas belum diarsipkan',
                    'body' => $message,
                    'url' => '/archive',
                    'urgency' => 'warning',
                ]);
            } catch (\Throwable $throwable) {
                Log::warning('Class archive outcome could not be stored as a notification', ['error' => $throwable->getMessage()]);
            }
        }

        $this->announce($broadcast, $message, [...PortalNotificationCenter::CACHE_KEYS], [...PortalNotificationCenter::SYNC_KEYS]);
    }

    /**
     * @param  list<string>  $extraCacheKeys
     * @param  list<string>  $extraSyncKeys
     */
    private function announce(PortalBroadcastService $broadcast, string $message, array $extraCacheKeys = [], array $extraSyncKeys = []): void
    {
        try {
            $broadcast->dataInvalidated(
                $this->sessionId,
                $this->campusSessionToken,
                [...self::CACHE_KEYS, ...$extraCacheKeys],
                [...self::SYNC_KEYS, ...$extraSyncKeys],
                $message
            );
        } catch (\Throwable) {
            // The archive page also refreshes on its next load.
        }
    }
}
