import {
  checkProgress,
  checklistTone,
  columnLabel,
  formatDecimal,
  formatDelta,
  groupBySemester,
  krsStatusTone,
  letterTone,
  periodTiming,
  semesterLabel,
  skkmTone,
  stepState,
} from '@/lib/academic';

describe('letterTone', () => {
  it('reads letters by their first character', () => {
    expect(letterTone('A')).toBe('success');
    expect(letterTone('a-')).toBe('success');
    expect(letterTone('B+')).toBe('brand');
    expect(letterTone('C')).toBe('warning');
    expect(letterTone('D')).toBe('danger');
    expect(letterTone('E')).toBe('danger');
    expect(letterTone('T')).toBe('neutral');
    expect(letterTone(null)).toBe('neutral');
  });
});

describe('statuses', () => {
  it('maps KRS tracker states', () => {
    expect(stepState('done')).toBe('done');
    expect(stepState('current')).toBe('current');
    expect(stepState('todo')).toBe('todo');
    expect(stepState(undefined)).toBe('todo');
  });

  it('maps KRS, SKKM and checklist statuses to tones', () => {
    expect(krsStatusTone('validated')).toBe('positive');
    expect(krsStatusTone('pending_dpa')).toBe('attention');
    expect(krsStatusTone('something')).toBe('neutral');
    expect(skkmTone('valid')).toBe('success');
    expect(skkmTone('invalid')).toBe('danger');
    expect(skkmTone('pending')).toBe('warning');
    expect(skkmTone(null)).toBe('neutral');
    expect(checklistTone('passed')).toBe('success');
    expect(checklistTone('failed')).toBe('danger');
    expect(checklistTone('manual')).toBe('neutral');
  });
});

describe('numbers', () => {
  it('formats grade points with a decimal comma', () => {
    expect(formatDecimal(3.21)).toBe('3,21');
    expect(formatDecimal(3)).toBe('3,00');
    expect(formatDecimal(29.25, 1)).toBe('29,3');
    expect(formatDecimal(null)).toBe('–');
  });

  it('signs a change and drops no change', () => {
    expect(formatDelta(0.18)).toBe('+0,18');
    expect(formatDelta(-0.05)).toBe('−0,05');
    expect(formatDelta(0)).toBeNull();
    expect(formatDelta(undefined)).toBeNull();
  });

  it('shows a check as current over target', () => {
    expect(checkProgress(42, 130)).toBe('42 / 130');
    expect(checkProgress(3.21, 2.75)).toBe('3,21 / 2,75');
    expect(checkProgress(3.36, 2)).toBe('3,36 / 2,00');
    expect(checkProgress(null, 2)).toBeNull();
  });
});

describe('labels', () => {
  it('names KHS columns and semesters', () => {
    expect(columnLabel('uts')).toBe('UTS');
    expect(columnLabel('absen')).toBe('Absen');
    expect(columnLabel('praktikum')).toBe('Praktikum');
    expect(semesterLabel(3)).toBe('Semester 3');
    expect(semesterLabel('T')).toBe('Semester T (konversi)');
    expect(semesterLabel(null)).toBe('Tanpa semester');
  });
});

describe('groupBySemester', () => {
  it('orders numbered semesters first and keeps the rest after', () => {
    const groups = groupBySemester([
      { name: 'c', semester: 2 },
      { name: 'x', semester: null },
      { name: 'a', semester: 1 },
      { name: 't', semester: 'T' },
      { name: 'b', semester: 1 },
    ]);

    expect(groups.map((group) => group.label)).toEqual(['Semester 1', 'Semester 2', 'Tanpa semester', 'Semester T (konversi)']);
    expect(groups[0].items.map((item) => item.name)).toEqual(['a', 'b']);
  });
});

describe('periodTiming', () => {
  it('says how long an open or upcoming period has, or that it ended', () => {
    expect(periodTiming({ is_open: true, days_left: 3 })).toEqual({ label: 'Dibuka · sisa 3 hari', tone: 'attention' });
    expect(periodTiming({ is_upcoming: true, days_until_start: 5 })?.label).toBe('Dibuka 5 hari lagi');
    expect(periodTiming({ is_past: true })?.label).toBe('Sudah berakhir');
    expect(periodTiming({})).toBeNull();
  });
});
