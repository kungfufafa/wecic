/**
 * A notification's `url` is a link to a MyCIC web page (`/class/12?…`,
 * `/announcement/…`, `/grades`). The app opens the screen that shows the
 * same thing, or the notification list while it has no such screen yet.
 */
export type NotificationRoute = '/' | '/classes' | '/notifications';

const CLASS_PAGES = ['/class', '/meeting', '/exam'];

export function routeForNotificationUrl(url: unknown): NotificationRoute {
  const path = typeof url === 'string' ? pathOf(url) : '';

  if (path === '/' || path === '/dashboard') {
    return '/';
  }

  if (CLASS_PAGES.some((page) => path === page || path.startsWith(`${page}/`))) {
    return '/classes';
  }

  return '/notifications';
}

function pathOf(url: string): string {
  const withoutOrigin = url.trim().replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]*/i, '');
  const path = withoutOrigin.split(/[?#]/)[0].replace(/\/+$/, '');

  return path === '' ? '/' : path;
}
