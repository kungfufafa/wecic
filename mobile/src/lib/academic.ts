import type { Tone } from '@/lib/format';

/** How a grade, a status or a check reads at a glance. */
export type AcademicTone = 'success' | 'brand' | 'warning' | 'danger' | 'neutral';

/** A letter grade's tone: A good, B fine, C weak, D and E failing; anything else neutral. */
export function letterTone(letter: string | null | undefined): AcademicTone {
  const first = letter?.trim().toUpperCase().charAt(0) ?? '';

  switch (first) {
    case 'A':
      return 'success';
    case 'B':
      return 'brand';
    case 'C':
      return 'warning';
    case 'D':
    case 'E':
      return 'danger';
    default:
      return 'neutral';
  }
}

export type StepState = 'done' | 'current' | 'todo';

/** A KRS tracker step's state, whatever word the server used. */
export function stepState(state: string | null | undefined): StepState {
  const value = state?.toLowerCase() ?? '';

  if (['done', 'complete', 'completed', 'passed'].includes(value)) {
    return 'done';
  }

  if (['current', 'active', 'in_progress', 'waiting'].includes(value)) {
    return 'current';
  }

  return 'todo';
}

/** A KRS status for the status pill: validated is good news, waiting needs action. */
export function krsStatusTone(code: string | null | undefined): Tone {
  if (code === 'validated') {
    return 'positive';
  }

  if (code === 'pending_dpa' || code === 'not_submitted' || code === 'pending') {
    return 'attention';
  }

  return 'neutral';
}

/** An SKKM entry's status tone. */
export function skkmTone(status: string | null | undefined): AcademicTone {
  if (status === 'valid') {
    return 'success';
  }

  if (status === 'invalid') {
    return 'danger';
  }

  if (status === 'pending') {
    return 'warning';
  }

  return 'neutral';
}

/** A thesis readiness check's tone. */
export function checklistTone(status: string | null | undefined): AcademicTone {
  if (status === 'passed') {
    return 'success';
  }

  if (status === 'failed') {
    return 'danger';
  }

  return 'neutral';
}

/** "3,21" for a grade point; the dash when there is none. */
export function formatDecimal(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || !Number.isFinite(value)) {
    return '–';
  }

  return value.toFixed(digits).replace('.', ',');
}

/** "+0,18" or "−0,05" for a change in IPS; null when there is none. */
export function formatDelta(delta: number | null | undefined): string | null {
  if (delta === null || delta === undefined || !Number.isFinite(delta) || delta === 0) {
    return null;
  }

  return `${delta > 0 ? '+' : '−'}${formatDecimal(Math.abs(delta))}`;
}

/** The column names of a KHS: "UTS" for uts, "Absen" for absen. */
export function columnLabel(column: string): string {
  const known: Record<string, string> = { absen: 'Absen', tugas: 'Tugas', uts: 'UTS', uas: 'UAS', quiz: 'Kuis', kuis: 'Kuis' };

  return known[column.toLowerCase()] ?? column.charAt(0).toUpperCase() + column.slice(1);
}

/** "Semester 3", or "Semester T (konversi)" for the transfer semester. */
export function semesterLabel(semester: number | string | null | undefined): string {
  if (semester === null || semester === undefined || semester === '') {
    return 'Tanpa semester';
  }

  return String(semester).toUpperCase() === 'T' ? 'Semester T (konversi)' : `Semester ${semester}`;
}

export type SemesterGroup<T> = { semester: number | string | null; label: string; items: T[] };

/** Courses by semester: numbered semesters in order, then the rest (conversion, unknown) as they come. */
export function groupBySemester<T extends { semester?: number | string | null }>(items: T[]): SemesterGroup<T>[] {
  const groups = new Map<string, SemesterGroup<T>>();

  for (const item of items) {
    const semester = item.semester ?? null;
    const key = semester === null ? '' : String(semester);
    const group = groups.get(key) ?? { semester, label: semesterLabel(semester), items: [] };
    group.items.push(item);
    groups.set(key, group);
  }

  const order = (group: SemesterGroup<T>): number => {
    const value = Number(group.semester);

    return group.semester !== null && Number.isFinite(value) ? value : Number.POSITIVE_INFINITY;
  };

  return [...groups.values()].sort((a, b) => order(a) - order(b));
}

/** What a KRS period says about time: days left while open, days to wait before, or that it ended. */
export function periodTiming(period: {
  is_open?: boolean;
  is_upcoming?: boolean;
  is_past?: boolean;
  days_left?: number | null;
  days_until_start?: number | null;
}): { label: string; tone: Tone } | null {
  if (period.is_open) {
    return { label: typeof period.days_left === 'number' ? `Dibuka · sisa ${period.days_left} hari` : 'Sedang dibuka', tone: 'attention' };
  }

  if (period.is_upcoming) {
    return {
      label: typeof period.days_until_start === 'number' ? `Dibuka ${period.days_until_start} hari lagi` : 'Segera dibuka',
      tone: 'neutral',
    };
  }

  if (period.is_past) {
    return { label: 'Sudah berakhir', tone: 'muted' };
  }

  return null;
}

/** "42 / 130" for a readiness check with numbers; null otherwise. */
export function checkProgress(current: number | string | null | undefined, target: number | string | null | undefined): string | null {
  if (current === null || current === undefined || target === null || target === undefined) {
    return null;
  }

  // When either side has decimals (an IPK), both show two, so 3,36 / 2 reads 3,36 / 2,00.
  const decimal = [current, target].some((value) => typeof value === 'number' && !Number.isInteger(value));
  const show = (value: number | string): string => (typeof value === 'number' && decimal ? formatDecimal(value) : String(value));

  return `${show(current)} / ${show(target)}`;
}
