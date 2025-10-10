<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Exceptions\CampusScrapingException;
use App\Services\CampusScrapingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class ScheduleExtractor extends CampusScrapingService
{
    /**
     * Extract class schedule data
     */
    public function extractSchedule(string $sessionToken, array $options = []): array
    {
        try {
            $this->setSession($sessionToken);
            $this->logScraping('Extracting class schedule', ['session_token' => $sessionToken]);

            // Try to get cached data first
            $cacheKey = 'student_schedule_' . md5($sessionToken . serialize($options));
            $cachedData = $this->getCachedResponse($cacheKey);

            if ($cachedData) {
                $this->logScraping('Using cached schedule data');
                return json_decode($cachedData, true);
            }

            // Try different possible schedule page URLs
            $scheduleUrls = $this->getSchedulePageUrls($options);
            $scheduleData = null;

            foreach ($scheduleUrls as $url) {
                try {
                    $html = $this->get($url);
                    $crawler = $this->createCrawler($html);

                    // Check if this page contains schedule data
                    if ($this->containsScheduleData($crawler)) {
                        $scheduleData = $this->extractScheduleFromPage($crawler, $url);
                        break;
                    }
                } catch (\Exception $e) {
                    $this->logScraping('Failed to access schedule page', ['url' => $url, 'error' => $e->getMessage()]);
                    continue;
                }
            }

            if (!$scheduleData) {
                throw new CampusScrapingException::elementNotFound('schedule data', 'any schedule page');
            }

            // Cache the extracted data
            $this->cacheResponse($cacheKey, json_encode($scheduleData));

            $this->logScraping('Schedule data extracted successfully', [
                'schedules_count' => count($scheduleData['schedules'] ?? []),
                'format' => $scheduleData['format'] ?? 'unknown',
            ]);

            return $scheduleData;

        } catch (\Exception $e) {
            $this->logScrapingError('Extracting schedule', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'schedule', $e->getMessage());
        }
    }

    /**
     * Get possible schedule page URLs
     */
    private function getSchedulePageUrls(array $options): array
    {
        $baseUrl = config('campus.base_url');
        $semester = $options['semester'] ?? null;
        $academicYear = $options['academic_year'] ?? null;

        $urls = [
            $baseUrl . '/portal/mahasiswa/?mod=student&act=schedule',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=jadwal',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=class-schedule',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=time-table',
            $baseUrl . '/portal/mahasiswa/?mod=student&act=kalender', // Calendar
        ];

        // Add semester-specific URLs if provided
        if ($semester) {
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=schedule&semester=' . $semester;
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=jadwal&semester=' . $semester;
        }

        if ($academicYear) {
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=schedule&year=' . $academicYear;
            $urls[] = $baseUrl . '/portal/mahasiswa/?mod=student&act=jadwal&year=' . $academicYear;
        }

        return $urls;
    }

    /**
     * Check if page contains schedule data
     */
    private function containsScheduleData(Crawler $crawler): bool
    {
        $scheduleIndicators = [
            'jadwal',
            'schedule',
            'waktu',
            'jam',
            'hari',
            'senin',
            'selasa',
            'rabu',
            'kamis',
            'jumat',
            'sabtu',
            'ruang',
            'kelas',
            'dosen',
            'matkul',
        ];

        $pageText = strtolower($crawler->text());

        foreach ($scheduleIndicators as $indicator) {
            if (Str::contains($pageText, $indicator)) {
                return true;
            }
        }

        // Check for common schedule table structures
        $hasScheduleTable = $crawler->filter('table')->reduce(function (Crawler $table) {
            $tableText = strtolower($table->text());
            return Str::contains($tableText, ['senin', 'jam', 'ruang', 'matkul']);
        })->count() > 0;

        return $hasScheduleTable;
    }

    /**
     * Extract schedule data from the page
     */
    private function extractScheduleFromPage(Crawler $crawler, string $url): array
    {
        // Determine the format of the schedule data
        $format = $this->determineScheduleFormat($crawler);

        $scheduleData = [
            'format' => $format,
            'semester_info' => $this->extractSemesterInfo($crawler),
            'schedules' => $this->extractSchedulesByFormat($crawler, $format),
            'summary' => $this->extractScheduleSummary($crawler),
            'extracted_at' => now()->toISOString(),
            'source_url' => $url,
        ];

        return $scheduleData;
    }

    /**
     * Determine the format of the schedule data
     */
    private function determineScheduleFormat(Crawler $crawler): string
    {
        // Check for weekly grid format
        if ($crawler->filter('table th:contains("Senin"), table th:contains("Monday")')->count() > 0) {
            return 'weekly_grid';
        }

        // Check for list format
        if ($crawler->filter('table')->reduce(function (Crawler $table) {
            $tableText = strtolower($table->text());
            return Str::contains($tableText, ['hari', 'jam', 'matkul']) &&
                   !Str::contains($tableText, ['senin', 'selasa']);
        })->count() > 0) {
            return 'list';
        }

        // Check for calendar format
        if ($crawler->filter('.calendar, .schedule-calendar')->count() > 0) {
            return 'calendar';
        }

        return 'unknown';
    }

    /**
     * Extract schedules based on detected format
     */
    private function extractSchedulesByFormat(Crawler $crawler, string $format): array
    {
        switch ($format) {
            case 'weekly_grid':
                return $this->extractWeeklyGridSchedule($crawler);
            case 'list':
                return $this->extractListSchedule($crawler);
            case 'calendar':
                return $this->extractCalendarSchedule($crawler);
            default:
                return $this->extractGenericSchedule($crawler);
        }
    }

    /**
     * Extract weekly grid schedule format
     */
    private function extractWeeklyGridSchedule(Crawler $crawler): array
    {
        $schedules = [];

        // Find the main schedule table
        $scheduleTable = $crawler->filter('table')->reduce(function (Crawler $table) {
            $tableText = strtolower($table->text());
            return Str::contains($tableText, ['senin', 'jam']);
        })->first();

        if ($scheduleTable->count() === 0) {
            return $schedules;
        }

        $tableData = $this->extractTableData($scheduleTable);
        $headers = array_keys($tableData[0] ?? []);

        // Map day columns
        $dayMap = $this->mapDayColumns($headers);
        $timeColumn = $this->findTimeColumn($headers);

        foreach ($tableData as $rowIndex => $row) {
            if ($rowIndex === 0) continue; // Skip header row

            $timeSlot = $row[$timeColumn] ?? null;
            if (!$timeSlot) continue;

            foreach ($dayMap as $day => $columnIndex) {
                $cellData = $row[$columnIndex] ?? null;
                if ($cellData && !empty(trim($cellData))) {
                    $schedule = $this->parseScheduleCell($cellData, $timeSlot, $day);
                    if ($schedule) {
                        $schedules[] = $schedule;
                    }
                }
            }
        }

        return $schedules;
    }

    /**
     * Extract list format schedule
     */
    private function extractListSchedule(Crawler $crawler): array
    {
        $schedules = [];

        // Find schedule tables
        $scheduleTables = $crawler->filter('table')->reduce(function (Crawler $table) {
            $tableText = strtolower($table->text());
            return Str::contains($tableText, ['hari', 'jam', 'matkul']);
        });

        $scheduleTables->each(function (Crawler $table) use (&$schedules) {
            $tableData = $this->extractTableData($table);
            $schedulesFromTable = $this->processScheduleTableData($tableData);
            $schedules = array_merge($schedules, $schedulesFromTable);
        });

        return $schedules;
    }

    /**
     * Extract calendar format schedule
     */
    private function extractCalendarSchedule(Crawler $crawler): array
    {
        $schedules = [];

        $crawler->filter('.calendar-event, .schedule-item, .event-item')->each(function (Crawler $event) use (&$schedules) {
            $title = $this->extractText($event, ['.title', '.event-title', 'h4', 'h5']);
            $time = $this->extractText($event, ['.time', '.event-time', '.schedule-time']);
            $location = $this->extractText($event, ['.location', '.venue', '.room', '.event-location']);
            $date = $this->extractText($event, ['.date', '.event-date', '.schedule-date']);

            if ($title && ($time || $date)) {
                $schedules[] = array_filter([
                    'course_name' => $title,
                    'time' => $time,
                    'location' => $location,
                    'date' => $date,
                    'day' => $this->extractDayFromText($date ?? $time ?? ''),
                ]);
            }
        });

        return $schedules;
    }

    /**
     * Extract generic schedule format
     */
    private function extractGenericSchedule(Crawler $crawler): array
    {
        $schedules = [];

        // Try to find any structured data
        $crawler->filter('.schedule-item, .class-item, .course-item')->each(function (Crawler $item) use (&$schedules) {
            $schedule = $this->extractScheduleFromItem($item);
            if ($schedule) {
                $schedules[] = $schedule;
            }
        });

        return $schedules;
    }

    /**
     * Map day columns in weekly grid
     */
    private function mapDayColumns(array $headers): array
    {
        $dayMap = [];
        $dayMapping = [
            'senin' => 'Senin',
            'monday' => 'Senin',
            'selasa' => 'Selasa',
            'tuesday' => 'Selasa',
            'rabu' => 'Rabu',
            'wednesday' => 'Rabu',
            'kamis' => 'Kamis',
            'thursday' => 'Kamis',
            'jumat' => 'Jumat',
            'friday' => 'Jumat',
            'sabtu' => 'Sabtu',
            'saturday' => 'Sabtu',
            'minggu' => 'Minggu',
            'sunday' => 'Minggu',
        ];

        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));
            foreach ($dayMapping as $key => $day) {
                if (Str::contains($headerLower, $key)) {
                    $dayMap[$day] = $index;
                    break;
                }
            }
        }

        return $dayMap;
    }

    /**
     * Find time column index
     */
    private function findTimeColumn(array $headers): int
    {
        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));
            if (Str::contains($headerLower, ['jam', 'waktu', 'time'])) {
                return $index;
            }
        }

        return 0; // Assume first column is time if not found
    }

    /**
     * Parse schedule cell content
     */
    private function parseScheduleCell(string $cellData, string $timeSlot, string $day): ?array
    {
        $cellData = $this->cleanText($cellData);

        if (empty($cellData)) {
            return null;
        }

        // Split by line breaks or common separators
        $lines = preg_split('/[\n\r]+|<br\s*\/?>/', $cellData);
        $lines = array_filter($lines, fn($line) => !empty(trim($line)));

        if (empty($lines)) {
            return null;
        }

        $schedule = [
            'day' => $day,
            'time_slot' => $timeSlot,
            'course_name' => $lines[0] ?? '',
        ];

        // Try to extract additional information
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Check for room information
            if (preg_match('/ruang|room|kelas/i', $line)) {
                $schedule['room'] = $line;
            }
            // Check for lecturer information
            elseif (preg_match('/dosen|lecturer|pengajar/i', $line)) {
                $schedule['lecturer'] = $line;
            }
            // Check for course code
            elseif (preg_match('/^[A-Z]{2,}\d{3,}/', $line)) {
                $schedule['course_code'] = $line;
            }
            // Add as additional info if not categorized
            elseif (!isset($schedule['additional_info'])) {
                $schedule['additional_info'] = $line;
            }
        }

        // Parse time slot into start and end time
        if (preg_match('/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/', $timeSlot, $matches)) {
            $schedule['start_time'] = $matches[1];
            $schedule['end_time'] = $matches[2];
        }

        return $schedule;
    }

    /**
     * Process schedule table data for list format
     */
    private function processScheduleTableData(array $tableData): array
    {
        $schedules = [];
        $headers = array_keys($tableData[0] ?? []);
        $columnMap = $this->mapScheduleColumns($headers);

        foreach ($tableData as $rowIndex => $row) {
            if ($rowIndex === 0 || $this->isScheduleHeaderRow($row)) {
                continue; // Skip header rows
            }

            $schedule = $this->buildScheduleFromRow($row, $columnMap);
            if ($schedule) {
                $schedules[] = $schedule;
            }
        }

        return $schedules;
    }

    /**
     * Map table columns to schedule data fields
     */
    private function mapScheduleColumns(array $headers): array
    {
        $map = [];
        $headers = array_map('strtolower', $headers);

        foreach ($headers as $index => $header) {
            if (Str::contains($header, ['hari', 'day'])) {
                $map['day'] = $index;
            } elseif (Str::contains($header, ['jam', 'waktu', 'time'])) {
                $map['time'] = $index;
            } elseif (Str::contains($header, ['kode', 'code', 'mk'])) {
                $map['course_code'] = $index;
            } elseif (Str::contains($header, ['mata kuliah', 'course', 'matkul', 'nama'])) {
                $map['course_name'] = $index;
            } elseif (Str::contains($header, ['ruang', 'room', 'kelas'])) {
                $map['room'] = $index;
            } elseif (Str::contains($header, ['dosen', 'lecturer', 'pengajar'])) {
                $map['lecturer'] = $index;
            } elseif (Str::contains($header, ['sks', 'credit'])) {
                $map['credits'] = $index;
            }
        }

        return $map;
    }

    /**
     * Check if row is a header row
     */
    private function isScheduleHeaderRow(array $row): bool
    {
        $headerKeywords = ['hari', 'jam', 'kode', 'mata kuliah', 'ruang', 'dosen'];
        $firstRowText = strtolower(implode(' ', $row));

        foreach ($headerKeywords as $keyword) {
            if (Str::contains($firstRowText, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build schedule data from table row
     */
    private function buildScheduleFromRow(array $row, array $columnMap): ?array
    {
        $schedule = [];

        foreach ($columnMap as $field => $index) {
            $value = $row[$index] ?? null;
            if ($value !== null) {
                $schedule[$field] = $this->cleanText($value);
            }
        }

        // Parse time if not separated
        if (isset($schedule['time'])) {
            $timeData = $this->parseTimeField($schedule['time']);
            $schedule = array_merge($schedule, $timeData);
        }

        return empty($schedule) ? null : $schedule;
    }

    /**
     * Parse time field into start/end times
     */
    private function parseTimeField(string $timeField): array
    {
        $timeData = [];

        // Look for time range patterns
        if (preg_match('/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/', $timeField, $matches)) {
            $timeData['start_time'] = $matches[1];
            $timeData['end_time'] = $matches[2];
        } else {
            $timeData['time'] = $timeField;
        }

        return $timeData;
    }

    /**
     * Extract schedule from generic item
     */
    private function extractScheduleFromItem(Crawler $item): ?array
    {
        $title = $this->extractText($item, ['.title', '.course-name', 'h4', 'h5']);
        $time = $this->extractText($item, ['.time', '.schedule-time']);
        $day = $this->extractText($item, ['.day', '.schedule-day']);
        $room = $this->extractText($item, ['.room', '.location', '.venue']);

        if ($title && ($time || $day)) {
            return array_filter([
                'course_name' => $title,
                'time' => $time,
                'day' => $day,
                'room' => $room,
            ]);
        }

        return null;
    }

    /**
     * Extract day from text
     */
    private function extractDayFromText(string $text): ?string
    {
        $days = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];
        $textLower = strtolower($text);

        foreach ($days as $day) {
            if (Str::contains($textLower, $day)) {
                return ucfirst($day);
            }
        }

        return null;
    }

    /**
     * Extract semester information
     */
    private function extractSemesterInfo(Crawler $crawler): array
    {
        $info = [];

        $selectors = [
            '.semester-info',
            '.academic-info',
            '.schedule-info',
        ];

        foreach ($selectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $this->extractInfoCards($element, $info);
            }
        }

        return $info;
    }

    /**
     * Extract info cards
     */
    private function extractInfoCards(Crawler $element, array &$info): void
    {
        $element->filter('.card, .info-item, .stat-item')->each(function (Crawler $card) use (&$info) {
            $label = $this->extractText($card, ['.label', '.title', '.card-title', 'h4', 'h5']);
            $value = $this->extractText($card, ['.value', '.content', '.card-body']);

            if ($label && $value) {
                $key = strtolower(str_replace([' ', '-', ':'], '_', $label));
                $info[$key] = $value;
            }
        });
    }

    /**
     * Extract schedule summary
     */
    private function extractScheduleSummary(Crawler $crawler): array
    {
        $summary = [];

        // Look for summary information
        $summarySelectors = [
            '.schedule-summary',
            '.summary-info',
            '.stats',
        ];

        foreach ($summarySelectors as $selector) {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                $this->extractInfoCards($element, $summary);
            }
        }

        return $summary;
    }

    /**
     * Get schedule summary without detailed data
     */
    public function getScheduleSummary(string $sessionToken): array
    {
        try {
            $this->setSession($sessionToken);

            $scheduleUrls = $this->getSchedulePageUrls();

            foreach ($scheduleUrls as $url) {
                try {
                    $html = $this->get($url);
                    $crawler = $this->createCrawler($html);

                    if ($this->containsScheduleData($crawler)) {
                        $format = $this->determineScheduleFormat($crawler);

                        return [
                            'format' => $format,
                            'semester_info' => $this->extractSemesterInfo($crawler),
                            'summary' => $this->extractScheduleSummary($crawler),
                            'has_schedule' => true,
                            'source_url' => $url,
                            'extracted_at' => now()->toISOString(),
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            return [
                'has_schedule' => false,
                'message' => 'No schedule data found',
                'extracted_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            $this->logScrapingError('Getting schedule summary', $e, ['session_token' => $sessionToken]);
            throw new CampusScrapingException('dataExtractionFailed', 'schedule summary', $e->getMessage());
        }
    }
}