<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Interactive API Testing Script for CIC Portal Scraping
 *
 * This script allows you to test all API endpoints and see the actual scraped data
 */

class CICApiTester
{
    private string $baseUrl = 'http://localhost:8000';
    private string $cookieFile = '/tmp/cic_api_test_cookies.txt';
    private array $credentials = [
        'username' => '20251030030',
        'password' => 'Kanjutz123@'
    ];

    public function __construct()
    {
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
        echo "🚀 CIC Portal API Tester\n";
        echo "========================\n\n";
    }

    public function run(): void
    {
        echo "📋 Available Tests:\n";
        echo "1. Test Complete Flow (Login + All APIs)\n";
        echo "2. Test Dashboard API Only\n";
        echo "3. Test Profile API Only\n";
        echo "4. Test Classes API Only\n";
        echo "5. Test Class Detail API\n";
        echo "6. Test Meeting Detail API\n";
        echo "7. Test Specific Class Details\n";
        echo "8. Show Raw Response Data\n";
        echo "9. Exit\n\n";

        while (true) {
            echo "Select test (1-9): ";
            $choice = trim(fgets(STDIN));

            switch ($choice) {
                case '1':
                    $this->testCompleteFlow();
                    break;
                case '2':
                    $this->testDashboard();
                    break;
                case '3':
                    $this->testProfile();
                    break;
                case '4':
                    $this->testClasses();
                    break;
                case '5':
                    $this->testClassDetail();
                    break;
                case '6':
                    $this->testMeetingDetail();
                    break;
                case '7':
                    $this->testSpecificClasses();
                    break;
                case '8':
                    $this->showRawData();
                    break;
                case '9':
                    echo "👋 Goodbye!\n";
                    return;
                default:
                    echo "❌ Invalid choice. Please try again.\n\n";
            }

            echo "\n" . str_repeat("=", 50) . "\n\n";
        }
    }

    private function login(): bool
    {
        echo "🔐 Logging in...\n";

        // Get CSRF token first
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $loginPage = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo "❌ Failed to get login page (HTTP $httpCode)\n";
            return false;
        }

        // Extract CSRF token
        $csrfToken = '';
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $loginPage, $matches)) {
            $csrfToken = $matches[1];
        }

        if (!$csrfToken) {
            echo "❌ Could not extract CSRF token\n";
            return false;
        }

        // Perform login
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->credentials));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CSRF-TOKEN: ' . $csrfToken
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['status']) && $data['status'] === 'success') {
                echo "✅ Login successful!\n";
                echo "   Session Token: " . substr($data['data']['session_token'] ?? 'N/A', 0, 20) . "...\n";
                return true;
            }
        }

        echo "❌ Login failed (HTTP $httpCode)\n";
        echo "   Response: " . substr($response, 0, 200) . "...\n";
        return false;
    }

    private function makeApiRequest(string $endpoint): ?array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        echo "❌ API request failed (HTTP $httpCode)\n";
        echo "   Response: " . substr($response, 0, 200) . "...\n";
        return null;
    }

    private function testCompleteFlow(): void
    {
        echo "🔄 Testing Complete API Flow\n";
        echo "----------------------------\n";

        if (!$this->login()) {
            return;
        }

        $endpoints = [
            'Dashboard' => '/api/campus/dashboard',
            'Profile' => '/api/campus/profile',
            'Classes' => '/api/campus/classes'
        ];

        foreach ($endpoints as $name => $endpoint) {
            echo "\n📊 Testing $name API...\n";
            $data = $this->makeApiRequest($endpoint);

            if ($data && isset($data['status']) && $data['status'] === 'success') {
                echo "✅ $name API - Success!\n";
                $this->showDataSummary($name, $data);
            } else {
                echo "❌ $name API - Failed\n";
            }
        }

        echo "\n🎉 Complete flow test finished!\n";
    }

    private function testDashboard(): void
    {
        echo "📊 Testing Dashboard API\n";
        echo "----------------------\n";

        if (!$this->login()) {
            return;
        }

        $data = $this->makeApiRequest('/api/campus/dashboard');

        if ($data && isset($data['status']) && $data['status'] === 'success') {
            echo "✅ Dashboard API - Success!\n\n";
            $this->displayDashboardData($data);
        }
    }

    private function testProfile(): void
    {
        echo "👤 Testing Profile API\n";
        echo "---------------------\n";

        if (!$this->login()) {
            return;
        }

        $data = $this->makeApiRequest('/api/campus/profile');

        if ($data && isset($data['status']) && $data['status'] === 'success') {
            echo "✅ Profile API - Success!\n\n";
            $this->displayProfileData($data);
        }
    }

    private function testClasses(): void
    {
        echo "📚 Testing Classes API\n";
        echo "---------------------\n";

        if (!$this->login()) {
            return;
        }

        $data = $this->makeApiRequest('/api/campus/classes');

        if ($data && isset($data['status']) && $data['status'] === 'success') {
            echo "✅ Classes API - Success!\n\n";
            $this->displayClassesData($data);
        }
    }

    private function testClassDetail(): void
    {
        echo "📝 Testing Class Detail API\n";
        echo "---------------------------\n";

        if (!$this->login()) {
            return;
        }

        echo "Enter Class ID: ";
        $classId = trim(fgets(STDIN));
        echo "Enter Lecturer ID: ";
        $dosenId = trim(fgets(STDIN));
        echo "Enter Course ID: ";
        $courseId = trim(fgets(STDIN));

        $endpoint = "/api/campus/class-detail-by-id?class_id=$classId&dosen_id=$dosenId&course_id=$courseId";
        $data = $this->makeApiRequest($endpoint);

        if ($data && isset($data['status']) && $data['status'] === 'success') {
            echo "✅ Class Detail API - Success!\n\n";
            $this->displayClassDetailData($data);
        }
    }

    private function testMeetingDetail(): void
    {
        echo "🎯 Testing Meeting Detail API\n";
        echo "-----------------------------\n";

        if (!$this->login()) {
            return;
        }

        echo "Enter Meeting ID: ";
        $meetingId = trim(fgets(STDIN));

        $endpoint = "/api/campus/meeting-detail-by-id?meeting_id=$meetingId";
        $data = $this->makeApiRequest($endpoint);

        if ($data && isset($data['status']) && $data['status'] === 'success') {
            echo "✅ Meeting Detail API - Success!\n\n";
            $this->displayMeetingDetailData($data);
        }
    }

    private function testSpecificClasses(): void
    {
        echo "🎓 Testing Specific Class Details\n";
        echo "---------------------------------\n";

        if (!$this->login()) {
            return;
        }

        $testClasses = [
            ['id' => '3142', 'dosen' => '1863', 'course' => '8861'],
            ['id' => '1451', 'dosen' => '2744', 'course' => '8833']
        ];

        foreach ($testClasses as $i => $class) {
            echo "\n📚 Testing Class " . ($i + 1) . " (ID: {$class['id']})...\n";
            $endpoint = "/api/campus/class-detail-by-id?class_id={$class['id']}&dosen_id={$class['dosen']}&course_id={$class['course']}";
            $data = $this->makeApiRequest($endpoint);

            if ($data && isset($data['status']) && $data['status'] === 'success') {
                echo "✅ Class {$class['id']} - Success!\n";
                $this->showDataSummary("Class {$class['id']}", $data);
            } else {
                echo "❌ Class {$class['id']} - Failed\n";
            }
        }
    }

    private function showRawData(): void
    {
        echo "🔍 Raw Data Viewer\n";
        echo "-----------------\n";

        if (!$this->login()) {
            return;
        }

        echo "Select endpoint:\n";
        echo "1. Dashboard\n";
        echo "2. Profile\n";
        echo "3. Classes\n";
        echo "Choice: ";
        $choice = trim(fgets(STDIN));

        $endpoints = [
            '1' => '/api/campus/dashboard',
            '2' => '/api/campus/profile',
            '3' => '/api/campus/classes'
        ];

        if (!isset($endpoints[$choice])) {
            echo "❌ Invalid choice\n";
            return;
        }

        $data = $this->makeApiRequest($endpoints[$choice]);

        if ($data) {
            echo "\n📄 Raw JSON Response:\n";
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
        }
    }

    private function showDataSummary(string $type, array $data): void
    {
        echo "   📊 Data Summary for $type:\n";
        if (isset($data['data'])) {
            $data = $data['data'];
        }

        if (isset($data['dashboard_overview'])) {
            $overview = $data['dashboard_overview'];
            echo "   - Courses: {$overview['total_courses']}\n";
            echo "   - GPA: {$overview['gpa']}\n";
            echo "   - Credits: {$overview['total_credits']}\n";
        }

        if (isset($data['classes']) && is_array($data['classes'])) {
            echo "   - Total Classes: " . count($data['classes']) . "\n";
        }

        if (isset($data['class_detail'])) {
            $detail = $data['class_detail'];
            echo "   - Course: {$detail['course_info']['name'] ?? 'N/A'}\n";
            echo "   - Lecturer: {$detail['lecturer_info']['name'] ?? 'N/A'}\n";
        }
    }

    private function displayDashboardData(array $data): void
    {
        $dashboard = $data['data'];
        echo "📊 Dashboard Overview:\n";
        echo "-------------------\n";
        echo "Student: {$dashboard['user_info']['name']}\n";
        echo "Student ID: {$dashboard['user_info']['student_id']}\n\n";

        $overview = $dashboard['dashboard_overview'];
        echo "Academic Summary:\n";
        echo "- Total Courses: {$overview['total_courses']}\n";
        echo "- GPA: {$overview['gpa']}\n";
        echo "- Total Credits: {$overview['total_credits']}\n";
        echo "- Outstanding Payments: {$overview['outstanding_payments']}\n\n";

        $summary = $dashboard['classes_summary'];
        echo "Classes Summary:\n";
        echo "- Total Classes: {$summary['total_classes']}\n";
        echo "- Active Semester: {$summary['active_semester']}\n";
    }

    private function displayProfileData(array $data): void
    {
        $profile = $data['profile'];
        echo "👤 Student Profile:\n";
        echo "-----------------\n";

        $personal = $profile['personal_data'];
        echo "Personal Information:\n";
        echo "- Name: {$personal['name']}\n";
        echo "- Student ID: {$personal['student_id']}\n";
        echo "- Birth Place: {$personal['birth_place']}\n";
        echo "- Birth Date: {$personal['birth_date']}\n";
        echo "- Gender: {$personal['gender']}\n";
        echo "- Religion: {$personal['religion']}\n\n";

        $contact = $profile['contact_data'];
        echo "Contact Information:\n";
        echo "- Email: {$contact['email']}\n";
        echo "- Phone: {$contact['phone']}\n";
        echo "- Address: {$contact['address']}\n";
        echo "- Emergency Contact: {$contact['emergency_contact']}\n\n";

        $education = $profile['education_data'];
        echo "Education Information:\n";
        echo "- Program: {$education['program']}\n";
        echo "- Faculty: {$education['faculty']}\n";
        echo "- Entry Year: {$education['entry_year']}\n";
        echo "- Status: {$education['status']}\n";
    }

    private function displayClassesData(array $data): void
    {
        $classes = $data['classes'];
        echo "📚 Enrolled Classes:\n";
        echo "-------------------\n";
        echo "Total Classes: " . count($classes) . "\n\n";

        foreach ($classes as $index => $class) {
            echo ($index + 1) . ". {$class['name']}\n";
            echo "   Lecturer: {$class['lecturer']}\n";
            echo "   Schedule: {$class['schedule']}\n";
            echo "   Room: {$class['room']}\n";
            echo "   Credits: {$class['credits']}\n";
            echo "   Status: {$class['status']}\n\n";
        }
    }

    private function displayClassDetailData(array $data): void
    {
        $detail = $data['class_detail'];
        echo "📝 Class Detail Information:\n";
        echo "----------------------------\n";

        $course = $detail['course_info'];
        echo "Course Information:\n";
        echo "- Name: {$course['name']}\n";
        echo "- Code: {$course['code']}\n";
        echo "- Credits: {$course['credits']}\n";
        echo "- Semester: {$course['semester']}\n\n";

        $lecturer = $detail['lecturer_info'];
        echo "Lecturer Information:\n";
        echo "- Name: {$lecturer['name']}\n";
        echo "- NIDN: {$lecturer['nidn']}\n";
        echo "- Email: {$lecturer['email']}\n\n";

        $schedule = $detail['schedule_info'];
        echo "Schedule Information:\n";
        echo "- Day: {$schedule['day']}\n";
        echo "- Time: {$schedule['time']}\n";
        echo "- Room: {$schedule['room']}\n";
        echo "- Building: {$schedule['building']}\n\n";

        $attendance = $detail['attendance_info'];
        echo "Attendance Summary:\n";
        echo "- Total Meetings: {$attendance['total_meetings']}\n";
        echo "- Attended: {$attendance['attended']}\n";
        echo "- Absent: {$attendance['absent']}\n";
        echo "- Percentage: {$attendance['percentage']}\n";
    }

    private function displayMeetingDetailData(array $data): void
    {
        $meeting = $data['meeting_detail'];
        echo "🎯 Meeting Detail Information:\n";
        echo "------------------------------\n";

        $info = $meeting['meeting_info'];
        echo "Meeting Information:\n";
        echo "- Session: {$info['session_number']}\n";
        echo "- Date: {$info['date']}\n";
        echo "- Time: {$info['time']}\n";
        echo "- Topic: {$info['topic']}\n";
        echo "- Room: {$info['room']}\n\n";

        $attendance = $meeting['attendance_info'];
        echo "Attendance Summary:\n";
        echo "- Total Students: {$attendance['total_students']}\n";
        echo "- Present: {$attendance['present']}\n";
        echo "- Absent: {$attendance['absent']}\n\n";

        $materials = $meeting['meeting_materials'];
        echo "Materials:\n";
        echo "- Slides: {$materials['slides_url']}\n";
        echo "- Exercise: {$materials['exercise_url']}\n";
    }
}

// Run the tester
$tester = new CICApiTester();
$tester->run();