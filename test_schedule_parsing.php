<?php

// Test schedule parsing regex patterns
$testCases = [
    'Senin 09:30:00-11:00:00',
    'Senin  09:30:00-11:00:00',  // double space
    'Senin 09:30:00-11:00:00 ', // trailing space
    'Selasa   14:30:00-16:00:00', // triple space
    'RABU 08:00:00-09:30:00', // uppercase
    'Kamis &nbsp;13:30:00-15:00:00', // HTML entity
    "Jumat\u{00A0}07:30:00-09:30:00\u{00A0}", // Unicode NBSP
    'Sabtu 10:00:00-12:00:00', // regular
    'Jumat', // day only
];

function parseSchedule($text)
{
    // Clean up the text first
    $text = str_replace('&nbsp;', ' ', $text); // Replace HTML entities with spaces
    $text = str_replace("\u{00A0}", ' ', $text); // Replace Unicode non-breaking spaces
    $text = str_replace("\xC2\xA0", ' ', $text); // Replace UTF-8 non-breaking spaces
    $text = preg_replace('/\s+/', ' ', $text); // Replace multiple spaces with single space
    $text = trim($text);

    echo "Original: '{$text}'\n";

    // Extract day and time from various formats
    if (preg_match('/(\w+)\s+(\d{2}:\d{2}:\d{2}-\d{2}:\d{2}:\d{2})/', $text, $matches)) {
        $day = ucfirst(strtolower($matches[1]));
        $timeRange = $matches[2];

        // Parse start and end time
        if (preg_match('/(\d{2}:\d{2}:\d{2})-(\d{2}:\d{2}:\d{2})/', $timeRange, $timeMatches)) {
            $formattedTime = formatTimeRange($timeMatches[1], $timeMatches[2]);

            return [
                'day' => $day,
                'start_time' => $timeMatches[1],
                'end_time' => $timeMatches[2],
                'time_range' => $timeRange,
                'formatted_time' => $formattedTime,
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

function formatTimeRange($startTime, $endTime)
{
    // Convert "09:30:00" to "09:30" and "11:00:00" to "11:00"
    $start = substr($startTime, 0, 5);
    $end = substr($endTime, 0, 5);

    return "{$start} - {$end}";
}

echo "Testing Schedule Parsing Regex\n";
echo "==============================\n\n";

foreach ($testCases as $i => $test) {
    echo 'Test '.($i + 1).": '{$test}'\n";
    $result = parseSchedule($test);

    if (isset($result['day'])) {
        echo "✅ Day: '{$result['day']}'\n";
    }

    if (isset($result['formatted_time'])) {
        echo "✅ Time: '{$result['formatted_time']}'\n";
    } elseif (isset($result['time_range'])) {
        echo "⚠️  Time Range: '{$result['time_range']}'\n";
    }

    if (isset($result['start_time'])) {
        echo "✅ Start: '{$result['start_time']}'\n";
    }

    if (isset($result['end_time'])) {
        echo "✅ End: '{$result['end_time']}'\n";
    }

    echo "📄 Raw: '{$result['raw']}'\n";
    echo "---\n";
}

echo "\nExpected Results:\n";
echo "Test 1: Senin, 09:30 - 11:00\n";
echo "Test 2: Senin, 09:30 - 11:00 (should work with double space)\n";
echo "Test 3: Senin, 09:30 - 11:00 (should work with trailing space)\n";
echo "Test 4: Selasa, 14:30 - 16:00 (should work with triple space)\n";
echo "Test 5: Rabu, 08:00 - 09:30 (should normalize case)\n";
echo "Test 6: Kamis, 13:30 - 15:00 (should handle HTML entity)\n";
echo "Test 7: Jumat (day only fallback)\n";
