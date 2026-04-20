<?php

$baseUrl = rtrim(env('CAMPUS_BASE_URL', 'https://my.cic.ac.id'), '/');
$mahasiswaBase = "{$baseUrl}/portal/mahasiswa";
$sipandaiBase = "{$baseUrl}/portal/sipandai";
$sipandaiViewsBase = "{$sipandaiBase}/views";

$loginPageUrl = env('CAMPUS_LOGIN_URL', "{$mahasiswaBase}/");
$loginActionUrl = env('CAMPUS_LOGIN_ACTION', "{$mahasiswaBase}/?mod=loginact");
$logoutUrl = env('CAMPUS_LOGOUT_URL', "{$mahasiswaBase}/?mod=logout");
$dashboardUrl = env('CAMPUS_DASHBOARD_URL', "{$mahasiswaBase}/?mod=student&act=dashboard");
$profileUrl = env('CAMPUS_PROFILE_URL', "{$mahasiswaBase}/?mod=student&act=profile");
$studentCourseUrl = env('CAMPUS_STUDENT_COURSE_URL', "{$mahasiswaBase}/?mod=student&act=course");
$studentCreditUrl = env('CAMPUS_STUDENT_CREDIT_URL', "{$mahasiswaBase}/?mod=student&act=credit");

$sipandaiDashboardUrl = env('CAMPUS_SIPANDAI_DASHBOARD_URL', "{$sipandaiBase}/?mod=sipandai&act=dashboardmahasiswa");
$sipandaiLogoutUrl = env('CAMPUS_SIPANDAI_LOGOUT_URL', "{$sipandaiBase}/controllers/logout.php");
$classesUrl = env('CAMPUS_CLASSES_URL', $sipandaiDashboardUrl);
$classDetailUrl = env('CAMPUS_CLASS_DETAIL_URL', "{$sipandaiBase}/?mod=sipandai&act=download");
$sipandaiAssignmentUrl = env('CAMPUS_SIPANDAI_ASSIGNMENT_URL', "{$sipandaiBase}/?mod=sipandai&act=send");
$sipandaiAttendanceUrl = env('CAMPUS_SIPANDAI_ATTENDANCE_URL', "{$sipandaiViewsBase}/validasi/demo.php?mod=sipandai&act=discuss");

$discussionUrl = env('CAMPUS_SIPANDAI_DISCUSS_URL', "{$sipandaiBase}/?mod=sipandai&act=discuss");
$assignmentSubmitUrl = env('CAMPUS_SIPANDAI_ASSIGNMENT_SUBMIT_URL', "{$sipandaiBase}/?mod=sipandai&act=sent");
$attendanceSubmitUrl = env('CAMPUS_SIPANDAI_ATTENDANCE_SUBMIT_URL', "{$sipandaiBase}/?mod=sipandai&act=actabsen");
$assignmentDeleteUrl = env('CAMPUS_SIPANDAI_ASSIGNMENT_DELETE_URL', "{$sipandaiBase}/?mod=sipandai&act=jobdel");

return [
    /*
    |--------------------------------------------------------------------------
    | Campus System Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the CIC campus system integration including URLs,
    | timeouts, and other connection settings.
    |
    */

    'base_url' => $baseUrl,
    'login_url' => $loginPageUrl,
    'login_action' => $loginActionUrl,
    'logout_url' => $logoutUrl,
    'dashboard_url' => $dashboardUrl,
    'profile_url' => $profileUrl,
    'classes_url' => $classesUrl,
    'class_detail_url' => $classDetailUrl,
    'sipandai_dashboard_url' => $sipandaiDashboardUrl,
    'sipandai_logout_url' => $sipandaiLogoutUrl,
    'student_course_url' => $studentCourseUrl,
    'student_credit_url' => $studentCreditUrl,
    'sipandai_assignment_url' => $sipandaiAssignmentUrl,
    'sipandai_attendance_url' => $sipandaiAttendanceUrl,
    'sipandai_assignment_delete_url' => $assignmentDeleteUrl,

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for HTTP requests to the campus system.
    |
    */

    'request_timeout' => (int) env('CAMPUS_REQUEST_TIMEOUT', 30),
    'request_delay' => (int) env('CAMPUS_REQUEST_DELAY', 1000), // milliseconds

    /*
    |--------------------------------------------------------------------------
    | Session Management
    |--------------------------------------------------------------------------
    |
    | Settings for managing campus sessions.
    |
    */

    'session_prefix' => env('CAMPUS_SESSION_PREFIX', 'cic_session_'),
    'auto_relogin' => env('CAMPUS_AUTO_RELOGIN', true),
    'session_refresh_interval' => (int) env('CAMPUS_SESSION_REFRESH_INTERVAL', 1200), // seconds

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | TTL settings for different types of cached data.
    |
    */

    'cache' => [
        'profile_ttl' => (int) env('CAMPUS_CACHE_PROFILE_TTL', 3600), // 1 hour
        'dynamic_ttl' => (int) env('CAMPUS_CACHE_DYNAMIC_TTL', 900), // 15 minutes
        'html_ttl' => (int) env('CAMPUS_CACHE_HTML_TTL', 300), // 5 minutes
        'failed_ttl' => (int) env('CAMPUS_CACHE_FAILED_TTL', 60), // 1 minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Scraping Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for web scraping operations.
    |
    */

    'scraping' => [
        'max_retries' => 3,
        'retry_delay' => 1000, // milliseconds
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security-related configuration for campus integration.
    |
    */

    'security' => [
        'encrypt_credentials' => true,
        'rate_limit_attempts' => 5,
        'rate_limit_decay' => 300, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Portal Endpoints Map
    |--------------------------------------------------------------------------
    |
    | Each endpoint listed below is sourced from the verified cURL workflow in
    | test_all_endpoints.sh. Having a single catalogue makes the integration
    | easier to maintain and prevents URL drift across services.
    |
    */

    'endpoints' => [
        'login_page' => [
            'method' => 'GET',
            'url' => $loginPageUrl,
            'description' => 'Displays the CIC portal login form.',
            'category' => 'authentication',
            'priority' => 'high',
            'requires_session' => false,
        ],
        'login' => [
            'method' => 'POST',
            'url' => $loginActionUrl,
            'default_query' => [
                'mod' => 'loginact',
            ],
            'description' => 'Authenticates the user and stores the PHPSESSID cookie.',
            'category' => 'authentication',
            'priority' => 'high',
            'requires_session' => false,
        ],
        'student_logout' => [
            'method' => 'GET',
            'url' => $logoutUrl,
            'default_query' => [
                'mod' => 'logout',
            ],
            'description' => 'Logs out from the mahasiswa portal session.',
            'category' => 'authentication',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'sipandai_logout' => [
            'method' => 'GET',
            'url' => $sipandaiLogoutUrl,
            'description' => 'Logs out from the SIPANDAI portal session.',
            'category' => 'authentication',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'dashboard' => [
            'method' => 'GET',
            'url' => $dashboardUrl,
            'default_query' => [
                'mod' => 'student',
                'act' => 'dashboard',
            ],
            'description' => 'Main portal dashboard (Test 2).',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'profile' => [
            'method' => 'GET',
            'url' => $profileUrl,
            'default_query' => [
                'mod' => 'student',
                'act' => 'profile',
            ],
            'description' => 'Student profile data (Test 3).',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'student_course' => [
            'method' => 'GET',
            'url' => $studentCourseUrl,
            'default_query' => [
                'mod' => 'student',
                'act' => 'course',
            ],
            'description' => 'Student course schedule with room info (Test 4).',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'sipandai_dashboard' => [
            'method' => 'GET',
            'url' => $sipandaiDashboardUrl,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'dashboardmahasiswa',
            ],
            'description' => 'SIPANDAI dashboard (Test 5).',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'course_materials' => [
            'method' => 'GET',
            'url' => $classDetailUrl,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'download',
            ],
            'description' => 'Course materials list for a given class (Test 6).',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['id', 'dosen_id', 'course_id'],
        ],
        'discussion' => [
            'method' => 'GET',
            'url' => $discussionUrl,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'discuss',
            ],
            'description' => 'Discussion/forum detail for a course material (Test 7).',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
            'required_params' => ['id', 'courses_id'],
        ],
        'assignment_form' => [
            'method' => 'GET',
            'url' => $sipandaiAssignmentUrl,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'send',
            ],
            'description' => 'Assignment upload form (Test 8).',
            'category' => 'action',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['courses_id', 'id', 'pertemuan'],
        ],
        'qr_attendance_page' => [
            'method' => 'GET',
            'url' => $sipandaiAttendanceUrl,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'discuss',
            ],
            'description' => 'QR attendance validation page (Test 9).',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['id', 'course_id'],
        ],
        'assignment_submit' => [
            'method' => 'POST',
            'url' => $assignmentSubmitUrl,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'sent',
            ],
            'description' => 'Submit assignment upload payload (Test 10).',
            'category' => 'action',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['courses_id', 'id', 'pertemuan'],
        ],
        'attendance_submit' => [
            'method' => 'POST',
            'url' => $attendanceSubmitUrl,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'actabsen',
            ],
            'description' => 'Submit QR attendance code (Test 11).',
            'category' => 'action',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['modulesid', 'courseid'],
        ],
        'assignment_delete' => [
            'method' => 'GET',
            'url' => $assignmentDeleteUrl,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'jobdel',
            ],
            'description' => 'Delete uploaded assignment entry (Test 12).',
            'category' => 'action',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['id', 'idx', 'pertemuan'],
        ],
        'exam_upload_form' => [
            'method' => 'GET',
            'url' => $sipandaiBase,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'sendujian',
            ],
            'description' => 'Exam (UAS/UTS) upload form page.',
            'category' => 'action',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['id'],
        ],
        'exam_submit' => [
            'method' => 'POST',
            'url' => $sipandaiBase,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'sentujian',
            ],
            'description' => 'Submit exam (UAS/UTS) upload.',
            'category' => 'action',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'exam_list' => [
            'method' => 'GET',
            'url' => $sipandaiBase,
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'ujian',
            ],
            'description' => 'List of exams (UAS/UTS) for student.',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
    ],
];
