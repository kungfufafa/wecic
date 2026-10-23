<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PortalException;
use App\Models\Notebook;
use App\Services\NotebookCourseSync;
use App\Services\NotebookLibrary;
use App\Services\PortalArchivedFileStore;
use App\Services\PortalBroadcastService;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Put every meeting of a class into its course notebook on the student's
 * request: each meeting listed on the class page is read (through the
 * dataset repository, with the queue's politeness delay), noted in the
 * notebook, and its course files are archived; archived files attach
 * themselves to the notebook and are read at the end of the run. Meetings
 * the student removed from the notebook stay out, and the run stops at the
 * notebook's source limit.
 */
class SyncCourseNotebookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly string $sessionId,
        private readonly string $campusSessionToken,
        private readonly int $notebookId
    ) {
        $this->onQueue('campus-background');
    }

    public function uniqueId(): string
    {
        return (string) $this->notebookId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(
        PortalDatasetRepository $datasets,
        PortalArchivedFileStore $files,
        NotebookCourseSync $courseSync,
        PortalSessionManager $sessionManager,
        PortalBroadcastService $broadcast,
        NotebookLibrary $library
    ): void {
        $notebook = Notebook::query()->find($this->notebookId);
        $classParams = $notebook?->class_params;

        if ($notebook === null || ! $notebook->isCourseNotebook() || ! is_array($classParams) || ! isset($classParams['id'])) {
            return;
        }

        if (! $sessionManager->isSessionUsable($this->campusSessionToken)) {
            return;
        }

        try {
            $classDetail = $datasets->ensureFresh($this->campusSessionToken, 'class_detail', $classParams);
        } catch (\Throwable $throwable) {
            Log::warning('Class detail could not be read for the course notebook', ['error' => $throwable->getMessage()]);
            $this->announce($broadcast, $notebook, 'Pertemuan kelas belum bisa dimasukkan ke notebook. Coba lagi nanti.');

            return;
        }

        $courseName = is_string($classDetail['course_info']['name'] ?? null) ? trim($classDetail['course_info']['name']) : null;
        $courseSync->courseNotebook($notebook->owner_hash, (string) $notebook->course_key, [
            'course_name' => $courseName,
            'course_code' => $classDetail['course_info']['code'] ?? null,
            'lecturer_name' => $classDetail['lecturer_info']['name'] ?? null,
        ]);

        $added = 0;
        $items = [];
        $full = false;
        $limit = max(1, (int) config('notebook.course_notebooks.max_meetings_per_sync', 20));

        foreach (array_slice((array) ($classDetail['course_materials'] ?? []), 0, $limit) as $material) {
            $meetingParams = NotebookCourseSync::meetingParams($material);

            if ($meetingParams === null || $courseSync->isMeetingDismissed($notebook, $meetingParams['meeting_id'])) {
                continue;
            }

            if (! $courseSync->canAddMeeting($notebook, $meetingParams['meeting_id'])) {
                $full = true;

                break;
            }

            try {
                $meeting = $datasets->ensureFresh($this->campusSessionToken, 'meeting_detail', $meetingParams);

                if (($meeting['found'] ?? true) === false) {
                    continue;
                }

                $courseSync->addMeeting($this->campusSessionToken, $notebook->refresh(), $meeting, $meetingParams);
                $added++;
                array_push($items, ...$files->candidatesFromMeeting($meeting, $meetingParams));
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    return;
                }

                Log::warning('Meeting could not be added to the course notebook', ['error' => $throwable->getMessage()]);
            }
        }

        $this->archiveMissing($files, $notebook, $items, $courseName);
        $library->readWaitingSources($notebook);

        $message = $added > 0
            ? "{$added} pertemuan masuk ke notebook \"{$notebook->title}\". Berkas materinya dibaca di background."
            : 'Belum ada pertemuan yang bisa dimasukkan ke notebook ini.';
        $this->announce($broadcast, $notebook, $full ? $message.' Batas jumlah sumber notebook tercapai; hapus sumber yang tidak perlu untuk menambah pertemuan lain.' : $message);
    }

    /**
     * Course files the archive does not have yet, or whose stored copy is
     * missing, are downloaded here (not queued per meeting), so the notebook
     * gets them in this run.
     *
     * @param  list<array{kind: string, source_url: string, title: string|null, course_key: string|null, meeting_id: string|null, meeting_number: int|null}>  $items
     */
    private function archiveMissing(PortalArchivedFileStore $files, Notebook $notebook, array $items, ?string $courseName): void
    {
        $seen = [];

        foreach ($items as $item) {
            if (isset($seen[$item['source_url']]) || $files->isArchived($notebook->owner_hash, $item['source_url'])) {
                continue;
            }

            $seen[$item['source_url']] = true;

            try {
                $files->store($this->campusSessionToken, $item, $courseName);
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    return;
                }

                Log::warning('Course file could not be archived for the notebook', ['kind' => $item['kind'], 'error' => $throwable->getMessage()]);
            }
        }
    }

    private function announce(PortalBroadcastService $broadcast, Notebook $notebook, string $message): void
    {
        try {
            $broadcast->dataInvalidated(
                $this->sessionId,
                $this->campusSessionToken,
                ['/api/notebooks', '/api/notebooks/'.$notebook->id, '/api/notebooks/'.$notebook->id.'/meetings', ...ArchiveCourseMaterialsJob::CACHE_KEYS],
                ['notebook-show', ...ArchiveCourseMaterialsJob::SYNC_KEYS],
                $message
            );
        } catch (\Throwable) {
            // The notebook page also refreshes on its next load.
        }
    }
}
