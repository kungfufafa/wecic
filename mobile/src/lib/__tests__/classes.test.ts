import {
  aiStatusMessage,
  classTargetFromUrl,
  coursesWithoutNotebook,
  daysUntil,
  deadlineLabel,
  meetingRows,
  meetingStatus,
  meetingTargetFromHref,
  mindMapLines,
  queryParam,
  queryString,
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

  it('finds the class a MyCIC class link opens', () => {
    expect(classTargetFromUrl('/class/2265?dosen_id=5240&course_id=8810')).toEqual({ id: '2265', dosen_id: '5240', course_id: '8810' });
    expect(classTargetFromUrl('/class/2265')).toEqual({ id: '2265', dosen_id: null, course_id: null });
    expect(classTargetFromUrl('/notebook/3')).toBeNull();
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

  it('flattens a mind map into indented lines', () => {
    expect(
      mindMapLines({ title: 'ERP', children: [{ title: 'Modul', children: [{ title: 'Keuangan' }, { title: ' ' }] }, { title: 'Sejarah' }] }),
    ).toEqual([
      { depth: 0, title: 'ERP' },
      { depth: 1, title: 'Modul' },
      { depth: 2, title: 'Keuangan' },
      { depth: 1, title: 'Sejarah' },
    ]);
    expect(mindMapLines(null)).toEqual([]);
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
