<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a meeting page (act=discuss) says about the student's attendance in
 * its "Absensi Kehadiran" row.
 *
 * Present, late, permit, sick and absent come only from the portal's own
 * markers: "Hadir Absen Jam : 19:36:34" (or another attendance time),
 * "Terlambat", "Izin", "Sakit", and an explicit absence ("Tidak Hadir",
 * "Alpa", "Bolos"). "Waktu Absensi Tidak Tersedia", "Belum dibuka" and a
 * window that has ended say only that no attendance can be taken now: that
 * is Unavailable, whether the meeting is still to come or long past.
 */
enum MeetingAttendanceState: string
{
    case Present = 'present';
    case Late = 'late';
    case Permit = 'permit';
    case Sick = 'sick';
    case Absent = 'absent';

    /**
     * The portal shows no attendance window: not open yet, or closed.
     */
    case Unavailable = 'unavailable';

    /**
     * The portal asks for attendance ("Silakan scan QR", "Belum Absen",
     * "Belum hadir").
     */
    case Open = 'open';

    case Unknown = 'unknown';

    /**
     * The state of the row's text and the attendance time read next to it.
     */
    public static function fromMeetingPage(string $status, ?string $time = null): self
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $status)));

        if ($text === '' && trim((string) $time) === '') {
            return self::Unknown;
        }

        if (preg_match('/\btidak\s+hadir\b|\balpa\b|\balpha\b|\bbolos\b|\bmangkir\b|^a$/u', $text) === 1) {
            return self::Absent;
        }

        $recorded = match (true) {
            str_contains($text, 'terlambat') => self::Late,
            preg_match('/\bizin\b/u', $text) === 1 => self::Permit,
            preg_match('/\bsakit\b/u', $text) === 1 => self::Sick,
            preg_match('/\bhadir\b/u', $text) === 1 => self::Present,
            default => null,
        };

        if (trim((string) $time) !== '') {
            return $recorded ?? self::Present;
        }

        if (preg_match('/tidak\s+tersedia|belum\s+(?:dibuka|tersedia|dimulai)|ditutup|\btutup\b|berakhir|\bselesai\b|kadaluarsa|kedaluwarsa|expired/u', $text) === 1) {
            return self::Unavailable;
        }

        if (preg_match('/\bbelum\b|silakan|silahkan|\bscan\b|\bqr\b/u', $text) === 1) {
            return self::Open;
        }

        return $recorded ?? self::Unknown;
    }

    /**
     * The state of a meeting's `attendance_info`: the `state` the extractor
     * stored, or, for a payload stored before it did, read from its text.
     *
     * @param  array<string, mixed>  $attendanceInfo
     */
    public static function fromAttendanceInfo(array $attendanceInfo): self
    {
        $stored = is_string($attendanceInfo['state'] ?? null) ? self::tryFrom($attendanceInfo['state']) : null;

        return $stored ?? self::fromMeetingPage((string) ($attendanceInfo['status'] ?? ''), (string) ($attendanceInfo['time'] ?? ''));
    }

    /**
     * Whether the portal recorded the student's attendance.
     */
    public function isRecorded(): bool
    {
        return in_array($this, [self::Present, self::Late, self::Permit, self::Sick], true);
    }
}
