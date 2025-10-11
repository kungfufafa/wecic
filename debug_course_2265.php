<?php

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

// Simulate the HTML structure from course 2265 (Bahasa Indonesia)
$html = '
<div class="card">
    <div class="header">
        <h6 class="title">Presensi Kehadiran  Apriansyah Rizqi Setiawan</h6>
    </div>
    <div class="content table-responsive table-full-width">
        <table class="table table-bordered">
            <tbody>
                <tr>
                    <th>P.1 <br></th>
                    <th>P.2 <br></th>
                    <th>P.3 <br></th>
                    <th>P.4 <br></th>
                    <th>P.5 <br></th>
                    <th>P.6 <br></th>
                    <th>P.7 <br></th>
                    <th>P.8 <br></th>
                    <th>P.9 <br></th>
                    <th>P.10 <br></th>
                    <th>P.11 <br></th>
                    <th>P.12 <br></th>
                    <th>P.13 <br></th>
                    <th>P.14 <br></th>
                </tr>
                <tr>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>';

$crawler = new Crawler($html);

echo "=== Debug Course 2265 (Bahasa Indonesia) Attendance ===\n";

// Test actual extraction logic
$attendanceInfo = [
    'student_name' => '',
    'meetings' => [],
    'summary' => [],
];

try {
    // Look for attendance table with Presensi Kehadiran
    $crawler->filter('.card')->each(function (Crawler $card) use (&$attendanceInfo) {
        $headerText = trim($card->filter('.header .title, .header h6')->text(''));
        echo "Header text: '{$headerText}'\n";

        // Extract student name from header
        if (strpos($headerText, 'Presensi Kehadiran') !== false) {
            $attendanceInfo['student_name'] = trim(str_replace('Presensi Kehadiran', '', $headerText));
            echo "Student name: '{$attendanceInfo['student_name']}'\n";

            // Extract table headers (P.1, P.2, etc.)
            $attendanceTable = $card->filter('table')->first();
            if ($attendanceTable->count() === 0) {
                echo "No table found!\n";

                return;
            }
            echo "Table found: YES\n";

            $headerRow = $attendanceTable->filter('tr')->first();

            $headers = [];
            $headerRow->filter('th')->each(function (Crawler $th) use (&$headers) {
                $headerText = trim($th->text());
                if (preg_match('/P\.(\d+)/', $headerText, $matches)) {
                    $headers[] = [
                        'meeting_number' => intval($matches[1]),
                        'header_text' => $headerText,
                    ];
                    echo "Header found: '{$headerText}' -> Meeting {$matches[1]}\n";
                }
            });

            echo 'Total headers: '.count($headers)."\n";

            // Extract attendance data from ALL data rows
            $dataRows = $attendanceTable->filter('tr')->slice(1);
            echo "Data rows: {$dataRows->count()}\n";

            $meetingData = [];

            $dataRows->each(function (Crawler $row) use ($headers, &$meetingData) {
                $cells = $row->filter('td');
                echo "Row cells: {$cells->count()}\n";

                $cells->each(function (Crawler $cell, $index) use ($headers, &$meetingData) {
                    if (isset($headers[$index])) {
                        $meetingInfo = $headers[$index];
                        $status = 'unknown';
                        $details = [];

                        echo "Processing cell {$index} (Meeting {$meetingInfo['meeting_number']}):\n";

                        // Check for image
                        $img = $cell->filter('img');
                        if ($img->count() > 0) {
                            $imgSrc = $img->attr('src');
                            $details['type'] = 'image';
                            $details['image_src'] = $imgSrc;
                            echo "  -> Image found: {$imgSrc}\n";

                            // Determine status from image
                            if (strpos($imgSrc, 'bolos.jpg') !== false) {
                                $status = 'absent';
                                echo "  -> Status: ABSENT\n";
                            } elseif (strpos($imgSrc, 'hadir.jpg') !== false || strpos($imgSrc, 'present.jpg') !== false) {
                                $status = 'present';
                                echo "  -> Status: PRESENT\n";
                            } elseif (strpos($imgSrc, 'izin.jpg') !== false || strpos($imgSrc, 'permit.jpg') !== false) {
                                $status = 'permit';
                                echo "  -> Status: PERMIT\n";
                            } elseif (strpos($imgSrc, 'sakit.jpg') !== false) {
                                $status = 'sick';
                                echo "  -> Status: SICK\n";
                            }
                        }

                        // Check for button
                        $button = $cell->filter('button');
                        if ($button->count() > 0) {
                            $buttonText = trim($button->text());
                            $buttonTitle = $button->attr('title');

                            $details['type'] = 'button';
                            $details['button_text'] = $buttonText;
                            $details['button_title'] = $buttonTitle;
                            echo "  -> Button found: '{$buttonText}' - '{$buttonTitle}'\n";

                            // Extract date from button title
                            if ($buttonTitle && preg_match('/(\w+),\s+(\d+)\s+(\w+)\s+(\d+),\s+Jam\s+(\d+:\d+)/', $buttonTitle, $matches)) {
                                $details['attendance_date'] = $matches[2].' '.$matches[3].' '.$matches[4];
                                $details['attendance_time'] = $matches[5];
                                echo "  -> Date extracted: {$details['attendance_date']}\n";
                            }

                            // Determine status from button text
                            if ($buttonText === 'H') {
                                $status = 'present';
                                echo "  -> Status: PRESENT (from button)\n";
                            } elseif ($buttonText === 'A' || $buttonText === 'α') {
                                $status = 'absent';
                                echo "  -> Status: ABSENT (from button)\n";
                            } elseif ($buttonText === 'I') {
                                $status = 'permit';
                                echo "  -> Status: PERMIT (from button)\n";
                            } elseif ($buttonText === 'S') {
                                $status = 'sick';
                                echo "  -> Status: SICK (from button)\n";
                            }
                        }

                        // Only add if we have valid data
                        if ($status !== 'unknown' || ! empty($details)) {
                            $meetingData[] = [
                                'meeting_number' => $meetingInfo['meeting_number'],
                                'status' => $status,
                                'details' => $details,
                            ];
                            echo "  -> Added meeting {$meetingInfo['meeting_number']}: {$status}\n";
                        } else {
                            echo "  -> No valid data, skipping meeting {$meetingInfo['meeting_number']}\n";
                        }
                    } else {
                        echo "Cell {$index}: No header found\n";
                    }
                });
            });

            $attendanceInfo['meetings'] = $meetingData;
            echo 'Total meetings extracted: '.count($meetingData)."\n";

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
                    'last_updated' => date('Y-m-d\TH:i:s.000\Z'),
                ];

                echo "Summary: {$presentCount}/{$totalMeetings} present ({$attendanceInfo['summary']['attendance_rate']}%)\n";
            } else {
                echo "No meeting data found!\n";
            }
        } else {
            echo "No attendance header found!\n";
        }
    });
} catch (Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
}

echo "\n=== Final Results ===\n";
echo json_encode($attendanceInfo, JSON_PRETTY_PRINT);

echo "\n=== Debug Complete ===\n";
