import type { Tone } from '@/lib/format';

import type { ArchiveVersionStatus, StageStatus } from '@/api/types/admin';

/** What a payment stage's status says, and its tone. */
export function stageStatusLook(status: StageStatus | null | undefined): { label: string; tone: Tone } {
  switch (status) {
    case 'paid':
      return { label: 'Lunas', tone: 'positive' };
    case 'unpaid':
      return { label: 'Belum dibayar', tone: 'attention' };
    case 'upcoming':
      return { label: 'Akan datang', tone: 'neutral' };
    default:
      return { label: 'Belum tercatat', tone: 'muted' };
  }
}

/** "Rp 1.250.000" from a whole rupiah amount; null when there is none. */
export function rupiah(amount: number | null | undefined): string | null {
  if (typeof amount !== 'number' || !Number.isFinite(amount)) {
    return null;
  }

  return `Rp ${Math.round(amount).toLocaleString('id-ID').replace(/,/g, '.')}`;
}

/** The query string for the thesis repository: only the filters that are set, the page only after the first. */
export function thesesQuery(filters: { q?: string | null; prodi?: string | null; ta?: string | null; pembimbing?: string | null; sort?: string | null; page?: number | null }): string {
  const params: string[] = [];

  for (const key of ['q', 'prodi', 'ta', 'pembimbing', 'sort'] as const) {
    const value = filters[key]?.trim();

    if (value) {
      params.push(`${key}=${encodeURIComponent(value)}`);
    }
  }

  if (filters.page && filters.page > 1) {
    params.push(`page=${filters.page}`);
  }

  return params.length > 0 ? `?${params.join('&')}` : '';
}

/** Categories in the order they first appear, each once, for the filter chips. */
export function announcementCategories(items: { category?: string | null; category_label?: string | null }[]): { key: string; label: string }[] {
  const seen = new Map<string, string>();

  for (const item of items) {
    if (item.category && !seen.has(item.category)) {
      seen.set(item.category, item.category_label?.trim() || item.category);
    }
  }

  return [...seen].map(([key, label]) => ({ key, label }));
}

/** A list of items without the ones whose id already appeared, for a page that overlaps the previous one. */
export function appendUnique<T extends { id: string | number }>(current: T[], next: T[]): T[] {
  const ids = new Set(current.map((item) => item.id));

  return [...current, ...next.filter((item) => !ids.has(item.id))];
}

/** The status of an archived version, and its tone. */
export function archiveStatusLook(status: ArchiveVersionStatus | null | undefined): { label: string; tone: Tone } {
  switch (status) {
    case 'trusted':
      return { label: 'Dipercaya', tone: 'positive' };
    case 'suspect':
      return { label: 'Perlu dicek', tone: 'attention' };
    case 'rejected':
      return { label: 'Ditolak', tone: 'muted' };
    default:
      return { label: 'Diabaikan', tone: 'neutral' };
  }
}

/** "1,2 MB" from a byte count. */
export function fileSize(bytes: number | null | undefined): string {
  const value = typeof bytes === 'number' && bytes > 0 ? bytes : 0;

  if (value < 1024) {
    return `${value} B`;
  }

  const units = ['KB', 'MB', 'GB'];
  let size = value / 1024;
  let unit = 0;

  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024;
    unit += 1;
  }

  return `${size.toLocaleString('id-ID', { maximumFractionDigits: 1 })} ${units[unit]}`;
}

/** "Personal_data" keys as the labels the web shows; unknown keys turned into words. */
const PROFILE_LABELS: Record<string, string> = {
  nama: 'Nama',
  nim: 'NIM',
  program_studi: 'Program studi',
  angkatan: 'Angkatan',
  status: 'Status',
  tempat_lahir: 'Tempat lahir',
  tanggal_lahir: 'Tanggal lahir',
  jenis_kelamin: 'Jenis kelamin',
  agama: 'Agama',
  alamat_rumah: 'Alamat',
  kota: 'Kota',
  propinsi: 'Provinsi',
  kodepos: 'Kode pos',
  telp: 'Telepon',
  hp: 'Ponsel',
  email: 'Email',
  nisn: 'NISN',
  tingkat_pendidikan: 'Pendidikan terakhir',
  asal_sekolah: 'Asal sekolah',
  alamat_sekolah: 'Alamat sekolah',
  kota_sekolah: 'Kota sekolah',
  propinsi_sekolah: 'Provinsi sekolah',
  negara_sekolah: 'Negara sekolah',
  telp_sekolah: 'Telepon sekolah',
};

export function profileLabel(key: string): string {
  const known = PROFILE_LABELS[key];

  if (known) {
    return known;
  }

  const words = key.replace(/_/g, ' ').trim();

  return words.charAt(0).toUpperCase() + words.slice(1);
}

/** The rows of one profile block, labelled, in the order the portal gives them. */
export function profileRows(block: Record<string, string | null> | null | undefined): { label: string; value: string | null }[] {
  return Object.entries(block ?? {}).map(([key, value]) => ({ label: profileLabel(key), value: typeof value === 'string' ? value.trim() : null }));
}
