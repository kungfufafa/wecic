<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Symfony\Component\DomCrawler\Crawler;

class StudentCourseExtractor extends PortalScrapingService
{
    /**
     * Extract classes data from student course endpoint (portal/mahasiswa/?mod=student&act=course)
     */
    public function extractStudentCourseData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting student course data with room info', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'student_course_'.md5($sessionToken);
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached student course data');

                return json_decode($cachedData, true);
            }

            // Get classes HTML from student course endpoint
            $studentCourseUrl = PortalEndpointRegistry::url('student_course');
            $html = $this->get($studentCourseUrl);
            $crawler = $this->createCrawler($html);

            // Extract classes data
            $classesData = [
                'student_info' => $this->extractStudentCourseInfo($crawler),
                'classes' => $this->extractStudentCourseClassesList($crawler),
                'semester_info' => $this->extractStudentCourseSemesterInfo($crawler),
                'extracted_at' => now()->toISOString(),
            ];

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($classesData));

            $this->logScraping('Student course data extracted successfully', [
                'classes_count' => count($classesData['classes']),
            ]);

            return $classesData;

        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting student course data', $e, ['session_token' => $sessionToken]);
            throw PortalScrapingException::dataExtractionFailed('student course', $e->getMessage());
        }
    }

    /**
     * Extract student information from student course page
     */
    private function extractStudentCourseInfo(Crawler $crawler): array
    {
        $studentInfo = [];

        try {
            // Look for student information in any table or card
            $crawler->filter('.card, .info-section')->each(function (Crawler $section) use (&$studentInfo) {
                $section->filter('table tr')->each(function (Crawler $row) use (&$studentInfo) {
                    $cells = $row->filter('td');
                    if ($cells->count() >= 2) {
                        $label = trim($cells->eq(0)->text());
                        $value = trim($cells->eq(1)->text());

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
            });
        } catch (\Exception $e) {
            Log::warning('Failed to extract student info from student course page', ['error' => $e->getMessage()]);
        }

        return $studentInfo;
    }

    /**
     * Extract classes list from student course page (with room information)
     */
    private function extractStudentCourseClassesList(Crawler $crawler): array
    {
        $classes = [];

        try {
            // Look for the card with "Semester" title that contains the classes table
            $crawler->filter('.card')->each(function (Crawler $card) use (&$classes) {
                $titleElement = $card->filter('.header .title, .header h4');
                if ($titleElement->count() > 0) {
                    $title = strtolower($titleElement->text());
                    if (strpos($title, 'semester') !== false) {
                        $classesTable = $card->filter('table')->first();

                        // Extract all rows from the classes table, skipping header
                        $classRows = $classesTable->filter('tr')->slice(1);

                        $classRows->each(function (Crawler $row) use (&$classes) {
                            $cells = $row->filter('td');
                            if ($cells->count() >= 5) {
                                $class = [
                                    'no' => trim($cells->eq(0)->text()),
                                    'schedule' => $this->extractStudentCourseScheduleInfo($cells->eq(1)),
                                    'class_room_info' => $this->extractStudentCourseClassRoomInfo($cells->eq(2)),
                                    'course_info' => $this->extractStudentCourseCourseInfo($cells->eq(3)),
                                    'lecturer_info' => $this->extractStudentCourseLecturerInfo($cells->eq(4)),
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
            Log::warning('Failed to extract classes list from student course page', ['error' => $e->getMessage()]);
        }

        return array_slice($classes, 0, 20); // Limit to 20 classes
    }

    /**
     * Extract schedule information from student course schedule cell
     */
    private function extractStudentCourseScheduleInfo(Crawler $cell): array
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
     * Extract class and room information from student course class/room cell
     */
    private function extractStudentCourseClassRoomInfo(Crawler $cell): array
    {
        $html = $cell->html();
        $text = trim($cell->text());

        $classRoomInfo = [];

        // Split by <br> to get class name and room
        $parts = preg_split('/<br\s*\/?>/i', $html);

        if (count($parts) >= 2) {
            // First part is class name, second part is room (usually in <b> tag)
            $className = trim(strip_tags($parts[0]));
            // Clean up class name from Unicode NBSP and other entities
            $className = str_replace("\u{00A0}", ' ', $className);
            $className = str_replace("\xC2\xA0", ' ', $className);
            $className = str_replace('&nbsp;', ' ', $className);
            $className = preg_replace('/\s+/', ' ', $className);
            $classRoomInfo['class_name'] = trim($className);

            // Extract room info (usually in <b> tag)
            if (preg_match('/<b>\s*([^<]+)\s*<\/b>/i', $parts[1], $matches)) {
                $roomName = trim($matches[1]);
                // Clean up room name from Unicode NBSP and other entities
                $roomName = str_replace("\u{00A0}", ' ', $roomName);
                $roomName = str_replace("\xC2\xA0", ' ', $roomName);
                $roomName = str_replace('&nbsp;', ' ', $roomName);
                $roomName = preg_replace('/\s+/', ' ', $roomName);
                $classRoomInfo['room'] = trim($roomName);
            } else {
                $roomName = trim(strip_tags($parts[1]));
                // Clean up room name from Unicode NBSP and other entities
                $roomName = str_replace("\u{00A0}", ' ', $roomName);
                $roomName = str_replace("\xC2\xA0", ' ', $roomName);
                $roomName = str_replace('&nbsp;', ' ', $roomName);
                $roomName = preg_replace('/\s+/', ' ', $roomName);
                $classRoomInfo['room'] = trim($roomName);
            }
        } else {
            // Try to extract from text format
            if (preg_match('/^(.+?)\s+(Ruang\s+.+)$/', $text, $matches)) {
                $classRoomInfo['class_name'] = trim($matches[1]);
                $classRoomInfo['room'] = trim($matches[2]);
            } else {
                $classRoomInfo['class_name'] = $text;
            }
        }

        return $classRoomInfo;
    }

    /**
     * Extract course information from student course course cell
     */
    private function extractStudentCourseCourseInfo(Crawler $cell): array
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
     * Extract lecturer information from student course lecturer cell
     */
    private function extractStudentCourseLecturerInfo(Crawler $cell): array
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
            $nameText = $text;
            // Clean name from contact info and email details
            $nameText = preg_replace('/\s*Kontak Dosen\s*:.*$/', '', $nameText);
            $nameText = preg_replace('/\s*Email Dosen\s*:.*$/', '', $nameText);
            $lecturerInfo['name'] = trim($nameText);
        }

        return $lecturerInfo;
    }

    /**
     * Extract semester information from student course page
     */
    private function extractStudentCourseSemesterInfo(Crawler $crawler): array
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
            Log::warning('Failed to extract semester info from student course page', ['error' => $e->getMessage()]);
        }

        return $semesterInfo;
    }
}
