import type { NotebookReviewCard } from '@/api/types/classes';
import {
  aiStatusMessage,
  askModeLabel,
  budgetLine,
  flashcardsToLearn,
  missedQuestions,
  practiceCount,
  quizChoicesPayload,
  quizItemView,
  quizReviewItems,
  scoreQuiz,
  studioCounts,
  askModeOf,
  NotebookAskModes,
  coursesWithoutNotebook,
  daysUntil,
  deadlineLabel,
  meetingRows,
  meetingStatus,
  meetingTargetFromHref,
  mindMapBudgetLine,
  mindMapConcept,
  mindMapLines,
  mindMapParent,
  mindMapQuestion,
  mindMapSourceIds,
  queryParam,
  queryString,
  formatReviewInterval,
  REVIEW_GRADES,
  reviewAfterCard,
  reviewCardMeta,
  reviewDueLabel,
  reviewFinishedCopy,
  reviewNextLine,
  reviewProgressLine,
  reviewQueue,
  scoreValue,
  sourceStatus,
  splitByDeadline,
} from '@/lib/classes';

describe('portal links', () => {
  it('reads a query parameter from a portal or MyCIC link', () => {
    expect(queryParam('?mod=sipandai&act=discuss&id=30061&courses_id=8987', 'courses_id')).toBe('8987');
    expect(queryParam('/meeting/28928?courses_id=8987#tugas', 'courses_id')).toBe('8987');
    expect(queryParam('/meeting/28928', 'courses_id')).toBeNull();
    expect(queryParam('?a=&b=1', 'a')).toBeNull();
    expect(queryParam(null, 'id')).toBeNull();
  });

  it('builds a query from the values that are set', () => {
    expect(queryString({ dosen_id: '5240', course_id: '8810' })).toBe('?dosen_id=5240&course_id=8810');
    expect(queryString({ dosen_id: undefined, course_id: '' , page: 2 })).toBe('?page=2');
    expect(queryString({ q: 'a b&c' })).toBe('?q=a%20b%26c');
    expect(queryString({})).toBe('');
  });

  it('finds the meeting a join link opens', () => {
    expect(meetingTargetFromHref('?mod=sipandai&act=discuss&id=30061&courses_id=8987')).toEqual({ id: '30061', courses_id: '8987' });
    expect(meetingTargetFromHref('?mod=sipandai&act=discuss&id=30061')).toEqual({ id: '30061', courses_id: null });
    expect(meetingTargetFromHref('?mod=sipandai&id=abc')).toBeNull();
    expect(meetingTargetFromHref(undefined)).toBeNull();
  });
});

describe('deadlines', () => {
  const now = new Date(2026, 10, 2, 16, 45);

  it('counts calendar days to a date', () => {
    expect(daysUntil('2026-11-02', now)).toBe(0);
    expect(daysUntil('2026-11-05', now)).toBe(3);
    expect(daysUntil('2026-10-30', now)).toBe(-3);
    expect(daysUntil('besok', now)).toBeNull();
    expect(daysUntil(null, now)).toBeNull();
  });

  it('says how close a deadline is', () => {
    expect(deadlineLabel(0)).toEqual({ label: 'Hari ini', tone: 'attention' });
    expect(deadlineLabel(1).label).toBe('Besok');
    expect(deadlineLabel(3)).toEqual({ label: '3 hari lagi', tone: 'attention' });
    expect(deadlineLabel(9).tone).toBe('neutral');
    expect(deadlineLabel(-2)).toEqual({ label: 'Lewat 2 hari', tone: 'muted' });
    expect(deadlineLabel(null).label).toBe('Tanpa tenggat');
  });

  it('puts open assignments first by deadline and passed ones apart, latest first', () => {
    const items = [
      { name: 'lewat lama', deadline_date: '2026-10-01' },
      { name: 'tanpa', deadline_date: null },
      { name: 'minggu depan', deadline_date: '2026-11-09' },
      { name: 'besok', deadline_date: '2026-11-03' },
      { name: 'kemarin', deadline_date: '2026-11-01' },
    ];
    const { open, passed } = splitByDeadline(items, now);

    expect(open.map((item) => item.name)).toEqual(['besok', 'minggu depan', 'tanpa']);
    expect(passed.map((item) => item.name)).toEqual(['kemarin', 'lewat lama']);
  });
});

describe('class details', () => {
  it('names each attendance status', () => {
    expect(meetingStatus('present')).toEqual({ kind: 'present', label: 'Hadir' });
    expect(meetingStatus('absent').label).toBe('Tidak hadir');
    expect(meetingStatus('upcoming').kind).toBe('upcoming');
    expect(meetingStatus('???').kind).toBe('unknown');
    expect(meetingStatus(null).kind).toBe('unknown');
  });

  it('reads a printed score', () => {
    expect(scoreValue('85')).toBe(85);
    expect(scoreValue('87,5')).toBe(87.5);
    expect(scoreValue('0')).toBe(0);
    expect(scoreValue('-')).toBeNull();
    expect(scoreValue('')).toBeNull();
    expect(scoreValue(null)).toBeNull();
  });
});

describe('notebooks', () => {
  it('lists each class without a course notebook once', () => {
    const classes = [
      { name: 'A', join_link: { params: { course_id: '1' } } },
      { name: 'B', join_link: { params: { course_id: '2' } } },
      { name: 'B lagi', join_link: { params: { course_id: '2' } } },
      { name: 'tanpa', join_link: {} },
    ];

    expect(coursesWithoutNotebook(classes, [{ course_key: '1' }, { course_key: null }]).map((c) => c.name)).toEqual(['B']);
  });

  it('flattens a mind map into indented lines, each with the titles above it', () => {
    expect(
      mindMapLines({ title: 'ERP', children: [{ title: 'Modul', children: [{ title: 'Keuangan' }, { title: ' ' }] }, { title: 'Sejarah' }] }),
    ).toEqual([
      { depth: 0, title: 'ERP', citations: [], path: [] },
      { depth: 1, title: 'Modul', citations: [], path: ['ERP'] },
      { depth: 2, title: 'Keuangan', citations: [], path: ['ERP', 'Modul'] },
      { depth: 1, title: 'Sejarah', citations: [], path: ['ERP'] },
    ]);
    expect(mindMapLines(null)).toEqual([]);
  });

  it('keeps a node\'s citations and its parent for the node actions', () => {
    const lines = mindMapLines({
      title: 'SQL',
      citations: [1],
      children: [{ title: 'JOIN', citations: [1, 0, 2.5, 3], children: [{ title: 'INNER', citations: null }] }],
    });

    expect(lines.map((line) => line.citations)).toEqual([[1], [1, 3], []]);
    expect(mindMapParent(lines[2])).toBe('JOIN');
    expect(mindMapParent(lines[0])).toBeNull();
    expect(mindMapQuestion(lines[2])).toBe('Jelaskan "INNER" dalam konteks "JOIN" berdasarkan sumber.');
    expect(mindMapQuestion(lines[0])).toBe('Jelaskan "SQL" berdasarkan sumber.');
    expect(mindMapConcept(lines[1])).toBe('JOIN (dalam konteks SQL)');
  });

  it('uses the mind map\'s own sources that are still ready, else none', () => {
    expect(mindMapSourceIds([3, 8], [3, 5])).toEqual([3]);
    expect(mindMapSourceIds([8], [3])).toBeNull();
    expect(mindMapSourceIds(undefined, [3])).toBeNull();
  });

  it('says each mind map action costs one daily request', () => {
    expect(mindMapBudgetLine(null)).toBe('Tiap pilihan memakai 1 permintaan dari kuota AI harianmu.');
    expect(mindMapBudgetLine({ limit: 60, used: 56, remaining: 4 })).toBe('Sisa kuota AI hari ini: 4 dari 60. Tiap pilihan memakai 1 permintaan.');
    expect(mindMapBudgetLine({ limit: 60, used: 60, remaining: 0 })).toBe('Kuota AI hari ini sudah habis. Coba lagi besok.');
  });
});

describe('notebook states', () => {
  it('explains why the assistant is off, and stays quiet when it works', () => {
    expect(aiStatusMessage(true, 'ok')).toBeNull();
    expect(aiStatusMessage(undefined, null)).toBeNull();
    expect(aiStatusMessage(false, 'missing_key')).toMatch(/belum diaktifkan/);
    expect(aiStatusMessage(false, 'unreachable')).toMatch(/tidak bisa dihubungi/);
  });

  it('names a source state', () => {
    expect(sourceStatus({ status: 'ready' })).toEqual({ label: 'Siap', tone: 'positive' });
    expect(sourceStatus({ status: 'failed' }).label).toBe('Gagal dibaca');
    expect(sourceStatus({ status: 'pending', waiting: true }).label).toBe('Menunggu');
    expect(sourceStatus({ status: 'pending', stalled: true }).label).toBe('Tertahan');
    expect(sourceStatus({ status: 'pending' }).label).toBe('Dibaca…');
  });
});

describe('meetingRows', () => {
  const numbers = (count: number) => Array.from({ length: count }, (_, index) => index + 1);

  it('puts a half-semester on each row, so rows end at meeting 7 and 14', () => {
    expect(meetingRows(numbers(14))).toEqual([numbers(7), numbers(14).slice(7)]);
  });

  it('keeps a short last row and extra meetings on a row of their own', () => {
    expect(meetingRows(numbers(16)).map((row) => row.length)).toEqual([7, 7, 2]);
    expect(meetingRows(numbers(5))).toEqual([numbers(5)]);
  });

  it('has no rows for no meetings', () => {
    expect(meetingRows([])).toEqual([]);
  });
});

describe('notebook answer modes', () => {
  it('offers the three modes in order', () => {
    expect(NotebookAskModes.map((item) => item.label)).toEqual(['Jawab langsung', 'Mode tutor', 'Ringkas']);
  });

  it('reads the mode an answer was given in, defaulting to a direct answer', () => {
    expect(askModeOf({ mode: 'tutor' })).toBe('tutor');
    expect(askModeOf({ mode: 'ringkas' })).toBe('ringkas');
    expect(askModeOf({ mode: 'ujian' })).toBe('answer');
    expect(askModeOf({})).toBe('answer');
    expect(askModeOf(null)).toBe('answer');
    expect(askModeLabel('tutor')).toBe('Mode tutor');
  });
});

describe('quiz practice', () => {
  const quiz = [
    { question: 'Fungsi JOIN?', options: ['Menghapus', 'Menggabungkan tabel'], answer_index: 1, explanation: 'JOIN menggabungkan tabel.' },
    { question: 'Fungsi WHERE?', options: ['Menyaring baris', 'Mengurutkan'], answer_index: 0, explanation: 'WHERE menyaring baris.' },
    { question: 'Fungsi ORDER BY?', options: ['Mengurutkan', 'Menghapus'], answer_index: 0, explanation: 'ORDER BY mengurutkan.' },
  ];

  it('scores only once every counted question is answered', () => {
    expect(scoreQuiz(quiz, { 0: 1 })).toEqual({ score: 1, total: 3, answered: 1, missed: [1, 2], done: false });
    expect(scoreQuiz(quiz, { 0: 1, 1: 1, 2: 0 })).toEqual({ score: 2, total: 3, answered: 3, missed: [1], done: true });
  });

  it('leaves questions marked wrong out of the score and the missed list', () => {
    expect(scoreQuiz(quiz, { 0: 1, 2: 1 }, [1])).toEqual({ score: 1, total: 2, answered: 2, missed: [2], done: true });
    expect(missedQuestions(quiz, { 0: 0, 1: 0 }, [2])).toEqual([0]);
    expect(scoreQuiz(quiz, {}, [0, 1, 2]).done).toBe(false);
  });

  it('counts unanswered questions as missed', () => {
    expect(missedQuestions(quiz, { 1: 0 })).toEqual([0, 2]);
  });

  it('keeps questions marked wrong in the review, so the mark can be undone', () => {
    const flagged = [1];
    const choices = { 0: 1, 1: 1, 2: 0 };

    expect(quizReviewItems(quiz, scoreQuiz(quiz, choices, flagged), flagged)).toEqual([1]);
    expect(quizReviewItems(quiz, scoreQuiz(quiz, choices), [])).toEqual([1]);
  });

  it('shows every question when there is nothing to review or the quiz is not finished', () => {
    expect(quizReviewItems(quiz, scoreQuiz(quiz, { 0: 1, 1: 0, 2: 0 }), [])).toBeNull();
    expect(quizReviewItems(quiz, scoreQuiz(quiz, { 0: 0 }), [])).toBeNull();
  });

  it('counts flashcards skipped with Berikutnya as still to learn', () => {
    expect(flashcardsToLearn(4, [3])).toEqual([0, 1, 2]);
    expect(flashcardsToLearn(3, [2, 0, 1])).toEqual([]);
  });

  it('sends one choice per question, null when skipped, for the server to score', () => {
    expect(quizChoicesPayload(quiz, { 0: 1, 2: 0 })).toEqual([1, null, 0]);
  });

  it('hides the answer, verdict and explanation until an option is picked', () => {
    expect(quizItemView(quiz[0], undefined)).toEqual({ answered: false, options: ['open', 'open'], verdict: null, explanation: null });
  });

  it('marks the right option and a wrong pick, and says the verdict in words', () => {
    expect(quizItemView(quiz[0], 0)).toEqual({
      answered: true,
      options: ['chosen-wrong', 'correct'],
      verdict: 'Salah. Jawaban benar: Menggabungkan tabel.',
      explanation: 'JOIN menggabungkan tabel.',
    });
    expect(quizItemView(quiz[0], 1).verdict).toBe('Benar.');
    expect(quizItemView(quiz[0], 1, true).answered).toBe(false);
  });

  it('asks a practice set for as many questions as were missed, within 5 to 15', () => {
    expect(practiceCount([1])).toBe(5);
    expect(practiceCount(Array.from({ length: 9 }, (_, index) => index))).toBe(9);
    expect(practiceCount(Array.from({ length: 20 }, (_, index) => index))).toBe(15);
  });

  it('offers 5 to 15 quiz questions and 5 to 20 flashcards', () => {
    expect(studioCounts('quiz')).toEqual({ counts: [5, 10, 15], initial: 10, unit: 'soal' });
    expect(studioCounts('flashcards').counts.at(-1)).toBe(20);
  });

  it('says what is left of the daily budget before a practice set is made', () => {
    expect(budgetLine({ limit: 60, used: 57, remaining: 3 })).toBe('Sisa kuota AI hari ini: 3 dari 60. Membuat latihan memakai 1.');
    expect(budgetLine({ limit: 60, used: 60, remaining: 0 })).toMatch(/sudah habis/);
    expect(budgetLine(undefined)).toBeNull();
  });
});

describe('Ulasan harian', () => {
  const card = (id: number, extra: Partial<NotebookReviewCard> = {}): NotebookReviewCard => ({ id, notebook_id: 3, front: `Depan ${id}`, back: `Belakang ${id}`, ...extra });

  it('keeps the server order without the cards graded or suspended in this sitting', () => {
    const cards = [card(1), card(2, { is_new: true }), card(3, { suspended: true }), card(4, { is_new: true })];

    expect(reviewQueue(cards, []).map((item) => item.id)).toEqual([1, 2, 4]);
    expect(reviewQueue(cards, [1, 4]).map((item) => item.id)).toEqual([2]);
    expect(reviewQueue(null, [])).toEqual([]);
  });

  it('takes a graded or held card out of the sitting for good, and reads the queue again when none is left', () => {
    const cards = [card(1), card(2)];

    expect(reviewAfterCard(cards, [], 1)).toEqual({ done: [1], reload: false });
    // The last card stays out even if the reload then fails, so it is never graded twice.
    expect(reviewAfterCard(cards, [1], 2)).toEqual({ done: [1, 2], reload: true });
    expect(reviewAfterCard(cards, [1, 2], 2)).toEqual({ done: [1, 2], reload: true });
  });

  it('says what the finished screen is about, held cards included', () => {
    const now = Date.parse('2026-11-23T09:00:00.000Z');

    expect(reviewFinishedCopy({ total: 0, suspended: 0 }, null, now)).toMatchObject({ title: 'Belum ada kartu ulasan', resume: false });
    expect(reviewFinishedCopy(undefined, null, now).title).toBe('Belum ada kartu ulasan');
    expect(reviewFinishedCopy({ total: 3, suspended: 3 }, null, now)).toEqual({
      title: 'Semua kartu sedang ditahan',
      description: '3 kartu sedang ditahan, jadi tidak ada yang muncul di ulasan.',
      resume: true,
    });
    expect(reviewFinishedCopy({ total: 5, suspended: 2 }, '2026-11-23T09:10:00.000Z', now)).toEqual({
      title: 'Selesai untuk sekarang',
      description: 'Kartu berikutnya muncul dalam 10 mnt. 2 kartu sedang ditahan dan tidak muncul sampai diaktifkan lagi.',
      resume: true,
    });
    expect(reviewFinishedCopy({ total: 5, suspended: 0 }, null, now)).toEqual({ title: 'Selesai untuk sekarang', description: 'Tidak ada kartu lain yang terjadwal.', resume: false });
  });

  it('says what is left and how many are new', () => {
    expect(reviewProgressLine([card(1), card(2, { is_new: true })])).toBe('Sisa 2 kartu hari ini · 1 baru');
    expect(reviewProgressLine([card(1)])).toBe('Sisa 1 kartu hari ini');
    expect(reviewProgressLine([])).toBe('Tidak ada kartu yang menunggu.');
  });

  it('formats when a grade brings the card back', () => {
    expect(formatReviewInterval(10)).toBe('10 mnt');
    expect(formatReviewInterval(0)).toBe('1 mnt');
    expect(formatReviewInterval(180)).toBe('3 jam');
    expect(formatReviewInterval(1440)).toBe('1 hari');
    expect(formatReviewInterval(25 * 1440)).toBe('25 hari');
    expect(formatReviewInterval(90 * 1440)).toBe('3 bln');
    expect(formatReviewInterval(undefined)).toBe('1 mnt');
    expect(REVIEW_GRADES.map((item) => item.grade)).toEqual(['lagi', 'sulit', 'bisa', 'mudah']);
  });

  it('describes the card, the due badge and the next card', () => {
    expect(reviewCardMeta(card(1, { notebook_title: 'Basis Data', meeting_number: 3, topic: 'Kunci' }))).toBe('Basis Data · Pertemuan 3 · Topik: Kunci');
    expect(reviewCardMeta(card(1))).toBe('');
    expect(reviewDueLabel(5)).toBe('5 kartu menunggu');
    expect(reviewDueLabel(150)).toBe('99+ kartu menunggu');
    expect(reviewDueLabel(0)).toBeNull();
    expect(reviewDueLabel(undefined)).toBeNull();
    expect(reviewNextLine('2026-11-23T09:10:00.000Z', Date.parse('2026-11-23T09:00:00.000Z'))).toBe('Kartu berikutnya muncul dalam 10 mnt.');
    expect(reviewNextLine(null)).toBeNull();
  });
});
