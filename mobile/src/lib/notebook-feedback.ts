import type { NotebookAiBudget, NotebookFeedback, NotebookFeedbackMode, NotebookFeedbackVerdict, NotebookMessage } from '@/api/types/classes';
import type { Tone } from '@/lib/format';

/** The answer's length the server takes (FeedbackNotebookRequest). */
export const FEEDBACK_ANSWER_MIN = 20;
export const FEEDBACK_ANSWER_MAX = 6000;
export const FEEDBACK_PROMPT_MAX = 1500;

/** As NotebookAssistant::FEEDBACK_MODES. */
export const FEEDBACK_MODES: Record<NotebookFeedbackMode, string> = {
  practice_answer: 'Jawaban latihan',
  explain_concept: 'Penjelasan konsep',
  outline_check: 'Cek kerangka',
};

/** As NotebookAssistant::FEEDBACK_VERDICTS, with the tone the verdict is drawn in (said in words too). */
export const FEEDBACK_VERDICTS: Record<NotebookFeedbackVerdict, { label: string; tone: Tone }> = {
  tepat: { label: 'Sudah tepat', tone: 'positive' },
  sebagian: { label: 'Sebagian tepat', tone: 'attention' },
  belum_tepat: { label: 'Belum tepat', tone: 'neutral' },
};

/** What the sheet opens with: the question, concept or brief, what is checked, and (a mind map node's) own sources. */
export type FeedbackRequest = { prompt: string; mode: NotebookFeedbackMode; sourceIds?: number[] };

/** The feedback check's own budget line (budgetLine() speaks of practice sets); null before the budget is known. */
export function feedbackBudgetLine(budget: NotebookAiBudget | null | undefined): string | null {
  if (!budget) {
    return null;
  }

  return budget.remaining > 0
    ? `Sisa kuota AI hari ini: ${budget.remaining} dari ${budget.limit}. Cek pemahaman memakai 1.`
    : 'Kuota AI hari ini sudah habis. Cek pemahaman bisa dipakai lagi besok.';
}

export function feedbackModeOf(mode: unknown): NotebookFeedbackMode {
  return typeof mode === 'string' && Object.hasOwn(FEEDBACK_MODES, mode) ? (mode as NotebookFeedbackMode) : 'practice_answer';
}

/** A request the sheet can open, or null for a prompt too short to send. */
export function feedbackRequest(prompt: string | null | undefined, mode: unknown = 'practice_answer'): FeedbackRequest | null {
  const text = (prompt ?? '').trim().slice(0, FEEDBACK_PROMPT_MAX);

  return text.length >= 2 ? { prompt: text, mode: feedbackModeOf(mode) } : null;
}

/** Why an answer cannot be sent yet, or null when it can. */
export function answerProblem(answer: string): string | null {
  const length = answer.trim().length;

  if (length < FEEDBACK_ANSWER_MIN) {
    return 'Jawabanmu terlalu pendek untuk dinilai. Tulis minimal 20 karakter.';
  }

  return length > FEEDBACK_ANSWER_MAX ? 'Jawaban maksimal 6.000 karakter.' : null;
}

/** A line without Markdown emphasis, code marks or citation numbers. */
function plainLine(text: string): string {
  return text
    .replace(/(^|[^\p{L}\p{N}_\]])(?:\[\d{1,4}\])+/gu, '$1')
    .replace(/[*#`]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/** The list items under a study guide's "Pertanyaan latihan" heading, as plain questions. */
export function practiceQuestions(content: string | null | undefined): string[] {
  const questions: string[] = [];
  let inSection = false;

  for (const line of (content ?? '').split('\n')) {
    const heading = line.match(/^#{1,4}\s+(.*)$/);

    if (heading) {
      inSection = /pertanyaan latihan/i.test(heading[1]);
      continue;
    }

    const item = inSection ? line.match(/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/) : null;
    const question = item ? plainLine(item[1]) : '';

    if (question.length >= 2 && !questions.includes(question)) {
      questions.push(question.slice(0, FEEDBACK_PROMPT_MAX));
    }
  }

  return questions;
}

/** The brief an assignment outline was made for, as the outline check's prompt. */
export function outlinePrompt(message: Pick<NotebookMessage, 'context'>): string {
  const focus = typeof message.context?.focus === 'string' ? message.context.focus.trim() : '';

  return focus !== '' ? focus.slice(0, FEEDBACK_PROMPT_MAX) : 'Kerangka tugas dari Catatan';
}

const points = <T extends Record<string, unknown>>(value: unknown, first: keyof T & string): T[] =>
  Array.isArray(value)
    ? value.filter((item): item is T => !!item && typeof item === 'object' && typeof (item as Record<string, unknown>)[first] === 'string' && ((item as Record<string, unknown>)[first] as string).trim() !== '')
    : [];

/** A `feedback` message's data in its shape, or null when it has no known verdict. */
export function feedbackOf(message: Pick<NotebookMessage, 'kind' | 'data'>): NotebookFeedback | null {
  const data = message.kind === 'feedback' && message.data && typeof message.data === 'object' ? (message.data as Record<string, unknown>) : null;

  if (!data || typeof data.verdict !== 'string' || !Object.hasOwn(FEEDBACK_VERDICTS, data.verdict)) {
    return null;
  }

  const numbers = (value: unknown): number[] => (Array.isArray(value) ? value.filter((n): n is number => Number.isInteger(n) && n > 0) : []);

  return {
    verdict: data.verdict as NotebookFeedbackVerdict,
    strengths: points<{ point: string; citations?: unknown }>(data.strengths, 'point').map((item) => ({ point: item.point, citations: numbers(item.citations) })),
    gaps: points<{ point: string; citations?: unknown }>(data.gaps, 'point').map((item) => ({ point: item.point, citations: numbers(item.citations) })),
    misconceptions: points<{ claim: string; correction?: unknown; citations?: unknown }>(data.misconceptions, 'claim').map((item) => ({
      claim: item.claim,
      correction: typeof item.correction === 'string' ? item.correction : '',
      citations: numbers(item.citations),
    })),
    next_step: typeof data.next_step === 'string' ? data.next_step : '',
    follow_up_question: typeof data.follow_up_question === 'string' ? data.follow_up_question : '',
  };
}

/** A point's citation numbers as the ` [1][2]` CitationText draws as chips. */
export function withCitations(text: string, numbers: number[]): string {
  return numbers.length > 0 ? `${text} ${numbers.map((n) => `[${n}]`).join('')}` : text;
}
