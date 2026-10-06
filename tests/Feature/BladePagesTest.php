<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalSessionManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class BladePagesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_login_page_clears_expired_session_without_redirect_loop(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('expired-token')
                    ->andReturnFalse();
            })
        );

        $response = $this->withSession(['campus_session_token' => 'expired-token'])
            ->get('/login');

        $response->assertOk()
            ->assertSessionMissing('campus_session_token');
    }

    public function test_dashboard_page_renders(): void
    {
        $this->mockValidCampusSession('token-dashboard');

        $this->withSession(['campus_session_token' => 'token-dashboard'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-sync-refresh', false)
            ->assertSee('Sinkronkan data')
            ->assertDontSee('Tagihan belum dibayar')
            ->assertSee("app.clearCachedResponse('/api/dashboard');", false)
            ->assertSee('await loadDashboard({ force: true });', false)
            ->assertDontSee('Aktivitas terbaru');
    }

    public function test_dashboard_page_redirects_to_login_when_session_is_missing(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token');
    }

    public function test_dashboard_page_redirects_to_login_when_session_has_expired(): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')
                    ->once()
                    ->with('expired-token')
                    ->andReturnFalse();
            })
        );

        $this->withSession(['campus_session_token' => 'expired-token', 'campus_user_info' => ['name' => 'User']])
            ->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionMissing('campus_session_token')
            ->assertSessionMissing('campus_user_info');
    }

    public function test_class_pages_render(): void
    {
        $this->mockValidCampusSession('token-class', 2);

        $this->withSession(['campus_session_token' => 'token-class'])
            ->get('/class')
            ->assertOk()
            ->assertSee('Lihat pertemuan', false)
            ->assertSee('Grid Time Blocking')
            ->assertSee('Timeline & Jam Kosong', false)
            ->assertSee('Matriks Jam Perkuliahan (Senin – Sabtu)')
            ->assertSee('class-detail-modal')
            ->assertDontSee('Segarkan')
            ->assertDontSee('return \\`', false)
            ->assertDontSee('P\\${app.escapeHtml(labelNumber)}', false);

        $this->withSession(['campus_session_token' => 'token-class'])
            ->get('/class/2265?dosen_id=5240&course_id=8810')
            ->assertOk()
            ->assertSee('const endpoint = () => {', false)
            ->assertDontSee('courseInfo.code ? app.renderBadge(courseInfo.code, \'primary\') : \'\'', false)
            ->assertDontSee('materials.length ? app.renderBadge(`${materials.length} materi`, \'info\') : \'\'', false)
            ->assertDontSee('return \\`', false)
            ->assertDontSee('\\${app.escapeHtml(materialLabel)}', false)
            ->assertDontSee('return \\\\\\`', false)
            ->assertDontSee('\\${app.renderBadge(isAbsent ? \'Absen\' : \'Hadir\'', false);
    }

    public function test_meeting_and_exam_pages_render(): void
    {
        $this->mockValidCampusSession('token-detail', 5);

        $this->withSession(['campus_session_token' => 'token-detail'])
            ->get('/meeting/445?courses_id=8861')
            ->assertOk()
            ->assertSee('const deriveAssignmentParams =', false)
            ->assertSee('id: String(meetingId)', false)
            ->assertSee('courses_id: String(coursesId)', false)
            ->assertSee('app.clearCachedResponse(endpoint);', false)
            ->assertSee('await loadMeeting({ force: true });', false)
            ->assertDontSee('Periksa materi utama, presensi, dan instruksi tugas pada pertemuan ini.', false)
            ->assertDontSee('inferredMeetingNumber ? app.renderBadge(`Pertemuan ${inferredMeetingNumber}`, \'primary\') : \'\'', false)
            ->assertDontSee('scheduleInfo.date ? app.renderBadge(scheduleInfo.date, \'secondary\') : \'\'', false)
            ->assertDontSee('app.renderBadge(attendanceRecorded ? \'Absensi tercatat\' : \'Absensi tersedia\'', false)
            ->assertDontSee('${attendanceRecorded ? \\`', false)
            ->assertDontSee('\\${app.escapeHtml(attendanceStatusText', false)
            ->assertDontSee('contentParts.push(\\`', false);

        $this->withSession(['campus_session_token' => 'token-detail'])
            ->get('/exam')
            ->assertOk();

        $this->withSession(['campus_session_token' => 'token-detail'])
            ->get('/exam/8833')
            ->assertOk();

        $this->withSession(['campus_session_token' => 'token-detail'])
            ->get('/profile')
            ->assertOk()
            ->assertSee('Data Pribadi & Akademik', false)
            ->assertSee('Kontak & Domisili', false)
            ->assertSee('Pendidikan Terakhir');

        $this->withSession(['campus_session_token' => 'token-detail'])
            ->get('/finance')
            ->assertOk()
            ->assertSee('Riwayat Pembayaran')
            ->assertSee('Total Terbayar')
            ->assertSee('/api/finance/payments')
            ->assertDontSee('finance-student-badge');
    }

    private function mockValidCampusSession(string $token, int $times = 1): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token, $times): void {
                $mock->shouldReceive('validateSession')
                    ->times($times)
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }
}
