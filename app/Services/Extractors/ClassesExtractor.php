<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\CampusScrapingException;
use App\Services\CampusScrapingService;
use Symfony\Component\DomCrawler\Crawler;

class ClassesExtractor extends CampusScrapingService
{
    /**
     * Extract student classes data
     */
    public function extractClassesData(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting student classes data', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'student_classes_'.md5($sessionToken);
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached classes data');

                return json_decode($cachedData, true);
            }

            // Get classes HTML from SIPANDAI
            $classesUrl = config('campus.classes_url');
            $html = $this->get($classesUrl);
            $crawler = $this->createCrawler($html);

            // Extract classes data
            $classesData = [
                'student_info' => $this->extractStudentInfo($crawler),
                'classes' => $this->extractClassesList($crawler),
                'semester_info' => $this->extractSemesterInfo($crawler),
                'academic_summary' => $this->extractAcademicSummary($crawler),
                'extracted_at' => now()->toISOString(),
            ];

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($classesData));

            $this->logScraping('Classes data extracted successfully', [
                'classes_count' => count($classesData['classes']),
            ]);

            return $classesData;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting classes data', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'classes', $e->getMessage());
        }
    }

    /**
     * Extract class detail data
     */
    public function extractClassDetail(string $sessionToken, array $params): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting class detail', ['session_token' => $sessionToken, 'params' => $params]);

            // Check if this is a meeting detail request
            if (! empty($params['meeting_id']) && ! empty($params['courses_id'])) {
                return $this->extractMeetingDetail($sessionToken, $params);
            }

            // Build class detail URL with parameters for SIPANDAI
            $classDetailUrl = config('campus.class_detail_url');

            // Add query parameters
            $queryString = '';
            if (! empty($params['id'])) {
                $queryString .= '&id='.$params['id'];
            }
            if (! empty($params['dosen_id'])) {
                $queryString .= '&dosen_id='.$params['dosen_id'];
            }
            if (! empty($params['course_id'])) {
                $queryString .= '&course_id='.$params['course_id'];
            }

            // If no specific parameters, use the ones from classes list
            if (empty($queryString) && ! empty($params['class_id'])) {
                $queryString = '&id='.$params['class_id'];
            }

            $fullUrl = $classDetailUrl.$queryString;

            // Get class detail HTML
            $html = $this->get($fullUrl);
            $crawler = $this->createCrawler($html);

            // Extract class detail - this could be various types of content
            $classDetail = [
                'request_url' => $fullUrl,
                'content_type' => $this->detectContentType($html),
                'course_info' => $this->extractCourseInfo($crawler),
                'lecturer_info' => $this->extractLecturerInfo($crawler),
                'attendance_info' => $this->extractAttendanceInfo($crawler),
                'assignment_history' => $this->extractAssignmentHistory($crawler),
                'course_materials' => $this->extractCourseMaterials($crawler),
                'file_info' => $this->extractFileInfo($html),
                'students_list' => $this->extractStudentsList($crawler),
                'extracted_at' => now()->toISOString(),
            ];

            $this->logScraping('Class detail extracted successfully', [
                'course_name' => $classDetail['course_info']['name'] ?? 'Unknown',
                'content_type' => $classDetail['content_type'],
            ]);

            return $classDetail;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting class detail', $e, ['session_token' => $sessionToken, 'params' => $params]);
            throw new CampusScrapingException('dataExtractionFailed', 'class detail', $e->getMessage());
        }
    }

    /**
     * Extract meeting detail data from individual meeting page
     */
    public function extractMeetingDetail(string $sessionToken, array $params): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting meeting detail', ['session_token' => $sessionToken, 'params' => $params]);

            // Build meeting detail URL
            $meetingUrl = 'https://my.cic.ac.id/portal/sipandai/';
            $queryString = '?mod=sipandai&act=discuss&id='.$params['meeting_id'].'&courses_id='.$params['courses_id'];
            $fullUrl = $meetingUrl.$queryString;

            // Get meeting detail HTML
            $html = $this->get($fullUrl);
            $crawler = $this->createCrawler($html);

            // Extract meeting detail
            $meetingDetail = [
                'request_url' => $fullUrl,
                'content_type' => 'meeting_detail',
                'meeting_info' => $this->extractMeetingInfo($crawler),
                'lecturer_info' => $this->extractMeetingLecturerInfo($crawler),
                'schedule_info' => $this->extractMeetingScheduleInfo($crawler),
                'rps_info' => $this->extractRpsInfo($crawler),
                'attendance_info' => $this->extractMeetingAttendanceInfo($crawler),
                'assignment_info' => $this->extractMeetingAssignmentInfo($crawler),
                'conformity_info' => $this->extractConformityInfo($crawler),
                'materials' => $this->extractMeetingMaterials($crawler),
                'instructions' => $this->extractMeetingInstructions($crawler),
                'extracted_at' => now()->toISOString(),
            ];

            $this->logScraping('Meeting detail extracted successfully', [
                'meeting_id' => $params['meeting_id'],
                'course_id' => $params['courses_id'],
            ]);

            return $meetingDetail;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting meeting detail', $e, ['session_token' => $sessionToken, 'params' => $params]);
            throw new CampusScrapingException('dataExtractionFailed', 'meeting detail', $e->getMessage());
        }
    }

    /**
     * Extract student information from SIPANDAI dashboard
     */
    private function extractStudentInfo(Crawler $crawler): array
    {
        $studentInfo = [];

        try {
            // Look for the first card that contains student information
            $firstCard = $crawler->filter('.card')->first();
            $studentTable = $firstCard->filter('.table')->first();

            $studentTable->filter('tr')->each(function (Crawler $row) use (&$studentInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim($cells->eq(0)->text());
                    $value = trim($cells->eq(2)->text());

                    // Clean up the value by removing HTML tags and extra whitespace
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
            Log::warning('Failed to extract student info from classes page', ['error' => $e->getMessage()]);
        }

        return $studentInfo;
    }

    /**
     * Extract classes list from SIPANDAI dashboard
     */
    private function extractClassesList(Crawler $crawler): array
    {
        $classes = [];

        try {
            // Look for the second card that contains the classes table
            $cards = $crawler->filter('.card');
            if ($cards->count() >= 2) {
                $classesCard = $cards->eq(1); // Second card contains classes
                $classesTable = $classesCard->filter('.table')->first();

                // Extract all rows from the classes table, skipping header
                $classRows = $classesTable->filter('tr')->slice(1);

                $classRows->each(function (Crawler $row, $index) use (&$classes) {
                    $cells = $row->filter('td');
                    if ($cells->count() >= 5) {
                        $class = [
                            'no' => trim($cells->eq(0)->text()),
                            'schedule' => $this->extractScheduleInfoFromCell($cells->eq(1)),
                            'course_info' => $this->extractCourseInfoFromCell($cells->eq(2)),
                            'lecturer_info' => $this->extractLecturerInfoFromCell($cells->eq(3)),
                            'join_link' => $this->extractJoinLinkFromCell($cells->eq(4)),
                        ];

                        if (! empty($class['course_info']['name'])) {
                            $classes[] = $class;
                        }
                    }
                });
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract classes list from SIPANDAI', ['error' => $e->getMessage()]);

            // Fallback to generic extraction
            $classes = $this->extractClassesFromTable($crawler);
        }

        return array_slice($classes, 0, 20); // Limit to 20 classes
    }

    /**
     * Extract class data from a list item
     */
    private function extractClassFromItem(Crawler $item): ?array
    {
        $class = [];

        // Extract course name
        $courseNameSelectors = [
            '.course-name',
            '.mata-kuliah',
            '.subject-name',
            '.class-name',
            'h3',
            'h4',
            '.title',
        ];

        $class['course_name'] = $this->extractText($item, $courseNameSelectors);

        // Extract course code
        $courseCodeSelectors = [
            '.course-code',
            '.kode-mata-kuliah',
            '.subject-code',
            '.class-code',
        ];

        $class['course_code'] = $this->extractText($item, $courseCodeSelectors);

        // Extract class name
        $classNameSelectors = [
            '.class-group',
            '.kelas',
            '.section',
            '.group-name',
        ];

        $class['class_name'] = $this->extractText($item, $classNameSelectors);

        // Extract credits
        $creditsSelectors = [
            '.credits',
            '.sks',
            '.credit-hours',
        ];

        $credits = $this->extractText($item, $creditsSelectors);
        if ($credits) {
            preg_match('/\d+/', $credits, $matches);
            $class['credits'] = (int) ($matches[0] ?? 0);
        }

        // Extract lecturer
        $lecturerSelectors = [
            '.lecturer',
            '.dosen',
            '.instructor',
            '.teacher',
        ];

        $class['lecturer'] = $this->extractText($item, $lecturerSelectors);

        // Extract schedule
        $scheduleSelectors = [
            '.schedule',
            '.jadwal',
            '.time',
            '.class-time',
        ];

        $class['schedule'] = $this->extractText($item, $scheduleSelectors);

        // Extract room
        $roomSelectors = [
            '.room',
            '.ruangan',
            '.classroom',
            '.location',
        ];

        $class['room'] = $this->extractText($item, $roomSelectors);

        // Extract IDs if available
        $class['course_id'] = $item->attr('data-course-id') ?: null;
        $class['class_id'] = $item->attr('data-class-id') ?: null;
        $class['dosen_id'] = $item->attr('data-dosen-id') ?: null;

        return $class['course_name'] ? $class : null;
    }

    /**
     * Extract schedule information from schedule cell
     */
    private function extractScheduleInfoFromCell(Crawler $cell): array
    {
        $html = $cell->html();
        $text = trim($cell->text());

        // Extract day and time from text like "Senin 09:30:00-11:00:00"
        if (preg_match('/(\w+)\s*(\d{2}:\d{2}:\d{2}-\d{2}:\d{2}:\d{2})/', $text, $matches)) {
            return [
                'day' => $matches[1],
                'time' => $matches[2],
                'raw' => $text,
            ];
        }

        return ['raw' => $text];
    }

    /**
     * Extract course information from course cell
     */
    private function extractCourseInfoFromCell(Crawler $cell): array
    {
        $text = trim($cell->text());

        // Split by <br> to get course code and name
        $html = $cell->html();
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
     * Extract lecturer information from lecturer cell
     */
    private function extractLecturerInfoFromCell(Crawler $cell): array
    {
        $html = $cell->html();
        $text = trim($cell->text());

        $lecturerInfo = [];

        // Extract lecturer name (usually in <b> tag)
        $boldText = $cell->filter('b')->first();
        if ($boldText->count() > 0) {
            $lecturerInfo['name'] = trim($boldText->text());
        } else {
            // Try to extract from first line
            $lines = explode("\n", $text);
            $lecturerInfo['name'] = trim($lines[0] ?? '');
        }

        // Extract contact information
        if (preg_match('/Kontak Dosen\s*:\s*([\d\s-]+)/', $html, $matches)) {
            $lecturerInfo['contact'] = trim($matches[1]);
        }

        // Extract email
        if (preg_match('/Email Dosen\s*:\s*([^\s<]+)/', $html, $matches)) {
            $lecturerInfo['email'] = trim($matches[1]);
        }

        return $lecturerInfo;
    }

    /**
     * Extract join link from join cell
     */
    private function extractJoinLinkFromCell(Crawler $cell): array
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
     * Extract classes from table structure (fallback)
     */
    private function extractClassesFromTable(Crawler $crawler): array
    {
        $classes = [];

        $crawler->filter('table')->each(function (Crawler $table) use (&$classes) {
            $headers = [];

            // Extract table headers
            $table->filter('thead th, tr:first-child th, tr:first-child td')->each(function (Crawler $cell) use (&$headers) {
                $headers[] = strtolower(trim($cell->text()));
            });

            // Extract table rows
            $table->filter('tbody tr, tr')->each(function (Crawler $row) use (&$headers, &$classes) {
                $cells = $row->filter('td, th');

                if ($cells->count() === 0) {
                    return;
                }

                $rowData = [];
                $cells->each(function (Crawler $cell) use (&$rowData) {
                    $rowData[] = trim($cell->text());
                });

                if (! empty($rowData)) {
                    $class = $this->mapRowToClass($rowData, $headers);
                    if ($class) {
                        $classes[] = $class;
                    }
                }
            });
        });

        return $classes;
    }

    /**
     * Map table row data to class structure
     */
    private function mapRowToClass(array $rowData, array $headers): array
    {
        $class = [];

        // Common header mappings
        $headerMap = [
            'kode' => 'course_code',
            'mata kuliah' => 'course_name',
            'matakuliah' => 'course_name',
            'nama mata kuliah' => 'course_name',
            'sks' => 'credits',
            'dosen' => 'lecturer',
            'pengajar' => 'lecturer',
            'jadwal' => 'schedule',
            'hari' => 'schedule',
            'waktu' => 'schedule',
            'ruang' => 'room',
            'kelas' => 'class_name',
            'kelas' => 'class_name',
        ];

        // Map data based on headers or position
        foreach ($rowData as $index => $value) {
            if (empty(trim($value))) {
                continue;
            }

            $header = $headers[$index] ?? '';
            $normalizedHeader = strtolower($header);

            // Try direct mapping
            if (isset($headerMap[$normalizedHeader])) {
                $field = $headerMap[$normalizedHeader];
                $class[$field] = $value;

                continue;
            }

            // Position-based mapping for common table structures
            if (count($rowData) >= 4) {
                switch ($index) {
                    case 0:
                        if (preg_match('/^[A-Z]{2,4}\d{3,}$/', $value)) {
                            $class['course_code'] = $value;
                        } else {
                            $class['course_name'] = $value;
                        }
                        break;
                    case 1:
                        if (! isset($class['course_name'])) {
                            $class['course_name'] = $value;
                        } elseif (! isset($class['credits'])) {
                            $class['credits'] = (int) $value;
                        } else {
                            $class['lecturer'] = $value;
                        }
                        break;
                    case 2:
                        if (! isset($class['lecturer'])) {
                            $class['lecturer'] = $value;
                        } elseif (! isset($class['schedule'])) {
                            $class['schedule'] = $value;
                        } else {
                            $class['room'] = $value;
                        }
                        break;
                    case 3:
                        if (! isset($class['schedule'])) {
                            $class['schedule'] = $value;
                        } else {
                            $class['room'] = $value;
                        }
                        break;
                }
            }
        }

        return $class;
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
            '.semester-aktif',
        ];

        foreach ($semesterSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $text = $this->cleanText($element->text());
                if (! empty($text)) {
                    $semesterInfo['semester'] = $text;
                    break;
                }
            }
        }

        return $semesterInfo;
    }

    /**
     * Extract academic summary
     */
    private function extractAcademicSummary(Crawler $crawler): array
    {
        $summary = [];

        // Look for summary cards or info sections
        $summarySelectors = [
            '.academic-summary',
            '.summary-cards',
            '.info-cards',
            '.stats-cards',
        ];

        foreach ($summarySelectors as $selector) {
            $crawler->filter($selector)->each(function (Crawler $card) use (&$summary) {
                $label = $this->extractText($card, ['.label', '.title', 'h4', 'h5']);
                $value = $this->extractText($card, ['.value', '.content', '.number']);

                if ($label && $value) {
                    $key = strtolower(str_replace([' ', '-'], '_', $label));
                    $summary[$key] = $value;
                }
            });
        }

        return $summary;
    }

    /**
     * Extract course information from class detail
     */
    private function extractCourseInfo(Crawler $crawler): array
    {
        $courseInfo = [
            'name' => '',
            'code' => '',
            'credits' => '',
            'class_name' => '',
            'rps_link' => '',
        ];

        // Try to extract from join class page table structure
        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$courseInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim(strtolower($cells->eq(0)->text()));
                    $valueCell = $cells->eq(2); // Third cell with actual data
                    $value = trim($valueCell->text());

                    // Extract course name
                    if (strpos($label, 'nama mata kuliah') !== false) {
                        $boldText = $valueCell->filter('b')->first();
                        if ($boldText->count() > 0) {
                            $courseText = trim($boldText->text());
                            // Extract code, name, and credits from format: "UC01024011 - Bahasa Indonesia - 2 SKS"
                            if (preg_match('/(\w+\d+)\s*-\s*([^-]+?)\s*-\s*(\d+)\s*SKS/i', $courseText, $matches)) {
                                $courseInfo['code'] = $matches[1];
                                $courseInfo['name'] = trim($matches[2]);
                                $courseInfo['credits'] = $matches[3];
                            } else {
                                $courseInfo['name'] = $courseText;
                            }
                        } else {
                            $courseInfo['name'] = $value;
                        }
                    }

                    // Extract class name
                    if (strpos($label, 'nama kelas') !== false) {
                        $boldText = $valueCell->filter('b')->first();
                        if ($boldText->count() > 0) {
                            $courseInfo['class_name'] = trim($boldText->text());
                        } else {
                            $courseInfo['class_name'] = $value;
                        }
                    }

                    // Extract RPS link
                    if (strpos($label, 'rps') !== false) {
                        $linkElement = $valueCell->filter('a')->first();
                        if ($linkElement->count() > 0) {
                            $courseInfo['rps_link'] = $linkElement->attr('href');
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $courseInfo;
    }

    /**
     * Extract lecturer information from class detail
     */
    private function extractLecturerInfo(Crawler $crawler): array
    {
        $lecturerInfo = [
            'name' => '',
            'nidn' => '',
            'contact' => '',
            'email' => '',
            'contact_info' => '',
        ];

        // Try to extract from join class page table structure
        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$lecturerInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim(strtolower($cells->eq(0)->text()));
                    $valueCell = $cells->eq(2); // Third cell with actual data
                    $value = trim($valueCell->text());

                    // Extract lecturer name (from <b> tag in the value cell)
                    if (strpos($label, 'nama dosen') !== false) {
                        $boldText = $valueCell->filter('b')->first();
                        if ($boldText->count() > 0) {
                            $lecturerInfo['name'] = trim($boldText->text());
                        } else {
                            $lecturerInfo['name'] = $value;
                        }
                    }

                    // Extract contact info (from <b> tag in the value cell)
                    if (strpos($label, 'nomor kontak dosen') !== false) {
                        $boldText = $valueCell->filter('b')->first();
                        if ($boldText->count() > 0) {
                            $contactText = trim($boldText->text());
                            $lecturerInfo['contact_info'] = $contactText;

                            // Extract phone and email from contact text
                            if (preg_match('/(\d[\d\s-]+)\s*\/\s*([^@\s]+@[^@\s]+)/', $contactText, $matches)) {
                                $lecturerInfo['contact'] = trim($matches[1]);
                                $lecturerInfo['email'] = trim($matches[2]);
                            }
                        } else {
                            $lecturerInfo['contact_info'] = $value;
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $lecturerInfo;
    }

    /**
     * Extract students list from class detail
     */
    private function extractStudentsList(Crawler $crawler): array
    {
        $students = [];

        // Look for students table
        $crawler->filter('table')->each(function (Crawler $table) use (&$students) {
            // Check if this table contains student data
            $hasStudentHeaders = $table->filter('th')->reduce(function (Crawler $header) {
                $text = strtolower(trim($header->text()));

                return in_array($text, ['nim', 'nama', 'no', 'mahasiswa']);
            })->count() > 0;

            if ($hasStudentHeaders) {
                $table->filter('tbody tr, tr')->each(function (Crawler $row) use (&$students) {
                    $cells = $row->filter('td, th');

                    if ($cells->count() >= 2) {
                        $student = [
                            'nim' => $this->cleanText($cells->eq(0)->text()),
                            'name' => $this->cleanText($cells->eq(1)->text()),
                        ];

                        if (! empty($student['nim']) && ! empty($student['name'])) {
                            $students[] = $student;
                        }
                    }
                });
            }
        });

        return array_slice($students, 0, 100); // Limit to 100 students
    }

    /**
     * Detect content type from HTML response
     */
    private function detectContentType(string $html): string
    {
        // Check if it's a file download
        if (preg_match('/\.(pdf|doc|docx|xls|xlsx|ppt|pptx|zip|rar)$/i', $html)) {
            return 'file_download';
        }

        // Check if it's a login page
        if (strpos($html, 'password') !== false && strpos($html, 'username') !== false) {
            return 'login_page';
        }

        // Check if it's an error page
        if (strpos($html, 'error') !== false || strpos($html, '404') !== false) {
            return 'error_page';
        }

        // Check if it's a table with class data
        if (strpos($html, '<table') !== false && strpos($html, 'mahasiswa') !== false) {
            return 'students_list';
        }

        // Check if it has class information
        if (strpos($html, 'mata kuliah') !== false || strpos($html, 'dosen') !== false) {
            return 'class_info';
        }

        // Default
        return 'unknown';
    }

    /**
     * Extract file information from download response
     */
    private function extractFileInfo(string $html): array
    {
        $fileInfo = [];

        // Try to extract filename from response
        if (preg_match('/filename="?([^"]+)"?/i', $html, $matches)) {
            $fileInfo['filename'] = $matches[1];
        }

        // Try to extract file size
        if (preg_match('/(\d+(?:\.\d+)?)\s*(KB|MB|GB)/i', $html, $matches)) {
            $fileInfo['size'] = $matches[1].' '.$matches[2];
        }

        // Try to extract file type
        if (preg_match('/\.(pdf|doc|docx|xls|xlsx|ppt|pptx|zip|rar)$/i', $html, $matches)) {
            $fileInfo['type'] = strtoupper($matches[1]);
        }

        return $fileInfo;
    }

    /**
     * Get classes overview without detailed extraction (faster)
     */
    public function getClassesOverview(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);

            $classesUrl = config('campus.classes_url');
            $html = $this->get($classesUrl);
            $crawler = $this->createCrawler($html);

            return [
                'has_classes' => $crawler->filter('.class-item, .course-item, table tr')->count() > 1,
                'classes_count' => $crawler->filter('.class-item, .course-item, table tbody tr')->count(),
                'page_title' => $this->extractText($crawler, ['title', 'h1', '.page-title']),
                'extracted_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            $this->logScrapingError('Getting classes overview', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'classes overview', $e->getMessage());
        }
    }

    /**
     * Extract attendance information from join class page
     */
    private function extractAttendanceInfo(Crawler $crawler): array
    {
        $attendanceInfo = [
            'student_name' => '',
            'meetings' => [],
            'summary' => [],
        ];

        try {
            // Debug: Count all cards
            $allCards = $crawler->filter('.card');
            $this->logScraping('Debug: Found '.$allCards->count().' cards');

            // Look for attendance table with Presensi Kehadiran
            $crawler->filter('.card')->each(function (Crawler $card) use (&$attendanceInfo) {
                $headerText = trim($card->filter('.header .title, .header h6')->text(''));
                $this->logScraping('Debug: Card header text: "'.$headerText.'"');

                // Extract student name from header
                if (strpos($headerText, 'Presensi Kehadiran') !== false) {
                    $attendanceInfo['student_name'] = trim(str_replace('Presensi Kehadiran', '', $headerText));
                    $this->logScraping('Debug: Found attendance card for student: '.$attendanceInfo['student_name']);

                    // Extract table headers (P.1, P.2, etc.)
                    $attendanceTable = $card->filter('table');
                    $this->logScraping('Debug: Found '.$attendanceTable->count().' tables in attendance card');

                    if ($attendanceTable->count() > 0) {
                        $attendanceTable = $attendanceTable->first();

                        // Try different selectors for header row
                        $headerRow = $attendanceTable->filter('tr')->first();
                        $this->logScraping('Debug: Header row HTML: '.$headerRow->html());
                        $this->logScraping('Debug: Found header row with '.$headerRow->filter('th')->count().' th elements');

                        // Try to find header row that contains "P." pattern
                        $headerRows = $attendanceTable->filter('tr');
                        $foundHeaderRow = null;

                        foreach ($headerRows as $index => $row) {
                            $rowCrawler = new Crawler($row);
                            $rowText = $rowCrawler->text();
                            if (strpos($rowText, 'P.') !== false) {
                                $foundHeaderRow = $rowCrawler;
                                $this->logScraping('Debug: Found header row at index '.$index.' with text: '.$rowText);
                                break;
                            }
                        }

                        if ($foundHeaderRow) {
                            $headerRow = $foundHeaderRow;
                            $this->logScraping('Debug: Using found header row with '.$headerRow->filter('th')->count().' th elements');
                            $hasHeaders = true;
                        } else {
                            $this->logScraping('Debug: No header row found with P. pattern, will generate meeting numbers from cell order');
                            $hasHeaders = false;
                        }

                        $headers = [];
                        if ($hasHeaders) {
                            $headerRow->filter('th')->each(function (Crawler $th) use (&$headers) {
                                $headerText = trim($th->text());
                                // Handle both "P.1" and "P.1 <br>" formats
                                $cleanHeaderText = preg_replace('/<br\s*\/?>/', '', $headerText);
                                $cleanHeaderText = trim($cleanHeaderText);

                                $this->logScraping('Debug: Header text: "'.$headerText.'" -> Clean: "'.$cleanHeaderText.'"');

                                if (preg_match('/P\.(\d+)/', $cleanHeaderText, $matches)) {
                                    $headers[] = [
                                        'meeting_number' => intval($matches[1]),
                                        'header_text' => $cleanHeaderText,
                                    ];
                                    $this->logScraping('Debug: Parsed header P.'.$matches[1]);
                                }
                            });

                            $this->logScraping('Debug: Total headers parsed: '.count($headers));
                        } else {
                            // No headers found, generate meeting numbers from cell order
                            $this->logScraping('Debug: No th elements found, will treat first row as data and generate meeting numbers');

                            // Get the first row as data row
                            $allRows = $attendanceTable->filter('tr');
                            if ($allRows->count() > 0) {
                                $firstRow = $allRows->first();
                                $cells = $firstRow->filter('td');
                                $this->logScraping('Debug: Found '.$cells->count().' cells in first row');

                                // Generate headers from cell order (P.1, P.2, P.3, etc.)
                                $headers = [];
                                for ($i = 0; $i < $cells->count(); $i++) {
                                    $headers[] = [
                                        'meeting_number' => $i + 1,
                                        'header_text' => 'P.'.($i + 1),
                                    ];
                                    $this->logScraping('Debug: Generated header P.'.($i + 1));
                                }
                            }
                        }
                    }

                    // Extract attendance data
                    if ($attendanceTable->count() > 0) {
                        $allRows = $attendanceTable->filter('tr');

                        // If we generated headers from first row, we need to use all rows
                        // If we have real headers, skip the first row
                        if ($hasHeaders) {
                            $dataRows = $allRows->slice(1);
                            $this->logScraping('Debug: Found '.$dataRows->count().' data rows (skipping header)');
                        } else {
                            $dataRows = $allRows;
                            $this->logScraping('Debug: Found '.$dataRows->count().' data rows (all rows are data)');
                        }

                        $meetingData = [];

                        $dataRows->each(function (Crawler $row) use ($headers, &$meetingData) {
                            $cells = $row->filter('td');
                            $this->logScraping('Debug: Processing row with '.$cells->count().' cells');

                            foreach ($cells as $index => $cell) {
                                if (isset($headers[$index])) {
                                    $meetingInfo = $headers[$index];
                                    $cellCrawler = new Crawler($cell);

                                    // Check for attendance status
                                    $status = 'unknown';
                                    $details = [];

                                    $img = $cellCrawler->filter('img');
                                    if ($img->count() > 0) {
                                        $imgSrc = $img->attr('src');
                                        $details['image_src'] = $imgSrc;

                                        // Enhanced status detection from image filename
                                        if (strpos($imgSrc, 'bolos.jpg') !== false) {
                                            $status = 'absent';
                                        } elseif (strpos($imgSrc, 'hadir.jpg') !== false || strpos($imgSrc, 'present.jpg') !== false) {
                                            $status = 'present';
                                        } elseif (strpos($imgSrc, 'izin.jpg') !== false || strpos($imgSrc, 'permit.jpg') !== false) {
                                            $status = 'permit';
                                        } elseif (strpos($imgSrc, 'sakit.jpg') !== false) {
                                            $status = 'sick';
                                        }
                                    }

                                    // Check for button (recent attendance)
                                    $button = $cellCrawler->filter('button');
                                    if ($button->count() > 0) {
                                        $buttonText = trim($button->text());
                                        $buttonTitle = $button->attr('title');
                                        $buttonBgColor = $button->attr('bgcolor');

                                        $details['button_text'] = $buttonText;
                                        $details['button_title'] = $buttonTitle;
                                        $details['button_bgcolor'] = $buttonBgColor;

                                        // Extract date from button title
                                        if ($buttonTitle && preg_match('/(\w+),\s+(\d+)\s+(\w+)\s+(\d+),\s+Jam\s+(\d+:\d+)/', $buttonTitle, $matches)) {
                                            $details['attendance_date'] = $matches[2].' '.$matches[3].' '.$matches[4];
                                            $details['attendance_time'] = $matches[5];
                                        }

                                        // Determine status from button text and color
                                        if ($buttonText === 'H') {
                                            // Check button color to distinguish between present and late
                                            $status = ($buttonBgColor === 'green' || $buttonBgColor === '#00FF00') ? 'present' : 'late';
                                        } elseif ($buttonText === 'A' || $buttonText === 'α') {
                                            $status = 'absent';
                                        } elseif ($buttonText === 'I') {
                                            $status = 'permit';
                                        } elseif ($buttonText === 'S') {
                                            $status = 'sick';
                                        }

                                        // If button is red but text is 'H', it might be late
                                        if ($buttonText === 'H' && ($buttonBgColor === 'red' || $buttonBgColor === '#FF0000')) {
                                            $status = 'late';
                                        }
                                    }

                                    $meetingData[] = [
                                        'meeting_number' => $meetingInfo['meeting_number'],
                                        'status' => $status,
                                        'details' => $details,
                                    ];
                                }
                            }
                        });

                        $this->logScraping('Debug: Total meetings parsed: '.count($meetingData));
                        $attendanceInfo['meetings'] = $meetingData;

                        // Calculate attendance summary
                        if (! empty($meetingData)) {
                            $totalMeetings = count($meetingData);
                            $presentCount = 0;
                            $absentCount = 0;
                            $permitCount = 0;
                            $sickCount = 0;

                            foreach ($meetingData as $meeting) {
                                switch ($meeting['status']) {
                                    case 'present':
                                        $presentCount++;
                                        break;
                                    case 'absent':
                                        $absentCount++;
                                        break;
                                    case 'permit':
                                        $permitCount++;
                                        break;
                                    case 'sick':
                                        $sickCount++;
                                        break;
                                }
                            }

                            $attendanceInfo['summary'] = [
                                'total_meetings' => $totalMeetings,
                                'present' => $presentCount,
                                'absent' => $absentCount,
                                'permit' => $permitCount,
                                'sick' => $sickCount,
                                'attendance_rate' => $totalMeetings > 0 ? round(($presentCount / $totalMeetings) * 100, 1) : 0,
                                'last_updated' => now()->toISOString(),
                            ];
                        }
                    } else {
                        $this->logScraping('Debug: No attendance table found');
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $attendanceInfo;
    }

    /**
     * Extract assignment history from join class page
     */
    private function extractAssignmentHistory(Crawler $crawler): array
    {
        $assignmentHistory = [
            'assignments' => [],
            'average_score' => null,
        ];

        try {
            // Look for assignment history table
            $crawler->filter('.card')->each(function (Crawler $card) use (&$assignmentHistory) {
                $headerText = $card->filter('.header .title, .header h6')->text('');
                if (strpos($headerText, 'Histori Upload Tugas') !== false) {
                    $assignmentTable = $card->filter('table')->first();

                    $assignmentTable->filter('tr')->slice(1)->each(function (Crawler $row) use (&$assignmentHistory) {
                        $cells = $row->filter('td');
                        if ($cells->count() >= 5) {
                            $assignment = [
                                'no' => trim($cells->eq(0)->text()),
                                'date' => trim($cells->eq(1)->text()),
                                'meeting' => trim($cells->eq(2)->text()),
                                'file_info' => null,
                                'score' => trim($cells->eq(4)->text()),
                            ];

                            // Extract file information
                            $fileLink = $cells->eq(3)->filter('a');
                            if ($fileLink->count() > 0) {
                                $assignment['file_info'] = [
                                    'href' => $fileLink->attr('href'),
                                    'text' => trim($fileLink->text()),
                                ];
                            }

                            $assignmentHistory['assignments'][] = $assignment;
                        }
                    });

                    // Extract average score
                    $assignmentTable->filter('tr')->each(function (Crawler $row) use (&$assignmentHistory) {
                        $cells = $row->filter('td');
                        if ($cells->count() >= 5) {
                            $label = trim($cells->eq(3)->text());
                            if (strpos($label, 'RATA-RATA NILAI TUGAS') !== false) {
                                $assignmentHistory['average_score'] = trim($cells->eq(4)->text());
                            }
                        }
                    });
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $assignmentHistory;
    }

    /**
     * Extract course materials from join class page
     */
    private function extractCourseMaterials(Crawler $crawler): array
    {
        $courseMaterials = [];

        try {
            // Look for course materials table
            $crawler->filter('.card')->each(function (Crawler $card) use (&$courseMaterials) {
                $headerText = $card->filter('.header .title, .header h5')->text('');
                if (strpos($headerText, 'Materi Perkuliahan') !== false) {
                    $materialsTable = $card->filter('table')->first();

                    $materialsTable->filter('tr')->each(function (Crawler $row) use (&$courseMaterials) {
                        $cell = $row->filter('td');
                        if ($cell->count() > 0) {
                            $content = $cell->html();

                            // Extract material information using regex
                            if (preg_match('/<h6>(.+?)<\/h6>/', $content, $matches)) {
                                $material = [
                                    'title' => trim(strip_tags($matches[1])),
                                    'content' => $content,
                                ];

                                // Extract date
                                if (preg_match('/([A-Z]+, \d+ [A-Z]+ \d+)/', $content, $matches)) {
                                    $material['date'] = $matches[1];
                                }

                                // Extract time
                                if (preg_match('/Jam : (\d+:\d+)/', $content, $matches)) {
                                    $material['time'] = $matches[1];
                                }

                                // Extract description
                                if (preg_match('/<h6>Uraian Pokok Pembahasan : <\/h6>(.+?)(?:<hr>|$)/s', $content, $matches)) {
                                    $material['description'] = trim(strip_tags($matches[1]));
                                }

                                // Extract join link
                                $joinLink = $cell->filter('a');
                                if ($joinLink->count() > 0) {
                                    $material['join_link'] = [
                                        'href' => $joinLink->attr('href'),
                                        'text' => trim($joinLink->text()),
                                    ];
                                }

                                $courseMaterials[] = $material;
                            }
                        }
                    });
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $courseMaterials;
    }

    /**
     * Extract meeting information from meeting page
     */
    private function extractMeetingInfo(Crawler $crawler): array
    {
        $meetingInfo = [
            'title' => '',
            'breadcrumb' => '',
        ];

        try {
            // Extract breadcrumb/title
            $titleElement = $crawler->filter('.header .title');
            if ($titleElement->count() > 0) {
                $meetingInfo['breadcrumb'] = trim($titleElement->text());
            }

            // Extract page title
            $pageTitle = $crawler->filter('.app-page-title');
            if ($pageTitle->count() > 0) {
                $meetingInfo['title'] = trim($pageTitle->text());
            }
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $meetingInfo;
    }

    /**
     * Extract lecturer information from meeting page
     */
    private function extractMeetingLecturerInfo(Crawler $crawler): array
    {
        $lecturerInfo = [
            'name' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$lecturerInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim(strtolower($cells->eq(0)->text()));
                    $value = trim($cells->eq(2)->text());

                    if (strpos($label, 'nama dosen') !== false) {
                        $lecturerInfo['name'] = $value;
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $lecturerInfo;
    }

    /**
     * Extract meeting schedule information
     */
    private function extractMeetingScheduleInfo(Crawler $crawler): array
    {
        $scheduleInfo = [
            'date' => '',
            'start_time' => '',
            'attendance_time' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$scheduleInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim(strtolower($cells->eq(0)->text()));
                    $value = trim($cells->eq(2)->text());

                    if (strpos($label, 'tanggal pertemuan') !== false) {
                        $scheduleInfo['date'] = $value;
                    } elseif (strpos($label, 'mulai kelas jam') !== false) {
                        $scheduleInfo['start_time'] = $value;
                    } elseif (strpos($label, 'absensi kehadiran') !== false) {
                        // Extract attendance time from the text
                        if (preg_match('/Absen Jam : (\d{2}:\d{2}:\d{2})/', $value, $matches)) {
                            $scheduleInfo['attendance_time'] = $matches[1];
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $scheduleInfo;
    }

    /**
     * Extract RPS information
     */
    private function extractRpsInfo(Crawler $crawler): array
    {
        $rpsInfo = [
            'file_link' => '',
            'file_text' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$rpsInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim(strtolower($cells->eq(0)->text()));

                    if (strpos($label, 'rps') !== false) {
                        $linkElement = $cells->eq(2)->filter('a');
                        if ($linkElement->count() > 0) {
                            $rpsInfo['file_link'] = $linkElement->attr('href');
                            $rpsInfo['file_text'] = trim($linkElement->text());
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $rpsInfo;
    }

    /**
     * Extract meeting attendance information
     */
    private function extractMeetingAttendanceInfo(Crawler $crawler): array
    {
        $attendanceInfo = [
            'status' => '',
            'time' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$attendanceInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim(strtolower($cells->eq(0)->text()));
                    $value = trim($cells->eq(2)->text());

                    if (strpos($label, 'absensi kehadiran') !== false) {
                        $attendanceInfo['status'] = $value;
                        if (preg_match('/Absen Jam : (\d{2}:\d{2}:\d{2})/', $value, $matches)) {
                            $attendanceInfo['time'] = $matches[1];
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $attendanceInfo;
    }

    /**
     * Extract meeting assignment information
     */
    private function extractMeetingAssignmentInfo(Crawler $crawler): array
    {
        $assignmentInfo = [
            'deadline' => '',
            'upload_link' => '',
            'upload_text' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$assignmentInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim(strtolower($cells->eq(0)->text()));
                    $value = trim($cells->eq(1)->text());
                    $actionCell = trim($cells->eq(2)->text());

                    if (strpos($label, 'upload tugas') !== false) {
                        $assignmentInfo['deadline'] = $value;

                        $linkElement = $cells->eq(2)->filter('a');
                        if ($linkElement->count() > 0) {
                            $assignmentInfo['upload_link'] = $linkElement->attr('href');
                            $assignmentInfo['upload_text'] = trim($linkElement->text());
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $assignmentInfo;
    }

    /**
     * Extract conformity information
     */
    private function extractConformityInfo(Crawler $crawler): array
    {
        $conformityInfo = [
            'description' => '',
            'yes_button' => '',
            'no_button' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$conformityInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 1) {
                    $text = trim($cells->eq(0)->text());
                    if (strpos($text, 'Kesesuaian Materi') !== false) {
                        $conformityInfo['description'] = $text;
                    }
                }

                if ($cells->count() >= 3) {
                    $leftCell = $cells->eq(0);
                    $rightCell = $cells->eq(2);

                    $leftLink = $leftCell->filter('a');
                    if ($leftLink->count() > 0) {
                        $conformityInfo['yes_button'] = [
                            'href' => $leftLink->attr('href'),
                            'text' => trim($leftLink->text()),
                        ];
                    }

                    $rightLink = $rightCell->filter('a');
                    if ($rightLink->count() > 0) {
                        $conformityInfo['no_button'] = [
                            'href' => $rightLink->attr('href'),
                            'text' => trim($rightLink->text()),
                        ];
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $conformityInfo;
    }

    /**
     * Extract meeting materials
     */
    private function extractMeetingMaterials(Crawler $crawler): array
    {
        $materials = [
            'main_topic' => '',
            'main_description' => '',
            'main_material_iframe' => '',
            'assignment_topic' => '',
            'assignment_instructions' => '',
            'assignment_material_iframe' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$materials) {
                $cell = $row->filter('td');
                if ($cell->count() > 0) {
                    $content = $cell->html();

                    // Extract main topic
                    if (preg_match('/<h5>(.+?)<\/h5>/', $content, $matches)) {
                        $materials['main_topic'] = trim(strip_tags($matches[1]));
                    }

                    // Extract main description
                    if (preg_match('/<h5>Uraian Pokok Pembahasan : <\/h5>(.+?)<h4>/s', $content, $matches)) {
                        $materials['main_description'] = trim(strip_tags($matches[1]));
                    }

                    // Extract main material iframe
                    if (preg_match('/<iframe[^>]*src="([^"]*modul[^"]*)"[^>]*>/i', $content, $matches)) {
                        $materials['main_material_iframe'] = $matches[1];
                    }

                    // Extract assignment topic
                    if (preg_match('/<h4> Instruksi Tugas Perkuliahan ([^:]+) :<\/h4>/', $content, $matches)) {
                        $materials['assignment_topic'] = 'Pertemuan '.trim($matches[1]);
                    }

                    // Extract assignment instructions and due date
                    if (preg_match('/<h4>Due Date Tugas ([^<]+) <\/h4>/', $content, $matches)) {
                        $materials['assignment_instructions'] = $matches[1];
                    }

                    // Extract assignment material iframe
                    if (preg_match('/<iframe[^>]*src="([^"]*latihan[^"]*)"[^>]*>/i', $content, $matches)) {
                        $materials['assignment_material_iframe'] = $matches[1];
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $materials;
    }

    /**
     * Extract meeting instructions
     */
    private function extractMeetingInstructions(Crawler $crawler): array
    {
        $instructions = [
            'deadline_text' => '',
            'conformity_note' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$instructions) {
                $cells = $row->filter('td');
                if ($cells->count() >= 2) {
                    $label = trim(strtolower($cells->eq(0)->text()));
                    $value = trim($cells->eq(1)->text());

                    if (strpos($label, 'batas waktu upload') !== false) {
                        $instructions['deadline_text'] = $value;
                    }
                }

                if ($cells->count() >= 1) {
                    $text = trim($cells->eq(0)->text());
                    if (strpos($text, 'Jika anda sudah melakukan absensi') !== false) {
                        $instructions['conformity_note'] = $text;
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        return $instructions;
    }
}
