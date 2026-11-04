import {
  extractAttendanceCode,
  meetingAttendanceState,
  meetingAttendanceView,
  meetingTakesAttendanceCode,
  parseIndonesianDate,
  todayClassTakesAttendanceCode,
  todayIso,
} from '@/lib/attendance';

describe('extractAttendanceCode', () => {
  it('reads a bare code, lowercased and cut to 8 characters', () => {
    expect(extractAttendanceCode(' A1B2C3D4 ')).toBe('a1b2c3d4');
    expect(extractAttendanceCode('a1b2c3d4e5f6')).toBe('a1b2c3d4');
  });

  it('prefers a hinted code, then one with hex letters, over digits-only runs', () => {
    expect(extractAttendanceCode('NIM 20990000001 kode: 5f3a9c21')).toBe('5f3a9c21');
    expect(extractAttendanceCode('20990000001 then abcd1234')).toBe('abcd1234');
    expect(extractAttendanceCode('unix=12345678 x 99999999abcdef')).toBe('12345678');
  });

  it('finds the code in a portal URL', () => {
    expect(extractAttendanceCode('https://my.cic.ac.id/portal/?mod=absen&kode=9f7c1e2a')).toBe('9f7c1e2a');
  });

  it('has nothing for empty or code-less text', () => {
    expect(extractAttendanceCode('')).toBeNull();
    expect(extractAttendanceCode(null)).toBeNull();
    expect(extractAttendanceCode('halo dunia')).toBeNull();
  });
});

describe('meeting attendance', () => {
  it('reads the portal state, or its words', () => {
    expect(meetingAttendanceState({ state: 'present' })).toBe('present');
    expect(meetingAttendanceState({ status: 'Terlambat', time: '08:10' })).toBe('late');
    expect(meetingAttendanceState({ status: 'Tidak hadir' })).toBe('absent');
    expect(meetingAttendanceState({ status: 'Absensi belum dibuka' })).toBe('unavailable');
    expect(meetingAttendanceState({ status: 'Silakan scan QR' })).toBe('open');
    expect(meetingAttendanceState({})).toBe('unknown');
    expect(meetingAttendanceState(null)).toBe('unknown');
  });

  it('offers the form only for today and an open session', () => {
    expect(meetingAttendanceView({ state: 'present' }, '2026-10-05', '2026-11-02')).toBe('recorded');
    expect(meetingAttendanceView({ state: 'open' }, '2026-10-05', '2026-11-02')).toBe('past');
    expect(meetingAttendanceView({ state: 'open' }, '2026-12-01', '2026-11-02')).toBe('upcoming');
    expect(meetingAttendanceView({ state: 'absent' }, '2026-11-02', '2026-11-02')).toBe('closed');
    expect(meetingAttendanceView({ status: 'Absensi sudah ditutup' }, null, '2026-11-02')).toBe('closed');
    expect(meetingAttendanceView({ status: 'Belum dibuka' }, null, '2026-11-02')).toBe('not_open');
    expect(meetingAttendanceView({ state: 'open' }, '2026-11-02', '2026-11-02')).toBe('open');
  });

  it('still takes a code on a meeting page the portal marks "Tidak hadir"', () => {
    expect(meetingTakesAttendanceCode('open')).toBe(true);
    expect(meetingTakesAttendanceCode('closed')).toBe(true);
    expect(meetingTakesAttendanceCode('recorded')).toBe(false);
    expect(meetingTakesAttendanceCode('past')).toBe(false);
    expect(meetingTakesAttendanceCode('upcoming')).toBe(false);
    expect(meetingTakesAttendanceCode('not_open')).toBe(false);
  });
});

describe('todayClassTakesAttendanceCode', () => {
  const payload = { courseid: '9520', modulesid: '37574' };

  it('takes a code whatever the window, even "Tidak hadir"', () => {
    expect(todayClassTakesAttendanceCode({ already_marked: false }, payload)).toBe(true);
    const tidakHadir = { already_marked: false, label: 'Tidak hadir', window: 'closed' as const };
    expect(todayClassTakesAttendanceCode(tidakHadir, payload)).toBe(true);
    expect(todayClassTakesAttendanceCode(null, payload)).toBe(true);
  });

  it('stops once the portal records the student', () => {
    expect(todayClassTakesAttendanceCode({ already_marked: true }, payload)).toBe(false);
  });

  it('needs a meeting to send to', () => {
    expect(todayClassTakesAttendanceCode({ already_marked: false }, null)).toBe(false);
    expect(todayClassTakesAttendanceCode({ already_marked: false }, { ...payload, modulesid: '' })).toBe(false);
  });
});

describe('dates', () => {
  it('reads the portal date', () => {
    expect(parseIndonesianDate('Senin, 05 Oktober 2026')).toBe('2026-10-05');
    expect(parseIndonesianDate('1 mei 2025')).toBe('2025-05-01');
    expect(parseIndonesianDate('besok')).toBeNull();
    expect(parseIndonesianDate(null)).toBeNull();
  });

  it('formats today on this device', () => {
    expect(todayIso(new Date(2026, 10, 2, 23, 59))).toBe('2026-11-02');
  });
});
