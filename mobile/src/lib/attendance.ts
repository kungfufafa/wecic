/**
 * Attendance codes and meeting attendance states, ported from the web's
 * resources/js/lib/attendance.js so both clients read the portal alike.
 */

import type { AttendanceStatus } from '@/api/types/actions';

/**
 * The 8-character attendance code in what the student typed or scanned (a
 * bare code, a portal URL or "Kode: …"). A code right after a hint word
 * wins, then one with hex letters over a digits-only run (a NIM or a
 * timestamp), then the first one. Null when there is none.
 */
export function extractAttendanceCode(value: unknown): string | null {
  const text = String(value ?? '').trim();

  if (text === '') {
    return null;
  }

  const candidates = Array.from(text.matchAll(/[a-f\d]{8,64}/gi))
    .map((match) => {
      const index = match.index ?? Number.MAX_SAFE_INTEGER;

      return {
        value: match[0],
        index,
        hasHexLetters: /[a-f]/i.test(match[0]),
        isHinted: /(?:kode|unix|qr|code)[^a-f\d]{0,20}$/i.test(text.slice(Math.max(0, index - 20), index)),
      };
    })
    .sort((left, right) => {
      if (left.isHinted !== right.isHinted) {
        return left.isHinted ? -1 : 1;
      }

      if (left.hasHexLetters !== right.hasHexLetters) {
        return left.hasHexLetters ? -1 : 1;
      }

      return left.index - right.index;
    });

  const code = candidates[0]?.value;

  return code ? code.slice(0, 8).toLowerCase() : null;
}

export type MeetingAttendanceState = 'present' | 'late' | 'permit' | 'sick' | 'absent' | 'unavailable' | 'open' | 'unknown';

const KNOWN_STATES: MeetingAttendanceState[] = ['present', 'late', 'permit', 'sick', 'absent', 'unavailable', 'open', 'unknown'];
const RECORDED_STATES: MeetingAttendanceState[] = ['present', 'late', 'permit', 'sick'];

/** What the portal's attendance text on a meeting page means. */
export function meetingAttendanceState(info: { state?: string | null; status?: string | null; time?: string | null } | null | undefined): MeetingAttendanceState {
  if (info?.state && (KNOWN_STATES as string[]).includes(info.state)) {
    return info.state as MeetingAttendanceState;
  }

  const text = String(info?.status ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
  const time = String(info?.time ?? '').trim();

  if (text === '' && time === '') {
    return 'unknown';
  }

  if (/\btidak\s+hadir\b|\balpa\b|\balpha\b|\bbolos\b|\bmangkir\b|^a$/.test(text)) {
    return 'absent';
  }

  let recorded: MeetingAttendanceState | null = null;

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
    return recorded ?? 'present';
  }

  if (/tidak\s+tersedia|belum\s+(?:dibuka|tersedia|dimulai)|ditutup|\btutup\b|berakhir|\bselesai\b|kadaluarsa|kedaluwarsa|expired/.test(text)) {
    return 'unavailable';
  }

  if (/\bbelum\b|silakan|silahkan|\bscan\b|\bqr\b/.test(text)) {
    return 'open';
  }

  return recorded ?? 'unknown';
}

export type MeetingAttendanceView = 'recorded' | 'past' | 'upcoming' | 'closed' | 'not_open' | 'open';

/**
 * What a meeting page offers: the recorded attendance, a form to send a
 * code, or why there is none. Dates are `YYYY-MM-DD`; a meeting on another
 * day never offers the form.
 */
export function meetingAttendanceView(
  info: { state?: string | null; status?: string | null; time?: string | null } | null | undefined,
  meetingDay: string | null,
  todayIso: string,
): MeetingAttendanceView {
  const state = meetingAttendanceState(info);

  if (RECORDED_STATES.includes(state)) {
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
    return /berakhir|ditutup|\btutup\b|\bselesai\b|kadaluarsa|kedaluwarsa|expired/i.test(String(info?.status ?? '')) ? 'closed' : 'not_open';
  }

  return 'open';
}

/**
 * Whether a class on the Absen tab offers the code form: it has a meeting to
 * send to and the portal has not recorded the student. Its window, even
 * `closed` ("Tidak hadir"), is not read: the server decides.
 */
export function todayClassTakesAttendanceCode(
  status: { already_marked?: boolean } | null | undefined,
  payload: { modulesid?: unknown } | null | undefined,
): boolean {
  return status?.already_marked !== true && Boolean(payload?.modulesid);
}

/**
 * The attendance to show right after the server recorded it: the status it
 * sent back (`data.attendance`), else a plain "Hadir". The class's card
 * takes it as is, without asking the portal for every class again.
 */
export function markedAttendanceStatus(
  response: { data?: { status?: string; attendance?: AttendanceStatus | null } | null } | null | undefined,
): AttendanceStatus {
  const attendance = response?.data?.attendance;

  if (attendance?.already_marked === true) {
    return attendance;
  }

  return {
    already_marked: true,
    status: 'present',
    label: response?.data?.status === 'already_marked' ? 'Sudah absen' : 'Hadir',
    time: null,
  };
}

export const ATTENDANCE_STATE_LABELS: Record<MeetingAttendanceState, string> = {
  present: 'Hadir',
  late: 'Terlambat',
  permit: 'Izin',
  sick: 'Sakit',
  absent: 'Tidak hadir',
  unavailable: 'Absensi belum dibuka',
  open: 'Absensi dibuka',
  unknown: 'Belum ada data',
};

const MONTHS: Record<string, number> = {
  januari: 1,
  februari: 2,
  maret: 3,
  april: 4,
  mei: 5,
  juni: 6,
  juli: 7,
  agustus: 8,
  september: 9,
  oktober: 10,
  november: 11,
  desember: 12,
};

/** `2026-10-05` from the portal's "Senin, 05 Oktober 2026"; null when it does not read as a date. */
export function parseIndonesianDate(value: string | null | undefined): string | null {
  const match = value?.toLowerCase().match(/(\d{1,2})\s+([a-z]+)\s+(\d{4})/);
  const month = match ? MONTHS[match[2]] : undefined;

  if (!match || month === undefined) {
    return null;
  }

  return `${match[3]}-${String(month).padStart(2, '0')}-${match[1].padStart(2, '0')}`;
}

/** Today on this device as `YYYY-MM-DD`. */
export function todayIso(now: Date = new Date()): string {
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}
