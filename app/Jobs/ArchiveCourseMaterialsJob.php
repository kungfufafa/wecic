<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PortalException;
use App\Services\PortalBroadcastService;
use App\Services\PortalCourseArchiver;
use App\Services\PortalDataCache;
use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Archive every file of one class on the student's request (see
 * PortalCourseArchiver), with the queue's politeness delay, and tell the
 * student's browser how it went.
 */
class ArchiveCourseMaterialsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CACHE_KEYS = ['/api/archive', '/api/archive/files'];

    public const SYNC_KEYS = ['archive-index', 'archive-files'];

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $classParams  `id`, `dosen_id` and `course_id` of the class
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly string $campusSessionToken,
        private readonly array $classParams
    ) {
        $this->onQueue('campus-refresh');
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
        PortalBroadcastService $broadcast
    ): void {
        if (! $sessionManager->isSessionUsable($this->campusSessionToken)) {
            return;
        }

        try {
            $result = $archiver->archiveClass($this->campusSessionToken, $this->classParams);
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                return;
            }

            Log::warning('Class detail could not be read for the material archive', ['error' => $throwable->getMessage()]);
            $this->announce($broadcast, 'Materi kelas belum bisa diarsipkan. Coba lagi nanti.');

            return;
        }

        $this->announce($broadcast, $result['files'] > 0
            ? "{$result['files']} berkas materi baru tersimpan di arsip MyCIC."
            : 'Semua materi kelas ini sudah ada di arsip MyCIC.');
    }

    private function announce(PortalBroadcastService $broadcast, string $message): void
    {
        try {
            $broadcast->dataInvalidated($this->sessionId, $this->campusSessionToken, self::CACHE_KEYS, self::SYNC_KEYS, $message);
        } catch (\Throwable) {
            // The archive page also refreshes on its next load.
        }
    }
}
