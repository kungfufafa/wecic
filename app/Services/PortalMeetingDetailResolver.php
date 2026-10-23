<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortalException;
use App\Services\Extractors\ClassesExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Builds a meeting (discussion) detail and resolves whether its assignment has
 * already been uploaded, by cross-checking the class assignment history and the
 * assignment upload page.
 */
class PortalMeetingDetailResolver
{
    public function __construct(
        private readonly ClassesExtractor $classesExtractor,
        private readonly PortalClassCatalog $classCatalog,
        private readonly SipandaiSubmissionService $submissionService
    ) {}

    /**
     * `class_url` is the class page of the meeting, for the meeting page's
     * way back: the breadcrumb's class link (`meeting_info.class_ref`) on
     * every meeting page that has one, otherwise the class the assignment
     * cross-check resolved from the class list.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $sessionToken, string $meetingId, string $coursesId): array
    {
        $meetingDetailData = $this->classesExtractor->extractClassDetail($sessionToken, [
            'meeting_id' => $meetingId,
            'courses_id' => $coursesId,
        ]);

        $classRef = $meetingDetailData['meeting_info']['class_ref'] ?? null;
        $breadcrumbClassUrl = is_array($classRef) ? $this->classUrl($classRef) : null;

        $meetingDetailData = $this->enrichMeetingDetailAssignmentInfo($sessionToken, $meetingDetailData, $coursesId);

        if ($breadcrumbClassUrl !== null) {
            $meetingDetailData['class_url'] = $breadcrumbClassUrl;
        }

        return $meetingDetailData;
    }

    /**
     * Whether the assignment was submitted can only be read from the class
     * page's submission history. When that page cannot be read (timeout, course not
     * in the class list) the status is `unresolved`: a confident
     * `not_uploaded` would invite a student who already submitted to upload
     * again.
     *
     * @param  array<string, mixed>  $meetingDetailData
     * @return array<string, mixed>
     */
    private function enrichMeetingDetailAssignmentInfo(string $sessionToken, array $meetingDetailData, string $coursesId): array
    {
        $assignmentInfo = is_array($meetingDetailData['assignment_info'] ?? null)
            ? $meetingDetailData['assignment_info']
            : [];
        $materials = is_array($meetingDetailData['materials'] ?? null)
            ? $meetingDetailData['materials']
            : [];
        $hasUploadLink = trim((string) ($assignmentInfo['upload_link'] ?? '')) !== '';

        $hasAssignmentContent = ! empty($materials['assignment_topic'])
            || ! empty($materials['assignment_instructions'])
            || ! empty($materials['assignment_material_iframe'])
            || $hasUploadLink
            || trim((string) ($assignmentInfo['upload_text'] ?? '')) !== '';

        if (! $hasAssignmentContent) {
            $meetingDetailData['assignment_info'] = $assignmentInfo;

            return $meetingDetailData;
        }

        $meetingNumber = $this->meetingNumberFromUploadLink((string) ($assignmentInfo['upload_link'] ?? ''))
            ?? $this->inferMeetingNumberFromMeetingDetail($meetingDetailData);

        if ($meetingNumber === null) {
            $assignmentInfo['status'] = 'unresolved';
            $meetingDetailData['assignment_info'] = $assignmentInfo;

            return $meetingDetailData;
        }

        $assignmentInfo['meeting_number'] = $meetingNumber;

        try {
            $classParameters = $this->classCatalog->findByCourseId($sessionToken, $coursesId);

            if ($classParameters === null) {
                $assignmentInfo['status'] = 'unresolved';
                $meetingDetailData['assignment_info'] = $assignmentInfo;

                return $meetingDetailData;
            }

            $meetingDetailData['class_url'] = $this->classUrl($classParameters);
            $classDetailData = $this->classesExtractor->extractClassDetail($sessionToken, $classParameters);
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            Log::warning('Failed to enrich meeting assignment info from class detail', [
                'courses_id' => $coursesId,
                'meeting_number' => $meetingNumber,
                'error' => $throwable->getMessage(),
            ]);

            $assignmentInfo['status'] = 'unresolved';
            $meetingDetailData['assignment_info'] = $assignmentInfo;

            return $meetingDetailData;
        }

        $submission = collect($classDetailData['assignment_history']['assignments'] ?? [])
            ->first(fn (mixed $item): bool => is_array($item) && (string) ($item['meeting'] ?? '') === $meetingNumber);

        if (! is_array($submission)) {
            $assignmentInfo['status'] = $hasUploadLink ? 'not_uploaded' : 'upload_unavailable';
            $meetingDetailData['assignment_info'] = $assignmentInfo;

            return $meetingDetailData;
        }

        $assignmentInfo['status'] = 'uploaded';
        $assignmentInfo['submission'] = $submission;

        $deletePayload = $this->extractDeletePayloadFromAssignmentHistory($submission, $meetingNumber);
        if ($deletePayload !== null) {
            $assignmentInfo['delete_payload'] = $deletePayload;
        }

        $assignmentPageState = $this->resolveAssignmentPageState(
            $sessionToken,
            $coursesId,
            $meetingDetailData,
            $assignmentInfo
        );

        if ($assignmentPageState !== null) {
            if (($assignmentPageState['delete_payload'] ?? null) !== null) {
                $assignmentInfo['delete_payload'] = $assignmentPageState['delete_payload'];
            }

            if (($assignmentPageState['file_info'] ?? null) !== null && empty($assignmentInfo['submission']['file_info'])) {
                $assignmentInfo['submission']['file_info'] = $assignmentPageState['file_info'];
            }
        }

        $meetingDetailData['assignment_info'] = $assignmentInfo;

        return $meetingDetailData;
    }

    /**
     * The upload link carries the portal's own meeting number; free text
     * such as a lecturer's "Review Pertemuan 1" does not.
     */
    private function meetingNumberFromUploadLink(string $uploadLink): ?string
    {
        if (trim($uploadLink) === '') {
            return null;
        }

        parse_str((string) parse_url(html_entity_decode($uploadLink, ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);
        $meetingNumber = $query['pertemuan'] ?? null;

        return is_scalar($meetingNumber) && ctype_digit((string) $meetingNumber) ? (string) (int) $meetingNumber : null;
    }

    /**
     * The class page of this meeting, for the meeting page's way back.
     *
     * @param  array<string, mixed>  $classParameters
     */
    private function classUrl(array $classParameters): ?string
    {
        if (! isset($classParameters['id']) || (string) $classParameters['id'] === '') {
            return null;
        }

        $query = http_build_query(array_filter([
            'dosen_id' => $classParameters['dosen_id'] ?? null,
            'course_id' => $classParameters['course_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return '/class/'.rawurlencode((string) $classParameters['id']).($query !== '' ? '?'.$query : '');
    }

    /**
     * @param  array<string, mixed>  $meetingDetailData
     */
    private function inferMeetingNumberFromMeetingDetail(array $meetingDetailData): ?string
    {
        $candidates = [
            $meetingDetailData['materials']['assignment_topic'] ?? null,
            $meetingDetailData['materials']['main_topic'] ?? null,
            $meetingDetailData['conformity_info']['description'] ?? null,
            $meetingDetailData['meeting_info']['breadcrumb'] ?? null,
            $meetingDetailData['meeting_info']['title'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if (preg_match('/Pertemuan\s+(\d+)/i', $candidate, $matches) === 1) {
                return (string) $matches[1];
            }
        }

        return null;
    }

    /**
     * The class-history row's delete link, only when it is this meeting's:
     * a delete link for another meeting must never be offered here.
     *
     * @param  array<string, mixed>  $assignmentHistoryItem
     * @return array{id: string, idx: string, pertemuan: string}|null
     */
    private function extractDeletePayloadFromAssignmentHistory(array $assignmentHistoryItem, string $meetingNumber): ?array
    {
        $deleteHref = $assignmentHistoryItem['delete_info']['href'] ?? null;

        if (! is_string($deleteHref) || trim($deleteHref) === '') {
            return null;
        }

        parse_str((string) parse_url($deleteHref, PHP_URL_QUERY), $query);

        $submissionId = $query['id'] ?? null;
        $discussionId = $query['idx'] ?? null;
        $linkMeetingNumber = $query['pertemuan'] ?? null;

        if (! is_scalar($submissionId) || ! is_scalar($discussionId) || ! is_scalar($linkMeetingNumber)) {
            return null;
        }

        if ((string) (int) $linkMeetingNumber !== $meetingNumber) {
            return null;
        }

        return [
            'id' => (string) $submissionId,
            'idx' => (string) $discussionId,
            'pertemuan' => (string) $linkMeetingNumber,
        ];
    }

    /**
     * @param  array<string, mixed>  $meetingDetailData
     * @param  array<string, mixed>  $assignmentInfo
     * @return array{
     *     status: string,
     *     delete_payload: array{id: string, idx: string, pertemuan: string}|null,
     *     file_info: array{href: string, text: string}|null
     * }|null
     */
    private function resolveAssignmentPageState(
        string $sessionToken,
        string $coursesId,
        array $meetingDetailData,
        array $assignmentInfo
    ): ?array {
        $meetingNumber = $assignmentInfo['meeting_number'] ?? null;

        if (! is_string($meetingNumber) || trim($meetingNumber) === '') {
            return null;
        }

        $discussionId = $this->extractDiscussionIdFromMeetingDetail($meetingDetailData);

        if ($discussionId === null) {
            return null;
        }

        try {
            return $this->submissionService->inspectAssignmentSubmissionState(
                $sessionToken,
                (int) $coursesId,
                (int) $discussionId,
                (int) $meetingNumber
            );
        } catch (\Throwable $throwable) {
            Log::warning('Failed to inspect assignment submission state from assignment page', [
                'courses_id' => $coursesId,
                'discussion_id' => $discussionId,
                'meeting_number' => $meetingNumber,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $meetingDetailData
     */
    private function extractDiscussionIdFromMeetingDetail(array $meetingDetailData): ?string
    {
        $requestUrl = $meetingDetailData['request_url'] ?? null;

        if (! is_string($requestUrl) || trim($requestUrl) === '') {
            return null;
        }

        parse_str((string) parse_url($requestUrl, PHP_URL_QUERY), $query);

        return is_scalar($query['id'] ?? null) ? (string) $query['id'] : null;
    }
}
