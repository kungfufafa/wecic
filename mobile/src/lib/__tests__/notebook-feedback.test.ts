import {
  answerProblem,
  FEEDBACK_PROMPT_MAX,
  feedbackBudgetLine,
  feedbackModeOf,
  feedbackOf,
  feedbackRequest,
  outlinePrompt,
  practiceQuestions,
  withCitations,
} from '@/lib/notebook-feedback';

const STUDY_GUIDE = [
  '## Konsep kunci',
  '- **Normalisasi**: mengurangi redundansi [1]',
  '## Pertanyaan latihan',
  '1. Mengapa **3NF** menghapus ketergantungan transitif? [2]',
  '2) Apa beda `1NF` dan 2NF?',
  '- Mengapa **3NF** menghapus ketergantungan transitif? [2]',
  '## Hal yang sering keliru',
  '- Bukan pertanyaan',
].join('\n');

describe('practiceQuestions', () => {
  it('takes the items under "Pertanyaan latihan" only, as plain text without citations, once each', () => {
    expect(practiceQuestions(STUDY_GUIDE)).toEqual(['Mengapa 3NF menghapus ketergantungan transitif?', 'Apa beda 1NF dan 2NF?']);
  });

  it('finds nothing without the section', () => {
    expect(practiceQuestions('## Ringkasan\n- Poin')).toEqual([]);
    expect(practiceQuestions(null)).toEqual([]);
  });
});

describe('feedbackRequest', () => {
  it('trims and caps the prompt and falls back to a practice answer for an unknown mode', () => {
    expect(feedbackRequest('  Apa itu 3NF?  ', 'explain_concept')).toEqual({ prompt: 'Apa itu 3NF?', mode: 'explain_concept' });
    expect(feedbackRequest('Apa itu 3NF?', 'kerjakan')?.mode).toBe('practice_answer');
    expect(feedbackRequest('x'.repeat(2000))?.prompt).toHaveLength(FEEDBACK_PROMPT_MAX);
    expect(feedbackRequest(' a ')).toBeNull();
    expect(feedbackModeOf('outline_check')).toBe('outline_check');
  });
});

describe('answerProblem', () => {
  it('asks for at least 20 characters and at most 6000', () => {
    expect(answerProblem('  terlalu pendek  ')).toMatch(/minimal 20 karakter/);
    expect(answerProblem('3NF menghapus ketergantungan transitif.')).toBeNull();
    expect(answerProblem('a'.repeat(6001))).toBe('Jawaban maksimal 6.000 karakter.');
  });
});

describe('outlinePrompt', () => {
  it('uses the brief the outline was made for', () => {
    expect(outlinePrompt({ context: { focus: ' Makalah normalisasi ' } })).toBe('Makalah normalisasi');
    expect(outlinePrompt({ context: null })).toBe('Kerangka tugas dari Catatan');
  });
});

describe('feedbackOf', () => {
  it('reads a feedback message, dropping empty points and odd citation numbers', () => {
    expect(
      feedbackOf({
        kind: 'feedback',
        data: {
          verdict: 'sebagian',
          strengths: [{ point: 'Tepat.', citations: [1, 0, 'x'] }, { point: ' ' }],
          gaps: 'bukan daftar',
          misconceptions: [{ claim: 'Semua atribut', citations: [2] }],
          next_step: 'Baca 2NF.',
          follow_up_question: 7,
          corrected_answer: 'tidak dipakai',
        },
      }),
    ).toEqual({
      verdict: 'sebagian',
      strengths: [{ point: 'Tepat.', citations: [1] }],
      gaps: [],
      misconceptions: [{ claim: 'Semua atribut', correction: '', citations: [2] }],
      next_step: 'Baca 2NF.',
      follow_up_question: '',
    });
  });

  it('is null for another kind or an unknown verdict', () => {
    expect(feedbackOf({ kind: 'quiz', data: { verdict: 'tepat' } })).toBeNull();
    expect(feedbackOf({ kind: 'feedback', data: { verdict: 'luar biasa' } })).toBeNull();
    expect(feedbackOf({ kind: 'feedback', data: null })).toBeNull();
  });
});

describe('withCitations', () => {
  it('adds the numbers CitationText draws as chips', () => {
    expect(withCitations('Poin', [1, 3])).toBe('Poin [1][3]');
    expect(withCitations('Poin', [])).toBe('Poin');
  });
});

describe('feedbackBudgetLine', () => {
  it('speaks of the feedback check, not practice sets, also when the quota is used up', () => {
    expect(feedbackBudgetLine(null)).toBeNull();
    expect(feedbackBudgetLine({ limit: 60, remaining: 12, used: 48 } as never)).toBe('Sisa kuota AI hari ini: 12 dari 60. Cek pemahaman memakai 1.');
    expect(feedbackBudgetLine({ limit: 60, remaining: 0, used: 60 } as never)).toBe('Kuota AI hari ini sudah habis. Cek pemahaman bisa dipakai lagi besok.');
    expect(feedbackBudgetLine({ limit: 60, remaining: 0, used: 60 } as never)).not.toMatch(/latihan/);
  });
});
