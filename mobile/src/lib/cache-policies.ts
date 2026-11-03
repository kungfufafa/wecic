/**
 * Which API answers the app keeps on the device, and for how long: the
 * same list as the web app (resources/js/lib/cache.js), for the paths the
 * app asks under /api/v1. `freshFor`: shown without asking the server
 * again; `retainFor`: shown while offline at most this long. Anything not
 * listed (the session check, downloads, notebooks, archives, searches) is
 * never kept.
 */

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

export type CachePolicy = { pattern: RegExp; freshFor: number; retainFor: number; label: string };

export const CACHE_POLICIES: readonly CachePolicy[] = [
  { pattern: /^dashboard$/, freshFor: 30 * MINUTE, retainFor: 7 * DAY, label: 'ringkasan dashboard' },
  { pattern: /^attendance\/today$/, freshFor: 15 * MINUTE, retainFor: 2 * DAY, label: 'absensi hari ini' },
  { pattern: /^assignment\/pending$/, freshFor: 20 * MINUTE, retainFor: 2 * DAY, label: 'tugas belum dikumpulkan' },
  { pattern: /^profile$/, freshFor: 6 * HOUR, retainFor: 14 * DAY, label: 'profil mahasiswa' },
  { pattern: /^class$/, freshFor: 6 * HOUR, retainFor: 14 * DAY, label: 'daftar kelas' },
  { pattern: /^class\/[^/?]+(?:\?.*)?$/, freshFor: 6 * HOUR, retainFor: 14 * DAY, label: 'detail kelas' },
  { pattern: /^meeting\/[^/?]+(?:\?.*)?$/, freshFor: 6 * HOUR, retainFor: 14 * DAY, label: 'detail pertemuan' },
  { pattern: /^exam$/, freshFor: 2 * HOUR, retainFor: 7 * DAY, label: 'daftar ujian' },
  { pattern: /^exam\/[^/?]+$/, freshFor: 2 * HOUR, retainFor: 7 * DAY, label: 'detail ujian' },
  { pattern: /^academic\/krs$/, freshFor: 15 * MINUTE, retainFor: 14 * DAY, label: 'KRS semester ini' },
  { pattern: /^academic\/krs\/\d+$/, freshFor: 6 * HOUR, retainFor: 14 * DAY, label: 'detail KRS' },
  { pattern: /^academic\/advising$/, freshFor: HOUR, retainFor: 14 * DAY, label: 'riwayat perwalian' },
  { pattern: /^academic\/grades$/, freshFor: HOUR, retainFor: 14 * DAY, label: 'nilai semester' },
  { pattern: /^academic\/transcript$/, freshFor: HOUR, retainFor: 14 * DAY, label: 'transkrip' },
  { pattern: /^announcement(?:\?page=\d+)?$/, freshFor: 30 * MINUTE, retainFor: 7 * DAY, label: 'pengumuman' },
  { pattern: /^announcement\/[^/?]+$/, freshFor: 6 * HOUR, retainFor: 7 * DAY, label: 'detail pengumuman' },
  // Payment history is sensitive: kept on the device for at most 7 days.
  { pattern: /^finance\/summary$/, freshFor: 30 * MINUTE, retainFor: 7 * DAY, label: 'ringkasan keuangan' },
  { pattern: /^finance\/payments$/, freshFor: 30 * MINUTE, retainFor: 7 * DAY, label: 'riwayat pembayaran' },
  { pattern: /^skkm$/, freshFor: 30 * MINUTE, retainFor: 14 * DAY, label: 'poin SKKM' },
  { pattern: /^thesis$/, freshFor: 6 * HOUR, retainFor: 14 * DAY, label: 'syarat & status skripsi' },
  { pattern: /^thesis\/readiness$/, freshFor: HOUR, retainFor: 14 * DAY, label: 'kesiapan skripsi' },
  { pattern: /^library$/, freshFor: DAY, retainFor: 30 * DAY, label: 'perpustakaan digital' },
  // Only the unfiltered list is kept; a search or a filter is always asked fresh.
  { pattern: /^library\/theses$/, freshFor: 6 * HOUR, retainFor: 14 * DAY, label: 'repositori skripsi' },
  { pattern: /^action-items$/, freshFor: 5 * MINUTE, retainFor: 2 * DAY, label: 'pusat tindakan' },
  { pattern: /^portal\/context$/, freshFor: 6 * HOUR, retainFor: 14 * DAY, label: 'info periode & panduan' },
  // The first page only: a later page (`before=`) is never kept.
  { pattern: /^notifications\?limit=\d+$/, freshFor: MINUTE, retainFor: 7 * DAY, label: 'notifikasi' },
];

/** `path` as it is kept: without a leading slash and without `fresh=1`. */
export function cacheKeyPath(path: string): string {
  const trimmed = path.trim().replace(/^\/+/, '');
  const [base, query = ''] = trimmed.split('?');
  const params = query.split('&').filter((pair) => pair !== '' && !/^fresh=/.test(pair));

  return params.length > 0 ? `${base}?${params.join('&')}` : base;
}

/** The policy that keeps a copy of this GET path, or null when it is never kept. */
export function policyFor(path: string): CachePolicy | null {
  const key = cacheKeyPath(path);

  return CACHE_POLICIES.find((policy) => policy.pattern.test(key)) ?? null;
}

/** Whether a copy saved at `savedAt` can stand in for the server without asking it. */
export function isFresh(policy: CachePolicy, savedAt: number, now: number = Date.now()): boolean {
  return now - savedAt >= 0 && now - savedAt < policy.freshFor;
}

/** Whether a copy saved at `savedAt` may still be shown at all. */
export function isRetained(policy: CachePolicy, savedAt: number, now: number = Date.now()): boolean {
  return now - savedAt >= 0 && now - savedAt < policy.retainFor;
}

/**
 * A short, file-safe name for a key path (FNV-1a, 32 bits, twice with
 * different seeds), so two paths never share a file in practice.
 */
export function cacheFileName(keyPath: string): string {
  const hash = (seed: number): string => {
    let value = seed;

    for (let index = 0; index < keyPath.length; index += 1) {
      value ^= keyPath.charCodeAt(index);
      value = Math.imul(value, 0x01000193) >>> 0;
    }

    return value.toString(16).padStart(8, '0');
  };

  return `${hash(0x811c9dc5)}${hash(0x01234567)}.json`;
}

/** The folder name of a student's copies: their NIM, lowercased and file-safe; null without one. */
export function ownerFolder(owner: string | null | undefined): string | null {
  const safe = owner?.trim().toLowerCase().replace(/[^a-z0-9._-]+/g, '-') ?? '';

  return safe === '' || safe === '.' || safe === '..' ? null : safe;
}

/** "baru saja", "12 menit lalu", "3 jam lalu", "2 hari lalu". */
export function ageLabel(savedAt: number, now: number = Date.now()): string {
  const minutes = Math.floor(Math.max(0, now - savedAt) / MINUTE);

  if (minutes < 1) {
    return 'baru saja';
  }

  if (minutes < 60) {
    return `${minutes} menit lalu`;
  }

  const hours = Math.floor(minutes / 60);

  return hours < 24 ? `${hours} jam lalu` : `${Math.floor(hours / 24)} hari lalu`;
}
