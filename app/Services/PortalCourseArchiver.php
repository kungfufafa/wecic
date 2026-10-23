<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
use Illuminate\Support\Facades\Log;

/**
 * Archives one class end to end: its class detail, every meeting the class
 * page lists and the files those meetings link to (RPS, modul, latihan).
 *
 * Class and meeting details are read through the dataset repository, so
 * they are archived (and their snapshot refreshed) like any other read.
 * Files are stored in the calling session, never queued: a background
 * session is destroyed when its sync ends, before a queued download could
 * run. One failing meeting or file never stops the others; an expired
 * session does, because that needs a new login.
 *
 * Used by the student's "archive this class" action
 * (ArchiveCourseMaterialsJob) and by the background sync
 * (PortalBackgroundSync), which crawls every class on its own interval.
 *
 * @phpstan-type ClassParams array{id?: string, dosen_id?: string, course_id?: string}
 * @phpstan-type ArchiveResult array{course_name: string|null, meetings: int, files: int, errors: list<string>}
 */
class PortalCourseArchiver
{
    public function __construct(
        private readonly PortalDatasetRepository $datasets,
        private readonly PortalArchivedFileStore $files,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * The link parameters of every class in a `classes` dataset payload.
     *
     * @param  array<string, mixed>  $classesData
     * @return list<array<string, string>>
     */
    public function classParamsFrom(array $classesData): array
    {
        $classes = [];

        foreach ((array) ($classesData['classes'] ?? []) as $class) {
            $params = is_array($class) ? ($class['join_link']['params'] ?? null) : null;

            if (! is_array($params) || ! isset($params['id'])) {
                continue;
            }

            $key = (string) $params['id'];
            $classes[$key] ??= array_map('strval', array_filter($params, 'is_scalar'));
        }

        return array_values($classes);
    }

    /**
     * @param  ClassParams  $classParams
     * @return ArchiveResult
     *
     * @throws \Throwable When the class detail cannot be read, or the session expired.
     */
    public function archiveClass(string $sessionToken, array $classParams): array
    {
        $classDetail = $this->datasets->ensureFresh($sessionToken, 'class_detail', $classParams);
        $ownerHash = $this->owner->key($sessionToken);
        $courseKey = isset($classParams['course_id']) ? (string) $classParams['course_id'] : null;
        $courseName = is_string($classDetail['course_info']['name'] ?? null) && trim($classDetail['course_info']['name']) !== ''
            ? trim($classDetail['course_info']['name'])
            : null;
        $result = ['course_name' => $courseName, 'meetings' => 0, 'files' => 0, 'errors' => []];
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
                $meeting = $this->datasets->ensureFresh($sessionToken, 'meeting_detail', $meetingParams);
                $result['meetings']++;
                array_push($items, ...$this->files->candidatesFromMeeting($meeting, $meetingParams));
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    throw $throwable;
                }

                Log::warning('Meeting could not be read for the material archive', ['error' => $throwable->getMessage()]);
                $result['errors'][] = "pertemuan {$meetingParams['meeting_id']}: {$throwable->getMessage()}";
            }
        }

        if (! $this->files->enabled()) {
            return $result;
        }

        foreach ($this->withoutDuplicates($items) as $item) {
            if ($this->files->isArchived($ownerHash, $item['source_url'])) {
                continue;
            }

            try {
                $this->files->store($sessionToken, $item, $courseName);
                $result['files']++;
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    throw $throwable;
                }

                Log::warning('Course file could not be archived', ['kind' => $item['kind'], 'error' => $throwable->getMessage()]);
                $result['errors'][] = "berkas {$item['kind']}: {$throwable->getMessage()}";
            }
        }

        return $result;
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

    /**
     * The RPS is linked from the class page and from every meeting.
     *
     * @param  list<array{kind: string, source_url: string, title: string|null, course_key: string|null, meeting_id: string|null, meeting_number: int|null}>  $items
     * @return list<array{kind: string, source_url: string, title: string|null, course_key: string|null, meeting_id: string|null, meeting_number: int|null}>
     */
    private function withoutDuplicates(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $unique[$item['source_url']] ??= $item;
        }

        return array_values($unique);
    }
}
