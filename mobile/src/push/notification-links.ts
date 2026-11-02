import type { Href } from 'expo-router';

/**
 * A notification's `url` (and an action item's `cta_url`) is a link to a
 * MyCIC web page (`/class/12?…`, `/announcement/…`, `/grades`). The app
 * opens the screen that shows the same thing.
 */

/** The app screen for a MyCIC web link; null when the app has no such screen. */
export function routeForPortalUrl(url: unknown): Href | null {
  if (typeof url !== 'string' || url.trim() === '') {
    return null;
  }

  const { path, query } = partsOf(url);
  const segments = path.split('/').filter(Boolean);
  const [page, id] = segments;

  if (segments.length === 0 || path === '/dashboard') {
    return '/';
  }

  if (segments.length === 1) {
    const listPages: Record<string, Href> = {
      class: '/classes',
      exam: '/exams',
      krs: '/krs',
      grades: '/grades',
      transcript: '/transcript',
      skkm: '/skkm',
      thesis: '/thesis',
      finance: '/finance',
      announcement: '/announcements',
      profile: '/biodata',
      password: '/password',
      library: '/library',
      notebook: '/notebooks',
      archive: '/archive',
      notifications: '/notifications',
      more: '/more',
      assignment: '/assignments',
      attendance: '/attendance',
    };

    return listPages[page] ?? null;
  }

  if (segments.length !== 2 || !id) {
    return null;
  }

  switch (page) {
    case 'class':
      return {
        pathname: '/class/[id]',
        params: { id, ...pick(query, ['dosen_id', 'course_id']) },
      };
    case 'meeting': {
      const coursesId = query.courses_id ?? query.course_id;

      return { pathname: '/meeting/[id]', params: { id, ...(coursesId ? { courses_id: coursesId } : {}) } };
    }
    case 'exam':
      return { pathname: '/exams/[id]', params: { id } };
    case 'krs':
      return /^\d+$/.test(id) ? { pathname: '/krs/[id]', params: { id } } : null;
    case 'announcement':
      return { pathname: '/announcements/[id]', params: { id } };
    case 'notebook':
      return /^\d+$/.test(id) ? { pathname: '/notebooks/[id]', params: { id } } : null;
    case 'archive':
      return { pathname: '/archive/[dataset]', params: { dataset: id, ...pick(query, ['params_hash']) } };
    case 'library':
      return id === 'theses' ? '/library/theses' : null;
    default:
      return null;
  }
}

/** A tapped notification opens its screen, or the notification list. */
export function routeForNotificationUrl(url: unknown): Href {
  return routeForPortalUrl(url) ?? '/notifications';
}

function partsOf(url: string): { path: string; query: Record<string, string> } {
  const withoutOrigin = url.trim().replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]*/i, '');
  const [beforeHash] = withoutOrigin.split('#');
  const [rawPath, rawQuery = ''] = beforeHash.split('?');
  const path = rawPath.replace(/\/+$/, '') || '/';
  const query = Object.fromEntries(
    rawQuery
      .split('&')
      .map((pair) => pair.split('='))
      .filter(([key, value]) => key && value)
      .map(([key, value]) => [decodeURIComponent(key), decodeURIComponent(value)]),
  );

  return { path, query };
}

function pick(query: Record<string, string>, keys: string[]): Record<string, string> {
  return Object.fromEntries(keys.filter((key) => query[key]).map((key) => [key, query[key]]));
}
