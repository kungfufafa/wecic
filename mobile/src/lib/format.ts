import type { PortalClass, TodayClass, UserInfo } from '@/api/types';

export type Tone = 'neutral' | 'positive' | 'attention' | 'muted';

/**
 * What a class on today's schedule says about attendance. `state` is
 * `checking` while MyCIC is still reading the class pages, `unavailable`
 * when they could not be read, `ready` once known.
 */
export function attendanceSummary(todayClass: TodayClass): { label: string; tone: Tone } {
  const attendance = todayClass.attendance;
  const status = attendance?.status;

  if (status?.already_marked) {
    const label = status.label ?? 'Sudah absen';

    return { label: status.time ? `${label} · ${status.time}` : label, tone: 'positive' };
  }

  if (attendance?.state === 'checking') {
    return { label: 'Memeriksa absensi…', tone: 'muted' };
  }

  if (attendance?.state === 'unavailable') {
    return { label: 'Status absensi belum bisa dibaca', tone: 'muted' };
  }

  if (attendance?.actionable) {
    return { label: 'Absensi dibuka', tone: 'attention' };
  }

  return { label: status?.label ?? 'Belum ada absensi', tone: 'neutral' };
}

/** "14:30" from the portal's "14:30:00" or "14.30"; anything else as it came. */
export function shortTime(value: string | null | undefined): string | null {
  const match = value?.trim().match(/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/);

  if (!match) {
    return value ?? null;
  }

  return `${match[1].padStart(2, '0')}:${match[2]}`;
}

export function timeRange(schedule: { time?: string | null; formatted_time?: string | null; start_time?: string | null; end_time?: string | null } | undefined): string | null {
  if (!schedule) {
    return null;
  }

  if (schedule.start_time && schedule.end_time) {
    return `${shortTime(schedule.start_time)}–${shortTime(schedule.end_time)}`;
  }

  return schedule.time ?? schedule.formatted_time ?? shortTime(schedule.start_time) ?? null;
}

export function studentNumber(user: UserInfo | null | undefined): string | null {
  return user?.nim ?? user?.student_id ?? null;
}

export function firstName(user: UserInfo | null | undefined): string | null {
  const name = user?.name?.trim();

  return name ? name.split(/\s+/)[0] : null;
}

/** "24 Oktober 2026" from a `YYYY-MM-DD` date, read as a calendar day; the raw value otherwise. */
export function formatDate(value: string | null | undefined): string {
  const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})$/);

  if (!match) {
    return value ?? '';
  }

  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

  return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** "24 Okt, 09.30" in the student's locale; the raw value when it does not parse. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return '';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/** "MC" for Mahasiswa Contoh; the first letter alone for a single name; "M" for nobody. */
export function initials(user: UserInfo | null | undefined): string {
  const words = user?.name?.trim().split(/\s+/).filter(Boolean) ?? [];

  if (words.length === 0) {
    return 'M';
  }

  return words
    .slice(0, 2)
    .map((word) => word[0].toUpperCase())
    .join('');
}

/** The greeting for an hour of the day, in Indonesian. */
export function greeting(date: Date = new Date()): string {
  const hour = date.getHours();

  if (hour < 11) {
    return 'Selamat pagi';
  }

  if (hour < 15) {
    return 'Selamat siang';
  }

  if (hour < 18) {
    return 'Selamat sore';
  }

  return 'Selamat malam';
}

/** "ERP" for Enterprise Resource Planning, "IF" for a bare code, "?" for nothing. */
export function courseInitials(name: string | null | undefined, code: string | null | undefined): string {
  const words = name?.trim().split(/\s+/).filter((word) => /^[A-Za-z]/.test(word)) ?? [];

  if (words.length >= 2) {
    return words
      .slice(0, 3)
      .map((word) => word[0].toUpperCase())
      .join('');
  }

  if (words.length === 1) {
    return words[0].slice(0, 2).toUpperCase();
  }

  const letters = code?.replace(/[^A-Za-z]/g, '') ?? '';

  return letters === '' ? '?' : letters.slice(0, 2).toUpperCase();
}

/** A stable choice among `count` colors for a course, so it keeps its color across screens. */
export function courseColorIndex(key: string | null | undefined, count: number): number {
  if (count <= 0) {
    return 0;
  }

  let hash = 0;

  for (const char of key ?? '') {
    hash = (hash * 31 + char.charCodeAt(0)) % 2147483647;
  }

  return hash % count;
}

export const DAY_ORDER = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as const;

const DAY_ALIASES: Record<string, (typeof DAY_ORDER)[number]> = {
  senin: 'Senin',
  selasa: 'Selasa',
  rabu: 'Rabu',
  kamis: 'Kamis',
  jumat: 'Jumat',
  sabtu: 'Sabtu',
  minggu: 'Minggu',
  ahad: 'Minggu',
};

/** The portal's day name as one of DAY_ORDER ("Jum'at" is Jumat); null when it is not a day. */
export function normalizeDay(day: string | null | undefined): (typeof DAY_ORDER)[number] | null {
  const key = day?.toLowerCase().replace(/[^a-z]/g, '') ?? '';

  return DAY_ALIASES[key] ?? null;
}

export const OTHER_DAY_LABEL = 'Jadwal lain';

export type ClassesByDay = { day: string; classes: PortalClass[] };

/**
 * Classes by weekday, Senin first, each day's classes by start time; classes
 * without a known day end up last under OTHER_DAY_LABEL.
 */
export function groupClassesByDay(classes: PortalClass[]): ClassesByDay[] {
  const groups = new Map<string, PortalClass[]>();

  for (const portalClass of classes) {
    const day = normalizeDay(portalClass.schedule?.day) ?? OTHER_DAY_LABEL;
    groups.set(day, [...(groups.get(day) ?? []), portalClass]);
  }

  const byTime = (a: PortalClass, b: PortalClass): number =>
    (a.schedule?.start_time ?? '').localeCompare(b.schedule?.start_time ?? '');

  return [...DAY_ORDER, OTHER_DAY_LABEL]
    .filter((day) => groups.has(day))
    .map((day) => ({ day, classes: [...(groups.get(day) ?? [])].sort(byTime) }));
}

/** How many of today's classes already have attendance recorded. */
export function attendedCount(classes: TodayClass[]): number {
  return classes.filter((todayClass) => todayClass.attendance?.status?.already_marked === true).length;
}

/** Whether a class's name, code, lecturer or room contains what the student typed. */
export function matchesClassQuery(portalClass: PortalClass, query: string): boolean {
  const needle = query.trim().toLowerCase();

  if (needle === '') {
    return true;
  }

  return [portalClass.course_info?.name, portalClass.course_info?.code, portalClass.lecturer_info?.name, portalClass.class_room_info?.room].some(
    (value) => typeof value === 'string' && value.toLowerCase().includes(needle),
  );
}

/** "2 Nov" from a `YYYY-MM-DD` date; the raw value otherwise. */
export function formatShortDate(value: string | null | undefined): string {
  const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})$/);

  if (!match) {
    return value ?? '';
  }

  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));

  return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
}
