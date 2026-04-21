<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Symfony\Component\DomCrawler\Crawler;

class SipandaiClassesExtractor extends PortalScrapingService
{
    /**
     * Extract classes data from SIPANDAI dashboard (portal/sipandai/?mod=sipandai&act=dashboardmahasiswa)
     */
    public function extractSipandaiClassesData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting SIPANDAI dashboard classes data', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'sipandai_classes_'.md5($sessionToken);
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached SIPANDAI classes data');

                return $this->withSyncMeta(json_decode($cachedData, true), 'server_cache');
            }

            // Get classes HTML from SIPANDAI dashboard
            $sipandaiUrl = PortalEndpointRegistry::url('sipandai_dashboard');
            $html = $this->get($sipandaiUrl);
            $crawler = $this->createCrawler($html);

            // Extract classes data
            $classesData = [
                'student_info' => $this->extractSipandaiStudentInfo($crawler),
                'classes' => $this->extractSipandaiClassesList($crawler),
                'semester_info' => $this->extractSipandaiSemesterInfo($crawler),
                'extracted_at' => now()->toISOString(),
            ];

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($classesData));

            $this->logScraping('SIPANDAI classes data extracted successfully', [
                'classes_count' => count($classesData['classes']),
            ]);

            return $this->withSyncMeta($classesData, 'upstream');

        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting SIPANDAI classes data', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('sipandai classes', $e->getMessage());
        }
    }

    /**
     * Extract student information from SIPANDAI dashboard
     */
    private function extractSipandaiStudentInfo(Crawler $crawler): array
    {
        $studentInfo = [];

        try {
            // Look for student information in the first card or table
            $crawler->filter('.card')->first()->filter('table tr')->each(function (Crawler $row) use (&$studentInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim($cells->eq(0)->text());
                    $value = trim($cells->eq(2)->text());

                    // Clean up the value
                    $value = strip_tags($value);
                    $value = preg_replace('/\s+/', ' ', $value);
                    $value = trim($value);

                    switch (strtolower($label)) {
                        case 'program studi':
                            $studentInfo['program_studi'] = $value;
                            break;
                        case 'n i m':
                        case 'nim':
                            $studentInfo['nim'] = $value;
                            break;
                        case 'nama':
                            $studentInfo['nama'] = $value;
                            break;
                    }
                }
            });
        } catch (\Exception $e) {
            Log::warning('Failed to extract student info from SIPANDAI dashboard', ['error' => $e->getMessage()]);
        }

        return $studentInfo;
    }

    /**
     * Extract classes list from SIPANDAI dashboard
     */
    private function extractSipandaiClassesList(Crawler $crawler): array
    {
        $classes = [];

        try {
            // Look for the card with "Daftar Kelas Yang Diikuti" title
            $crawler->filter('.card')->each(function (Crawler $card) use (&$classes) {
                $titleElement = $card->filter('.header .title, .header h4');
                if ($titleElement->count() > 0) {
                    $title = strtolower($titleElement->text());
                    if (strpos($title, 'daftar kelas yang diikuti') !== false) {
                        $classesTable = $card->filter('table')->first();

                        // Extract all rows from the classes table, skipping header
                        $classRows = $classesTable->filter('tr')->slice(1);

                        $classRows->each(function (Crawler $row) use (&$classes) {
                            $cells = $row->filter('td');
                            if ($cells->count() >= 5) {
                                $class = [
                                    'no' => trim($cells->eq(0)->text()),
                                    'schedule' => $this->extractSipandaiScheduleInfo($cells->eq(1)),
                                    'course_info' => $this->extractSipandaiCourseInfo($cells->eq(2)),
                                    'lecturer_info' => $this->extractSipandaiLecturerInfo($cells->eq(3)),
                                    'join_link' => $this->extractSipandaiJoinLink($cells->eq(4)),
                                ];

                                if (! empty($class['course_info']['name'])) {
                                    $classes[] = $class;
                                }
                            }
                        });
                    }
                }
            });
        } catch (\Exception $e) {
            Log::warning('Failed to extract classes list from SIPANDAI dashboard', ['error' => $e->getMessage()]);
        }

        return array_slice($classes, 0, 20); // Limit to 20 classes
    }

    /**
     * Extract schedule information from SIPANDAI schedule cell
     */
    private function extractSipandaiScheduleInfo(Crawler $cell): array
    {
        $html = $cell->html();
        $text = trim($cell->text());

        // Clean up the text first
        $text = str_replace('&nbsp;', ' ', $text); // Replace HTML entities with spaces
        $text = str_replace("\u{00A0}", ' ', $text); // Replace Unicode non-breaking spaces
        $text = str_replace("\xC2\xA0", ' ', $text); // Replace UTF-8 non-breaking spaces
        $text = preg_replace('/\s+/', ' ', $text); // Replace multiple spaces with single space
        $text = trim($text);

        // Extract day and time from various formats:
        // 1. "Senin 09:30:00-11:00:00"
        // 2. "Senin  09:30:00-11:00:00" (with multiple spaces)
        // 3. "Senin 09:30:00-11:00:00 " (with trailing space)
        // 4. "Senin &nbsp;09:30:00-11:00:00" (with HTML entities)
        if (preg_match('/(\w+)\s+(\d{2}:\d{2}:\d{2}-\d{2}:\d{2}:\d{2})/', $text, $matches)) {
            $day = ucfirst(strtolower($matches[1]));
            $timeRange = $matches[2];

            // Parse start and end time
            if (preg_match('/(\d{2}:\d{2}:\d{2})-(\d{2}:\d{2}:\d{2})/', $timeRange, $timeMatches)) {
                return [
                    'day' => $day,
                    'start_time' => $timeMatches[1],
                    'end_time' => $timeMatches[2],
                    'time_range' => $timeRange,
                    'formatted_time' => $this->formatTimeRange($timeMatches[1], $timeMatches[2]),
                    'raw' => $text,
                ];
            }
        }

        // Fallback for simple day extraction
        if (preg_match('/(\w+)/', $text, $matches)) {
            return [
                'day' => ucfirst(strtolower($matches[1])),
                'raw' => $text,
            ];
        }

        return ['raw' => $text];
    }

    /**
     * Format time range to more readable format
     */
    private function formatTimeRange(string $startTime, string $endTime): string
    {
        // Convert "09:30:00" to "09:30" and "11:00:00" to "11:00"
        $start = substr($startTime, 0, 5);
        $end = substr($endTime, 0, 5);

        return "{$start} - {$end}";
    }

    /**
     * Extract course information from SIPANDAI course cell
     */
    private function extractSipandaiCourseInfo(Crawler $cell): array
    {
        $html = $cell->html();
        $text = trim($cell->text());

        // Split by <br> to get course code and name
        $parts = preg_split('/<br\s*\/?>/i', $html);

        $courseInfo = [];

        if (count($parts) >= 2) {
            $courseInfo['code'] = trim(strip_tags($parts[0]));
            $courseInfo['name'] = trim(strip_tags($parts[1]));
        } else {
            // Try to extract from text format
            if (preg_match('/^([A-Z]{2,4}\d{3,})\s+(.+)$/', $text, $matches)) {
                $courseInfo['code'] = $matches[1];
                $courseInfo['name'] = $matches[2];
            } else {
                $courseInfo['name'] = $text;
            }
        }

        return $courseInfo;
    }

    /**
     * Extract lecturer information from SIPANDAI lecturer cell
     */
    private function extractSipandaiLecturerInfo(Crawler $cell): array
    {
        $html = $cell->html();
        $text = trim($cell->text());

        $lecturerInfo = [];

        // Extract lecturer name (usually in <b> tag)
        $boldText = $cell->filter('b')->first();
        if ($boldText->count() > 0) {
            $nameText = trim($boldText->text());
            // Clean name from contact info and email details
            $nameText = preg_replace('/\s*Kontak Dosen\s*:.*$/', '', $nameText);
            $nameText = preg_replace('/\s*Email Dosen\s*:.*$/', '', $nameText);
            $lecturerInfo['name'] = trim($nameText);
        } else {
            $lines = explode("\n", $text);
            $nameText = trim($lines[0] ?? '');
            // Clean name from contact info and email details
            $nameText = preg_replace('/\s*Kontak Dosen\s*:.*$/', '', $nameText);
            $nameText = preg_replace('/\s*Email Dosen\s*:.*$/', '', $nameText);
            $lecturerInfo['name'] = trim($nameText);
        }

        // Extract contact information
        if (preg_match('/Kontak Dosen\s*:\s*([\d\s-]+)/', $html, $matches)) {
            $contact = trim($matches[1]);
            $lecturerInfo['contact'] = $contact !== '-' ? $contact : null;
        }

        // Extract email
        if (preg_match('/Email Dosen\s*:\s*([^\s<]+)/', $html, $matches)) {
            $email = trim($matches[1]);
            $lecturerInfo['email'] = $email !== '-' ? $email : null;
        }

        return $lecturerInfo;
    }

    /**
     * Extract join link from SIPANDAI join cell
     */
    private function extractSipandaiJoinLink(Crawler $cell): array
    {
        $joinInfo = [];

        // Look for the join link
        $linkElement = $cell->filter('a')->first();
        if ($linkElement->count() > 0) {
            $href = $linkElement->attr('href') ?? '';

            // Extract parameters from the link
            $params = [];
            if (preg_match('/id=(\d+)/', $href, $matches)) {
                $params['id'] = $matches[1];
            }
            if (preg_match('/dosen_id=(\d+)/', $href, $matches)) {
                $params['dosen_id'] = $matches[1];
            }
            if (preg_match('/course_id=(\d+)/', $href, $matches)) {
                $params['course_id'] = $matches[1];
            }

            $joinInfo = [
                'link' => $href,
                'params' => $params,
                'text' => trim($linkElement->text()),
            ];
        }

        return $joinInfo;
    }

    /**
     * Extract semester information from SIPANDAI dashboard
     */
    private function extractSipandaiSemesterInfo(Crawler $crawler): array
    {
        $semesterInfo = [];

        try {
            // Look for semester info in the card header
            $crawler->filter('.card .header .title, .card .header h4')->each(function (Crawler $title) use (&$semesterInfo) {
                $text = $title->text();
                if (preg_match('/Semester\s*:\s*(\d+)/i', $text, $matches)) {
                    $semesterInfo['semester'] = trim($matches[1]);
                }
            });
        } catch (\Exception $e) {
            Log::warning('Failed to extract semester info from SIPANDAI dashboard', ['error' => $e->getMessage()]);
        }

        return $semesterInfo;
    }
}
