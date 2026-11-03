/**
 * Where the MyCIC server is: `EXPO_PUBLIC_API_URL` (the site's origin, e.g.
 * `https://mycic.example`), inlined at build time. The app talks to
 * version 1 of the API under it.
 */
export function apiBaseUrl(origin: string | undefined = process.env.EXPO_PUBLIC_API_URL): string | null {
  const trimmed = origin?.trim().replace(/\/+$/, '') ?? '';

  return trimmed === '' ? null : `${trimmed}/api/v1`;
}

/** The MyCIC website itself, for what the app does not show yet; null without a configured origin. */
export function webUrl(origin: string | undefined = process.env.EXPO_PUBLIC_API_URL): string | null {
  const trimmed = origin?.trim().replace(/\/+$/, '') ?? '';

  return trimmed === '' ? null : trimmed;
}

export const MISSING_API_URL_MESSAGE = 'Alamat server WeCIC belum diatur (EXPO_PUBLIC_API_URL).';
