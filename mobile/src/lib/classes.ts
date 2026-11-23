import type { NotebookAiBudget, NotebookAskMode, NotebookDifficulty, NotebookQuizItem } from '@/api/types/classes';
import type { Tone } from '@/lib/format';

/** One query parameter of a portal or MyCIC link (`?mod=…&id=30061`, `/meeting/1?courses_id=2`); null when it is missing. */
export function queryParam(href: string | null | undefined, name: string): string | null {
  const query = href?.split('#')[0].split('?')[1];

  if (!query) {
    return null;
  }

  for (const pair of query.split('&')) {
    const [key, value = ''] = pair.split('=');

    if (decodeURIComponent(key) === name) {
      const decoded = decodeURIComponent(value.replace(/\+/g, ' ')).trim();

      return decoded === '' ? null : decoded;
    }
  }

  return null;
}

/** `?a=1&b=2` from the values that are set; empty when none are. */
export function queryString(values: Record<string, string | number | null | undefined>): string {
  const pairs = Object.entries(values)
    .filter((entry): entry is [string, string | number] => entry[1] !== null && entry[1] !== undefined && String(entry[1]) !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);

  return pairs.length > 0 ? `?${pairs.join('&')}` : '';
}

/** The meeting a portal "Join Pertemuan" link opens: `?mod=sipandai&act=discuss&id=30061&courses_id=8987`. */
export function meetingTargetFromHref(href: string | null | undefined): { id: string; courses_id: string | null } | null {
  const id = queryParam(href, 'id');

  return id && /^\d+$/.test(id) ? { id, courses_id: queryParam(href, 'courses_id') } : null;
}

/** The class a MyCIC link opens: `/class/2265?dosen_id=5240&course_id=8810`. */
export function classTargetFromUrl(url: string | null | undefined): { id: string; dosen_id: string | null; course_id: string | null } | null {
  const match = url?.match(/\/class\/(\d+)/);

  return match ? { id: match[1], dosen_id: queryParam(url, 'dosen_id'), course_id: queryParam(url, 'course_id') } : null;
}

/** Calendar days from `now` to a `YYYY-MM-DD` date: 0 today, negative once passed; null when it does not parse. */
export function daysUntil(date: string | null | undefined, now: Date = new Date()): number | null {
  const match = date?.match(/^(\d{4})-(\d{2})-(\d{2})/);

  if (!match) {
    return null;
  }

  const target = Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());

  return Math.round((target - today) / 86_400_000);
}

/** "Hari ini", "Besok", "3 hari lagi", "Lewat 2 hari", and its tone. */
export function deadlineLabel(days: number | null): { label: string; tone: Tone } {
  if (days === null) {
    return { label: 'Tanpa tenggat', tone: 'muted' };
  }

  if (days < 0) {
    return { label: `Lewat ${-days} hari`, tone: 'muted' };
  }

  if (days === 0) {
    return { label: 'Hari ini', tone: 'attention' };
  }

  if (days === 1) {
    return { label: 'Besok', tone: 'attention' };
  }

  return { label: `${days} hari lagi`, tone: days <= 3 ? 'attention' : 'neutral' };
}

/**
 * Assignments split into the ones still open (soonest first) and the ones
 * whose deadline passed (latest first); no deadline counts as open, last.
 */
export function splitByDeadline<T extends { deadline_date?: string | null }>(items: T[], now: Date = new Date()): { open: T[]; passed: T[] } {
  const withDays = items.map((item) => ({ item, days: daysUntil(item.deadline_date, now) }));
  const open = withDays
    .filter((entry) => entry.days === null || entry.days >= 0)
    .sort((a, b) => (a.days ?? Number.MAX_SAFE_INTEGER) - (b.days ?? Number.MAX_SAFE_INTEGER));
  const passed = withDays.filter((entry) => entry.days !== null && entry.days < 0).sort((a, b) => (b.days ?? 0) - (a.days ?? 0));

  return { open: open.map((entry) => entry.item), passed: passed.map((entry) => entry.item) };
}

export type MeetingStatusKind = 'present' | 'late' | 'absent' | 'permit' | 'sick' | 'upcoming' | 'unknown';

/** Meetings a half-semester to a row: 1–7 end at UTS, 8–14 at UAS. */
export const MeetingsPerRow = 7;

/** Meetings in rows of `size`, in order; the last row may be shorter. */
export function meetingRows<T>(meetings: T[], size: number = MeetingsPerRow): T[][] {
  const rows: T[][] = [];

  for (let start = 0; start < meetings.length; start += size) {
    rows.push(meetings.slice(start, start + size));
  }

  return rows;
}

/** What a meeting's attendance status says, in words. */
export function meetingStatus(status: string | null | undefined): { kind: MeetingStatusKind; label: string } {
  switch (status) {
    case 'present':
      return { kind: 'present', label: 'Hadir' };
    case 'late':
      return { kind: 'late', label: 'Terlambat' };
    case 'absent':
      return { kind: 'absent', label: 'Tidak hadir' };
    case 'permit':
      return { kind: 'permit', label: 'Izin' };
    case 'sick':
      return { kind: 'sick', label: 'Sakit' };
    case 'upcoming':
      return { kind: 'upcoming', label: 'Belum berlangsung' };
    default:
      return { kind: 'unknown', label: 'Tidak diketahui' };
  }
}

/** The classes (by `course_id`) that have no course notebook yet. */
export function coursesWithoutNotebook<T extends { join_link?: { params?: { course_id?: string } } }>(
  classes: T[],
  notebooks: { course_key?: string | null }[],
): T[] {
  const taken = new Set(notebooks.map((notebook) => notebook.course_key).filter(Boolean));
  const seen = new Set<string>();

  return classes.filter((portalClass) => {
    const key = portalClass.join_link?.params?.course_id;

    if (!key || taken.has(key) || seen.has(key)) {
      return false;
    }

    seen.add(key);

    return true;
  });
}

export type MindMapNode = { title?: string | null; children?: MindMapNode[] | null };

/** A mind map as indented lines, the root first. */
export function mindMapLines(node: MindMapNode | null | undefined, depth = 0): { depth: number; title: string }[] {
  if (!node || typeof node !== 'object') {
    return [];
  }

  const own = typeof node.title === 'string' && node.title.trim() !== '' ? [{ depth, title: node.title.trim() }] : [];

  return [...own, ...(Array.isArray(node.children) ? node.children : []).flatMap((child) => mindMapLines(child, depth + 1))];
}

/** A score the portal printed ("85", "0", "-"), as a number; null when there is none. */
export function scoreValue(score: string | null | undefined): number | null {
  const value = Number(score?.replace(',', '.').trim());

  return score?.trim() && Number.isFinite(value) ? value : null;
}

/** What a notebook's `ai_status` means for the student, or null when the assistant works. */
export function aiStatusMessage(available: boolean | undefined, status: string | null | undefined): string | null {
  if (available !== false) {
    return null;
  }

  switch (status) {
    case 'missing_key':
      return 'Asisten AI belum diaktifkan di server WeCIC. Sumber dan catatan tetap tersimpan, tetapi tanya jawab dan studio belum bisa dipakai.';
    case 'disabled':
      return 'Asisten AI dimatikan di server WeCIC. Sumber dan catatan tetap tersimpan.';
    case 'invalid_key':
    case 'model_misconfigured':
      return 'Asisten AI salah diatur di server WeCIC. Hubungi pengelola WeCIC.';
    default:
      return 'Asisten AI sedang tidak bisa dihubungi. Coba lagi nanti.';
  }
}

/** A source's reading state in words. */
export function sourceStatus(source: { status?: string | null; waiting?: boolean; stalled?: boolean }): { label: string; tone: Tone } {
  if (source.status === 'ready') {
    return { label: 'Siap', tone: 'positive' };
  }

  if (source.status === 'failed') {
    return { label: 'Gagal dibaca', tone: 'attention' };
  }

  if (source.stalled) {
    return { label: 'Tertahan', tone: 'attention' };
  }

  return { label: source.waiting ? 'Menunggu' : 'Dibaca…', tone: 'muted' };
}

/** The answer modes the notebook offers, in the order of the chip row. */
export const NotebookAskModes: readonly { mode: NotebookAskMode; label: string }[] = [
  { mode: 'answer', label: 'Jawab langsung' },
  { mode: 'tutor', label: 'Mode tutor' },
  { mode: 'ringkas', label: 'Ringkas' },
];

/** The mode an answer was given in; one from before modes existed, or unknown, was a direct answer. */
export function askModeOf(context: { mode?: string | null } | null | undefined): NotebookAskMode {
  return NotebookAskModes.find((item) => item.mode === context?.mode)?.mode ?? 'answer';
}

export function askModeLabel(mode: NotebookAskMode): string {
  return NotebookAskModes.find((item) => item.mode === mode)?.label ?? 'Jawab langsung';
}

/** Quiz answers given so far, by question index; a skipped question has none. */
export type QuizChoices = Record<number, number | undefined>;

export type QuizScore = { score: number; total: number; answered: number; missed: number[]; done: boolean };

/**
 * The questions still missed: every counted question (not marked wrong with "Soal ini salah?") whose choice is not
 * its answer, unanswered ones included.
 */
export function missedQuestions(items: NotebookQuizItem[], choices: QuizChoices, flagged: number[] = []): number[] {
  return items.flatMap((item, index) => (flagged.includes(index) || choices[index] === item.answer_index ? [] : [index]));
}

/** The score of a quiz attempt; questions marked wrong leave it, and it is `done` once every counted question is answered. */
export function scoreQuiz(items: NotebookQuizItem[], choices: QuizChoices, flagged: number[] = []): QuizScore {
  const counted = items.map((_, index) => index).filter((index) => !flagged.includes(index));
  const answered = counted.filter((index) => Number.isInteger(choices[index])).length;
  const missed = missedQuestions(items, choices, flagged);

  return { score: counted.length - missed.length, total: counted.length, answered, missed, done: counted.length > 0 && answered === counted.length };
}

/**
 * The questions "Tinjau yang salah" lists: the missed ones and the ones marked wrong, so a mark can still be undone.
 * Null when the review does not apply (not finished, or nothing missed or marked), so every question is shown.
 */
export function quizReviewItems(items: NotebookQuizItem[], score: QuizScore, flagged: number[]): number[] | null {
  const reviewed = items.map((_, index) => index).filter((index) => score.missed.includes(index) || flagged.includes(index));

  return score.done && reviewed.length ? reviewed : null;
}

/** The flashcards still to learn: every card not marked "Sudah hafal", the ones skipped with "Berikutnya" included. */
export function flashcardsToLearn(count: number, known: number[]): number[] {
  return Array.from({ length: count }, (_, index) => index).filter((index) => !known.includes(index));
}

/** The choices sent with a quiz attempt, one per question (null when skipped): the server counts the score from them. */
export function quizChoicesPayload(items: NotebookQuizItem[], choices: QuizChoices): (number | null)[] {
  return items.map((_, index) => (Number.isInteger(choices[index]) ? (choices[index] as number) : null));
}

/** How many questions a practice set made from `missed` items asks for: as many, within 5 to 15. */
export function practiceCount(missed: number[]): number {
  return Math.min(15, Math.max(5, missed.length));
}

export const NotebookDifficulties: readonly { difficulty: NotebookDifficulty; label: string }[] = [
  { difficulty: 'campuran', label: 'Campuran' },
  { difficulty: 'dasar', label: 'Dasar' },
  { difficulty: 'sulit', label: 'Sulit' },
];

/** The counts a quiz (5 to 15) or flashcards (5 to 20) may be asked for, and the one picked first. */
export function studioCounts(kind: string): { counts: number[]; initial: number; unit: string } {
  return kind === 'flashcards'
    ? { counts: [5, 10, 15, 20], initial: 15, unit: 'kartu' }
    : { counts: [5, 10, 15], initial: 10, unit: 'soal' };
}

/** What making a practice set costs, said before the button; null when the server does not say. */
export function budgetLine(budget: NotebookAiBudget | null | undefined): string | null {
  if (!budget) {
    return null;
  }

  return budget.remaining > 0
    ? `Sisa kuota AI hari ini: ${budget.remaining} dari ${budget.limit}. Membuat latihan memakai 1.`
    : 'Kuota AI hari ini sudah habis. Ulangi dan tinjau tetap bisa dipakai; buat latihan baru besok.';
}

export type QuizOptionState = 'open' | 'correct' | 'chosen-wrong' | 'other';

/**
 * How one quiz question is shown: before a choice every option is open and the answer, verdict and explanation stay
 * hidden; after it the right option and a wrong pick are marked, and the verdict is said in words.
 */
export function quizItemView(
  item: NotebookQuizItem,
  choice: number | undefined,
  flagged = false,
): { answered: boolean; options: QuizOptionState[]; verdict: string | null; explanation: string | null } {
  const options = item.options ?? [];
  const answered = Number.isInteger(choice) && !flagged;

  if (!answered) {
    return { answered: false, options: options.map(() => 'open'), verdict: null, explanation: null };
  }

  const correct = choice === item.answer_index;
  const answerText = typeof item.answer_index === 'number' ? options[item.answer_index] ?? '' : '';

  return {
    answered: true,
    options: options.map((_, index) => (index === item.answer_index ? 'correct' : index === choice ? 'chosen-wrong' : 'other')),
    verdict: correct ? 'Benar.' : `Salah. Jawaban benar: ${answerText}.`,
    explanation: item.explanation ?? null,
  };
}
