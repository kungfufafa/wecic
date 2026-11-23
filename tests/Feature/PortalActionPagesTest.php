<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The action pages (meeting, exam, dashboard) are rendered by inline
 * scripts; these checks pin the behaviour those scripts must keep.
 */
class PortalActionPagesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_meeting_page_keeps_live_forms_across_re_renders_and_scans_into_the_form_on_screen(): void
    {
        $this->mockValidCampusSession('token-meeting');

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->get('/meeting/445?courses_id=8861')
            ->assertOk()
            ->assertSee('data-meeting-slot="attendance"', false)
            ->assertSee('if (renderedSlots.get(name) === signature) {', false)
            ->assertSee("app.requestFormSubmit(document.getElementById('attendance-form'));", false)
            ->assertSee('void app.openQrScanner(input, { onDetected: submitScannedCode });', false)
            ->assertDontSee('app.openQrScannerFromUrl(', false)
            ->assertDontSee('${attendanceSectionMarkup}', false);
    }

    public function test_meeting_page_offers_attendance_and_assignment_upload_directly(): void
    {
        $this->mockValidCampusSession('token-meeting');

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->get('/meeting/445?courses_id=8861')
            ->assertOk()
            ->assertSee("const attendanceState = attendanceRecorded ? 'recorded' : (['past', 'upcoming'].includes(meetingTiming) ? meetingTiming : 'open');", false)
            ->assertSee("} else if (assignmentStatus === 'upload_unavailable') {", false)
            ->assertSee("} else if (assignmentStatus === 'unresolved') {", false)
            ->assertSee('Cek ulang status')
            ->assertSee('data-assignment-closed', false)
            ->assertDontSee('data-assignment-override', false)
            ->assertSee('Kirim langsung ke endpoint upload portal')
            ->assertSee("if (assignmentForm.closest('[data-assignment-closed]')) {", false)
            ->assertSee("formData.append('allow_closed', '1');", false)
            ->assertSee('if (file.size > ASSIGNMENT_MAX_BYTES) {', false)
            ->assertSee('if (error.status === 409 && error.payload?.data?.operation_id) {', false);
    }

    public function test_meeting_page_sends_typed_codes_like_scanned_ones_and_goes_back_where_the_student_came_from(): void
    {
        $this->mockValidCampusSession('token-meeting');

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->get('/meeting/445?courses_id=8861')
            ->assertOk()
            ->assertSee('placeholder="Contoh: 8a2b9c1d"', false)
            ->assertDontSee('font-bold uppercase tracking-widest', false)
            ->assertSee("const kode = normalizeAttendanceCode(document.getElementById('attendance-code')?.value);", false)
            ->assertSee('href="${app.escapeHtml(backHref(detail.class_url))}"', false)
            ->assertDontSee('href="/class" class="${app.ui.backLink} mb-2">${BACK_ICON}', false);
    }

    public function test_meeting_link_without_course_explains_itself_instead_of_a_blank_page(): void
    {
        $this->mockValidCampusSession('token-meeting');

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->get('/meeting/445')
            ->assertOk()
            ->assertSee('const coursesId = null;', false)
            ->assertSee('Link pertemuan ini tidak lengkap, jadi pertemuannya tidak bisa dibuka.')
            ->assertDontSee('courses_id wajib.');
    }

    public function test_exam_page_checks_the_answer_file_and_offers_only_fields_the_student_fills_in(): void
    {
        $this->mockValidCampusSession('token-exam');

        $this->withSession(['campus_session_token' => 'token-exam'])
            ->get('/exam/8833')
            ->assertOk()
            ->assertSee('accept=".pdf,.zip,.rar"', false)
            ->assertSee('PDF, ZIP, atau RAR, maks. 20 MB.')
            ->assertSee('if (file.size > EXAM_FILE_MAX_BYTES) {', false)
            ->assertSee('const editable = Array.isArray(form.editable_fields)', false)
            ->assertSee('${buildOverrideInputs(form)}', false)
            ->assertSee('if (error.status === 409 && error.payload?.data?.operation_id) {', false);
    }

    public function test_exam_list_does_not_count_pending_statuses_as_submitted(): void
    {
        $this->mockValidCampusSession('token-exam');

        $this->withSession(['campus_session_token' => 'token-exam'])
            ->get('/exam')
            ->assertOk()
            ->assertSee('const isSubmittedStatus = (status) => !/belum|tidak|not/i.test(status) && /sudah|terkirim|telah|selesai|uploaded/i.test(status);', false)
            ->assertDontSee('/upload|terkirim|dikumpulkan|sudah|selesai/i.test(', false);
    }

    public function test_dashboard_takes_attendance_only_from_todays_schedule_and_never_crawls_tasks_inline(): void
    {
        $this->mockValidCampusSession('token-dashboard');

        $this->withSession(['campus_session_token' => 'token-dashboard'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('const actionable = snapshotIsToday && Boolean(attendance.actionable);', false)
            ->assertSee('const scannable = snapshotIsToday', false)
            ->assertSee("const payload = await app.request('/api/assignment/pending', { fresh: false, background });", false)
            ->assertSee("'announcements', 'exams', 'assignments'];", false)
            ->assertDontSee("app.request('/api/assignment/pending', { fresh: force })", false)
            ->assertSee('${assignment.upload_status_text ? esc(assignment.upload_status_text) : \'Portal sedang tidak membuka upload tugas ini.', false);
    }

    public function test_meeting_page_on_another_day_still_offers_the_attendance_form_directly(): void
    {
        $this->mockValidCampusSession('token-meeting');

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->get('/meeting/445?courses_id=8861')
            ->assertOk()
            ->assertDontSee('data-attendance-override', false)
            ->assertSee('id="attendance-form"', false)
            ->assertSee('kehadiranmu belum terlihat tercatat di portal')
            ->assertDontSee('kehadiranmu tidak tercatat di portal')
            ->assertSee("if (input && attendanceState === 'open') {", false);
    }

    public function test_meeting_page_says_when_the_course_notebook_is_full(): void
    {
        $this->mockValidCampusSession('token-meeting');

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->get('/meeting/445?courses_id=8861')
            ->assertOk()
            ->assertSee('const notebookLimitReached = payload?.meta?.notebook_limit_reached === true || detail.notebook_limit_reached === true;', false)
            ->assertSee('Notebook mata kuliah ini sudah penuh; pertemuan ini belum dimasukkan. Hapus sumber yang tidak perlu di notebook.');
    }

    public function test_dashboard_says_a_student_without_grades_has_no_ipk_yet(): void
    {
        $this->mockValidCampusSession('token-dashboard');

        $this->withSession(['campus_session_token' => 'token-dashboard'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee("if (metric?.status === 'empty') {", false)
            ->assertSee('Belum ada nilai di KHS. IPK muncul setelah nilai pertama keluar.');
    }

    /**
     * The meeting page and the dashboard read a meeting's attendance through
     * resources/js/lib/attendance.js (tests/js/attendance.test.mjs): an
     * unavailable window is never read from the word "absen" in its text.
     */
    public function test_meeting_and_dashboard_read_an_unavailable_attendance_window_as_not_open_or_not_attended(): void
    {
        $this->mockValidCampusSession('token-meeting');

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->get('/meeting/445?courses_id=8861')
            ->assertOk()
            ->assertSee('const attendanceView = app.meetingAttendanceView(attendanceInfo, meetingDay, todayIso);', false)
            ->assertSee("const attendanceRecorded = attendanceView === 'recorded';", false)
            ->assertSee("const attendanceExpired = attendanceView === 'closed';", false)
            ->assertSee('Portal belum membuka absensi pertemuan ini', false)
            ->assertDontSee('/hadir|terlambat|izin|sakit/i.test(attendanceStatusText)', false);

        $this->withSession(['campus_session_token' => 'token-meeting'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('const attendanceNote = targetReliable ? app.todayAttendanceNote(attendance.status) : null;', false)
            ->assertSee('` : attendanceNote && !actionable ? `', false);
    }

    private function mockValidCampusSession(string $token): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('validateSession')->with($token)->andReturnTrue();
            })
        );
    }
}
