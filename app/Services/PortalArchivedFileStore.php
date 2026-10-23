<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ArchivePortalFilesJob;
use App\Models\PortalArchivedFile;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copies of the course files a student's meetings link to (meeting
 * material, assignment material, RPS), kept under `portal.archive.files`.
 *
 * Blobs are stored once by content hash and shared, rows are per student:
 * a student only ever reaches files their own meetings linked to. Only
 * static files under /portal/files/ are fetched (PortalUrlGuard).
 *
 * @phpstan-type FileItem array{kind: string, source_url: string, title: string|null, course_key: string|null, meeting_id: string|null, meeting_number: int|null}
 */
class PortalArchivedFileStore
{
    public const KINDS = [
        'modul' => 'Materi',
        'latihan' => 'Latihan / tugas',
        'rps' => 'RPS',
    ];

    public function __construct(private readonly PortalDataOwner $owner) {}

    public function enabled(): bool
    {
        return (bool) config('portal.archive.enabled', true)
            && (bool) config('portal.archive.files.enabled', true);
    }

    /**
     * The files a meeting detail links to.
     *
     * @param  array<string, mixed>  $meetingDetail
     * @param  array<string, mixed>  $params  `meeting_id` and `courses_id`
     * @return list<FileItem>
     */
    public function candidatesFromMeeting(array $meetingDetail, array $params): array
    {
        $materials = is_array($meetingDetail['materials'] ?? null) ? $meetingDetail['materials'] : [];
        $rps = is_array($meetingDetail['rps_info'] ?? null) ? $meetingDetail['rps_info'] : [];
        $meetingNumber = $this->meetingNumber($materials);
        $references = [
            ['modul', $materials['main_material_iframe'] ?? null, $materials['main_topic'] ?? null],
            ['latihan', $materials['assignment_material_iframe'] ?? null, $materials['assignment_topic'] ?? null],
            ['rps', $rps['file_link'] ?? null, $rps['file_text'] ?? null],
        ];
        $items = [];

        foreach ($references as [$kind, $reference, $title]) {
            $sourceUrl = is_string($reference) ? PortalUrlGuard::campusFileUrl($reference) : null;

            if ($sourceUrl === null || isset($items[$sourceUrl])) {
                continue;
            }

            $items[$sourceUrl] = [
                'kind' => $kind,
                'source_url' => $sourceUrl,
                'title' => is_string($title) && trim($title) !== '' ? mb_substr(trim($title), 0, 255) : null,
                'course_key' => isset($params['courses_id']) ? (string) $params['courses_id'] : null,
                'meeting_id' => $kind === 'rps' || ! isset($params['meeting_id']) ? null : (string) $params['meeting_id'],
                'meeting_number' => $kind === 'rps' ? null : $meetingNumber,
            ];
        }

        return array_values($items);
    }

    /**
     * Queue the download of the files of a meeting that are not archived
     * yet. Never throws: archiving must not fail the sync that found them.
     *
     * @param  array<string, mixed>  $meetingDetail
     * @param  array<string, mixed>  $params
     */
    public function queueFromMeeting(string $sessionToken, array $meetingDetail, array $params): void
    {
        try {
            $ownerHash = $this->owner->key($sessionToken);

            if (! $this->enabled() || ! str_starts_with($ownerHash, 'student_')) {
                return;
            }

            // A background sync stores the files itself (PortalCourseArchiver):
            // its session is gone before a queued download would run.
            if (app(PortalSessionManager::class)->isBackgroundSession($sessionToken)) {
                return;
            }

            $items = $this->candidatesFromMeeting($meetingDetail, $params);
            $known = $this->archivedSourceUrls($ownerHash, array_column($items, 'source_url'));
            $missing = array_values(array_filter($items, static fn (array $item): bool => ! in_array($item['source_url'], $known, true)));

            if ($missing !== []) {
                ArchivePortalFilesJob::dispatch($sessionToken, $missing);
            }
        } catch (\Throwable $throwable) {
            Log::warning('Course files could not be queued for the archive', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Download one file with the student's portal session and archive it.
     * The blob check, the write and the row that starts using the blob run
     * under the blob's lock, so deleteOwner() never removes a blob in
     * between.
     *
     * @param  FileItem  $item
     */
    public function store(string $sessionToken, array $item, ?string $courseName = null): PortalArchivedFile
    {
        $ownerHash = $this->owner->key($sessionToken);

        if (! str_starts_with($ownerHash, 'student_')) {
            throw new RuntimeException('Files are only archived for a known student.');
        }

        $download = app(SipandaiSubmissionService::class)->downloadCampusFile($sessionToken, $item['source_url']);
        $size = strlen($download['contents']);

        if ($size === 0 || $size > (int) config('portal.archive.files.max_file_size', 26214400)) {
            throw new RuntimeException("Course file size {$size} is outside the archive limit.");
        }

        $sha256 = hash('sha256', $download['contents']);
        $blobPath = (new PortalArchivedFile(['sha256' => $sha256]))->blobPath();

        $archived = $this->withBlobLock($sha256, function () use ($blobPath, $download, $ownerHash, $item, $courseName, $size, $sha256): PortalArchivedFile {
            if (! $this->disk()->exists($blobPath)) {
                $this->disk()->put($blobPath, $download['contents']);
            }

            return PortalArchivedFile::query()->updateOrCreate(
                ['owner_hash' => $ownerHash, 'source_url' => $item['source_url']],
                [
                    'course_key' => $item['course_key'],
                    'course_name' => $courseName !== null ? mb_substr($courseName, 0, 255) : null,
                    'meeting_id' => $item['meeting_id'],
                    'meeting_number' => $item['meeting_number'],
                    'kind' => $item['kind'],
                    'title' => $item['title'],
                    'filename' => mb_substr($download['filename'], 0, 255),
                    'mime' => mb_substr($download['content_type'], 0, 127),
                    'size' => $size,
                    'sha256' => $sha256,
                    'archived_at' => now(),
                ]
            );
        });

        // The course notebook that tracks this meeting reads the file too.
        app(NotebookCourseSync::class)->attachArchivedFile($archived);

        return $archived;
    }

    /**
     * Files grouped by course, newest course activity first.
     *
     * @return list<array{course_key: string|null, course_name: string|null, files: list<array<string, mixed>>}>
     */
    public function grouped(string $ownerHash): array
    {
        return PortalArchivedFile::query()
            ->forOwner($ownerHash)
            ->orderBy('course_name')
            ->orderBy('meeting_number')
            ->orderBy('kind')
            ->get()
            ->groupBy(static fn (PortalArchivedFile $file): string => (string) $file->course_key)
            ->map(static fn ($files): array => [
                'course_key' => $files->first()?->course_key,
                'course_name' => $files->pluck('course_name')->filter()->first(),
                'files' => $files->map(static fn (PortalArchivedFile $file): array => [
                    ...$file->toApiArray(),
                    'kind_label' => self::KINDS[$file->kind] ?? $file->kind,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{count: int, bytes: int}
     */
    public function totals(string $ownerHash): array
    {
        $query = PortalArchivedFile::query()->forOwner($ownerHash);

        return [
            'count' => (clone $query)->count(),
            'bytes' => (int) (clone $query)->sum('size'),
        ];
    }

    public function findForOwner(string $ownerHash, int $fileId): ?PortalArchivedFile
    {
        return PortalArchivedFile::query()->forOwner($ownerHash)->whereKey($fileId)->first();
    }

    public function contents(PortalArchivedFile $file): ?string
    {
        $contents = $this->disk()->get($file->blobPath());

        return is_string($contents) ? $contents : null;
    }

    public function absolutePath(PortalArchivedFile $file): ?string
    {
        return $this->disk()->exists($file->blobPath()) ? $this->disk()->path($file->blobPath()) : null;
    }

    /**
     * The source URLs among `$sourceUrls` this student has a readable copy
     * of. A row whose blob is missing does not count, so it is downloaded
     * again instead of failing forever.
     *
     * @param  list<string>  $sourceUrls
     * @return list<string>
     */
    public function archivedSourceUrls(string $ownerHash, array $sourceUrls): array
    {
        if ($sourceUrls === []) {
            return [];
        }

        return PortalArchivedFile::query()
            ->forOwner($ownerHash)
            ->whereIn('source_url', $sourceUrls)
            ->get(['id', 'source_url', 'sha256'])
            ->filter(fn (PortalArchivedFile $file): bool => $this->disk()->exists($file->blobPath()))
            ->pluck('source_url')
            ->values()
            ->all();
    }

    public function isArchived(string $ownerHash, string $sourceUrl): bool
    {
        return $this->archivedSourceUrls($ownerHash, [$sourceUrl]) !== [];
    }

    /**
     * Drop a student's files; blobs no other student uses are deleted too.
     */
    public function deleteOwner(string $ownerHash): int
    {
        $files = PortalArchivedFile::query()->forOwner($ownerHash)->get(['id', 'sha256']);
        $deleted = PortalArchivedFile::query()->whereKey($files->pluck('id')->all())->delete();

        foreach ($files->pluck('sha256')->unique() as $sha256) {
            try {
                $this->withBlobLock((string) $sha256, function () use ($sha256): void {
                    if (! PortalArchivedFile::query()->where('sha256', $sha256)->exists()) {
                        $this->disk()->delete((new PortalArchivedFile(['sha256' => $sha256]))->blobPath());
                    }
                });
            } catch (LockTimeoutException) {
                Log::warning('Archived file blob was busy and kept', ['sha256' => $sha256]);
            }
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $materials
     */
    private function meetingNumber(array $materials): ?int
    {
        foreach (['assignment_topic', 'main_topic'] as $field) {
            if (is_string($materials[$field] ?? null) && preg_match('/pertemuan\s*(?:ke\s*)?-?\s*(\d{1,2})/i', $materials[$field], $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Blobs are shared between students: storing one and deleting an unused
     * one take turns per content hash.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     *
     * @throws LockTimeoutException
     */
    private function withBlobLock(string $sha256, callable $callback): mixed
    {
        return Cache::lock('portal_archive_blob_'.$sha256, 60)->block(15, $callback);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('portal.archive.files.disk', 'local'));
    }
}
