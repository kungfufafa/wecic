<?php

require_once __DIR__.'/vendor/autoload.php';

// Initialize Laravel app
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\CampusSessionManager;
use App\Services\Extractors\ClassesExtractor;

// Test with actual session and extraction
$sessionManager = app(CampusSessionManager::class);
$extractor = new ClassesExtractor($sessionManager);

// Test session first
echo "=== Testing Campus Session ===\n";

// Try to get session token (simulate)
$sessionToken = 'test_token'; // This would normally come from session()

echo 'Session token: '.(strlen($sessionToken) > 0 ? 'EXISTS' : 'MISSING')."\n";

// Test extraction with simulated session
echo "\n=== Testing Extraction Logic ===\n";

// Use the debug HTML for course 2265
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
                </tr>
                <tr>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                    <td><img src="../images/bolos.jpg" width="30"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>';

try {
    // Simulate the createCrawler method
    $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

    // Test the actual extraction method
    $reflection = new ReflectionClass($extractor);
    $method = $reflection->getMethod('extractAttendanceInfo');
    $method->setAccessible(true);

    $result = $method->invoke($extractor, $crawler);

    echo "Extraction result:\n";
    echo json_encode($result, JSON_PRETTY_PRINT);

    echo "\n\nExtracted meetings count: ".count($result['meetings'])."\n";
    echo 'Summary exists: '.(! empty($result['summary']) ? 'YES' : 'NO')."\n";

} catch (Exception $e) {
    echo 'Extraction failed: '.$e->getMessage()."\n";
    echo "Stack trace:\n".$e->getTraceAsString()."\n";
}

echo "\n=== Testing Different HTML Structures ===\n";

// Test 1: Course 2265 structure (all absent)
$html1 = '
<div class="card">
    <div class="header">
        <h6 class="title">Presensi Kehadiran Apriansyah Rizqi Setiawan</h6>
    </div>
    <div class="content table-responsive table-full-width">
        <table class="table table-bordered">
            <tbody><tr><th>P.1</th><th>P.2</th></tr>
            <tr><td><img src="../images/bolos.jpg"></td><td><img src="../images/bolos.jpg"></td></tr>
        </tbody></table>
    </div>
</div>';

$crawler1 = new \Symfony\Component\DomCrawler\Crawler($html1);
$result1 = $method->invoke($extractor, $crawler1);
echo 'Course 2265 structure: '.count($result1['meetings'])." meetings\n";

// Test 2: Course 3142 structure (mixed)
$html2 = '
<div class="card">
    <div class="header">
        <h6 class="title">Presensi Kehadiran Apriansyah Rizqi Setiawan</h6>
    </div>
    <div class="content table-responsive table-full-width">
        <table class="table table-bordered">
            <tbody><tr><th>P.1</th><th>P.2</th></tr>
            <tr>
                <td><img src="../images/bolos.jpg"></td>
                <td><button type="button">H</button></td>
            </tr>
        </tbody></table>
    </div>
</div>';

$crawler2 = new \Symfony\Component\DomCrawler\Crawler($html2);
$result2 = $method->invoke($extractor, $crawler2);
echo 'Course 3142 structure: '.count($result2['meetings'])." meetings\n";

echo "\n=== Debug Complete ===\n";
