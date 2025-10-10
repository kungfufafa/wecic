<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\CampusScrapingException;
use App\Services\CampusScrapingService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class StudentDashboardExtractor extends CampusScrapingService
{
    /**
     * Extract student dashboard data
     */
    public function extractDashboardData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting student dashboard data', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'student_dashboard_' . md5($sessionToken);
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached dashboard data');
                return json_decode($cachedData, true);
            }

            // Get dashboard HTML
            $dashboardUrl = config('campus.dashboard_url');
            $html = $this->get($dashboardUrl);
            $crawler = $this->createCrawler($html);

            // Extract dashboard components
            $dashboardData = [
                'user_info' => $this->extractUserInfo($crawler),
                'academic_summary' => $this->extractAcademicSummary($crawler),
                'recent_activities' => $this->extractRecentActivities($crawler),
                'quick_links' => $this->extractQuickLinks($crawler),
                'notifications' => $this->extractNotifications($crawler),
                'semester_info' => $this->extractSemesterInfo($crawler),
                'extracted_at' => now()->toISOString(),
            ];

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($dashboardData));

            $this->logScraping('Dashboard data extracted successfully', [
                'data_keys' => array_keys($dashboardData)
            ]);

            return $dashboardData;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting dashboard data', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'dashboard', $e->getMessage());
        }
    }

    /**
     * Extract user information from dashboard
     */
    private function extractUserInfo(Crawler $crawler): array
    {
        $userInfo = [];

        // Try multiple selectors for student name
        $nameSelectors = [
            '.student-name',
            '.user-name',
            '.profile-name',
            'h1',
            '.welcome-message .name',
            '[data-student-name]',
            '.card-header .student-name',
        ];

        $name = $this->extractText($crawler, $nameSelectors);
        if ($name) {
            $userInfo['name'] = $name;
        }

        // Try selectors for student ID/NIM
        $idSelectors = [
            '.student-id',
            '.nim',
            '.user-id',
            '.registration-number',
            '[data-student-id]',
            '.student-info .id',
        ];

        $studentId = $this->extractText($crawler, $idSelectors);
        if ($studentId) {
            $userInfo['student_id'] = $studentId;
        }

        // Try selectors for department/program
        $deptSelectors = [
            '.department',
            '.program-study',
            '.study-program',
            '.jurusan',
            '.prodi',
            '.faculty',
            '.student-info .department',
        ];

        $department = $this->extractText($crawler, $deptSelectors);
        if ($department) {
            $userInfo['department'] = $department;
        }

        // Try selectors for academic year
        $yearSelectors = [
            '.academic-year',
            '.year',
            '.angkatan',
            '.batch',
            '.student-info .year',
        ];

        $academicYear = $this->extractText($crawler, $yearSelectors);
        if ($academicYear) {
            $userInfo['academic_year'] = $academicYear;
        }

        // Try to extract photo/avatar
        $photoSelectors = [
            '.student-photo img',
            '.profile-photo img',
            '.user-avatar img',
            '.avatar img',
        ];

        foreach ($photoSelectors as $selector) {
            $photoElement = $crawler->filter($selector);
            if ($photoElement->count() > 0) {
                $photoUrl = $photoElement->attr('src');
                if ($photoUrl && !empty($photoUrl)) {
                    $userInfo['photo_url'] = $photoUrl;
                    break;
                }
            }
        }

        return $userInfo;
    }

    /**
     * Extract academic summary information
     */
    private function extractAcademicSummary(Crawler $crawler): array
    {
        $summary = [];

        // Look for academic summary cards or tables
        $summarySelectors = [
            '.academic-summary',
            '.study-summary',
            '.academic-info',
            '.summary-cards',
            '.info-cards',
        ];

        foreach ($summarySelectors as $selector) {
            $summaryElement = $crawler->filter($selector);
            if ($summaryElement->count() > 0) {
                $summary = array_merge($summary, $this->extractSummaryFromElement($summaryElement));
            }
        }

        // Try to extract GPA information
        $gpaSelectors = [
            '.gpa',
            '.ipk',
            '.grade-point-average',
        ];

        $gpa = $this->extractText($crawler, $gpaSelectors);
        if ($gpa) {
            $summary['gpa'] = $gpa;
        }

        // Try to extract credits completed
        $creditsSelectors = [
            '.credits',
            '.sks',
            '.credit-hours',
            '.total-credits',
        ];

        $credits = $this->extractText($crawler, $creditsSelectors);
        if ($credits) {
            $summary['total_credits'] = $credits;
        }

        return $summary;
    }

    /**
     * Extract summary data from a container element
     */
    private function extractSummaryFromElement(Crawler $element): array
    {
        $summary = [];

        // Look for card-like structures
        $element->filter('.card, .summary-item, .info-item')->each(function (Crawler $card) use (&$summary) {
            $title = $this->extractText($card, ['.title', '.label', '.card-title', 'h3', 'h4']);
            $value = $this->extractText($card, ['.value', '.content', '.card-body', '.info-value']);

            if ($title && $value) {
                $key = strtolower(str_replace([' ', '-'], '_', $title));
                $summary[$key] = $value;
            }
        });

        // Look for key-value pairs in tables or definition lists
        $element->filter('table tr, dl dt')->each(function (Crawler $row) use (&$summary) {
            if ($row->nodeName() === 'tr') {
                $cells = $row->filter('td, th');
                if ($cells->count() >= 2) {
                    $key = strtolower(str_replace([' ', ':'], '_', $this->cleanText($cells->eq(0)->text())));
                    $value = $this->cleanText($cells->eq(1)->text());
                    $summary[$key] = $value;
                }
            } elseif ($row->nodeName() === 'dt') {
                $key = strtolower(str_replace([' ', ':'], '_', $this->cleanText($row->text())));
                $dd = $row->nextAll()->filter('dd')->first();
                if ($dd->count() > 0) {
                    $value = $this->cleanText($dd->text());
                    $summary[$key] = $value;
                }
            }
        });

        return $summary;
    }

    /**
     * Extract recent activities from dashboard
     */
    private function extractRecentActivities(Crawler $crawler): array
    {
        $activities = [];

        $activitySelectors = [
            '.recent-activities .activity-item',
            '.activity-list .item',
            '.timeline .item',
            '.activity-feed .activity',
        ];

        foreach ($activitySelectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $item) use (&$activities) {
                $title = $this->extractText($item, ['.title', '.activity-title', 'h4', 'h5']);
                $description = $this->extractText($item, ['.description', '.content', '.activity-desc']);
                $date = $this->extractText($item, ['.date', '.time', '.activity-date']);

                if ($title) {
                    $activities[] = array_filter([
                        'title' => $title,
                        'description' => $description,
                        'date' => $date,
                    ]);
                }
            });
        }

        return array_slice($activities, 0, 10); // Limit to 10 recent activities
    }

    /**
     * Extract quick links from dashboard
     */
    private function extractQuickLinks(Crawler $crawler): array
    {
        $links = [];

        $linkSelectors = [
            '.quick-links a',
            '.shortcuts a',
            '.menu-quick a',
            '.dashboard-menu a',
        ];

        foreach ($linkSelectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $link) use (&$links) {
                $text = trim($link->text());
                $href = $link->attr('href');

                if ($text && $href) {
                    // Make URL absolute if needed
                    if (!str_starts_with($href, 'http')) {
                        $baseUrl = config('campus.base_url');
                        $href = rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
                    }

                    $links[] = [
                        'text' => $text,
                        'url' => $href,
                        'icon' => $link->attr('data-icon') ?: $this->extractIconClass($link),
                    ];
                }
            });
        }

        return $links;
    }

    /**
     * Extract notifications from dashboard
     */
    private function extractNotifications(Crawler $crawler): array
    {
        $notifications = [];

        $notificationSelectors = [
            '.notifications .notification-item',
            '.alerts .alert',
            '.messages .message',
            '.notification-list .item',
        ];

        foreach ($notificationSelectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $item) use (&$notifications) {
                $title = $this->extractText($item, ['.title', '.notification-title', 'h4', 'h5']);
                $message = $this->extractText($item, ['.message', '.content', '.notification-message']);
                $date = $this->extractText($item, ['.date', '.time', '.notification-date']);
                $type = $item->attr('class') ?? '';

                if ($title || $message) {
                    $notifications[] = array_filter([
                        'title' => $title,
                        'message' => $message,
                        'date' => $date,
                        'type' => $this->determineNotificationType($type),
                    ]);
                }
            });
        }

        return array_slice($notifications, 0, 5); // Limit to 5 notifications
    }

    /**
     * Extract semester information
     */
    private function extractSemesterInfo(Crawler $crawler): array
    {
        $semesterInfo = [];

        $semesterSelectors = [
            '.semester-info',
            '.current-semester',
            '.academic-semester',
        ];

        foreach ($semesterSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $semesterInfo = array_merge($semesterInfo, $this->extractSummaryFromElement($element));
            }
        }

        // Try to find semester text directly
        $semesterText = $this->extractText($crawler, [
            '.semester',
            '.term',
            '[data-semester]',
        ]);

        if ($semesterText) {
            $semesterInfo['semester'] = $semesterText;
        }

        return $semesterInfo;
    }

    /**
     * Extract icon class from element
     */
    private function extractIconClass(Crawler $element): ?string
    {
        $classes = $element->attr('class') ?? '';

        // Look for common icon classes
        $iconPatterns = [
            '/fa-\w+/',
            '/icon-\w+/',
            '/glyphicon-\w+/',
            '/material-icons/',
        ];

        foreach ($iconPatterns as $pattern) {
            if (preg_match($pattern, $classes, $matches)) {
                return $matches[0];
            }
        }

        return null;
    }

    /**
     * Determine notification type from CSS classes
     */
    private function determineNotificationType(string $classes): string
    {
        $classes = strtolower($classes);

        if (str_contains($classes, 'success') || str_contains($classes, 'info')) {
            return 'info';
        }

        if (str_contains($classes, 'warning') || str_contains($classes, 'alert')) {
            return 'warning';
        }

        if (str_contains($classes, 'error') || str_contains($classes, 'danger')) {
            return 'error';
        }

        return 'general';
    }

    /**
     * Get dashboard overview without detailed extraction (faster)
     */
    public function getDashboardOverview(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);

            $dashboardUrl = config('campus.dashboard_url');
            $html = $this->get($dashboardUrl);
            $crawler = $this->createCrawler($html);

            return [
                'user_name' => $this->extractText($crawler, ['.student-name', '.user-name', 'h1']),
                'has_notifications' => $crawler->filter('.notifications, .alerts')->count() > 0,
                'notification_count' => $crawler->filter('.notification-item, .alert')->count(),
                'has_activities' => $crawler->filter('.recent-activities, .activity-list')->count() > 0,
                'page_title' => $this->extractText($crawler, ['title', 'h1', '.page-title']),
                'extracted_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            $this->logScrapingError('Getting dashboard overview', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'dashboard overview', $e->getMessage());
        }
    }
}