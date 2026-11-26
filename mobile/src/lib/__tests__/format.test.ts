import {
  attendanceSummary,
  attendedCount,
  courseColorIndex,
  courseInitials,
  firstName,
  formatDate,
  formatDateTime,
  formatShortDate,
  greeting,
  groupClassesByDay,
  initials,
  matchesClassQuery,
  normalizeDay,
  shortTime,
  studentNumber,
  timeRange,
} from '@/lib/format';

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
  it('drops the seconds the portal adds to start and end times', () => {
    expect(timeRange({ start_time: '14:30:00', end_time: '16:00:00' })).toBe('14:30–16:00');
    expect(shortTime('8.05')).toBe('08:05');
    expect(shortTime('14:30:00')).toBe('14:30');
    expect(shortTime('13.00 - 14.40')).toBe('13.00 - 14.40');
    expect(shortTime(null)).toBeNull();
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

describe('initials and greeting', () => {
  it('takes the first letters of the first two names', () => {
    expect(initials({ name: 'Mahasiswa Contoh Ketiga' })).toBe('MC');
    expect(initials({ name: 'rizqi' })).toBe('R');
    expect(initials(null)).toBe('M');
    expect(initials({ name: '  ' })).toBe('M');
  });

  it('greets by the hour', () => {
    expect(greeting(new Date(2026, 10, 2, 7))).toBe('Selamat pagi');
    expect(greeting(new Date(2026, 10, 2, 12))).toBe('Selamat siang');
    expect(greeting(new Date(2026, 10, 2, 16))).toBe('Selamat sore');
    expect(greeting(new Date(2026, 10, 2, 20))).toBe('Selamat malam');
  });
});

describe('course tiles', () => {
  it('abbreviates the course name, then the code', () => {
    expect(courseInitials('Enterprise Resource Planning', 'IF401')).toBe('ERP');
    expect(courseInitials('Pemrograman Web Lanjut Sekali', null)).toBe('PWL');
    expect(courseInitials('Kalkulus', null)).toBe('KA');
    expect(courseInitials(null, 'IF-123')).toBe('IF');
    expect(courseInitials('', '')).toBe('?');
  });

  it('gives a course the same color every time, within the palette', () => {
    expect(courseColorIndex('IF401', 6)).toBe(courseColorIndex('IF401', 6));
    expect(courseColorIndex('IF401', 6)).toBeLessThan(6);
    expect(courseColorIndex(null, 6)).toBe(0);
    expect(courseColorIndex('x', 0)).toBe(0);
  });
});

describe('groupClassesByDay', () => {
  it('orders days from Senin, sorts each day by start time and keeps unknown days last', () => {
    const grouped = groupClassesByDay([
      { course_info: { name: 'Rabu sore' }, schedule: { day: 'Rabu', start_time: '15:00' } },
      { course_info: { name: 'Tanpa hari' }, schedule: {} },
      { course_info: { name: 'Senin pagi' }, schedule: { day: 'senin', start_time: '08:00' } },
      { course_info: { name: 'Rabu pagi' }, schedule: { day: "Jum'at", start_time: '07:30' } },
      { course_info: { name: 'Rabu siang' }, schedule: { day: 'RABU', start_time: '10:00' } },
    ]);

    expect(grouped.map((group) => group.day)).toEqual(['Senin', 'Rabu', 'Jumat', 'Jadwal lain']);
    expect(grouped[1].classes.map((portalClass) => portalClass.course_info?.name)).toEqual(['Rabu siang', 'Rabu sore']);
    expect(grouped[3].classes).toHaveLength(1);
  });

  it('normalizes the portal day names', () => {
    expect(normalizeDay("Jum'at")).toBe('Jumat');
    expect(normalizeDay('Ahad')).toBe('Minggu');
    expect(normalizeDay('Libur')).toBeNull();
    expect(normalizeDay(undefined)).toBeNull();
  });
});

describe('attendedCount', () => {
  it('counts the classes whose attendance is recorded', () => {
    expect(
      attendedCount([
        { attendance: { status: { already_marked: true } } },
        { attendance: { status: { already_marked: false } } },
        {},
      ]),
    ).toBe(1);
  });
});

describe('matchesClassQuery', () => {
  const portalClass = {
    course_info: { name: 'Manajemen Proyek', code: 'SI032022' },
    lecturer_info: { name: 'Lena Magdalena' },
    class_room_info: { room: 'Ruang 102' },
  };

  it('matches the name, code, lecturer or room, ignoring case', () => {
    expect(matchesClassQuery(portalClass, 'proyek')).toBe(true);
    expect(matchesClassQuery(portalClass, 'si032')).toBe(true);
    expect(matchesClassQuery(portalClass, 'LENA')).toBe(true);
    expect(matchesClassQuery(portalClass, '102')).toBe(true);
  });

  it('matches everything for an empty query and nothing for a stranger', () => {
    expect(matchesClassQuery(portalClass, '  ')).toBe(true);
    expect(matchesClassQuery(portalClass, 'kalkulus')).toBe(false);
    expect(matchesClassQuery({}, 'x')).toBe(false);
  });
});

describe('formatShortDate', () => {
  it('shows the day and short month, or the raw value', () => {
    expect(formatShortDate('2026-11-02')).toMatch(/^2 Nov/);
    expect(formatShortDate('besok')).toBe('besok');
    expect(formatShortDate(null)).toBe('');
  });
});
