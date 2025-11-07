<?php

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

    'base_url' => env('CAMPUS_BASE_URL', 'https://my.cic.ac.id'),
    'login_url' => env('CAMPUS_LOGIN_URL', 'https://my.cic.ac.id/portal/mahasiswa/'),
    'login_action' => env('CAMPUS_LOGIN_ACTION', 'https://my.cic.ac.id/portal/mahasiswa/?mod=loginact'),
    'dashboard_url' => env('CAMPUS_DASHBOARD_URL', 'https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=dashboard'),
    'profile_url' => env('CAMPUS_PROFILE_URL', 'https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=profile'),
    'classes_url' => env('CAMPUS_CLASSES_URL', 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=dashboardmahasiswa'),
    'class_detail_url' => env('CAMPUS_CLASS_DETAIL_URL', 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=download'),
    'sipandai_dashboard_url' => env('CAMPUS_SIPANDAI_DASHBOARD_URL', 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=dashboardmahasiswa'),
    'student_course_url' => env('CAMPUS_STUDENT_COURSE_URL', 'https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=course'),

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
];
