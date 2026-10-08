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
$passwordUrl = env('CAMPUS_PASSWORD_URL', "{$mahasiswaBase}/?mod=student&act=password");
$passwordActionUrl = env('CAMPUS_PASSWORD_ACTION_URL', "{$mahasiswaBase}/?mod=student&act=passwordact");
$studentCourseUrl = env('CAMPUS_STUDENT_COURSE_URL', "{$mahasiswaBase}/?mod=student&act=course");
$studentCreditUrl = env('CAMPUS_STUDENT_CREDIT_URL', "{$mahasiswaBase}/?mod=student&act=credit");
$studentFinanceUrl = env('CAMPUS_STUDENT_FINANCE_URL', "{$mahasiswaBase}/?mod=student&act=keuangan");

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
    'password_url' => $passwordUrl,
    'password_action_url' => $passwordActionUrl,
    'classes_url' => $classesUrl,
    'class_detail_url' => $classDetailUrl,
    'sipandai_dashboard_url' => $sipandaiDashboardUrl,
    'sipandai_logout_url' => $sipandaiLogoutUrl,
    'student_course_url' => $studentCourseUrl,
    'student_credit_url' => $studentCreditUrl,
    'student_finance_url' => $studentFinanceUrl,
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
    'auto_relogin' => (bool) env('CAMPUS_AUTO_RELOGIN', false),
    'auto_relogin_credential_ttl' => (int) env('CAMPUS_AUTO_RELOGIN_CREDENTIAL_TTL', 900),
    // Seconds between upstream keep-alive pings; must stay below the portal's PHP session lifetime.
    'session_refresh_interval' => (int) env('CAMPUS_SESSION_REFRESH_INTERVAL', 1200),
    // Seconds of user inactivity after which the campus session is ended and keep-alive stops.
    'session_idle_timeout' => (int) env('CAMPUS_SESSION_IDLE_TIMEOUT', 7200),
    // Minimum seconds between two last-activity writes for the same session.
    'session_activity_touch_interval' => (int) env('CAMPUS_SESSION_ACTIVITY_TOUCH_INTERVAL', 60),

    /*
    |--------------------------------------------------------------------------
    | Resource Cache
    |--------------------------------------------------------------------------
    |
    | TTL (seconds) of parsed upstream pages, shared by every dataset that
    | needs them. Entries are owned by the student, so they survive re-login,
    | and are invalidated explicitly after portal actions.
    |
    */

    'cache' => [
        'resources' => [
            'profile' => (int) env('CAMPUS_CACHE_PROFILE_TTL', 600),
            'lms_classes' => (int) env('CAMPUS_CACHE_HTML_TTL', 300),
            'sipandai_classes' => (int) env('CAMPUS_CACHE_HTML_TTL', 300),
            'student_course' => (int) env('CAMPUS_CACHE_HTML_TTL', 300),
            'payments' => (int) env('CAMPUS_CACHE_DYNAMIC_TTL', 300),
            'credit_total' => (int) env('CAMPUS_CACHE_CREDIT_TTL', 1800),
            'class_catalog' => (int) env('CAMPUS_CACHE_CLASS_CATALOG_TTL', 1800),
            'class_detail' => (int) env('CAMPUS_CACHE_CLASS_DETAIL_TTL', 7200),
            'meeting_detail' => (int) env('CAMPUS_CACHE_MEETING_DETAIL_TTL', 86400),
        ],
        'default_ttl' => (int) env('CAMPUS_CACHE_HTML_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Datasets (Snapshot Read Models)
    |--------------------------------------------------------------------------
    |
    | Every data endpoint is served from a snapshot. A snapshot older than
    | `fresh_for` is still served while one deduplicated background job
    | re-syncs it; a missing snapshot is built once, inline. `cache_keys` and
    | `sync_keys` tell the browser what to reload after a background sync.
    | Parameterized datasets hold one snapshot per parameter set.
    |
    */

    'sync' => [
        'lock_ttl' => (int) env('PORTAL_SYNC_LOCK_TTL', 300),
        'lock_wait' => (int) env('PORTAL_SYNC_LOCK_WAIT', 20),
        'force_cooldown' => (int) env('PORTAL_SYNC_FORCE_COOLDOWN', 30),
        'failure_backoff' => (int) env('PORTAL_SYNC_FAILURE_BACKOFF', 120),
        'snapshot_retention' => (int) env('PORTAL_SNAPSHOT_RETENTION', 604800),
        'bulk_request_delay' => (int) env('CAMPUS_BULK_REQUEST_DELAY', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Portal Actions
    |--------------------------------------------------------------------------
    |
    | A confirmed action (attendance, assignment upload/delete) is projected
    | onto the snapshots right away and remembered for `ledger_ttl` seconds,
    | so a re-sync that started before the action cannot revert it. Uploads
    | run right after the HTTP response, so they get their own time budget.
    |
    */

    'actions' => [
        'ledger_ttl' => (int) env('PORTAL_ACTION_LEDGER_TTL', 1800),
        'upload_time_limit' => (int) env('PORTAL_UPLOAD_TIME_LIMIT', 180),
        'upload_request_timeout' => (int) env('PORTAL_UPLOAD_REQUEST_TIMEOUT', 120),
    ],

    'datasets' => [
        'dashboard' => [
            'fresh_for' => 600,
            'cache_keys' => ['/api/dashboard', '/api/attendance/today'],
            'sync_keys' => ['dashboard-overview'],
        ],
        'assignments' => [
            'fresh_for' => 600,
            'cache_keys' => ['/api/assignment/pending'],
            'sync_keys' => ['dashboard-pending-assignments'],
        ],
        'classes' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/class'],
            'sync_keys' => ['class-index'],
        ],
        'lms_classes' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/lms/class'],
            'sync_keys' => [],
        ],
        'schedule' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/schedule/class'],
            'sync_keys' => [],
        ],
        'profile' => [
            'fresh_for' => 3600,
            'cache_keys' => ['/api/profile'],
            'sync_keys' => ['profile'],
        ],
        'payments' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/finance/payments'],
            'sync_keys' => ['finance'],
        ],
        'exams' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/exam'],
            'sync_keys' => ['exam-index'],
        ],
        'class_detail' => [
            'fresh_for' => 900,
            'parameterized' => true,
            'cache_keys' => [],
            'sync_keys' => ['class-show'],
        ],
        'meeting_detail' => [
            'fresh_for' => 900,
            'parameterized' => true,
            'cache_keys' => [],
            'sync_keys' => ['meeting-show'],
        ],
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
        'web_request_timeout' => (int) env('CAMPUS_WEB_REQUEST_TIMEOUT', 15), // seconds; PHP-FPM friendly
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'secure_dns_url' => env('CAMPUS_SECURE_DNS_URL', 'https://chrome.cloudflare-dns.com/dns-query'),
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
        'login_rate_limit_attempts' => (int) env('CAMPUS_LOGIN_RATE_LIMIT_ATTEMPTS', 5),
        'login_rate_limit_decay' => (int) env('CAMPUS_LOGIN_RATE_LIMIT_DECAY', 300),
        'action_rate_limit_attempts' => (int) env('CAMPUS_ACTION_RATE_LIMIT_ATTEMPTS', 10),
        'action_rate_limit_decay' => (int) env('CAMPUS_ACTION_RATE_LIMIT_DECAY', 60),
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
        'password_form' => [
            'method' => 'GET',
            'url' => $passwordUrl,
            'default_query' => [
                'mod' => 'student',
                'act' => 'password',
            ],
            'description' => 'Student password change form.',
            'category' => 'action',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'password_submit' => [
            'method' => 'POST',
            'url' => $passwordActionUrl,
            'default_query' => [
                'mod' => 'student',
                'act' => 'passwordact',
            ],
            'description' => 'Submit student password change payload.',
            'category' => 'action',
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
        'student_finance' => [
            'method' => 'GET',
            'url' => $studentFinanceUrl,
            'default_query' => [
                'mod' => 'student',
                'act' => 'keuangan',
            ],
            'description' => 'Student payment history / financial records.',
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

    'realtime' => [
        'notifications' => [
            'deadline_urgent_hours' => (int) env('PORTAL_NOTIFICATION_DEADLINE_URGENT_HOURS', 24),
            'deadline_warning_hours' => (int) env('PORTAL_NOTIFICATION_DEADLINE_WARNING_HOURS', 72),
            'scan_lock_ttl' => (int) env('PORTAL_NOTIFICATION_SCAN_LOCK_TTL', 300),
        ],
    ],
];
