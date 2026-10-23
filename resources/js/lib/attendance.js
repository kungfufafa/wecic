/**
 * Attendance codes read from a QR scan and the quick "Absen" shortcut.
 * Pure, so tests/js runs them under Node.
 */

/**
 * The 8-character attendance code in a scanned QR text (a bare code, a
 * portal URL or "Kode: …"). A code right after a hint word wins, then one
 * with hex letters over a digits-only run (a NIM or a timestamp), then the
 * first one. Null when there is none.
 *
 * @param {unknown} value
 * @returns {string|null}
 */
export function extractAttendanceCode(value) {
    const normalizedValue = String(value || '').trim();

    if (normalizedValue === '') {
        return null;
    }

    const codeCandidates = Array.from(normalizedValue.matchAll(/[a-f\d]{8,64}/gi))
        .map((match) => ({
            value: match[0],
            index: match.index ?? Number.MAX_SAFE_INTEGER,
            hasHexLetters: /[a-f]/i.test(match[0]),
            isHinted: /(?:kode|unix|qr|code)[^a-f\d]{0,20}$/i.test(normalizedValue.slice(Math.max(0, (match.index ?? 0) - 20), match.index ?? 0)),
        }))
        .sort((left, right) => {
            if (left.isHinted !== right.isHinted) {
                return left.isHinted ? -1 : 1;
            }

            if (left.hasHexLetters !== right.hasHexLetters) {
                return left.hasHexLetters ? -1 : 1;
            }

            return left.index - right.index;
        });

    const code = codeCandidates[0]?.value;

    if (!code) {
        return null;
    }

    return code.slice(0, 8).toLowerCase();
}

/**
 * The meeting to scan for, from a dashboard `classes_summary`: the first of
 * today's classes whose attendance can be filled, opened with the scanner.
 * `null` when today has nothing to scan, `undefined` when the summary is
 * missing, belongs to another day, or is still checking a class's attendance
 * (the schedule is built before the meeting pages are read).
 *
 * @param {{ date?: string, today_classes?: Array<{ attendance?: { actionable?: boolean, meeting_url?: string, state?: string } }> }|null|undefined} summary
 * @param {string} todayIso Today as `YYYY-MM-DD` on this device.
 * @returns {string|null|undefined}
 */
export function quickQrTargetFromSummary(summary, todayIso) {
    if (!summary || !Array.isArray(summary.today_classes) || (summary.date && summary.date !== todayIso)) {
        return undefined;
    }

    const scannable = summary.today_classes.find((klass) => klass.attendance?.actionable && klass.attendance?.meeting_url);

    if (scannable) {
        return `${scannable.attendance.meeting_url}&qr=1`;
    }

    return summary.today_classes.some((klass) => klass.attendance?.state === 'checking') ? undefined : null;
}

/**
 * Attendance states a meeting page can show (App\Enums\MeetingAttendanceState).
 */
const MEETING_ATTENDANCE_STATES = ['present', 'late', 'permit', 'sick', 'absent', 'unavailable', 'open', 'unknown'];

const RECORDED_ATTENDANCE_STATES = ['present', 'late', 'permit', 'sick'];

/**
 * What a meeting page's `attendance_info` says, as the server reads it
 * (MeetingAttendanceState): its stored `state`, or for a payload stored
 * before there was one, the same reading of its text. "Waktu Absensi Tidak
 * Tersedia" is `unavailable`, never an absence; only the portal's own
 * markers ("Hadir Absen Jam …", "Tidak Hadir") are presence or absence.
 *
 * @param {{ status?: string, time?: string, state?: string }|null|undefined} attendanceInfo
 * @returns {'present'|'late'|'permit'|'sick'|'absent'|'unavailable'|'open'|'unknown'}
 */
export function meetingAttendancePortalState(attendanceInfo) {
    const info = attendanceInfo || {};

    if (MEETING_ATTENDANCE_STATES.includes(info.state)) {
        return info.state;
    }

    const text = String(info.status || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const time = String(info.time || '').trim();

    if (text === '' && time === '') {
        return 'unknown';
    }

    if (/\btidak\s+hadir\b|\balpa\b|\balpha\b|\bbolos\b|\bmangkir\b|^a$/.test(text)) {
        return 'absent';
    }

    let recorded = null;

    if (text.includes('terlambat')) {
        recorded = 'late';
    } else if (/\bizin\b/.test(text)) {
        recorded = 'permit';
    } else if (/\bsakit\b/.test(text)) {
        recorded = 'sick';
    } else if (/\bhadir\b/.test(text)) {
        recorded = 'present';
    }

    if (time !== '') {
        return recorded || 'present';
    }

    if (/tidak\s+tersedia|belum\s+(?:dibuka|tersedia|dimulai)|ditutup|\btutup\b|berakhir|\bselesai\b|kadaluarsa|kedaluwarsa|expired/.test(text)) {
        return 'unavailable';
    }

    if (/\bbelum\b|silakan|silahkan|\bscan\b|\bqr\b/.test(text)) {
        return 'open';
    }

    return recorded || 'unknown';
}

/**
 * How the meeting page shows its attendance: `recorded`, `past` (another
 * day, not recorded: not attended), `upcoming` (not open yet), and for the
 * meeting day `closed` (the window has ended, or the portal marked the
 * student absent), `not_open` (the portal shows no window yet) or `open`.
 *
 * @param {{ status?: string, time?: string, state?: string }|null|undefined} attendanceInfo
 * @param {string|null} meetingDay The meeting's date as `YYYY-MM-DD`, null when unknown.
 * @param {string} todayIso Today as `YYYY-MM-DD` on this device.
 * @returns {'recorded'|'past'|'upcoming'|'closed'|'not_open'|'open'}
 */
export function meetingAttendanceView(attendanceInfo, meetingDay, todayIso) {
    const state = meetingAttendancePortalState(attendanceInfo);

    if (RECORDED_ATTENDANCE_STATES.includes(state)) {
        return 'recorded';
    }

    if (meetingDay && meetingDay < todayIso) {
        return 'past';
    }

    if (meetingDay && meetingDay > todayIso) {
        return 'upcoming';
    }

    if (state === 'absent') {
        return 'closed';
    }

    if (state === 'unavailable') {
        return /berakhir|ditutup|\btutup\b|\bselesai\b|kadaluarsa|kedaluwarsa|expired/i.test(String(attendanceInfo?.status || '')) ? 'closed' : 'not_open';
    }

    return 'open';
}

/**
 * The line a dashboard class shows for a meeting page without an attendance
 * window (`attendance_status.status` `unavailable`): not attended once the
 * class is over (`window` `closed`), not open yet before that. Null for any
 * other status.
 *
 * @param {{ status?: string|null, window?: string|null }|null|undefined} status
 * @returns {string|null}
 */
export function todayAttendanceNote(status) {
    if (status?.status !== 'unavailable') {
        return null;
    }

    return status.window === 'closed'
        ? 'Tidak hadir: absensi kelas ini sudah ditutup di portal.'
        : 'Absensi belum dibuka di portal. Kirim kode saat dosen membukanya.';
}
