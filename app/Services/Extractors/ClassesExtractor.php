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
                'class_info' => $this->extractClassInfo($crawler),
                'lecturer_info' => $this->extractLecturerInfo($crawler),
                'schedule_info' => $this->extractScheduleInfo($crawler),
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
        return [
            'name' => $this->extractText($crawler, ['.course-name', '.mata-kuliah', 'h1', 'h2']),
            'code' => $this->extractText($crawler, ['.course-code', '.kode-mata-kuliah']),
            'credits' => $this->extractText($crawler, ['.credits', '.sks']),
        ];
    }

    /**
     * Extract class information from class detail
     */
    private function extractClassInfo(Crawler $crawler): array
    {
        return [
            'name' => $this->extractText($crawler, ['.class-name', '.kelas', '.section']),
            'room' => $this->extractText($crawler, ['.room', '.ruangan', '.classroom']),
        ];
    }

    /**
     * Extract lecturer information from class detail
     */
    private function extractLecturerInfo(Crawler $crawler): array
    {
        return [
            'name' => $this->extractText($crawler, ['.lecturer-name', '.dosen', '.instructor']),
            'nidn' => $this->extractText($crawler, ['.nidn', '.lecturer-id']),
        ];
    }

    /**
     * Extract schedule information from class detail
     */
    private function extractScheduleInfo(Crawler $crawler): array
    {
        return [
            'day' => $this->extractText($crawler, ['.schedule-day', '.hari']),
            'time' => $this->extractText($crawler, ['.schedule-time', '.waktu']),
            'schedule' => $this->extractText($crawler, ['.schedule', '.jadwal']),
        ];
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
}
