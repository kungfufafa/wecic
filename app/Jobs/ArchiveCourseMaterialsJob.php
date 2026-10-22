<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PortalException;
use App\Models\PortalArchivedFile;
use App\Services\PortalArchivedFileStore;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataCache;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use App\Services\PortalUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Archive every file of one class on the student's request: the RPS, then
 * the materials of each meeting listed on the class page. Meetings are read
 * through the dataset repository, so they are archived (and their snapshot
 * refreshed) like any other read, with the queue's politeness delay.
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
        PortalDatasetRepository $datasets,
        PortalArchivedFileStore $files,
        PortalSessionManager $sessionManager,
        PortalBroadcastService $broadcast,
        PortalDataOwner $owner
    ): void {
        if (! $sessionManager->isSessionUsable($this->campusSessionToken)) {
            return;
        }

        $ownerHash = $owner->key($this->campusSessionToken);
        $archived = 0;

        try {
            $classDetail = $datasets->ensureFresh($this->campusSessionToken, 'class_detail', $this->classParams);
        } catch (\Throwable $throwable) {
            Log::warning('Class detail could not be read for the material archive', ['error' => $throwable->getMessage()]);
            $this->announce($broadcast, 'Materi kelas belum bisa diarsipkan. Coba lagi nanti.');

            return;
        }

        $courseKey = isset($this->classParams['course_id']) ? (string) $this->classParams['course_id'] : null;
        $courseName = is_string($classDetail['course_info']['name'] ?? null) ? trim($classDetail['course_info']['name']) : null;
        $items = [];
        $rpsUrl = is_string($classDetail['course_info']['rps_link'] ?? null) ? PortalUrlGuard::campusFileUrl($classDetail['course_info']['rps_link']) : null;

        if ($rpsUrl !== null) {
            $items[] = ['kind' => 'rps', 'source_url' => $rpsUrl, 'title' => 'RPS', 'course_key' => $courseKey, 'meeting_id' => null, 'meeting_number' => null];
        }

        foreach ((array) ($classDetail['course_materials'] ?? []) as $material) {
            $meetingParams = $this->meetingParams($material);

            if ($meetingParams === null) {
                continue;
            }

            try {
                $meeting = $datasets->ensureFresh($this->campusSessionToken, 'meeting_detail', $meetingParams);
                array_push($items, ...$files->candidatesFromMeeting($meeting, $meetingParams));
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    return;
                }

                Log::warning('Meeting could not be read for the material archive', ['error' => $throwable->getMessage()]);
            }
        }

        foreach ($items as $item) {
            if (PortalArchivedFile::query()->forOwner($ownerHash)->where('source_url', $item['source_url'])->exists()) {
                continue;
            }

            try {
                $files->store($this->campusSessionToken, $item, $courseName);
                $archived++;
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    return;
                }

                Log::warning('Course file could not be archived', ['kind' => $item['kind'], 'error' => $throwable->getMessage()]);
            }
        }

        $this->announce($broadcast, $archived > 0
            ? "{$archived} berkas materi baru tersimpan di arsip MyCIC."
            : 'Semua materi kelas ini sudah ada di arsip MyCIC.');
    }

    /**
     * @return array{meeting_id: string, courses_id: string}|null
     */
    private function meetingParams(mixed $material): ?array
    {
        $href = is_array($material) ? ($material['join_link']['href'] ?? null) : null;

        if (! is_string($href) || $href === '') {
            return null;
        }

        parse_str((string) (parse_url(html_entity_decode($href), PHP_URL_QUERY) ?? ''), $query);
        $meetingId = $query['id'] ?? null;
        $coursesId = $query['courses_id'] ?? ($query['course_id'] ?? null);

        if (! is_string($meetingId) || ! ctype_digit($meetingId) || ! is_string($coursesId) || ! ctype_digit($coursesId)) {
            return null;
        }

        return ['meeting_id' => $meetingId, 'courses_id' => $coursesId];
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
