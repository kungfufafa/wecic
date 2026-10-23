<?php

declare(strict_types=1);

namespace App\Services\Extractors;

use App\Enums\MeetingAttendanceState;
use App\Exceptions\PortalScrapingException;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalScrapingService;
use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;

class ClassesExtractor extends PortalScrapingService
{
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

            $detailParams = array_filter([
                'id' => $params['id'] ?? ($params['class_id'] ?? null),
                'dosen_id' => $params['dosen_id'] ?? null,
                'course_id' => $params['course_id'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');

            return $this->rememberResource('class_detail', $detailParams, function () use ($detailParams): array {
                $fullUrl = PortalEndpointRegistry::url('course_materials', $detailParams);
                $html = $this->get($fullUrl);
                $crawler = $this->createCrawler($html);

                // Extract class detail - this could be various types of content
                $courseMaterials = $this->extractCourseMaterials($crawler);

                $classDetail = [
                    'request_url' => $fullUrl,
                    'content_type' => $this->detectContentType($html),
                    'course_info' => $this->extractCourseInfo($crawler),
                    'lecturer_info' => $this->extractLecturerInfo($crawler),
                    'attendance_info' => $this->markUpcomingMeetings($this->extractAttendanceInfo($crawler), $courseMaterials),
                    'assignment_history' => $this->extractAssignmentHistory($crawler),
                    'course_materials' => $courseMaterials,
                    'file_info' => $this->extractFileInfo($html),
                    'students_list' => $this->extractStudentsList($crawler),
                    'extracted_at' => now()->toISOString(),
                ];

                // An unknown or stale id still renders the page shell, just empty.
                $classDetail['found'] = $this->classDetailHasContent($classDetail);

                $this->logScraping('Class detail extracted successfully', [
                    'course_name' => $classDetail['course_info']['name'] ?? 'Unknown',
                    'content_type' => $classDetail['content_type'],
                ]);

                return $classDetail;
            },
                static fn (array $classDetail): bool => ! in_array($classDetail['content_type'] ?? null, ['error_page', 'login_page'], true)
                    && ($classDetail['found'] ?? true) !== false,
                ($params['fresh'] ?? false) === true
            );

        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting class detail', $e, ['session_token' => $sessionToken, 'params' => $params]);
            throw PortalScrapingException::dataExtractionFailed('class detail', $e->getMessage());
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
            $isLoginPage = false;

            return $this->rememberResource('meeting_detail', self::meetingResourceParams($params), function () use ($params, &$isLoginPage): array {
                $fullUrl = PortalEndpointRegistry::url('discussion', array_filter([
                    'id' => $params['meeting_id'] ?? null,
                    'courses_id' => $params['courses_id'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''));

                $html = $this->get($fullUrl);
                $crawler = $this->createCrawler($html);
                $isLoginPage = $this->detectContentType($html) === 'login_page';

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

                // An unknown id still renders the page shell, just empty.
                $meetingDetail['found'] = $this->meetingDetailHasContent($meetingDetail);

                $this->logScraping('Meeting detail extracted successfully', [
                    'meeting_id' => $params['meeting_id'],
                    'course_id' => $params['courses_id'],
                ]);

                return $meetingDetail;
            },
                static function () use (&$isLoginPage): bool {
                    return ! $isLoginPage;
                },
                ($params['fresh'] ?? false) === true
            );

        } catch (PortalScrapingException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logScrapingError('Extracting meeting detail', $e, ['session_token' => $sessionToken, 'params' => $params]);
            throw PortalScrapingException::dataExtractionFailed('meeting detail', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{meeting_id: mixed, courses_id: mixed}
     */
    private static function meetingResourceParams(array $params): array
    {
        return [
            'meeting_id' => $params['meeting_id'] ?? null,
            'courses_id' => $params['courses_id'] ?? null,
        ];
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
                            if (preg_match('/^([A-Z0-9]+)\s*-\s*(.+)\s*-\s*(\d+)\s*SKS$/i', $courseText, $matches)) {
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
                        $contactText = $this->normalizePortalText(($boldText->count() > 0 ? $boldText : $valueCell)->text(''));

                        // Cloudflare shows "[email protected]" and keeps the address in data-cfemail.
                        $email = $this->extractCloudflareEmail($valueCell);
                        if ($email !== null) {
                            $contactText = (string) preg_replace('/\[\s*email\s+protected\s*\]/iu', $email, $contactText);
                        }

                        $lecturerInfo['contact_info'] = $contactText;

                        // Extract phone and email from contact text
                        if (preg_match('/(\d[\d\s-]+)\s*\/\s*([^@\s]+@[^@\s]+)/', $contactText, $matches)) {
                            $lecturerInfo['contact'] = trim($matches[1]);
                            $lecturerInfo['email'] = trim($matches[2]);
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
        // Content-bearing markers are checked first: valid class pages often
        // contain the words "error" or "password" inside their scripts. Login
        // detection uses structural form evidence, so it can safely precede
        // the word-based class_info check; error detection follows it. Every
        // SIPANDAI page has tables and links to act=dashboardmahasiswa, so
        // "students_list" is only a last resort, never a reason to accept a page.
        if (str_starts_with($html, '%PDF-') || preg_match('/\.(pdf|doc|docx|xls|xlsx|ppt|pptx|zip|rar)(?:\?[^"\'<\s]*)?["\']?\s*$/i', $html)) {
            return 'file_download';
        }

        if (preg_match('/<input[^>]+type=[\'"]password[\'"]/i', $html) && preg_match('/<input[^>]+name=[\'"](username|user|email|nim|login)[\'"]/i', $html)) {
            return 'login_page';
        }

        if (stripos($html, 'mata kuliah') !== false || stripos($html, 'dosen') !== false) {
            return 'class_info';
        }

        if (preg_match('/<title>[^<]*(error|not found|forbidden)[^<]*<\/title>/i', $html) || stripos($html, 'halaman tidak ditemukan') !== false) {
            return 'error_page';
        }

        if (strpos($html, '<table') !== false && stripos($html, 'mahasiswa') !== false) {
            return 'students_list';
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
            $allCards = $crawler->filter('.card');
            $this->logScrapingDebug('Found '.$allCards->count().' cards on class detail page');

            // Look for attendance table with Presensi Kehadiran
            $crawler->filter('.card')->each(function (Crawler $card) use (&$attendanceInfo) {
                $headerText = trim($card->filter('.header .title, .header h6')->text(''));
                $this->logScrapingDebug('Card header text: "'.$headerText.'"');

                // Extract student name from header
                if (strpos($headerText, 'Presensi Kehadiran') !== false) {
                    $attendanceInfo['student_name'] = trim(str_replace('Presensi Kehadiran', '', $headerText));
                    $this->logScrapingDebug('Found attendance card for student: '.$attendanceInfo['student_name']);

                    // Extract table headers (P.1, P.2, etc.)
                    $attendanceTable = $card->filter('table');
                    $this->logScrapingDebug('Found '.$attendanceTable->count().' tables in attendance card');

                    if ($attendanceTable->count() > 0) {
                        $attendanceTable = $attendanceTable->first();

                        // Try different selectors for header row
                        $headerRow = $attendanceTable->filter('tr')->first();
                        $this->logScrapingDebug('Header row HTML: '.$headerRow->html());
                        $this->logScrapingDebug('Found header row with '.$headerRow->filter('th')->count().' th elements');

                        // Try to find header row that contains "P." pattern
                        $headerRows = $attendanceTable->filter('tr');
                        $foundHeaderRow = null;

                        foreach ($headerRows as $index => $row) {
                            $rowCrawler = new Crawler($row);
                            $rowText = $rowCrawler->text();
                            if (strpos($rowText, 'P.') !== false) {
                                $foundHeaderRow = $rowCrawler;
                                $this->logScrapingDebug('Found header row at index '.$index.' with text: '.$rowText);
                                break;
                            }
                        }

                        if ($foundHeaderRow) {
                            $headerRow = $foundHeaderRow;
                            $this->logScrapingDebug('Using found header row with '.$headerRow->filter('th')->count().' th elements');
                            $hasHeaders = true;
                        } else {
                            $this->logScrapingDebug('No header row found with P. pattern, will generate meeting numbers from cell order');
                            $hasHeaders = false;
                        }

                        $headers = [];
                        if ($hasHeaders) {
                            $headerRow->filter('th')->each(function (Crawler $th) use (&$headers) {
                                $headerText = trim($th->text());
                                // Handle both "P.1" and "P.1 <br>" formats
                                $cleanHeaderText = preg_replace('/<br\s*\/?>/', '', $headerText);
                                $cleanHeaderText = trim($cleanHeaderText);

                                $this->logScrapingDebug('Header text: "'.$headerText.'" -> Clean: "'.$cleanHeaderText.'"');

                                if (preg_match('/P\.(\d+)/', $cleanHeaderText, $matches)) {
                                    $headers[] = [
                                        'meeting_number' => intval($matches[1]),
                                        'header_text' => $cleanHeaderText,
                                    ];
                                }
                            });

                            $this->logScrapingDebug('Total headers parsed: '.count($headers));
                        } else {
                            // No headers found, generate meeting numbers from cell order
                            $this->logScrapingDebug('No th elements found, treating first row as data and generating meeting numbers');

                            // Get the first row as data row
                            $allRows = $attendanceTable->filter('tr');
                            if ($allRows->count() > 0) {
                                $firstRow = $allRows->first();
                                $cells = $firstRow->filter('td');
                                $this->logScrapingDebug('Found '.$cells->count().' cells in first row');

                                // Generate headers from cell order (P.1, P.2, P.3, etc.)
                                $headers = [];
                                for ($i = 0; $i < $cells->count(); $i++) {
                                    $headers[] = [
                                        'meeting_number' => $i + 1,
                                        'header_text' => 'P.'.($i + 1),
                                    ];
                                }
                                $this->logScrapingDebug('Generated '.count($headers).' meeting headers from cell order');
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
                            $this->logScrapingDebug('Found '.$dataRows->count().' data rows (skipping header)');
                        } else {
                            $dataRows = $allRows;
                            $this->logScrapingDebug('Found '.$dataRows->count().' data rows (all rows are data)');
                        }

                        $meetingData = [];

                        $dataRows->each(function (Crawler $row) use ($headers, &$meetingData) {
                            $cells = $row->filter('td');
                            $this->logScrapingDebug('Processing row with '.$cells->count().' cells');

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

                                        // Determine status from button text. The portal colours
                                        // every "H" button red, so colour says nothing about lateness;
                                        // only an explicit note in the tooltip does.
                                        if ($buttonText === 'H') {
                                            $status = preg_match('/terlambat|telat|late/i', (string) $buttonTitle) ? 'late' : 'present';
                                        } elseif ($buttonText === 'A' || $buttonText === 'α') {
                                            $status = 'absent';
                                        } elseif ($buttonText === 'I') {
                                            $status = 'permit';
                                        } elseif ($buttonText === 'S') {
                                            $status = 'sick';
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

                        $this->logScrapingDebug('Total meetings parsed: '.count($meetingData));
                        $attendanceInfo['meetings'] = $meetingData;

                        // Calculate attendance summary
                        if (! empty($meetingData)) {
                            $totalMeetings = count($meetingData);
                            $presentCount = 0;
                            $lateCount = 0;
                            $absentCount = 0;
                            $permitCount = 0;
                            $sickCount = 0;

                            foreach ($meetingData as $meeting) {
                                switch ($meeting['status']) {
                                    case 'present':
                                        $presentCount++;
                                        break;
                                    case 'late':
                                        $lateCount++;
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
                                'present' => $presentCount + $lateCount,
                                'late' => $lateCount,
                                'absent' => $absentCount,
                                'permit' => $permitCount,
                                'sick' => $sickCount,
                                'attendance_rate' => $totalMeetings > 0 ? round((($presentCount + $lateCount) / $totalMeetings) * 100, 1) : 0,
                                'last_updated' => now()->toISOString(),
                            ];
                        }
                    } else {
                        $this->logScrapingDebug('No attendance table found');
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

                            $deleteLink = $row->filter('a[href*="jobdel"]');
                            if ($deleteLink->count() > 0) {
                                $assignment['delete_info'] = [
                                    'href' => $deleteLink->first()->attr('href'),
                                    'text' => trim($deleteLink->first()->text()),
                                ];
                            }

                            $assignmentHistory['assignments'][] = $assignment;
                        }
                    });

                    // Average row: `<td colspan="4">RATA-RATA NILAI TUGAS</td><td>0</td>`
                    $assignmentTable->filter('tr')->each(function (Crawler $row) use (&$assignmentHistory) {
                        $cells = $row->filter('td');
                        if ($cells->count() >= 2 && stripos($this->normalizePortalText($row->text('')), 'RATA-RATA NILAI TUGAS') !== false) {
                            $average = $this->normalizePortalText($cells->last()->text(''));
                            $assignmentHistory['average_score'] = $average !== '' ? $average : null;
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
     *
     * Only the parsed fields are kept: the cell's raw HTML (lecturer rich
     * text, possibly with inline base64 images) is read by nobody and would
     * otherwise travel with every cached, archived and served class page.
     *
     * @return list<array{title: string, meeting_number: int|null, date?: string, time?: string, description?: string, join_link?: array{href: string|null, text: string}}>
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
                                    'title' => $this->richTextToPlain($matches[1]),
                                    'meeting_number' => null,
                                ];

                                if (preg_match('/Pertemuan\s+(\d+)/i', $material['title'], $meetingMatches)) {
                                    $material['meeting_number'] = (int) $meetingMatches[1];
                                }

                                // Extract date, e.g. "Rabu, 05 November 2025" or "Jum'at, 7 Maret 2025"
                                if (preg_match('/([\p{L}\'\x{2019}]+),\s*(\d{1,2})\s+(\p{L}+)\s+(\d{4})/iu', $this->richTextToPlain($content), $matches)) {
                                    $material['date'] = "{$matches[1]}, {$matches[2]} {$matches[3]} {$matches[4]}";
                                }

                                // Extract time
                                if (preg_match('/Jam : (\d+:\d+)/', $content, $matches)) {
                                    $material['time'] = $matches[1];
                                }

                                $joinLink = $this->meetingJoinAnchor($cell);

                                // Extract description: lecturer rich text up to the join button
                                if (preg_match('/<h6>\s*Uraian Pokok Pembahasan\s*:\s*<\/h6>(.+?)(?:<hr\b|$)/is', $this->contentBeforeAnchor($content, $joinLink), $matches)) {
                                    $material['description'] = $this->richTextToPlain($matches[1]);
                                }

                                if ($joinLink !== null) {
                                    $material['join_link'] = [
                                        'href' => $joinLink->attr('href'),
                                        'text' => trim($joinLink->text()),
                                    ];

                                    if ($material['meeting_number'] === null && preg_match('/Pertemuan\s+(\d+)/i', $material['join_link']['text'], $meetingMatches)) {
                                        $material['meeting_number'] = (int) $meetingMatches[1];
                                    }
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
     * The material's "Join Pertemuan N" link to its act=discuss page
     *
     * The description written above it is lecturer rich text that may hold
     * links of its own (Meet, Zoom, s.id, Drive), so the cell's first anchor
     * is not necessarily the meeting link. The join button comes last.
     */
    private function meetingJoinAnchor(Crawler $cell): ?Crawler
    {
        $discussLinks = $cell->filter('a[href*="act=discuss"]');

        if ($discussLinks->count() > 0) {
            return $discussLinks->last();
        }

        $joinLinks = $cell->filter('a')->reduce(
            static fn (Crawler $anchor): bool => preg_match('/Join\s+Pertemuan/i', $anchor->text('')) === 1
        );

        return $joinLinks->count() > 0 ? $joinLinks->last() : null;
    }

    /**
     * Serialized cell HTML up to the given anchor (all of it without one)
     */
    private function contentBeforeAnchor(string $content, ?Crawler $anchor): string
    {
        if ($anchor === null) {
            return $content;
        }

        $position = strrpos($content, $anchor->outerHtml());

        return $position === false ? $content : substr($content, 0, $position);
    }

    /**
     * Extract meeting information from meeting page
     *
     * `class_ref` holds the link parameters of the class page the breadcrumb
     * leads back to (`Beranda > Kelas <nama_mk> > Pertemuan <n>`), or null
     * when the breadcrumb has no class link.
     *
     * @return array{title: string, breadcrumb: string, class_ref: array{id: string, dosen_id: string, course_id: string}|null}
     */
    private function extractMeetingInfo(Crawler $crawler): array
    {
        $meetingInfo = [
            'title' => '',
            'breadcrumb' => '',
            'class_ref' => null,
        ];

        try {
            // Extract breadcrumb/title
            $titleElement = $crawler->filter('.header .title');
            if ($titleElement->count() > 0) {
                $meetingInfo['breadcrumb'] = trim($titleElement->text());
                $meetingInfo['class_ref'] = $this->breadcrumbClassRef($titleElement->first());
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
     * Link parameters of the breadcrumb's class page link (act=download)
     *
     * @return array{id: string, dosen_id: string, course_id: string}|null
     */
    private function breadcrumbClassRef(Crawler $breadcrumb): ?array
    {
        foreach ($breadcrumb->filter('a[href]') as $anchor) {
            $href = html_entity_decode((string) $anchor->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

            if (($query['act'] ?? null) !== 'download') {
                continue;
            }

            $classRef = [];

            foreach (['id', 'dosen_id', 'course_id'] as $param) {
                $value = $query[$param] ?? null;

                if (! is_string($value) || ! ctype_digit($value)) {
                    return null;
                }

                $classRef[$param] = $value;
            }

            return $classRef;
        }

        return null;
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
     *
     * `status` and `time` are the portal's words; `state` is what they mean
     * (MeetingAttendanceState): "Waktu Absensi Tidak Tersedia" is an
     * unavailable window, never an absence.
     *
     * @return array{status: string, time: string, state: string}
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

                        // "Hadir Absen Jam : 18:36:31" → status "Hadir", time "18:36:31"
                        if (preg_match('/^(.*?)\s*Absen Jam\s*:\s*(\d{2}:\d{2}(?::\d{2})?)/i', $value, $matches)) {
                            $attendanceInfo['status'] = trim($matches[1]) !== '' ? trim($matches[1]) : 'Hadir';
                            $attendanceInfo['time'] = $matches[2];
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Ignore extraction errors
        }

        $attendanceInfo['state'] = MeetingAttendanceState::fromMeetingPage($attendanceInfo['status'], $attendanceInfo['time'])->value;

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
            'status_text' => '',
        ];

        try {
            $crawler->filter('.card .content table tr')->each(function (Crawler $row) use (&$assignmentInfo) {
                $cells = $row->filter('td');
                if ($cells->count() >= 3) {
                    $label = trim(strtolower($cells->eq(0)->text()));

                    if (strpos($label, 'upload tugas') !== false) {
                        $valueCell = $this->labelledValueCell($cells);
                        $value = $this->normalizePortalText($valueCell->text(''));
                        $linkElement = $valueCell->filter('a');
                        $linkText = $linkElement->count() > 0 ? $this->normalizePortalText($linkElement->text('')) : '';
                        $deadlineText = $linkText !== '' ? $this->normalizePortalText(str_replace($linkText, '', $value)) : $value;

                        // The value is "Batas waktu upload tugas ... Sd <date>" next to the
                        // upload button, or "Tidak ada tugas perkuliahan ..."; it is a
                        // deadline only when it names a date, and the button's label is not
                        // part of it (the due date also has its own h4).
                        $assignmentInfo['deadline'] = $this->parseIndonesianDate($deadlineText) !== null ? $deadlineText : '';
                        $assignmentInfo['status_text'] = $value;

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
     * Value cell of a "label | : | value" row; a row without the ":" cell
     * keeps its value in the second cell
     */
    private function labelledValueCell(Crawler $cells): Crawler
    {
        $separator = $this->normalizePortalText($cells->eq(1)->text(''));

        return $cells->count() >= 3 && ($separator === ':' || $separator === '') ? $cells->eq(2) : $cells->eq(1);
    }

    /**
     * Extract conformity information
     *
     * The buttons are the portal's `act=kesesuaian` links ("Perkuliahan Ada,
     * Materi Sesuai" / "... Tidak Sesuai"). Other rows of the info table link
     * the RPS or the upload form in the same cells, and a student who has not
     * attended gets no buttons at all.
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

                    $leftLink = $leftCell->filter('a[href*="act=kesesuaian"]');
                    if ($leftLink->count() > 0) {
                        $conformityInfo['yes_button'] = [
                            'href' => $leftLink->attr('href'),
                            'text' => trim($leftLink->text()),
                        ];
                    }

                    $rightLink = $rightCell->filter('a[href*="act=kesesuaian"]');
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
                        $materials['main_topic'] = $this->richTextToPlain($matches[1]);
                    }

                    // Extract main description
                    if (preg_match('/<h5>\s*Uraian Pokok Pembahasan\s*:\s*<\/h5>(.+?)<h4\b/is', $content, $matches)) {
                        $materials['main_description'] = $this->richTextToPlain($matches[1]);
                    }

                    // Extract main material iframe
                    if (preg_match('/<iframe[^>]*src="([^"]*modul[^"]*)"[^>]*>/i', $content, $matches)) {
                        $materials['main_material_iframe'] = $matches[1];
                    }

                    // Extract assignment topic
                    if (preg_match('/<h4>\s*Instruksi Tugas Perkuliahan\s+([^:<]+?)\s*:\s*<\/h4>/i', $content, $matches)) {
                        $topic = $this->richTextToPlain($matches[1]);
                        $materials['assignment_topic'] = str_starts_with(strtolower($topic), 'pertemuan')
                            ? $topic
                            : 'Pertemuan '.$topic;
                    }

                    // Extract assignment instructions and due date
                    if (preg_match('/<h4>\s*Due Date Tugas\s+([^<]+?)\s*<\/h4>/i', $content, $matches)) {
                        $materials['assignment_instructions'] = $this->normalizePortalText($matches[1]);
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
     * Whether the class page names a course or lists its materials or
     * attendance; the page title and sidebar say nothing about the id
     *
     * @param  array<string, mixed>  $classDetail
     */
    private function classDetailHasContent(array $classDetail): bool
    {
        $courseInfo = is_array($classDetail['course_info'] ?? null) ? $classDetail['course_info'] : [];
        $code = is_string($courseInfo['code'] ?? null) ? $courseInfo['code'] : '';
        $name = is_string($courseInfo['name'] ?? null) ? $courseInfo['name'] : '';

        if (preg_match('/[A-Z1-9]/i', $code) === 1 || $this->namesSomething($name)) {
            return true;
        }

        return ($classDetail['course_materials'] ?? []) !== []
            || ($classDetail['attendance_info']['meetings'] ?? []) !== [];
    }

    /**
     * Whether a course name has words in it. The info row of an unknown id
     * can still print its template, " -  -  SKS", with every value empty.
     */
    private function namesSomething(string $courseName): bool
    {
        $words = (string) preg_replace('/\bSKS\b/iu', '', $courseName);

        return preg_match('/\p{L}/u', $words) === 1;
    }

    /**
     * @param  array<string, mixed>  $meetingDetail
     */
    private function meetingDetailHasContent(array $meetingDetail): bool
    {
        // meeting_info.title is the portal's page title ("SELAMAT DATANG DI
        // APLIKASI SiPANDAI...") even for unknown ids, so it says nothing.
        $signals = [
            $meetingDetail['meeting_info']['breadcrumb'] ?? null,
            $meetingDetail['lecturer_info']['name'] ?? null,
            $meetingDetail['schedule_info']['date'] ?? null,
            $meetingDetail['materials']['main_topic'] ?? null,
            $meetingDetail['materials']['assignment_topic'] ?? null,
            $meetingDetail['attendance_info']['status'] ?? null,
        ];

        foreach ($signals as $signal) {
            if (is_string($signal) && trim($signal) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Rich portal HTML as readable text: block boundaries become line breaks,
     * entities (&amp;, &nbsp;) are decoded, whitespace is collapsed per line.
     */
    private function richTextToPlain(string $html): string
    {
        $withBreaks = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6]|/tr)\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{A0}", ' ', $text);

        $lines = array_filter(array_map(
            static fn (string $line): string => trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line),
            explode("\n", $text)
        ), static fn (string $line): bool => $line !== '');

        return implode("\n", $lines);
    }

    /**
     * The portal marks every meeting the student has not attended as absent,
     * including meetings that have not happened yet. A meeting without
     * material and without an attendance record is still upcoming, and so
     * is one whose material a lecturer posted ahead of its date (WIB).
     *
     * @param  array<string, mixed>  $attendanceInfo
     * @param  list<array<string, mixed>>  $courseMaterials
     * @return array<string, mixed>
     */
    private function markUpcomingMeetings(array $attendanceInfo, array $courseMaterials): array
    {
        $meetings = $attendanceInfo['meetings'] ?? [];

        if (! is_array($meetings) || $meetings === []) {
            return $attendanceInfo;
        }

        $today = CarbonImmutable::instance(now())->setTimezone(self::PORTAL_TIMEZONE)->startOfDay();
        $heldMeetings = 0;
        foreach ($courseMaterials as $index => $material) {
            $materialDate = is_string($material['date'] ?? null) ? $this->parseIndonesianDate($material['date']) : null;

            if ($materialDate !== null && $materialDate->greaterThan($today)) {
                continue;
            }

            $heldMeetings = max($heldMeetings, (int) ($material['meeting_number'] ?? ($index + 1)));
        }
        foreach ($meetings as $meeting) {
            $details = is_array($meeting['details'] ?? null) ? $meeting['details'] : [];
            if (in_array($meeting['status'] ?? null, ['present', 'late', 'permit', 'sick'], true) || isset($details['attendance_date'])) {
                $heldMeetings = max($heldMeetings, (int) ($meeting['meeting_number'] ?? 0));
            }
        }

        $upcoming = 0;
        $upcomingFromAbsent = 0;
        foreach ($meetings as $index => $meeting) {
            $details = is_array($meeting['details'] ?? null) ? $meeting['details'] : [];
            $isUpcoming = (int) ($meeting['meeting_number'] ?? 0) > $heldMeetings
                && in_array($meeting['status'] ?? null, ['absent', 'unknown'], true)
                && ! isset($details['attendance_date']);

            if ($isUpcoming) {
                // Only an absent meeting was counted in summary.absent; an unknown one never was.
                if ($meeting['status'] === 'absent') {
                    $upcomingFromAbsent++;
                }

                $meetings[$index]['status'] = 'upcoming';
                $upcoming++;
            }
        }

        $attendanceInfo['meetings'] = $meetings;

        if (is_array($attendanceInfo['summary'] ?? null) && $attendanceInfo['summary'] !== []) {
            $summary = $attendanceInfo['summary'];
            $held = max(0, (int) ($summary['total_meetings'] ?? count($meetings)) - $upcoming);
            $attended = (int) ($summary['present'] ?? 0);
            $summary['upcoming'] = $upcoming;
            $summary['held_meetings'] = $held;
            $summary['absent'] = max(0, (int) ($summary['absent'] ?? 0) - $upcomingFromAbsent);
            $summary['attendance_rate'] = $held > 0 ? round(($attended / $held) * 100, 1) : 0;
            $attendanceInfo['summary'] = $summary;
        }

        return $attendanceInfo;
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

                    if (strpos($label, 'batas waktu upload') !== false) {
                        $instructions['deadline_text'] = $this->normalizePortalText($this->labelledValueCell($cells)->text(''));
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
