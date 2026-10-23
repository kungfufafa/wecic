<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\DashboardTodayClassesBuilder;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class DashboardExtractor extends PortalScrapingService
{
    public function __construct(
        PortalSessionManager $sessionManager,
        private readonly ProfileExtractor $profileExtractor,
        private readonly ClassesExtractor $classesExtractor,
        private readonly SipandaiClassesExtractor $sipandaiClassesExtractor,
        private readonly StudentCourseExtractor $studentCourseExtractor,
        private readonly DashboardTodayClassesBuilder $dashboardTodayClassesBuilder
    ) {
        parent::__construct($sessionManager);
    }

    /**
     * Extract dashboard data from CIC portal
     *
     * Today's schedule only needs the list pages: profile, the SIPANDAI
     * class list (also the overview page), the student schedule and, when
     * neither states the SKS, the credit page. The attendance of each class
     * needs its class and meeting page, so it is its own snapshot
     * (PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS) joined at read
     * time; `today_classes` here carry no `attendance`.
     *
     * @param  string  $sessionToken  Active session token
     * @return array Dashboard data
     *
     * @throws Exception
     */
    public function extractDashboardData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting dashboard data', ['session_token' => $sessionToken]);
            $reference = CarbonImmutable::now(config('app.timezone'));

            $profileData = $this->profileExtractor->extractProfileData($sessionToken);
            $profileSync = $this->takeSyncMeta($profileData);
            $sipandaiClassesData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $sipandaiClassesSync = $this->takeSyncMeta($sipandaiClassesData);
            $studentCourseData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);
            $studentCourseSync = $this->takeSyncMeta($studentCourseData);

            $dashboardUrl = PortalEndpointRegistry::url('sipandai_dashboard');
            $html = $this->get($dashboardUrl);
            $crawler = $this->createCrawler($html);

            $dashboardOverview = $this->extractDashboardOverview($crawler);
            $dashboardOverview['total_courses'] = (string) count($sipandaiClassesData['classes'] ?? []);
            $creditSync = [];
            $dashboardOverview['total_credits'] = (string) $this->resolveTotalCredits($sessionToken, $sipandaiClassesData['classes'] ?? [], $dashboardOverview, $creditSync);
            $recentActivity = $this->extractRecentActivity($crawler);

            $personalData = $profileData['personal_data'] ?? [];
            $studentInfo = $sipandaiClassesData['student_info'] ?? [];
            $resolvedName = $personalData['nama'] ?? ($studentInfo['nama'] ?? null);
            $resolvedNim = $personalData['nim'] ?? ($studentInfo['nim'] ?? null);
            $resolvedProdi = $personalData['program_studi'] ?? ($studentInfo['program_studi'] ?? null);

            $resolvedUserInfo = array_filter([
                'name' => $resolvedName,
                'nama' => $resolvedName,
                'student_id' => $resolvedNim,
                'nim' => $resolvedNim,
                'program_studi' => $resolvedProdi,
            ]);

            return $this->withSyncMeta([
                'user_info' => $resolvedUserInfo ?: ($profileData['user_info'] ?? []),
                'dashboard_overview' => $dashboardOverview,
                'recent_activity' => $recentActivity,
                'classes_summary' => $this->createClassesSummary($sipandaiClassesData, $studentCourseData, $reference),
                'status' => 'success',
                'timestamp' => now()->toISOString(),
            ], $this->resolveDashboardDataOrigin([
                $profileSync,
                $sipandaiClassesSync,
                $studentCourseSync,
                $creditSync,
            ]));

        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new Exception('Failed to extract dashboard data: '.$e->getMessage());
        }
    }

    /**
     * Extract dashboard overview information
     *
     * The SIPANDAI dashboard shows neither an IPK nor a bill amount, so
     * `gpa` and `outstanding_payments` stay null instead of a made-up "0.00"
     * or "Rp 0" (Fase 0.7). The real values come from the `grades` and
     * `payments` snapshots via `data.metrics` of GET /api/dashboard.
     *
     * @return array{total_courses: string, gpa: null, total_credits: string, outstanding_payments: null}
     */
    private function extractDashboardOverview(Crawler $crawler): array
    {
        $overview = [
            'total_courses' => '0',
            'gpa' => null,
            'total_credits' => '0',
            'outstanding_payments' => null,
        ];

        // Look for dashboard statistics
        $crawler->filter('div.statistic-box, div.info-box, .card-statistic')->each(function (Crawler $node) use (&$overview) {
            $title = $node->filter('h3, .title, .stat-title')->text('');
            $value = $node->filter('h2, .value, .stat-value')->text('');

            if (stripos($title, 'mata kuliah') !== false || stripos($title, 'course') !== false) {
                $overview['total_courses'] = $value;
            } elseif (stripos($title, 'sks') !== false || stripos($title, 'credit') !== false) {
                $overview['total_credits'] = $value;
            }
        });

        // Alternative extraction using table structure
        if ($overview['total_courses'] === '0') {
            $crawler->filter('table')->each(function (Crawler $table) use (&$overview) {
                $table->filter('tr')->each(function (Crawler $row) use (&$overview) {
                    $cells = $row->filter('td');
                    if ($cells->count() >= 2) {
                        $label = trim($cells->eq(0)->text(''));
                        $value = trim($cells->eq(1)->text(''));

                        if (stripos($label, 'mata kuliah') !== false) {
                            $overview['total_courses'] = $value;
                        } elseif (stripos($label, 'sks') !== false) {
                            $overview['total_credits'] = $value;
                        }
                    }
                });
            });
        }

        return $overview;
    }

    /**
     * Extract recent activity information
     */
    private function extractRecentActivity(Crawler $crawler): array
    {
        $activities = [];

        // Look for recent activity or announcements
        $crawler->filter('.activity-item, .announcement-item, .recent-item')->each(function (Crawler $node) use (&$activities) {
            $title = $node->filter('.title, h4, .activity-title')->text('');
            $date = $node->filter('.date, .time, .activity-date')->text('');
            $description = $node->filter('.description, p, .activity-desc')->text('');

            if ($title) {
                $activities[] = [
                    'title' => $title,
                    'date' => $date,
                    'description' => $description,
                ];
            }
        });

        return $activities;
    }

    /**
     * Create classes summary from classes data
     *
     * `actionable_attendance_count` is filled in when the attendance is
     * joined (DashboardTodayClassesBuilder::attachAttendance()).
     */
    private function createClassesSummary(
        array $sipandaiClassesData,
        array $studentCourseData,
        CarbonImmutable $reference
    ): array {
        $classes = $sipandaiClassesData['classes'] ?? [];
        $todaySchedule = $this->dashboardTodayClassesBuilder->build(
            $classes,
            $studentCourseData['classes'] ?? [],
            $reference
        );

        return [
            'total_classes' => count($classes),
            'active_semester' => $this->extractActiveSemester($sipandaiClassesData, $studentCourseData),
            'day' => $todaySchedule['day'],
            'date' => $todaySchedule['date'],
            'today_classes_count' => count($todaySchedule['classes']),
            'actionable_attendance_count' => 0,
            'today_classes' => $todaySchedule['classes'],
        ];
    }

    /**
     * Extract active semester from classes data
     */
    private function extractActiveSemester(array $sipandaiClassesData, array $studentCourseData): string
    {
        $semester = $sipandaiClassesData['semester_info']['semester']
            ?? $studentCourseData['semester_info']['semester']
            ?? null;

        if (is_string($semester) && trim($semester) !== '') {
            return trim($semester);
        }

        return 'N/A';
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     * @param  array<string, mixed>  $dashboardOverview
     */
    private function resolveTotalCredits(string $sessionToken, array $classes, array $dashboardOverview, ?array &$syncMeta = null): int
    {
        $totalCredits = 0;

        foreach ($classes as $class) {
            $credits = (int) preg_replace('/\D+/', '', (string) (
                $class['credits']
                ?? $class['course_info']['credits']
                ?? '0'
            ));

            $totalCredits += $credits;
        }

        if ($totalCredits > 0) {
            return $totalCredits;
        }

        $overviewCredits = (int) preg_replace('/\D+/', '', (string) ($dashboardOverview['total_credits'] ?? '0'));

        if ($overviewCredits > 0) {
            return $overviewCredits;
        }

        return $this->resolveTotalCreditsFromCreditPage($sessionToken, $syncMeta) ?? 0;
    }

    private function resolveTotalCreditsFromCreditPage(string $sessionToken, ?array &$syncMeta = null): ?int
    {
        try {
            $creditTotal = $this->rememberResource('credit_total', [], fn (): array => [
                'total' => $this->extractCreditPageTotal($this->get(PortalEndpointRegistry::url('student_credit'))),
            ]);
            $syncMeta = [
                'data_origin' => $creditTotal['_sync']['data_origin'] ?? 'upstream',
            ];

            return $creditTotal['total'];
        } catch (\Throwable $throwable) {
            Log::warning('Failed to resolve total credits from credit page', [
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function takeSyncMeta(array &$payload): array
    {
        $sync = is_array($payload['_sync'] ?? null) ? $payload['_sync'] : [];

        unset($payload['_sync']);

        return $sync;
    }

    private function resolveDashboardDataOrigin(array $syncEntries): string
    {
        foreach ($syncEntries as $syncEntry) {
            if (($syncEntry['data_origin'] ?? null) === 'server_cache') {
                return 'server_cache';
            }
        }

        return 'upstream';
    }

    private function extractCreditPageTotal(string $html): ?int
    {
        $crawler = $this->createCrawler($html);
        $candidates = [];

        $crawler->filter('tr, .card, .info-box, .statistic-box, .summary-item, .list-group-item')->each(function (Crawler $node) use (&$candidates): void {
            $text = preg_replace('/\s+/', ' ', strtolower($node->text('')));

            if (! is_string($text) || $text === '' || ! str_contains($text, 'sks')) {
                return;
            }

            preg_match_all('/(\d{1,3})\s*sks/u', $text, $matches);

            foreach ($matches[1] ?? [] as $match) {
                $value = (int) $match;
                $score = 1;

                if (str_contains($text, 'total') || str_contains($text, 'jumlah')) {
                    $score += 3;
                }

                if (str_contains($text, 'ambil') || str_contains($text, 'krs') || str_contains($text, 'reg')) {
                    $score += 2;
                }

                if ($value >= 12 && $value <= 30) {
                    $score += 2;
                } elseif ($value > 60) {
                    $score -= 3;
                }

                $candidates[] = [
                    'value' => $value,
                    'score' => $score,
                ];
            }
        });

        if ($candidates === []) {
            preg_match_all('/(\d{1,3})\s*sks/u', strtolower($crawler->text('')), $matches);

            foreach ($matches[1] ?? [] as $match) {
                $value = (int) $match;
                $candidates[] = [
                    'value' => $value,
                    'score' => $value >= 12 && $value <= 30 ? 2 : 1,
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return $right['value'] <=> $left['value'];
            }

            return $right['score'] <=> $left['score'];
        });

        return $candidates[0]['value'] ?? null;
    }
}
