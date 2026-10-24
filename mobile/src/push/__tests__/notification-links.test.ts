import { routeForNotificationUrl } from '@/push/notification-links';

describe('routeForNotificationUrl', () => {
  it.each([
    ['/dashboard', '/'],
    ['/', '/'],
    ['https://mycic.example/dashboard?tab=today', '/'],
    ['/class/2265?dosen_id=5240&course_id=8810', '/classes'],
    ['/class', '/classes'],
    ['/meeting/91?courses_id=8810', '/classes'],
    ['/exam/12', '/classes'],
    ['https://mycic.example/class/2265#tugas', '/classes'],
    ['/announcement/0123456789abcdef0123456789abcdef01234567', '/notifications'],
    ['/grades', '/notifications'],
    ['/classroom', '/notifications'],
  ])('opens %s on %s', (url, route) => {
    expect(routeForNotificationUrl(url)).toBe(route);
  });

  it('opens the notification list for a push without a link', () => {
    expect(routeForNotificationUrl(null)).toBe('/notifications');
    expect(routeForNotificationUrl(undefined)).toBe('/notifications');
    expect(routeForNotificationUrl(42)).toBe('/notifications');
  });
});
