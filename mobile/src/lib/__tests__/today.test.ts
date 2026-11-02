import { daysLeftLabel, todayClassRoute } from '@/lib/today';

describe('todayClassRoute', () => {
  it('opens the meeting once its ids are known', () => {
    expect(todayClassRoute({ attendance: { payload: { courseid: '8810', modulesid: '30061' } } })).toEqual({
      pathname: '/meeting/[id]',
      params: { id: '30061', courses_id: '8810' },
    });
  });

  it('opens the class while the meeting is unknown, and nothing without either', () => {
    expect(todayClassRoute({ attendance: { payload: null, class_url: '/class/2265?dosen_id=5240&course_id=8810' } })).toEqual({
      pathname: '/class/[id]',
      params: { id: '2265', dosen_id: '5240', course_id: '8810' },
    });
    expect(todayClassRoute({})).toBeNull();
  });
});

describe('daysLeftLabel', () => {
  it('words the days left', () => {
    expect(daysLeftLabel(0)).toBe('Hari ini');
    expect(daysLeftLabel(1)).toBe('Besok');
    expect(daysLeftLabel(4)).toBe('4 hari lagi');
    expect(daysLeftLabel(-2)).toBe('Lewat 2 hari');
    expect(daysLeftLabel(null)).toBeNull();
  });
});
