<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Extractors\AdvisingExtractor;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\FinancialHistoryExtractor;
use App\Services\Extractors\KrsDetailExtractor;
use App\Services\Extractors\KrsExtractor;
use App\Services\Extractors\LibraryExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\SkkmExtractor;
use App\Services\Extractors\SkripsiExtractor;
use App\Services\Extractors\StudentCourseExtractor;
use App\Services\Extractors\ThesisRepositoryExtractor;
use InvalidArgumentException;

/**
 * Single definition of how every dataset is built from upstream pages.
 *
 * API endpoints and background jobs both go through here, so a snapshot
 * written by a job is exactly what the endpoint would have returned.
 * Payloads may carry `_sync` metadata, which the repository strips.
 */
class PortalDatasetBuilder
{
    public function __construct(
        private readonly DashboardExtractor $dashboardExtractor,
        private readonly AttendanceShortcutService $attendanceShortcutService,
        private readonly ProfileExtractor $profileExtractor,
        private readonly ClassesExtractor $classesExtractor,
        private readonly SipandaiClassesExtractor $sipandaiClassesExtractor,
        private readonly StudentCourseExtractor $studentCourseExtractor,
        private readonly FinancialHistoryExtractor $financialHistoryExtractor,
        private readonly PortalExamListResolver $examListResolver,
        private readonly PortalMeetingDetailResolver $meetingDetailResolver,
        private readonly PortalContextResolver $portalContextResolver,
        private readonly PortalAnnouncementFeed $announcementFeed,
        private readonly AnnouncementExtractor $announcementExtractor,
        private readonly KrsExtractor $krsExtractor,
        private readonly AdvisingExtractor $advisingExtractor,
        private readonly KrsDetailExtractor $krsDetailExtractor,
        private readonly PortalGradesResolver $gradesResolver,
        private readonly SkkmExtractor $skkmExtractor,
        private readonly SkripsiExtractor $skripsiExtractor,
        private readonly LibraryExtractor $libraryExtractor,
        private readonly ThesisRepositoryExtractor $thesisRepositoryExtractor,
        private readonly PortalSnapshotStore $snapshots
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function build(string $sessionToken, string $dataset, array $params = []): array
    {
        return match ($dataset) {
            'dashboard' => $this->dashboardExtractor->extractDashboardData($sessionToken),
            'assignments' => $this->attendanceShortcutService->getPendingAssignmentsSnapshot($sessionToken),
            'profile' => $this->profileExtractor->extractProfileData($sessionToken),
            'lms_classes' => $this->classesExtractor->extractClassesData($sessionToken),
            'schedule' => $this->studentCourseExtractor->extractStudentCourseData($sessionToken),
            'classes' => $this->buildMergedClasses($sessionToken),
            'payments' => $this->financialHistoryExtractor->extractPaymentHistoryData($sessionToken),
            'exams' => $this->examListResolver->resolve($sessionToken),
            'class_detail' => $this->classesExtractor->extractClassDetail($sessionToken, $params),
            'meeting_detail' => $this->meetingDetailResolver->resolve(
                $sessionToken,
                (string) ($params['meeting_id'] ?? ''),
                (string) ($params['courses_id'] ?? '')
            ),
            'portal_context' => $this->portalContextResolver->resolve($sessionToken),
            'announcements' => $this->announcementFeed->fetch($sessionToken),
            'announcement_page' => $this->announcementExtractor->extractAnnouncementsData($sessionToken, max(1, (int) ($params['page'] ?? 1))),
            'krs' => $this->krsExtractor->extractKrsData($sessionToken),
            'advising' => $this->advisingExtractor->extractAdvisingData($sessionToken),
            'krs_detail' => $this->krsDetailExtractor->extractKrsDetailData($sessionToken, $this->krsDetailId($params)),
            'grades' => $this->gradesResolver->resolve($sessionToken),
            'skkm' => $this->skkmExtractor->extractSkkmData($sessionToken),
            'thesis' => $this->skripsiExtractor->extractSkripsiData($sessionToken),
            'library' => $this->libraryExtractor->extractLibraryLinksData($sessionToken),
            // The previous index lets an unchanged repository skip pages 2..n.
            'thesis_repository' => $this->thesisRepositoryExtractor->extractRepositoryIndex(
                $sessionToken,
                $this->snapshots->get($sessionToken, 'thesis_repository')['data'] ?? null
            ),
            default => throw new InvalidArgumentException("Unknown portal dataset [{$dataset}]."),
        };
    }

    /**
     * KRS ids are only ever taken from the student's own advising history
     * (see PortalKrsDetailRequest), so anything else is a programming error.
     *
     * @param  array<string, mixed>  $params
     */
    private function krsDetailId(array $params): int
    {
        $krsId = $params['id'] ?? null;

        if (! is_int($krsId) && ! (is_string($krsId) && ctype_digit($krsId))) {
            throw new InvalidArgumentException('Dataset [krs_detail] needs a numeric id parameter.');
        }

        return (int) $krsId;
    }

    /**
     * LMS/SIPANDAI classes carry materials and action links; the student
     * schedule adds room details.
     *
     * @return array<string, mixed>
     */
    private function buildMergedClasses(string $sessionToken): array
    {
        $sipandaiData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
        $studentCourseData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);
        $dataOrigins = [
            $sipandaiData['_sync']['data_origin'] ?? null,
            $studentCourseData['_sync']['data_origin'] ?? null,
        ];
        unset($sipandaiData['_sync'], $studentCourseData['_sync']);

        $mergedData = $this->buildMergedClassesPayload($sipandaiData, $studentCourseData);
        $mergedData['_sync'] = [
            'data_origin' => in_array('server_cache', $dataOrigins, true) ? 'server_cache' : 'upstream',
        ];

        return $mergedData;
    }

    /**
     * @param  array<string, mixed>  $sipandaiData
     * @param  array<string, mixed>  $studentCourseData
     * @return array<string, mixed>
     */
    private function buildMergedClassesPayload(array $sipandaiData, array $studentCourseData): array
    {
        $sipandaiClasses = $sipandaiData['classes'] ?? [];
        $studentCourseClasses = $studentCourseData['classes'] ?? [];

        $studentCourseMap = [];
        foreach ($studentCourseClasses as $class) {
            $key = ($class['course_info']['code'] ?? '').'|'.($class['schedule']['day'] ?? '').'|'.($class['schedule']['time'] ?? ($class['schedule']['time_range'] ?? ''));
            $studentCourseMap[$key] = $class;
        }

        $mergedClasses = [];
        foreach ($sipandaiClasses as $sipandaiClass) {
            $key = ($sipandaiClass['course_info']['code'] ?? '').'|'.($sipandaiClass['schedule']['day'] ?? '').'|'.($sipandaiClass['schedule']['time'] ?? ($sipandaiClass['schedule']['time_range'] ?? ''));

            $mergedClass = $sipandaiClass;
            if (isset($studentCourseMap[$key])) {
                $studentClass = $studentCourseMap[$key];
                $mergedClass['class_room_info'] = $studentClass['class_room_info'] ?? null;
                $mergedClass['student_course_reference'] = $studentClass;
            }

            $mergedClasses[] = $mergedClass;
        }

        return [
            'student_info' => $sipandaiData['student_info'] ?? $studentCourseData['student_info'] ?? [],
            'classes' => $mergedClasses,
            'semester_info' => $sipandaiData['semester_info'] ?? $studentCourseData['semester_info'] ?? [],
            'data_sources' => [
                'sipandai_dashboard' => true,
                'student_course' => true,
            ],
            'extracted_at' => now()->toISOString(),
        ];
    }
}
