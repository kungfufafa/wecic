<?php

require_once __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';

// Create a request to simulate web middleware
$request = Illuminate\Http\Request::create('/test', 'GET');
$app->instance('request', $request);

// Bootstrap the application
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Test attendance parsing with actual HTML structure
$html = '<div class="card">
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
                </tr>
            </tbody>
            <tbody>
                <tr>
                    <td>
                        <button bgcolor="red" type="button" rel="tooltip" title="Jumat, 03 Oktober 2025, Jam 08:39:49 ">
                            <a href="">
                                H
                            </a>
                        </button>
                    </td>
                    <td>
                        <button bgcolor="red" type="button" rel="tooltip" title="Jumat, 10 Oktober 2025, Jam 09:17:08 ">
                            <a href="">
                                H
                            </a>
                        </button>
                    </td>
                    <td>
                        <img src="../images/bolos.jpg" width="30">
                    </td>
                    <td>
                        <img src="../images/bolos.jpg" width="30">
                    </td>
                    <td>
                        <img src="../images/bolos.jpg" width="30">
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>';

use Symfony\Component\DomCrawler\Crawler;

function parseAttendanceInfo($html)
{
    $crawler = new Crawler($html);

    $attendanceInfo = [
        'student_name' => '',
        'meetings' => [],
        'summary' => [],
    ];

    try {
        // Look for attendance table with Presensi Kehadiran
        $crawler->filter('.card')->each(function (Crawler $card) use (&$attendanceInfo) {
            $headerText = trim($card->filter('.header .title, .header h6')->text(''));

            // Extract student name from header
            if (strpos($headerText, 'Presensi Kehadiran') !== false) {
                $attendanceInfo['student_name'] = trim(str_replace('Presensi Kehadiran', '', $headerText));

                // Extract table headers (P.1, P.2, etc.)
                $attendanceTable = $card->filter('table')->first();
                $headerRow = $attendanceTable->filter('tr')->first();

                $headers = [];
                $headerRow->filter('th')->each(function (Crawler $th) use (&$headers) {
                    $headerText = trim($th->text());
                    // Handle both "P.1" and "P.1 <br>" formats
                    $cleanHeaderText = preg_replace('/<br\s*\/?>/', '', $headerText);
                    $cleanHeaderText = trim($cleanHeaderText);

                    if (preg_match('/P\.(\d+)/', $cleanHeaderText, $matches)) {
                        $headers[] = [
                            'meeting_number' => intval($matches[1]),
                            'header_text' => $cleanHeaderText,
                        ];
                    }
                });

                // Extract attendance data - look for data rows (skip header row)
                $allRows = $attendanceTable->filter('tr');
                $dataRows = $allRows->slice(1);
                $meetingData = [];

                $dataRows->each(function (Crawler $row) use ($headers, &$meetingData) {
                    $cells = $row->filter('td');

                    foreach ($cells as $index => $cell) {
                        if (isset($headers[$index])) {
                            $meetingInfo = $headers[$index];
                            $cellCrawler = new Crawler($cell);

                            // Check for attendance status
                            $status = 'unknown';
                            $details = [];

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
                                    // If button is red but text is 'H', it's late
                                    $status = ($buttonBgColor === 'red' || $buttonBgColor === '#FF0000') ? 'late' : 'present';
                                } elseif ($buttonText === 'A' || $buttonText === 'α') {
                                    $status = 'absent';
                                } elseif ($buttonText === 'I') {
                                    $status = 'permit';
                                } elseif ($buttonText === 'S') {
                                    $status = 'sick';
                                }
                            }

                            // Check for image status
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

                            $meetingData[] = [
                                'meeting_number' => $meetingInfo['meeting_number'],
                                'status' => $status,
                                'details' => $details,
                            ];
                        }
                    }
                });

                $attendanceInfo['meetings'] = $meetingData;

                // Create summary
                $summary = [
                    'total_meetings' => count($meetingData),
                    'present' => count(array_filter($meetingData, fn ($m) => $m['status'] === 'present')),
                    'late' => count(array_filter($meetingData, fn ($m) => $m['status'] === 'late')),
                    'absent' => count(array_filter($meetingData, fn ($m) => $m['status'] === 'absent')),
                    'permit' => count(array_filter($meetingData, fn ($m) => $m['status'] === 'permit')),
                    'sick' => count(array_filter($meetingData, fn ($m) => $m['status'] === 'sick')),
                    'unknown' => count(array_filter($meetingData, fn ($m) => $m['status'] === 'unknown')),
                ];

                $attendanceInfo['summary'] = $summary;
            }
        });
    } catch (\Exception $e) {
        // Handle parsing errors
        $attendanceInfo['error'] = $e->getMessage();
    }

    return $attendanceInfo;
}

echo "Testing Attendance Parsing\n";
echo "==========================\n\n";

$result = parseAttendanceInfo($html);

echo "Student Name: '{$result['student_name']}'\n\n";

echo "Meetings Data:\n";
foreach ($result['meetings'] as $meeting) {
    echo "P.{$meeting['meeting_number']}: {$meeting['status']}\n";
    if (! empty($meeting['details'])) {
        foreach ($meeting['details'] as $key => $value) {
            echo "  {$key}: {$value}\n";
        }
    }
    echo "\n";
}

echo "Summary:\n";
foreach ($result['summary'] as $key => $value) {
    echo "  {$key}: {$value}\n";
}

echo "\nExpected Results:\n";
echo "- Student Name: 'Apriansyah Rizqi Setiawan'\n";
echo "- P.1: late (red button with 'H')\n";
echo "- P.2: late (red button with 'H')\n";
echo "- P.3: absent (bolos.jpg image)\n";
echo "- P.4: absent (bolos.jpg image)\n";
echo "- P.5: absent (bolos.jpg image)\n";
echo "- Total: 5 meetings\n";
echo "- Late: 2, Absent: 3\n";
