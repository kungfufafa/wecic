<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Services\CampusScrapingService;
use App\Services\CampusSessionManager;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

class DashboardExtractor extends CampusScrapingService
{
    public function __construct(CampusSessionManager $sessionManager)
    {
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

            // Get profile data
            $profileExtractor = new ProfileExtractor($this->sessionManager);
            $profileData = $profileExtractor->extractProfileData($sessionToken);

            // Get classes data
            $classesExtractor = new ClassesExtractor($this->sessionManager);
            $classesData = $classesExtractor->extractClassesData($sessionToken);

            // Get dashboard overview page
            $dashboardUrl = 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=dashboardmahasiswa';
            $html = $this->get($dashboardUrl);
            $crawler = $this->createCrawler($html);

            // Extract dashboard overview information
            $dashboardOverview = $this->extractDashboardOverview($crawler);

            // Extract recent activity
            $recentActivity = $this->extractRecentActivity($crawler);

            return [
                'user_info' => $profileData['user_info'] ?? [],
                'dashboard_overview' => $dashboardOverview,
                'recent_activity' => $recentActivity,
                'classes_summary' => $this->createClassesSummary($classesData),
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
    private function createClassesSummary(array $classesData): array
    {
        $classes = $classesData['classes'] ?? [];

        return [
            'total_classes' => count($classes),
            'active_semester' => $this->extractActiveSemester($classes),
            'recent_classes' => array_slice($classes, 0, 3), // Show last 3 classes
        ];
    }

    /**
     * Extract active semester from classes data
     */
    private function extractActiveSemester(array $classes): string
    {
        if (empty($classes)) {
            return 'N/A';
        }

        // Try to extract semester info from first class
        $firstClass = $classes[0];
        if (isset($firstClass['semester'])) {
            return $firstClass['semester'];
        }

        // Default to current semester format
        return 'Ganjil 2025/2026';
    }
}
