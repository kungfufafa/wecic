<?php

declare(strict_types=1);

namespace App\Services\Extractors;

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
            $sipandaiClassesData = $this->sipandaiClassesExtractor->extractSipandaiClassesData($sessionToken);
            $studentCourseData = $this->studentCourseExtractor->extractStudentCourseData($sessionToken);

            $dashboardUrl = PortalEndpointRegistry::url('sipandai_dashboard');
            $html = $this->get($dashboardUrl);
            $crawler = $this->createCrawler($html);

            $dashboardOverview = $this->extractDashboardOverview($crawler);
            $dashboardOverview['total_courses'] = (string) count($sipandaiClassesData['classes'] ?? []);
            $dashboardOverview['total_credits'] = (string) $this->resolveTotalCredits($sessionToken, $sipandaiClassesData['classes'] ?? [], $dashboardOverview);
            $recentActivity = $this->extractRecentActivity($crawler);

            return [
                'user_info' => $profileData['user_info'] ?? [],
                'dashboard_overview' => $dashboardOverview,
                'recent_activity' => $recentActivity,
                'classes_summary' => $this->createClassesSummary($sipandaiClassesData, $studentCourseData, $reference),
                'status' => 'success',
                'timestamp' => now()->toISOString(),
            ];

        } catch (Exception $e) {
            throw new Exception('Failed to extract dashboard data: '.$e->getMessage());
        }
    }

    /**
     * Extract dashboard overview information
     */
    private function extractDashboardOverview(Crawler $crawler): array
    {
        $overview = [
            'total_courses' => '0',
            'gpa' => '0.00',
            'total_credits' => '0',
            'outstanding_payments' => 'Rp 0',
        ];

        // Look for dashboard statistics
        $crawler->filter('div.statistic-box, div.info-box, .card-statistic')->each(function (Crawler $node) use (&$overview) {
            $title = $node->filter('h3, .title, .stat-title')->text('');
            $value = $node->filter('h2, .value, .stat-value')->text('');

            // Map based on title content
            if (stripos($title, 'mata kuliah') !== false || stripos($title, 'course') !== false) {
                $overview['total_courses'] = $value;
            } elseif (stripos($title, 'ip') !== false || stripos($title, 'gpa') !== false) {
                $overview['gpa'] = $value;
            } elseif (stripos($title, 'sks') !== false || stripos($title, 'credit') !== false) {
                $overview['total_credits'] = $value;
            } elseif (stripos($title, 'tunggakan') !== false || stripos($title, 'payment') !== false) {
                $overview['outstanding_payments'] = $value;
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
                        } elseif (stripos($label, 'ipk') !== false || stripos($label, 'ip') !== false) {
                            $overview['gpa'] = $value;
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
     */
    private function createClassesSummary(array $sipandaiClassesData, array $studentCourseData, CarbonImmutable $reference): array
    {
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
    private function resolveTotalCredits(string $sessionToken, array $classes, array $dashboardOverview): int
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

        return $this->resolveTotalCreditsFromCreditPage($sessionToken) ?? 0;
    }

    private function resolveTotalCreditsFromCreditPage(string $sessionToken): ?int
    {
        $cacheKey = 'dashboard_credit_total_v1_'.md5($sessionToken);

        return cache()->remember($cacheKey, now()->addMinutes(30), function (): ?int {
            try {
                $html = $this->get((string) config('portal.student_credit_url'));

                return $this->extractCreditPageTotal($html);
            } catch (\Throwable $throwable) {
                Log::warning('Failed to resolve total credits from credit page', [
                    'error' => $throwable->getMessage(),
                ]);

                return null;
            }
        });
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
