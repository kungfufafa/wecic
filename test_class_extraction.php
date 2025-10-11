<?php

require_once __DIR__.'/vendor/autoload.php';

use App\Services\Extractors\SipandaiClassesExtractor;
use App\Services\Extractors\StudentCourseExtractor;

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';

// Create a request to simulate web middleware
$request = Illuminate\Http\Request::create('/test', 'GET');
$app->instance('request', $request);

// Bootstrap the application
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing Class Extraction from Both Endpoints\n";
echo "==============================================\n\n";

// You need to have a valid session token for this to work
// For testing purposes, you'll need to login first through the web interface
$sessionToken = session('campus_session_token');

if (! $sessionToken) {
    echo "❌ No active campus session found. Please login first through the web interface.\n";
    echo "   Visit: http://localhost:8000/login and authenticate with your campus credentials.\n";
    exit(1);
}

echo "✅ Found active campus session\n\n";

try {
    // Test SIPANDAI Classes Extractor
    echo "1. Testing SIPANDAI Dashboard Classes Extractor\n";
    echo "   URL: https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=dashboardmahasiswa\n";

    $sipandaiExtractor = new SipandaiClassesExtractor;
    $sipandaiData = $sipandaiExtractor->extractSipandaiClassesData($sessionToken);

    echo "✅ SIPANDAI extraction successful\n";
    echo '   - Classes found: '.count($sipandaiData['classes'])."\n";
    echo '   - Semester: '.($sipandaiData['semester_info']['semester'] ?? 'Unknown')."\n";

    if (! empty($sipandaiData['classes'])) {
        $firstClass = $sipandaiData['classes'][0];
        echo '   - First class: '.($firstClass['course_info']['name'] ?? 'Unknown')."\n";
        echo '   - Schedule: '.($firstClass['schedule']['day'] ?? 'Unknown').' '.($firstClass['schedule']['time'] ?? 'Unknown')."\n";
        echo '   - Lecturer: '.($firstClass['lecturer_info']['name'] ?? 'Unknown')."\n";
    }
    echo "\n";

    // Test Student Course Extractor
    echo "2. Testing Student Course Extractor\n";
    echo "   URL: https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=course\n";

    $studentCourseExtractor = new StudentCourseExtractor;
    $studentCourseData = $studentCourseExtractor->extractStudentCourseData($sessionToken);

    echo "✅ Student course extraction successful\n";
    echo '   - Classes found: '.count($studentCourseData['classes'])."\n";
    echo '   - Semester: '.($studentCourseData['semester_info']['semester'] ?? 'Unknown')."\n";

    if (! empty($studentCourseData['classes'])) {
        $firstClass = $studentCourseData['classes'][0];
        echo '   - First class: '.($firstClass['course_info']['name'] ?? 'Unknown')."\n";
        echo '   - Schedule: '.($firstClass['schedule']['day'] ?? 'Unknown').' '.($firstClass['schedule']['time'] ?? 'Unknown')."\n";
        echo '   - Class & Room: '.($firstClass['class_room_info']['class_name'] ?? 'Unknown').' - '.($firstClass['class_room_info']['room'] ?? 'Unknown')."\n";
        echo '   - Lecturer: '.($firstClass['lecturer_info']['name'] ?? 'Unknown')."\n";
    }
    echo "\n";

    // Test Merged Data
    echo "3. Testing Merged Classes Data\n";
    $mergedData = $studentCourseExtractor->getMergedClassesData($sessionToken);

    echo "✅ Merged data extraction successful\n";
    echo '   - Total classes: '.count($mergedData['classes'])."\n";
    echo '   - Data sources: '.implode(', ', array_keys(array_filter($mergedData['data_sources'])))."\n";

    if (! empty($mergedData['classes'])) {
        $firstClass = $mergedData['classes'][0];
        echo '   - First class: '.($firstClass['course_info']['name'] ?? 'Unknown')."\n";
        echo '   - Schedule: '.($firstClass['schedule']['day'] ?? 'Unknown').' '.($firstClass['schedule']['time'] ?? 'Unknown')."\n";

        if (isset($firstClass['class_room_info'])) {
            echo '   - Class & Room: '.($firstClass['class_room_info']['class_name'] ?? 'Unknown').' - '.($firstClass['class_room_info']['room'] ?? 'Unknown')."\n";
        } else {
            echo "   - Room info: Not available\n";
        }

        echo '   - Lecturer: '.($firstClass['lecturer_info']['name'] ?? 'Unknown')."\n";
        echo '   - Join link: '.(empty($firstClass['join_link']['link']) ? 'Not available' : 'Available')."\n";
    }
    echo "\n";

    echo "🎉 All tests completed successfully!\n";
    echo "\nAvailable API Endpoints:\n";
    echo "- GET /api/campus/sipandai-classes - SIPANDAI dashboard classes (with join links)\n";
    echo "- GET /api/campus/student-course - Student course classes (with room info)\n";
    echo "- GET /api/campus/merged-classes - Merged data from both endpoints\n";

} catch (Exception $e) {
    echo '❌ Error during testing: '.$e->getMessage()."\n";
    echo '   File: '.$e->getFile().':'.$e->getLine()."\n";
    echo "\nThis might be due to:\n";
    echo "1. Expired session - Try logging in again\n";
    echo "2. Network connectivity issues\n";
    echo "3. Changes in the campus website structure\n";
}
