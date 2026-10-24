<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PortalException;
use App\Services\PortalArchivedFileStore;
use App\Services\PortalClassCatalog;
use App\Services\PortalDataArchive;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Download course files a meeting links to into the student's archive.
 *
 * Files archived meanwhile (by another job or the course crawl) are skipped,
 * and one failing file never stops the others.
 */
class ArchivePortalFilesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<array{kind: string, source_url: string, title: string|null, course_key: string|null, meeting_id: string|null, meeting_number: int|null}>  $items
     */
    public function __construct(
        private readonly string $campusSessionToken,
        private readonly array $items,
        private readonly ?string $courseName = null
    ) {
        $this->onQueue('background');
    }

    public function uniqueId(): string
    {
        return md5($this->campusSessionToken.'|'.implode(',', array_column($this->items, 'source_url')));
    }

    public function uniqueFor(): int
    {
        return (int) config('portal.sync.lock_ttl', 300);
    }

    public function handle(
        PortalArchivedFileStore $files,
        PortalSessionManager $sessionManager,
        PortalDataOwner $owner
    ): void {
        if (! $sessionManager->isSessionUsable($this->campusSessionToken)) {
            return;
        }

        $ownerHash = $owner->key($this->campusSessionToken);
        $courseNames = [];

        foreach ($this->items as $item) {
            if ($files->isArchived($ownerHash, $item['source_url'])) {
                continue;
            }

            $courseKey = $item['course_key'];
            $courseNames[$courseKey ?? ''] ??= $this->courseName ?? $this->resolveCourseName($courseKey);

            try {
                $files->store($this->campusSessionToken, $item, $courseNames[$courseKey ?? '']);
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    return;
                }

                Log::warning('Course file could not be archived', [
                    'kind' => $item['kind'],
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
    }

    /**
     * The course name from the class detail the student already has.
     */
    private function resolveCourseName(?string $courseKey): ?string
    {
        if ($courseKey === null || $courseKey === '') {
            return null;
        }

        try {
            $classParams = app(PortalClassCatalog::class)->findByCourseId($this->campusSessionToken, $courseKey);

            if ($classParams === null) {
                return null;
            }

            $classDetail = app(PortalSnapshotStore::class)->get($this->campusSessionToken, 'class_detail', $classParams)['data']
                ?? app(PortalDataArchive::class)->restore($this->campusSessionToken, 'class_detail', $classParams)['data']
                ?? [];
            $name = $classDetail['course_info']['name'] ?? null;

            return is_string($name) && trim($name) !== '' ? trim($name) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
