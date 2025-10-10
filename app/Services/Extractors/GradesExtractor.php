<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\CampusScrapingException;
use App\Services\CampusScrapingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class GradesExtractor extends CampusScrapingService
{
    /**
     * Extract student grades/transcript data
     */
    public function extractGrades(string $sessionToken, array $options = []): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting student grades', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'student_grades_' . md5($sessionToken . serialize($options));
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached grades data');
                return json_decode($cachedData, true);
            }

            // Try different possible grade page URLs
            $gradeUrls = $this->getGradePageUrls($options);
            $gradesData = null;

            foreach ($gradeUrls as $url) {
                try {
                    $html = $this->get($url);
                    $crawler = $this->createCrawler($html);

                    // Check if this page contains grade data
                    if ($this->containsGradeData($crawler)) {
                        $gradesData = $this->extractGradesFromPage($crawler, $url);
                        break;
                    }
                } catch (\Exception $e) {
                    $this->logScraping('Failed to access grade page', ['url' => $url, 'error' => $e->getMessage()]);
                    continue;
                }
            }

            if (!$gradesData) {
                throw new CampusScrapingException::elementNotFound('grade data', 'any grade page');
            }

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($gradesData));

            $this->logScraping('Grades data extracted successfully', [
                'semesters_count' => count($gradesData['semesters'] ?? []),
                'gpa' => $gradesData['summary']['gpa'] ?? null,
            ]);

            return $gradesData;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting grades', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'grades', $e->getMessage());
        }
    }

    /**
     * Get possible grade page URLs
     */
    private function getGradePageUrls(array $options): array
    {
        $baseUrl = config('campus.base_url');
        $semester = $options['semester'] ?? null;
        $academicYear = $options['academic_year'] ?? null;

        $urls = [
            $baseUrl . '/portal/mahasiswa/?mod=student&act=transcript',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=grades',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=nilai',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=academic',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=khs', // Kartu Hasil Studi
        ];

        // Add semester-specific URLs if provided
        if ($semester) {
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=grades&semester=' . $semester;
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=khs&semester=' . $semester;
        }

        if ($academicYear) {
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=grades&year=' . $academicYear;
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=transcript&year=' . $academicYear;
        }

        return $urls;
    }

    /**
     * Check if page contains grade data
     */
    private function containsGradeData(Crawler $crawler): bool
    {
        $gradeIndicators = [
            'nilai',
            'grade',
            'ipk',
            'gpa',
            'khs',
            'transkrip',
            'transcript',
            'bobot',
            'sks',
            'credit',
        ];

        $pageText = strtolower($crawler->text());

        foreach ($gradeIndicators as $indicator) {
            if (Str::contains($pageText, $indicator)) {
                return true;
            }
        }

        // Check for common grade-related table structures
        $hasGradeTable = $crawler->filter('table')->reduce(function (Crawler $table) {
            $tableText = strtolower($table->text());
            return Str::contains($tableText, ['nilai', 'grade', 'matkul', 'course']);
        })->count() > 0;

        return $hasGradeTable;
    }

    /**
     * Extract grades data from the page
     */
    private function extractGradesFromPage(Crawler $crawler, string $url): array
    {
        $gradesData = [
            'summary' => $this->extractGradeSummary($crawler),
            'semesters' => $this->extractSemestersData($crawler),
            'current_gpa' => $this->extractCurrentGPA($crawler),
            'academic_stats' => $this->extractAcademicStats($crawler),
            'extracted_at' => now()->toISOString(),
            'source_url' => $url,
        ];

        return $gradesData;
    }

    /**
     * Extract grade summary information
     */
    private function extractGradeSummary(Crawler $crawler): array
    {
        $summary = [];

        // Look for GPA/IPK information
        $gpaSelectors = [
            '.gpa',
            '.ipk',
            '.grade-point-average',
            '.cumulative-gpa',
        ];

        $gpa = $this->extractText($crawler, $gpaSelectors);
        if ($gpa) {
            $summary['gpa'] = $gpa;
        }

        // Look for total credits/SKS
        $creditsSelectors = [
            '.total-credits',
            '.total-sks',
            '.cumulative-credits',
        ];

        $credits = $this->extractText($crawler, $creditsSelectors);
        if ($credits) {
            $summary['total_credits'] = $credits;
        }

        // Look for other summary information in cards or info panels
        $summarySelectors = [
            '.grade-summary',
            '.academic-summary',
            '.summary-info',
            '.info-cards',
        ];

        foreach ($summarySelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $summary = array_merge($summary, $this->extractSummaryCards($element));
            }
        }

        return $summary;
    }

    /**
     * Extract summary cards data
     */
    private function extractSummaryCards(Crawler $element): array
    {
        $summary = [];

        $element->filter('.card, .summary-item, .info-item, .stat-item')->each(function (Crawler $card) use (&$summary) {
            $label = $this->extractText($card, ['.label', '.title', '.card-title', 'h4', 'h5']);
            $value = $this->extractText($card, ['.value', '.content', '.card-body', '.stat-value']);

            if ($label && $value) {
                $key = strtolower(str_replace([' ', '-', ':'], '_', $label));
                $summary[$key] = $value;
            }
        });

        return $summary;
    }

    /**
     * Extract semester data from tables or tabs
     */
    private function extractSemestersData(Crawler $crawler): array
    {
        $semesters = [];

        // Try to find semester tabs or accordion
        $semesterTabs = $crawler->filter('.tabs .tab, .semester-tabs .tab, .accordion .item');

        if ($semesterTabs->count() > 0) {
            $semesterTabs->each(function (Crawler $tab) use (&$semesters) {
                $semesterData = $this->extractSemesterFromTab($tab);
                if ($semesterData) {
                    $semesters[] = $semesterData;
                }
            });
        } else {
            // Try to find all grade tables
            $gradeTables = $crawler->filter('table')->reduce(function (Crawler $table) {
                $tableText = strtolower($table->text());
                return Str::contains($tableText, ['matkul', 'course', 'kode', 'nilai', 'grade']);
            });

            if ($gradeTables->count() > 0) {
                $gradeTables->each(function (Crawler $table, $index) use (&$semesters) {
                    $semesterData = $this->extractSemesterFromTable($table, $index + 1);
                    if ($semesterData) {
                        $semesters[] = $semesterData;
                    }
                });
            }
        }

        return $semesters;
    }

    /**
     * Extract semester data from tab/accordion item
     */
    private function extractSemesterFromTab(Crawler $tab): ?array
    {
        $title = $this->extractText($tab, ['.title', '.tab-title', '.semester-title', 'h3', 'h4']);

        if (!$title) {
            return null;
        }

        // Find the associated content/table
        $content = $tab->filter('.content, .tab-content, .semester-content, table')->first();

        if ($content->count() === 0) {
            return null;
        }

        $courses = $this->extractCoursesFromElement($content);
        $semesterInfo = $this->extractSemesterInfo($tab);

        return array_merge([
            'semester_name' => $title,
            'courses' => $courses,
            'course_count' => count($courses),
        ], $semesterInfo);
    }

    /**
     * Extract semester data directly from a table
     */
    private function extractSemesterFromTable(Crawler $table, int $index): ?array
    {
        $courses = $this->extractCoursesFromElement($table);

        if (empty($courses)) {
            return null;
        }

        // Try to find semester title from nearby elements
        $semesterTitle = $this->findSemesterTitleForTable($table, $index);

        return [
            'semester_name' => $semesterTitle ?? "Semester {$index}",
            'courses' => $courses,
            'course_count' => count($courses),
        ];
    }

    /**
     * Extract courses from a table or content element
     */
    private function extractCoursesFromElement(Crawler $element): array
    {
        $courses = [];

        if ($element->nodeName() === 'table') {
            $tableData = $this->extractTableData($element);
            $courses = $this->processCoursesTableData($tableData);
        } else {
            // Look for tables within the content
            $element->filter('table')->each(function (Crawler $table) use (&$courses) {
                $tableData = $this->extractTableData($table);
                $coursesFromTable = $this->processCoursesTableData($tableData);
                $courses = array_merge($courses, $coursesFromTable);
            });
        }

        return $courses;
    }

    /**
     * Process table data into course information
     */
    private function processCoursesTableData(array $tableData): array
    {
        $courses = [];

        // Determine column mapping based on headers
        $headers = array_keys($tableData[0] ?? []);
        $columnMap = $this->mapTableColumns($headers);

        foreach ($tableData as $row) {
            if (empty($row)) continue;

            $course = $this->buildCourseFromRow($row, $columnMap);
            if ($course) {
                $courses[] = $course;
            }
        }

        return $courses;
    }

    /**
     * Map table columns to course data fields
     */
    private function mapTableColumns(array $headers): array
    {
        $map = [];
        $headers = array_map('strtolower', $headers);

        foreach ($headers as $index => $header) {
            if (Str::contains($header, ['kode', 'code', 'mk'])) {
                $map['course_code'] = $index;
            } elseif (Str::contains($header, ['mata kuliah', 'course', 'matkul', 'nama'])) {
                $map['course_name'] = $index;
            } elseif (Str::contains($header, ['sks', 'credit', 'kredit'])) {
                $map['credits'] = $index;
            } elseif (Str::contains($header, ['nilai', 'grade', 'huruf'])) {
                $map['grade'] = $index;
            } elseif (Str::contains($header, ['bobot', 'weight', 'ip'])) {
                $map['grade_point'] = $index;
            } elseif (Str::contains($header, ['nilai angka', 'score', 'marks'])) {
                $map['numeric_grade'] = $index;
            }
        }

        return $map;
    }

    /**
     * Build course data from table row
     */
    private function buildCourseFromRow(array $row, array $columnMap): ?array
    {
        // Skip header rows or empty rows
        $firstValue = reset($row);
        if (empty($firstValue) || $this->isHeaderRow($row)) {
            return null;
        }

        $course = [];

        foreach ($columnMap as $field => $index) {
            $value = $row[$index] ?? null;
            if ($value !== null) {
                $course[$field] = $this->cleanText($value);
            }
        }

        // Add calculated fields
        if (isset($course['grade']) && isset($course['credits'])) {
            $course['grade_points'] = $this->calculateGradePoints($course['grade'], (float) $course['credits']);
        }

        return empty($course) ? null : $course;
    }

    /**
     * Check if row is a header row
     */
    private function isHeaderRow(array $row): bool
    {
        $headerKeywords = ['kode', 'mata kuliah', 'sks', 'nilai', 'grade', 'bobot', 'semester'];
        $firstRowText = strtolower(implode(' ', $row));

        foreach ($headerKeywords as $keyword) {
            if (Str::contains($firstRowText, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate grade points for a course
     */
    private function calculateGradePoints(string $grade, float $credits): float
    {
        $gradeScale = [
            'A' => 4.0,
            'A-' => 3.7,
            'B+' => 3.3,
            'B' => 3.0,
            'B-' => 2.7,
            'C+' => 2.3,
            'C' => 2.0,
            'C-' => 1.7,
            'D+' => 1.3,
            'D' => 1.0,
            'E' => 0.0,
        ];

        $gradePoint = $gradeScale[strtoupper(trim($grade))] ?? 0.0;

        return round($gradePoint * $credits, 2);
    }

    /**
     * Find semester title for a table
     */
    private function findSemesterTitleForTable(Crawler $table, int $index): ?string
    {
        // Look for preceding headings
        $prevElements = $table->previousAll();

        foreach ($prevElements as $element) {
            $elementCrawler = new Crawler($element);
            $text = $this->extractText($elementCrawler, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', '.title']);

            if ($text && Str::contains(strtolower($text), ['semester', 'tahun', 'ganjil', 'genap'])) {
                return $text;
            }
        }

        return null;
    }

    /**
     * Extract semester information
     */
    private function extractSemesterInfo(Crawler $element): array
    {
        $info = [];

        // Look for semester stats
        $statsSelectors = [
            '.semester-stats',
            '.semester-info',
            '.summary',
        ];

        foreach ($statsSelectors as $selector) {
            $statsElement = $element->filter($selector);
            if ($statsElement->count() > 0) {
                $info = array_merge($info, $this->extractSummaryCards($statsElement));
            }
        }

        return $info;
    }

    /**
     * Extract current GPA information
     */
    private function extractCurrentGPA(Crawler $crawler): ?string
    {
        $gpaSelectors = [
            '.current-gpa',
            '.semester-gpa',
            '.ip-semester',
        ];

        return $this->extractText($crawler, $gpaSelectors);
    }

    /**
     * Extract academic statistics
     */
    private function extractAcademicStats(Crawler $crawler): array
    {
        $stats = [];

        // Look for performance statistics
        $statsSelectors = [
            '.academic-stats',
            '.performance-stats',
            '.grade-distribution',
        ];

        foreach ($statsSelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $stats = array_merge($stats, $this->extractSummaryCards($element));
            }
        }

        return $stats;
    }

    /**
     * Get grades summary without detailed course data
     */
    public function getGradesSummary(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);

            $gradeUrls = $this->getGradePageUrls();

            foreach ($gradeUrls as $url) {
                try {
                    $html = $this->get($url);
                    $crawler = $this->createCrawler($html);

                    if ($this->containsGradeData($crawler)) {
                        return [
                            'summary' => $this->extractGradeSummary($crawler),
                            'current_gpa' => $this->extractCurrentGPA($crawler),
                            'academic_stats' => $this->extractAcademicStats($crawler),
                            'semesters_count' => $crawler->filter('.tabs .tab, .semester-tabs .tab, .accordion .item')->count(),
                            'source_url' => $url,
                            'extracted_at' => now()->toISOString(),
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            throw new CampusScrapingException::elementNotFound('grade summary', 'any grade page');

        } catch (\Exception $e) {
            $this->logScrapingError('Getting grades summary', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'grades summary', $e->getMessage());
        }
    }
}