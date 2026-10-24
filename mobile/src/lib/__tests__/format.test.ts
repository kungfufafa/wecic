import { attendanceSummary, classMeta, firstName, formatDate, formatDateTime, studentNumber, timeRange } from '@/lib/format';

describe('attendanceSummary', () => {
  it('shows a recorded attendance with its time', () => {
    expect(
      attendanceSummary({ attendance: { state: 'ready', status: { already_marked: true, label: 'Hadir', time: '08:05' } } }),
    ).toEqual({ label: 'Hadir · 08:05', tone: 'positive' });
  });

  it('says when attendance is open, still being checked or unreadable', () => {
    expect(attendanceSummary({ attendance: { state: 'ready', actionable: true } })).toEqual({ label: 'Absensi dibuka', tone: 'attention' });
    expect(attendanceSummary({ attendance: { state: 'checking' } }).tone).toBe('muted');
    expect(attendanceSummary({ attendance: { state: 'unavailable' } }).label).toBe('Status absensi belum bisa dibaca');
  });

  it('falls back to the portal label, then to a neutral one', () => {
    expect(attendanceSummary({ attendance: { state: 'ready', status: { label: 'Tidak hadir' } } }).label).toBe('Tidak hadir');
    expect(attendanceSummary({})).toEqual({ label: 'Belum ada absensi', tone: 'neutral' });
  });
});

describe('class details', () => {
  it('joins the day, time and room that exist', () => {
    expect(
      classMeta({
        schedule: { day: 'Senin', start_time: '08:00', end_time: '09:40' },
        class_room_info: { room: 'Lab 1' },
      }),
    ).toBe('Senin · 08:00–09:40 · Lab 1');
    expect(classMeta({ schedule: { day: 'Selasa' }, class_room_info: { room: ' ' } })).toBe('Selasa');
  });

  it('uses the portal time text when there is no start and end', () => {
    expect(timeRange({ time: '13.00 - 14.40' })).toBe('13.00 - 14.40');
    expect(timeRange(undefined)).toBeNull();
  });
});

describe('student details', () => {
  it('reads the NIM under either key and the first name', () => {
    expect(studentNumber({ nim: '2099' })).toBe('2099');
    expect(studentNumber({ student_id: '2100' })).toBe('2100');
    expect(firstName({ name: '  Siti Aminah ' })).toBe('Siti');
    expect(firstName(null)).toBeNull();
  });

  it('reads a schedule date as a calendar day', () => {
    expect(formatDate('2026-10-24')).toMatch(/24/);
    expect(formatDate('2026-10-24')).toMatch(/2026/);
    expect(formatDate('besok')).toBe('besok');
    expect(formatDate(undefined)).toBe('');
  });

  it('keeps a date it cannot parse as it is', () => {
    expect(formatDateTime('bukan tanggal')).toBe('bukan tanggal');
    expect(formatDateTime(null)).toBe('');
    expect(formatDateTime('2026-10-24T02:30:00Z')).not.toBe('');
  });
});
