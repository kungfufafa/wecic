<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\MeetingAttendanceState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MeetingAttendanceStateTest extends TestCase
{
    /**
     * The "Absensi Kehadiran" text of a meeting page, the attendance time
     * read next to it, and what that means. The first rows are the texts of
     * the real captures (tests/Fixtures/portal/real).
     *
     * @return array<string, array{string, string, MeetingAttendanceState}>
     */
    public static function meetingTexts(): array
    {
        return [
            'real: attended' => ['Hadir', '19:36:34', MeetingAttendanceState::Present],
            'real: no window' => ['Waktu Absensi Tidak Tersedia', '', MeetingAttendanceState::Unavailable],
            'late' => ['Hadir (Terlambat)', '08:17:00', MeetingAttendanceState::Late],
            'permit' => ['Izin', '', MeetingAttendanceState::Permit],
            'sick' => ['Sakit', '', MeetingAttendanceState::Sick],
            'present without time' => ['Hadir', '', MeetingAttendanceState::Present],
            'absent' => ['Tidak Hadir', '', MeetingAttendanceState::Absent],
            'alpa' => ['Alpa', '', MeetingAttendanceState::Absent],
            'class page code' => ['A', '', MeetingAttendanceState::Absent],
            'not open yet' => ['Belum dibuka', '', MeetingAttendanceState::Unavailable],
            'window ended' => ['Waktu absen sudah berakhir', '', MeetingAttendanceState::Unavailable],
            'window closed' => ['Absensi ditutup', '', MeetingAttendanceState::Unavailable],
            'asks for a scan' => ['Silakan scan QR untuk absensi', '', MeetingAttendanceState::Open],
            'not attended yet' => ['Belum Absen', '', MeetingAttendanceState::Open],
            'not present yet' => ['Belum hadir', '', MeetingAttendanceState::Open],
            'empty' => ['', '', MeetingAttendanceState::Unknown],
            'unrecognised' => ['Absen', '', MeetingAttendanceState::Unknown],
        ];
    }

    #[DataProvider('meetingTexts')]
    public function test_the_meeting_pages_attendance_text_is_read_by_its_own_markers(string $status, string $time, MeetingAttendanceState $expected): void
    {
        $this->assertSame($expected, MeetingAttendanceState::fromMeetingPage($status, $time));
    }

    public function test_an_unavailable_window_is_neither_recorded_nor_absent(): void
    {
        $state = MeetingAttendanceState::fromMeetingPage('Waktu Absensi Tidak Tersedia');

        $this->assertFalse($state->isRecorded());
        $this->assertNotSame(MeetingAttendanceState::Absent, $state);
        $this->assertTrue(MeetingAttendanceState::Present->isRecorded());
        $this->assertFalse(MeetingAttendanceState::Absent->isRecorded());
    }

    public function test_a_stored_state_wins_and_a_payload_without_one_is_read_from_its_text(): void
    {
        $this->assertSame(MeetingAttendanceState::Present, MeetingAttendanceState::fromAttendanceInfo(['status' => 'Hadir', 'time' => '', 'state' => 'present']));
        $this->assertSame(MeetingAttendanceState::Unavailable, MeetingAttendanceState::fromAttendanceInfo(['status' => 'Waktu Absensi Tidak Tersedia', 'time' => '']));
        $this->assertSame(MeetingAttendanceState::Present, MeetingAttendanceState::fromAttendanceInfo(['status' => 'Hadir', 'time' => '18:51:21', 'state' => 'not-a-state']));
    }
}
