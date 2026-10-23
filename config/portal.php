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
            'sipandai_classes' => (int) env('CAMPUS_CACHE_HTML_TTL', 300),
            'student_course' => (int) env('CAMPUS_CACHE_HTML_TTL', 300),
            'payments' => (int) env('CAMPUS_CACHE_DYNAMIC_TTL', 300),
            'credit_total' => (int) env('CAMPUS_CACHE_CREDIT_TTL', 1800),
            'class_catalog' => (int) env('CAMPUS_CACHE_CLASS_CATALOG_TTL', 1800),
            'class_detail' => (int) env('CAMPUS_CACHE_CLASS_DETAIL_TTL', 7200),
            'meeting_detail' => (int) env('CAMPUS_CACHE_MEETING_DETAIL_TTL', 86400),
            'portal_context' => (int) env('CAMPUS_CACHE_PORTAL_CONTEXT_TTL', 3600),
            'announcements' => (int) env('CAMPUS_CACHE_ANNOUNCEMENTS_TTL', 900),
            'krs' => (int) env('CAMPUS_CACHE_KRS_TTL', 900),
            'advising' => (int) env('CAMPUS_CACHE_ADVISING_TTL', 3600),
            'krs_detail' => (int) env('CAMPUS_CACHE_KRS_DETAIL_TTL', 21600),
            'khs' => (int) env('CAMPUS_CACHE_GRADES_TTL', 3600),
            'skkm' => (int) env('CAMPUS_CACHE_SKKM_TTL', 1800),
            'thesis' => (int) env('CAMPUS_CACHE_THESIS_TTL', 3600),
            'library_links' => (int) env('CAMPUS_CACHE_LIBRARY_TTL', 86400),
            'thesis_repository' => (int) env('CAMPUS_CACHE_THESIS_REPOSITORY_TTL', 43200),
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
    | Parameterized datasets hold one snapshot per parameter set. `notify`
    | rules turn changes between syncs into notifications; the rule format is
    | documented on `App\Services\PortalSnapshotDiffNotifier`.
    |
    */

    'sync' => [
        'lock_ttl' => (int) env('PORTAL_SYNC_LOCK_TTL', 300),
        'lock_wait' => (int) env('PORTAL_SYNC_LOCK_WAIT', 20),
        'force_cooldown' => (int) env('PORTAL_SYNC_FORCE_COOLDOWN', 30),
        'failure_backoff' => (int) env('PORTAL_SYNC_FAILURE_BACKOFF', 120),
        // Seconds a background build that failed before any snapshot existed
        // is not queued again (reads then report the dataset `failed`).
        'build_failure_backoff' => (int) env('PORTAL_SYNC_BUILD_FAILURE_BACKOFF', 900),
        'snapshot_retention' => (int) env('PORTAL_SNAPSHOT_RETENTION', 604800),
        'bulk_request_delay' => (int) env('CAMPUS_BULK_REQUEST_DELAY', 250),
        // Datasets a student needs to decide what to do, synced in one background
        // job right after login (and topped up by the keep-alive ping), so their
        // pages and the action center open from a snapshot instead of the portal.
        'warmup_datasets' => ['portal_context', 'krs', 'advising', 'payments', 'grades', 'skkm', 'announcements', 'exams'],
        // Seconds the login warm-up waits, so the first dashboard build is not
        // competing with it for the same portal session.
        'warmup_delay' => (int) env('PORTAL_SYNC_WARMUP_DELAY', 5),
        'keepalive_warmup' => (bool) env('PORTAL_SYNC_KEEPALIVE_WARMUP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Academic & SKKM Targets
    |--------------------------------------------------------------------------
    |
    | Fallbacks used only when the portal itself does not state the target:
    | graduation SKS (the official "SKS Terkumpul + Sisa SKS" wins) and the
    | SKKM graduation points (curated from the SKKM guide; unset = unknown).
    |
    */

    'academic' => [
        'target_sks' => (int) env('CAMPUS_ACADEMIC_TARGET_SKS', 144),
    ],

    'skkm' => [
        'required_points' => env('CAMPUS_SKKM_REQUIRED_POINTS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thesis & Library
    |--------------------------------------------------------------------------
    |
    | Thesis thresholds are read from SiMASITA's requirement list; these
    | defaults only fill a value the portal does not state (marked `default`).
    | The library links are served when act=ebook yields none.
    |
    */

    'thesis' => [
        'default_thresholds' => [
            'min_sks' => [
                'D3' => (int) env('CAMPUS_THESIS_MIN_SKS_D3', 90),
                'S1' => (int) env('CAMPUS_THESIS_MIN_SKS_S1', 120),
            ],
            'min_ipk' => (float) env('CAMPUS_THESIS_MIN_IPK', 2.0),
            'max_d' => (int) env('CAMPUS_THESIS_MAX_D', 2),
            'allow_e' => false,
        ],
    ],

    'library' => [
        'fallback_links' => [
            ['title' => 'Perpustakaan UCIC', 'url' => 'https://pustaka.cic.ac.id/'],
            ['title' => 'Digital Library Mahardika', 'url' => 'https://digilib.mahardika.ac.id/'],
            ['title' => 'Repository USNI', 'url' => 'https://repository.usni.ac.id/'],
            ['title' => 'UCIC Library', 'url' => 'https://uciclibrary.perpustakaan.co.id/home.ks', 'requires_account' => true],
            ['title' => 'eBook UCIC', 'url' => 'https://ebook.cic.ac.id/home/daftar', 'requires_account' => true],
            ['title' => 'Repositori Skripsi', 'internal_route' => '/library/theses'],
        ],
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
            'cache_keys' => ['/api/assignment/pending', '/api/action-items'],
            'sync_keys' => ['dashboard-pending-assignments', 'dashboard-action-items'],
            'notify' => [
                [
                    'type' => 'assignment_new',
                    'on' => 'added',
                    'records' => 'assignments',
                    'key' => ['meeting_id'],
                    'title' => 'Tugas baru: :course_name',
                    'body' => ':material_title',
                    'url' => ':meeting_url|path',
                ],
            ],
        ],
        'classes' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/class'],
            'sync_keys' => ['class-index'],
            'archive' => [
                'label' => 'Jadwal & kelas',
            ],
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
            'archive' => [
                'label' => 'Profil',
                // Paths are the keys ProfileExtractor writes (nim, nama).
                'requires' => ['personal_data.nim' => '@filled'],
                'guards' => [
                    ['type' => 'field', 'path' => 'personal_data.nim', 'label' => 'NIM'],
                    ['type' => 'field', 'path' => 'personal_data.nama', 'label' => 'Nama'],
                ],
            ],
        ],
        'payments' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/finance/payments', '/api/finance/summary', '/api/dashboard', '/api/action-items', '/api/thesis/readiness'],
            'sync_keys' => ['finance', 'dashboard-overview', 'dashboard-action-items', 'thesis-index'],
            'archive' => [
                'label' => 'Keuangan',
                'requires' => ['summary.history_available' => true],
                'guards' => [
                    ['type' => 'count', 'records' => 'payment_history', 'label' => 'Riwayat pembayaran'],
                    [
                        'type' => 'values',
                        'records' => 'payment_history',
                        'key' => ['paid_at', 'reference', 'installment_stage', 'study_year'],
                        'field' => 'amount',
                        'label' => 'Nominal pembayaran',
                    ],
                ],
            ],
            'notify' => [
                [
                    'type' => 'bill_new',
                    'on' => 'added',
                    'records' => 'outstanding_bills',
                    'key' => ['name', 'installment_stage', 'study_year'],
                    'requires' => ['outstanding_summary.status' => ['clear', 'outstanding']],
                    'title' => 'Tagihan baru: :name',
                    'body' => ':amount_label',
                    'url' => '/finance',
                    'urgency' => 'warning',
                ],
                [
                    'type' => 'bill_cleared',
                    'on' => 'changed',
                    'field' => 'outstanding_summary.status',
                    'ignore' => 'unknown',
                    'from' => 'outstanding',
                    'to' => 'clear',
                    'title' => 'Tagihan lunas tercatat',
                    'body' => 'Portal tidak lagi mencatat tagihan yang belum dibayar.',
                    'url' => '/finance',
                ],
                [
                    'type' => 'payment_recorded',
                    'on' => 'added',
                    'records' => 'payment_history',
                    'key' => ['paid_at', 'amount', 'reference', 'installment_stage', 'study_year'],
                    'requires' => ['summary.history_available' => true],
                    'title' => 'Pembayaran :amount_label tercatat',
                    'body' => ':description · :paid_at_label',
                    'url' => '/finance',
                ],
            ],
        ],
        'exams' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/exam', '/api/action-items'],
            'sync_keys' => ['exam-index', 'dashboard-action-items'],
            'archive' => [
                'label' => 'Ujian',
                'requires' => ['source' => 'exam_page'],
            ],
            'notify' => [
                [
                    'type' => 'exam_opened',
                    'on' => 'changed',
                    'records' => 'exams',
                    'key' => ['course_code', 'course_name'],
                    'field' => 'can_upload',
                    'to' => '@true',
                    // Fallback rows guess from the class schedule; only the exam page is authoritative.
                    'requires' => ['source' => 'exam_page'],
                    'title' => 'Ujian :course_name dibuka',
                    'body' => 'Unggah jawaban sebelum waktunya habis · :schedule',
                    'url' => '/exam/:id',
                    'urgency' => 'critical',
                ],
            ],
        ],
        'class_detail' => [
            'fresh_for' => 900,
            'parameterized' => true,
            'cache_keys' => [],
            'sync_keys' => ['class-show'],
            'archive' => [
                'label' => 'Detail kelas (presensi & tugas)',
                'subject' => ['course_info.name', 'course_info.course_name'],
                // Classmates' data is not the student's to keep.
                'exclude' => ['students_list'],
                // `found` is false for the empty page shell of an unknown or stale id;
                // payloads cached before the flag existed carry none.
                'requires' => ['content_type' => ['class_info', 'students_list'], 'found' => ['@true', '@empty']],
                'guards' => [
                    ['type' => 'count', 'records' => 'attendance_info.meetings', 'label' => 'Rekap presensi'],
                    [
                        'type' => 'values',
                        'records' => 'attendance_info.meetings',
                        'key' => ['meeting_number'],
                        'field' => 'status',
                        'from' => ['present', 'late', 'permit', 'sick'],
                        'label' => 'Status presensi pertemuan',
                        'display' => ['present' => 'Hadir', 'late' => 'Terlambat', 'permit' => 'Izin', 'sick' => 'Sakit', 'absent' => 'Absen'],
                    ],
                    ['type' => 'count', 'records' => 'assignment_history.assignments', 'label' => 'Riwayat tugas terkumpul'],
                ],
            ],
        ],
        'meeting_detail' => [
            'fresh_for' => 900,
            'parameterized' => true,
            'cache_keys' => [],
            'sync_keys' => ['meeting-show'],
            'archive' => [
                'label' => 'Detail pertemuan',
                'subject' => ['materials.main_topic', 'materials.assignment_topic'],
                'requires' => ['found' => true],
                'guards' => [
                    // Recorded attendance does not turn into an absence or a closed window.
                    [
                        'type' => 'field',
                        'path' => 'attendance_info.state',
                        'from' => ['present', 'late', 'permit', 'sick'],
                        'label' => 'Status presensi pertemuan',
                        'display' => ['present' => 'Hadir', 'late' => 'Terlambat', 'permit' => 'Izin', 'sick' => 'Sakit', 'absent' => 'Absen', 'unavailable' => 'Absensi tidak tersedia', 'open' => 'Belum absen', 'unknown' => 'tidak terbaca'],
                    ],
                ],
            ],
        ],
        'portal_context' => [
            'fresh_for' => 21600,
            'cache_keys' => ['/api/dashboard', '/api/portal/context', '/api/finance/summary', '/api/action-items'],
            'sync_keys' => ['dashboard-overview', 'portal-context', 'dashboard-action-items'],
            'notify' => [
                [
                    'type' => 'academic_period_changed',
                    'on' => 'changed',
                    'field' => 'academic_period.label',
                    'from' => '@filled',
                    'title' => 'Periode akademik berganti',
                    'body' => 'Sekarang :academic_period.label. KRS, jadwal, dan tagihan semester baru menyusul di MyCIC.',
                    'url' => '/dashboard',
                ],
                [
                    'type' => 'questionnaire_opened',
                    'on' => 'changed',
                    'field' => 'questionnaire.available',
                    'to' => '@true',
                    'title' => 'Kuesioner perlu diisi',
                    'body' => ':questionnaire.title',
                    'url' => '/dashboard',
                    'urgency' => 'warning',
                ],
                [
                    'type' => 'portal_menu_added',
                    'on' => 'added',
                    'records' => 'sidebar_menu',
                    'key' => ['href_act'],
                    'title' => 'Menu baru di portal: :label',
                    'body' => 'Menu ini belum ada di MyCIC. Buka lewat portal kampus.',
                    'url' => '/more',
                ],
            ],
        ],
        'announcements' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/announcement', '/api/academic/krs', '/api/finance/summary', '/api/action-items'],
            'sync_keys' => ['announcement-index', 'dashboard-action-items'],
            'archive' => [
                'label' => 'Pengumuman',
            ],
            'notify' => [
                [
                    'type' => 'announcement_new',
                    'on' => 'added',
                    'records' => 'items',
                    'key' => ['id'],
                    'when' => ['duplicate_of' => '@empty'],
                    // A re-post or edit on a later sync gets a new id; reading the same is not news.
                    'repeat_window_days' => 7,
                    'title' => 'Pengumuman: :title',
                    'body' => ':publisher_unit_short · :excerpt',
                    'url' => '/announcement/:id',
                ],
            ],
        ],
        // One portal page past the feed ("Muat lagi"), kept per feed state so a
        // page read before new posts is never continued after them.
        'announcement_page' => [
            'fresh_for' => 86400,
            'parameterized' => true,
            'cache_keys' => [],
            'sync_keys' => ['announcement-index'],
            // Pages past the feed searched for an announcement detail (old notification links).
            'detail_lookup_pages' => 4,
        ],
        'krs' => [
            'fresh_for' => 900,
            'cache_keys' => ['/api/academic/krs', '/api/academic/grades', '/api/academic/transcript', '/api/dashboard', '/api/action-items'],
            'sync_keys' => ['krs-index', 'dashboard-overview', 'dashboard-action-items'],
            'archive' => [
                'label' => 'KRS',
                'requires' => ['found' => true],
            ],
            'notify' => [
                [
                    'type' => 'krs_validated',
                    'on' => 'changed',
                    'field' => 'status.code',
                    'ignore' => 'unknown',
                    'from' => ['pending_dpa', 'not_submitted'],
                    'to' => 'validated',
                    'requires' => ['found' => true],
                    'title' => 'KRS sudah divalidasi DPA',
                    'body' => 'KRS :academic_period.label sudah disetujui dosen pembimbing akademik.',
                    'url' => '/krs',
                ],
                [
                    'type' => 'krs_validation_reopened',
                    'on' => 'changed',
                    'field' => 'status.code',
                    'ignore' => 'unknown',
                    'from' => 'validated',
                    'to' => 'pending_dpa',
                    'requires' => ['found' => true],
                    'title' => 'KRS kembali menunggu validasi DPA',
                    'body' => 'KRS :academic_period.label berubah dan perlu disetujui DPA lagi.',
                    'url' => '/krs',
                    'urgency' => 'warning',
                ],
                [
                    'type' => 'krs_period_announced',
                    'on' => 'added',
                    'records' => 'periods',
                    'key' => ['kind', 'start', 'end'],
                    'requires' => ['found' => true],
                    'title' => 'Jadwal :label',
                    'body' => ':start|date sampai :end|date',
                    'url' => '/krs',
                ],
            ],
        ],
        'advising' => [
            'fresh_for' => 3600,
            'cache_keys' => ['/api/academic/advising', '/api/academic/krs', '/api/action-items'],
            'sync_keys' => ['advising-index', 'krs-index', 'dashboard-action-items'],
            'archive' => [
                'label' => 'Perwalian',
                'requires' => ['found' => true],
            ],
        ],
        'krs_detail' => [
            'fresh_for' => 21600,
            'parameterized' => true,
            'cache_keys' => [],
            'sync_keys' => ['krs-show'],
            'archive' => [
                'label' => 'Detail KRS',
                'subject' => ['header.academic_year'],
                'requires' => ['found' => true],
                'guards' => [
                    ['type' => 'count', 'records' => 'courses', 'label' => 'Mata kuliah di KRS'],
                ],
            ],
        ],
        'grades' => [
            'fresh_for' => 3600,
            'cache_keys' => ['/api/academic/grades', '/api/academic/transcript', '/api/dashboard', '/api/action-items', '/api/thesis/readiness'],
            'sync_keys' => ['grades-index', 'transcript-index', 'dashboard-overview', 'dashboard-action-items', 'thesis-index'],
            'archive' => [
                'label' => 'Nilai (KHS)',
                'requires' => ['found' => true],
                'guards' => [
                    ['type' => 'count', 'records' => 'semesters.*.courses', 'label' => 'Mata kuliah di KHS'],
                    [
                        'type' => 'values',
                        'records' => 'semesters.*.courses',
                        'key' => ['parent.label', 'code'],
                        'field' => 'letter',
                        'label' => 'Nilai huruf',
                    ],
                ],
            ],
            'notify' => [
                [
                    'type' => 'grade_released',
                    'on' => 'changed',
                    'records' => 'semesters.*.courses',
                    'key' => ['parent.label', 'code'],
                    'field' => 'letter',
                    'to' => '@filled',
                    'requires' => ['found' => true],
                    'title' => 'Nilai :name keluar: :letter',
                    'body' => ':parent.label · :sks SKS',
                    'url' => '/grades',
                    // A whole semester's grades can land in one sync: the rest in one summary.
                    'overflow' => ['title' => ':count nilai lain juga keluar', 'url' => '/grades'],
                ],
                [
                    'type' => 'ips_complete',
                    'on' => 'changed',
                    'records' => 'summary.computed.ips_series',
                    'key' => ['label'],
                    'field' => 'is_complete',
                    'to' => '@true',
                    'requires' => ['found' => true],
                    'title' => 'IPS :label: :ips',
                    'body' => 'Semua nilai semester ini sudah keluar.',
                    'url' => '/grades',
                ],
            ],
        ],
        'skkm' => [
            'fresh_for' => 1800,
            'cache_keys' => ['/api/skkm', '/api/dashboard', '/api/action-items'],
            'sync_keys' => ['skkm-index', 'dashboard-overview', 'dashboard-action-items'],
            'archive' => [
                'label' => 'SKKM',
                'requires' => ['found' => true],
                'guards' => [
                    ['type' => 'count', 'records' => 'entries', 'label' => 'Kegiatan SKKM'],
                    [
                        'type' => 'values',
                        'records' => 'entries',
                        'key' => ['name', 'activity', 'level', 'type'],
                        'field' => 'status',
                        'from' => 'valid',
                        'label' => 'Status SKKM yang sudah valid',
                        'display' => ['valid' => 'Valid', 'invalid' => 'Ditolak', 'pending' => 'Menunggu'],
                    ],
                ],
            ],
            'notify' => [
                [
                    'type' => 'skkm_validated',
                    'on' => 'changed',
                    'records' => 'entries',
                    'key' => ['name', 'activity', 'level', 'type'],
                    'field' => 'status',
                    'ignore' => 'unknown',
                    'to' => 'valid',
                    'requires' => ['found' => true],
                    'title' => 'SKKM divalidasi: :name',
                    'body' => ':points_label poin · :activity',
                    'url' => '/skkm',
                ],
                [
                    'type' => 'skkm_rejected',
                    'on' => 'changed',
                    'records' => 'entries',
                    'key' => ['name', 'activity', 'level', 'type'],
                    'field' => 'status',
                    'ignore' => 'unknown',
                    'to' => 'invalid',
                    'requires' => ['found' => true],
                    'title' => 'SKKM ditolak: :name',
                    'body' => 'Periksa lagi data dan berkas kegiatan ini di halaman SKKM.',
                    'url' => '/skkm',
                    'urgency' => 'warning',
                ],
            ],
        ],
        'thesis' => [
            'fresh_for' => 43200,
            'cache_keys' => ['/api/thesis', '/api/thesis/readiness'],
            'sync_keys' => ['thesis-index'],
            'archive' => [
                'label' => 'Skripsi',
                'requires' => ['found' => true],
            ],
            'notify' => [
                [
                    'type' => 'thesis_status_changed',
                    'on' => 'changed',
                    'field' => 'status.state',
                    // A status page that could not be read is not a new status.
                    'ignore' => 'unknown',
                    'requires' => ['found' => true],
                    'title' => 'Status skripsi di portal berubah',
                    'body' => 'Cek status e-Skripsi dan kesiapanmu di MyCIC.',
                    'url' => '/thesis',
                ],
            ],
        ],
        'library' => [
            'fresh_for' => 604800,
            'cache_keys' => ['/api/library'],
            'sync_keys' => ['library-index'],
        ],
        // The whole repository (about 7 portal pages), crawled in the background.
        'thesis_repository' => [
            'fresh_for' => 86400,
            'cache_keys' => ['/api/library/theses', '/api/library'],
            'sync_keys' => ['library-theses', 'library-index'],
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
        // Campus file downloads (/api/support/download) are streamed with their own limits.
        'download_timeout' => (int) env('CAMPUS_DOWNLOAD_TIMEOUT', 120), // seconds for the whole transfer
        'download_read_timeout' => (int) env('CAMPUS_DOWNLOAD_READ_TIMEOUT', 30), // seconds without a byte
        'download_max_bytes' => (int) env('CAMPUS_DOWNLOAD_MAX_BYTES', 52428800), // 50 MB
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
        // Failed logins allowed per IP within login_rate_limit_decay, counting
        // portal rejections and wrong offline passwords while the portal is down.
        'login_failure_attempts_per_ip' => (int) env('CAMPUS_LOGIN_FAILURE_ATTEMPTS_PER_IP', 30),
        'action_rate_limit_attempts' => (int) env('CAMPUS_ACTION_RATE_LIMIT_ATTEMPTS', 10),
        'action_rate_limit_decay' => (int) env('CAMPUS_ACTION_RATE_LIMIT_DECAY', 60),
        // Parameterized reads (class, meeting, flow) per minute per campus session.
        'read_rate_limit_attempts' => (int) env('CAMPUS_READ_RATE_LIMIT_ATTEMPTS', 60),
        // Comma-separated proxy addresses or CIDRs, or '*'; null trusts no proxy.
        'trusted_proxies' => env('TRUSTED_PROXIES'),
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
        'student_announcement' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'pengumuman',
            ],
            'description' => 'University announcements (3 per page, optional page).',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'student_questionnaire_form' => [
            'method' => 'POST',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'kuesionerform',
            ],
            'description' => 'Opens the VMTS questionnaire form (body: vmts). Never called automatically.',
            'category' => 'action',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_credit' => [
            'method' => 'GET',
            'url' => $studentCreditUrl,
            'default_query' => [
                'mod' => 'student',
                'act' => 'credit',
            ],
            'description' => 'Reg & KRS Online: KRS period, validation status and planned courses.',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'student_advising' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'perwalian',
            ],
            'description' => 'Perwalian: academic advisor (DPA) and KRS history.',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'student_krs_detail' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'viewkrs',
            ],
            'description' => 'KRS detail of one semester plus the full KHS (id = krs id from the KRS history).',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['id'],
        ],
        'student_krs_print' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/views/cetakkrs/print.php",
            'description' => 'Printable proof of the current KRS (the portal ignores id).',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_skkm' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'skkm',
            ],
            'description' => 'SIREKA: SKKM point statistics and entries.',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'student_skkm_add_form' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'addskkm',
            ],
            'description' => 'SKKM upload form.',
            'category' => 'action',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_skkm_submit' => [
            'method' => 'POST',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'addskkm',
            ],
            'description' => 'Submit an SKKM entry (multipart: data[nama_kegiatan], berkas, data[kegiatan_id], data[tingkat_id], data[jenis_id], cmd=Simpan).',
            'category' => 'action',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_skkm_kegiatan_options' => [
            'method' => 'POST',
            'url' => "{$mahasiswaBase}/views/skkm/get_kegiatan.php",
            'description' => 'SKKM activity options (<option> fragment, empty body).',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_skkm_tingkat_options' => [
            'method' => 'POST',
            'url' => "{$mahasiswaBase}/views/skkm/get_tingkat.php",
            'description' => 'SKKM level options for an activity (body: kegiatan).',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_skkm_jenis_options' => [
            'method' => 'POST',
            'url' => "{$mahasiswaBase}/views/skkm/get_jenis.php",
            'description' => 'SKKM type options for a level (body: tingkat).',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_simasita' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'simasita',
            ],
            'description' => 'SiMASITA: thesis requirements and SOP.',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_skripsi' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'skripsi',
            ],
            'description' => 'Thesis (e-Skripsi) status.',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_ebook' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'ebook',
            ],
            'description' => 'e-Book and digital library links.',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'student_repository' => [
            'method' => 'GET',
            'url' => "{$mahasiswaBase}/",
            'default_query' => [
                'mod' => 'student',
                'act' => 'repositori',
            ],
            'description' => 'Thesis repository (optional page).',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'sipandai_score' => [
            'method' => 'GET',
            'url' => "{$sipandaiBase}/",
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'score',
            ],
            'description' => 'SIPANDAI Kartu Hasil Studi (KHS) for every semester.',
            'category' => 'data',
            'priority' => 'high',
            'requires_session' => true,
        ],
        'sipandai_transcript_print' => [
            'method' => 'GET',
            'url' => "{$sipandaiViewsBase}/transcripts/print.php",
            'description' => 'Printable official transcript.',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'exam_card' => [
            'method' => 'GET',
            'url' => "{$sipandaiViewsBase}/ujian/kartuujian.php",
            'description' => 'Printable exam card.',
            'category' => 'data',
            'priority' => 'medium',
            'requires_session' => true,
        ],
        'meeting_conformity' => [
            'method' => 'GET',
            'url' => "{$sipandaiBase}/",
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'kesesuaian',
            ],
            'description' => 'Confirm whether a meeting followed the RPS (GET with write effect; payload comes from the server-side href).',
            'category' => 'action',
            'priority' => 'medium',
            'requires_session' => true,
            'required_params' => ['subject', 'pertemuan', 'courses', 'dosen', 'sesuai', 'modules'],
        ],
        'exam_submission_delete' => [
            'method' => 'GET',
            'url' => "{$sipandaiBase}/",
            'default_query' => [
                'mod' => 'sipandai',
                'act' => 'jobdelujian',
            ],
            'description' => 'Delete an uploaded exam answer (GET with write effect).',
            'category' => 'action',
            'priority' => 'high',
            'requires_session' => true,
            'required_params' => ['id'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Center
    |--------------------------------------------------------------------------
    |
    | What the snapshot diff last saw is kept per student for
    | `baseline_retention` seconds (longer than snapshots, so changes made
    | while a student was away are still found). `max_per_sync` caps how many
    | notifications one rule may raise in one sync.
    |
    */

    'notifications' => [
        'baseline_retention' => (int) env('PORTAL_NOTIFICATION_BASELINE_RETENTION', 15552000),
        'retention_days' => (int) env('PORTAL_NOTIFICATION_RETENTION_DAYS', 90),
        'max_per_sync' => (int) env('PORTAL_NOTIFICATION_MAX_PER_SYNC', 5),
        'max_baseline_records' => 500,
        'page_size' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile App (Bearer Tokens and Push)
    |--------------------------------------------------------------------------
    |
    | `POST /api/auth/token` gives the app a bearer token that names a
    | server-side Laravel session (PortalMobileSession), so it ends with that
    | session; the session driver must keep sessions on the server (not
    | `cookie`). A token unused for `token_retention_days` is pruned. Push
    | tokens the app has not registered again for
    | `push_token_retention_days` are pruned too.
    |
    */

    'mobile' => [
        'token_retention_days' => (int) env('PORTAL_MOBILE_TOKEN_RETENTION_DAYS', 30),
        'push_token_retention_days' => (int) env('PORTAL_PUSH_TOKEN_RETENTION_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Archive (Backup of Portal Data)
    |--------------------------------------------------------------------------
    |
    | Datasets with an `archive` block are kept per student in the database,
    | one encrypted row per distinct content, `max_versions` per dataset. The
    | guards are documented on `App\Services\PortalArchiveGuard`; keys in
    | `volatile_keys` (scrape timestamps) never make a version "new".
    |
    | Offline login lets a student who logged in online within
    | `offline_login_days` read the archive while the portal is unreachable,
    | checked against a hash of that last password. An offline session keeps
    | that password encrypted and tries to log in again every
    | `offline_resume_interval` seconds (doubling up to
    | `offline_resume_max_interval`), so it turns into an online session on
    | its own once the portal is back. Course files linked from meetings
    | (modul, latihan, RPS) are archived up to `max_file_size` bytes.
    |
    */

    'archive' => [
        'enabled' => (bool) env('PORTAL_ARCHIVE_ENABLED', true),
        'max_versions' => (int) env('PORTAL_ARCHIVE_MAX_VERSIONS', 30),
        'volatile_keys' => ['extracted_at', 'generated_at', 'last_updated', 'request_url'],
        'offline_login' => (bool) env('PORTAL_ARCHIVE_OFFLINE_LOGIN', true),
        'offline_login_days' => (int) env('PORTAL_ARCHIVE_OFFLINE_LOGIN_DAYS', 30),
        'offline_resume' => (bool) env('PORTAL_ARCHIVE_OFFLINE_RESUME', true),
        'offline_resume_interval' => (int) env('PORTAL_ARCHIVE_OFFLINE_RESUME_INTERVAL', 120),
        'offline_resume_max_interval' => (int) env('PORTAL_ARCHIVE_OFFLINE_RESUME_MAX_INTERVAL', 900),
        'files' => [
            'enabled' => (bool) env('PORTAL_ARCHIVE_FILES_ENABLED', true),
            'disk' => env('PORTAL_ARCHIVE_FILES_DISK', 'local'),
            'max_file_size' => (int) env('PORTAL_ARCHIVE_MAX_FILE_SIZE', 26214400),
        ],
        // Secret every student's owner key (the `student_…` owner_hash of all
        // archived data) is derived from; empty means APP_KEY, as before. Pin it
        // to the current APP_KEY before rotating APP_KEY (PortalDataOwner).
        'owner_key' => env('PORTAL_OWNER_KEY'),
        // Earlier owner secrets, comma separated: data stored under them moves
        // to the current key at the student's next login. APP_PREVIOUS_KEYS
        // are tried as well.
        'previous_owner_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('PORTAL_PREVIOUS_OWNER_KEYS', ''))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Background Sync (Archive Kept Current While the Student Is Away)
    |--------------------------------------------------------------------------
    |
    | With background sync on, every online login stores the student's
    | portal credentials encrypted (PortalOfflineAccount). The scheduler then
    | re-syncs `datasets` for each student every `interval` minutes, until
    | `credential_days` after their last online login: an active online
    | session is reused, otherwise MyCIC logs in, syncs and logs out again.
    | A login the portal rejects (password changed) stops the syncs until the
    | student logs in online again. Each scheduler tick handles at most
    | `batch_size` students, so a big campus is spread over several ticks.
    |
    | Every `courses.interval` minutes a sync also crawls the student's
    | classes: the class detail, every meeting it lists and the files those
    | meetings link to (PortalCourseArchiver), so materials are archived
    | without the student opening each meeting. That is dozens of pages per
    | student, hence the longer interval.
    |
    */

    'background_sync' => [
        'enabled' => (bool) env('PORTAL_BACKGROUND_SYNC_ENABLED', true),
        'interval' => (int) env('PORTAL_BACKGROUND_SYNC_INTERVAL', 180),
        'credential_days' => (int) env('PORTAL_BACKGROUND_SYNC_CREDENTIAL_DAYS', 30),
        'batch_size' => (int) env('PORTAL_BACKGROUND_SYNC_BATCH_SIZE', 10),
        'datasets' => ['portal_context', 'profile', 'classes', 'dashboard', 'assignments', 'krs', 'advising', 'payments', 'grades', 'skkm', 'announcements', 'exams'],
        'courses' => [
            'enabled' => (bool) env('PORTAL_BACKGROUND_SYNC_COURSES_ENABLED', true),
            'interval' => (int) env('PORTAL_BACKGROUND_SYNC_COURSES_INTERVAL', 1440),
        ],
    ],

    'realtime' => [
        'notifications' => [
            'scan_lock_ttl' => (int) env('PORTAL_NOTIFICATION_SCAN_LOCK_TTL', 300),
            // Seconds between reminder scans while a session lives (sooner when a class starts).
            'scan_interval' => (int) env('PORTAL_NOTIFICATION_SCAN_INTERVAL', 600),
            // Seconds before a follow-up scan re-syncs assignments/exams; younger snapshots are reused.
            'follow_up_resync_after' => (int) env('PORTAL_NOTIFICATION_FOLLOW_UP_RESYNC_AFTER', 3600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug: Recording the Portal's Answers to Write Actions
    |--------------------------------------------------------------------------
    |
    | With `record_action_responses` on, SipandaiSubmissionService keeps a
    | sanitized copy (PortalResponseRecorder) of what the portal answers to
    | every write action (attendance, assignment upload and delete, exam
    | upload) and of the pages read to verify it, under
    | `capture_path`/{action}/{Y-m-d_His}_{hash}.html. Names, NIM, email
    | addresses, phone numbers, cookies, hidden tokens, uploaded file names
    | and the student's other profile values are replaced by placeholders.
    | Production never records unless `allow_in_production` is on as well.
    | The read-only `portal:capture-fixtures` command uses the same sanitizer.
    |
    */

    'debug' => [
        'record_action_responses' => (bool) env('PORTAL_RECORD_ACTION_RESPONSES', false),
        'allow_in_production' => (bool) env('PORTAL_DEBUG_ALLOW_IN_PRODUCTION', false),
        'capture_path' => env('PORTAL_CAPTURE_PATH') ?: storage_path('app/portal-captures'),
    ],
];
