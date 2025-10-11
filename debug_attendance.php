<?php

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

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
                </tr>
                <tr>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td>
                        <button bgcolor="red" type="button" rel="tooltip" title="Senin, 06 Oktober 2025, Jam 14:06:56">
                            <a href="">H</a>
                        </button>
                    </td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>';

$crawler = new Crawler($html);

echo "=== Debug Attendance Extraction ===\n";

// Test 1: Find attendance card
$cards = $crawler->filter('.card');
echo "Found {$cards->count()} card(s)\n";

$cards->each(function (Crawler $card, $cardIndex) {
    echo "\n--- Card {$cardIndex} ---\n";

    // Test 2: Find header
    $headerText = trim($card->filter('.header .title, .header h6')->text(''));
    echo "Header text: '{$headerText}'\n";

    if (strpos($headerText, 'Presensi Kehadiran') !== false) {
        $studentName = trim(str_replace('Presensi Kehadiran', '', $headerText));
        echo "Student name: '{$studentName}'\n";

        // Test 3: Find table
        $table = $card->filter('table')->first();
        echo 'Found table: '.($table->count() > 0 ? 'YES' : 'NO')."\n";

        if ($table->count() > 0) {
            // Test 4: Extract headers
            $headerRow = $table->filter('tr')->first();
            $headers = [];
            $headerRow->filter('th')->each(function (Crawler $th) use (&$headers) {
                $headerText = trim($th->text());
                echo "Header: '{$headerText}'\n";
                if (preg_match('/P\.(\d+)/', $headerText, $matches)) {
                    $headers[] = [
                        'meeting_number' => intval($matches[1]),
                        'header_text' => $headerText,
                    ];
                    echo "  -> Meeting number: {$matches[1]}\n";
                }
            });

            echo 'Total headers found: '.count($headers)."\n";

            // Test 5: Extract data rows
            $dataRows = $table->filter('tr')->slice(1);
            echo "Data rows found: {$dataRows->count()}\n";

            $meetingData = [];
            $dataRows->each(function (Crawler $row) use ($headers, &$meetingData) {
                $cells = $row->filter('td');
                echo "Row cells: {$cells->count()}\n";

                $cells->each(function (Crawler $cell, $index) use ($headers, &$meetingData) {
                    if (isset($headers[$index])) {
                        $meetingInfo = $headers[$index];
                        $status = 'unknown';
                        $details = [];

                        echo "Cell {$index} (Meeting {$meetingInfo['meeting_number']}):\n";

                        // Check for image
                        $img = $cell->filter('img');
                        if ($img->count() > 0) {
                            $imgSrc = $img->attr('src');
                            $details['type'] = 'image';
                            $details['image_src'] = $imgSrc;
                            echo "  -> Image: {$imgSrc}\n";

                            if (strpos($imgSrc, 'bolos.jpg') !== false) {
                                $status = 'absent';
                                echo "  -> Status: ABSENT\n";
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
                            echo "  -> Button: '{$buttonText}' - '{$buttonTitle}'\n";

                            if ($buttonText === 'H') {
                                $status = 'present';
                                echo "  -> Status: PRESENT\n";
                            }
                        }

                        $meetingData[] = [
                            'meeting_number' => $meetingInfo['meeting_number'],
                            'status' => $status,
                            'details' => $details,
                        ];
                    }
                });
            });

            echo 'Total meetings extracted: '.count($meetingData)."\n";

            // Display results
            echo "\n=== Final Results ===\n";
            foreach ($meetingData as $meeting) {
                echo "Meeting {$meeting['meeting_number']}: {$meeting['status']}\n";
                if (! empty($meeting['details'])) {
                    echo '  Details: '.json_encode($meeting['details'], JSON_PRETTY_PRINT)."\n";
                }
            }
        }
    }
});

echo "\n=== Debug Complete ===\n";
