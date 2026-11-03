import { statusMessage } from '@/api/client';

/**
 * Portal files (`../files/modul/x.pdf`, `files/skkm/…`) come through
 * `GET support/download`, which fetches them from the portal with the
 * student's session; the server checks the path.
 */
export function downloadPath(href: string, name?: string | null): string {
  const query = [`path=${encodeURIComponent(href.trim())}`];
  const fileName = name?.trim();

  if (fileName) {
    query.push(`name=${encodeURIComponent(fileName)}`);
  }

  return `support/download?${query.join('&')}`;
}

/** The HTTP status in a failed download's message ("…status code 404…"), or 0 when there is none. */
export function downloadStatus(error: unknown): number {
  const message = error instanceof Error ? error.message : String(error ?? '');
  const match = message.match(/\b([45]\d\d)\b/);

  return match ? Number(match[1]) : 0;
}

/** What to tell the student when a file could not be fetched. */
export function downloadErrorMessage(status: number): string {
  if (status === 0) {
    return 'File tidak bisa diunduh. Periksa koneksi internet lalu coba lagi.';
  }

  if (status === 404) {
    return 'File tidak ditemukan di portal kampus.';
  }

  if (status === 422) {
    return 'Tautan file ini tidak bisa dibuka dari WeCIC.';
  }

  return statusMessage(status);
}

/**
 * The name a downloaded file is kept under: the last part of its portal
 * path ("SI053026-rps47a.pdf"), so its extension tells the system preview
 * what it is. Characters a file name cannot hold become "-".
 */
export function localFileName(href: string): string {
  const last = href.trim().split(/[?#]/)[0].split('/').filter(Boolean).pop() ?? '';
  let decoded = last;

  try {
    decoded = decodeURIComponent(last);
  } catch {
    // A stray % stays as it is.
  }

  const safe = decoded.replace(/[\\/:*?"<>|]+/g, '-').trim();

  return safe === '' || safe === '.' || safe === '..' ? 'file' : safe;
}
