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

export function timeRange(schedule: { time?: string | null; formatted_time?: string | null; start_time?: string | null; end_time?: string | null } | undefined): string | null {
  if (!schedule) {
    return null;
  }

  if (schedule.start_time && schedule.end_time) {
    return `${schedule.start_time}–${schedule.end_time}`;
  }

  return schedule.time ?? schedule.formatted_time ?? schedule.start_time ?? null;
}

/** "Senin · 08:00–09:40 · Lab 1" from what the class lists. */
export function classMeta(portalClass: PortalClass): string {
  return [portalClass.schedule?.day, timeRange(portalClass.schedule), portalClass.class_room_info?.room]
    .filter((part): part is string => typeof part === 'string' && part.trim() !== '')
    .join(' · ');
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
