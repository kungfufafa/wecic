import { routeForNotificationUrl, routeForPortalUrl } from '@/push/notification-links';

describe('routeForPortalUrl', () => {
  it.each([
    ['/dashboard', '/'],
    ['/', '/'],
    ['https://mycic.example/dashboard?tab=today', '/'],
    ['/class', '/classes'],
    ['/grades', '/grades'],
    ['/krs', '/krs'],
    ['/finance', '/finance'],
    ['/announcement', '/announcements'],
    ['/profile', '/biodata'],
    ['/library/theses', '/library/theses'],
  ])('opens the page %s on %s', (url, route) => {
    expect(routeForPortalUrl(url)).toBe(route);
  });

  it('opens a class with its lecturer and course', () => {
    expect(routeForPortalUrl('https://mycic.example/class/2265?dosen_id=5240&course_id=8810#tugas')).toEqual({
      pathname: '/class/[id]',
      params: { id: '2265', dosen_id: '5240', course_id: '8810' },
    });
  });

  it('opens a meeting with its course, under either name', () => {
    expect(routeForPortalUrl('/meeting/91?courses_id=8810')).toEqual({ pathname: '/meeting/[id]', params: { id: '91', courses_id: '8810' } });
    expect(routeForPortalUrl('/meeting/91?course_id=8810')).toEqual({ pathname: '/meeting/[id]', params: { id: '91', courses_id: '8810' } });
    expect(routeForPortalUrl('/meeting/91')).toEqual({ pathname: '/meeting/[id]', params: { id: '91' } });
  });

  it('opens one exam, KRS, announcement, notebook or archive', () => {
    expect(routeForPortalUrl('/exam/12')).toEqual({ pathname: '/exams/[id]', params: { id: '12' } });
    expect(routeForPortalUrl('/krs/90004')).toEqual({ pathname: '/krs/[id]', params: { id: '90004' } });
    expect(routeForPortalUrl('/announcement/0123456789abcdef0123456789abcdef01234567')).toEqual({
      pathname: '/announcements/[id]',
      params: { id: '0123456789abcdef0123456789abcdef01234567' },
    });
    expect(routeForPortalUrl('/notebook/11')).toEqual({ pathname: '/notebooks/[id]', params: { id: '11' } });
    expect(routeForPortalUrl('/archive/grades?params_hash=abc')).toEqual({ pathname: '/archive/[dataset]', params: { dataset: 'grades', params_hash: 'abc' } });
  });

  it('has no screen for unknown pages or non-links', () => {
    expect(routeForPortalUrl('/classroom')).toBeNull();
    expect(routeForPortalUrl('/krs/print')).toBeNull();
    expect(routeForPortalUrl('/a/b/c')).toBeNull();
    expect(routeForPortalUrl(null)).toBeNull();
    expect(routeForPortalUrl(42)).toBeNull();
  });
});

describe('routeForNotificationUrl', () => {
  it('falls back to the notification list', () => {
    expect(routeForNotificationUrl('/classroom')).toBe('/notifications');
    expect(routeForNotificationUrl(undefined)).toBe('/notifications');
    expect(routeForNotificationUrl('/grades')).toBe('/grades');
  });
});
