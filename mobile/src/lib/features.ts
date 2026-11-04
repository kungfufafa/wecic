import type { Href } from 'expo-router';

import type { IconName } from '@/components/icon';

export type Feature = {
  key: string;
  title: string;
  description: string;
  icon: IconName;
  href: Href;
};

export type FeatureGroup = { title: string; features: Feature[] };

/** Every MyCIC service the app has, grouped as the web menu groups them. */
export const FEATURE_GROUPS: FeatureGroup[] = [
  {
    title: 'Perkuliahan',
    features: [
      { key: 'attendance', title: 'Absensi', description: 'Kirim kode absensi kelas hari ini', icon: 'qr', href: '/attendance' },
      { key: 'classes', title: 'Kelas', description: 'Jadwal, materi, dan kehadiran', icon: 'bookOutline', href: '/classes' },
      { key: 'assignments', title: 'Tugas', description: 'Tugas yang belum dikumpulkan', icon: 'task', href: '/assignments' },
      { key: 'exams', title: 'Ujian', description: 'Daftar ujian dan unggah jawaban', icon: 'checklist', href: '/exams' },
    ],
  },
  {
    title: 'Akademik',
    features: [
      { key: 'krs', title: 'KRS', description: 'Rencana studi dan perwalian', icon: 'listOutline', href: '/krs' },
      { key: 'grades', title: 'Nilai', description: 'IPK, IPS, dan KHS per semester', icon: 'chartOutline', href: '/grades' },
      { key: 'transcript', title: 'Transkrip', description: 'Semua nilai mata kuliah', icon: 'documentOutline', href: '/transcript' },
      { key: 'skkm', title: 'SKKM', description: 'Poin kegiatan kemahasiswaan', icon: 'award', href: '/skkm' },
      { key: 'thesis', title: 'Skripsi', description: 'Status dan kesiapan skripsi', icon: 'graduationOutline', href: '/thesis' },
    ],
  },
  {
    title: 'Administrasi',
    features: [
      { key: 'finance', title: 'Keuangan', description: 'Tagihan dan riwayat pembayaran', icon: 'walletOutline', href: '/finance' },
      { key: 'announcements', title: 'Pengumuman', description: 'Info resmi dari kampus', icon: 'megaphoneOutline', href: '/announcements' },
      { key: 'biodata', title: 'Biodata', description: 'Data pribadi dan kontak', icon: 'idCard', href: '/biodata' },
    ],
  },
  {
    title: 'Lainnya',
    features: [
      { key: 'library', title: 'Perpustakaan', description: 'Tautan dan repositori skripsi', icon: 'libraryOutline', href: '/library' },
      { key: 'notebooks', title: 'Notebook AI', description: 'Belajar dari materi kuliah', icon: 'sparkles', href: '/notebooks' },
      { key: 'archive', title: 'Arsip', description: 'Riwayat data portal', icon: 'archiveOutline', href: '/archive' },
      { key: 'outbox', title: 'Antrean', description: 'Kiriman yang menunggu portal', icon: 'outboxOutline', href: '/outbox' },
      { key: 'password', title: 'Kata sandi', description: 'Ganti kata sandi portal', icon: 'keyOutline', href: '/password' },
    ],
  },
];

/**
 * The services on Beranda's grid, in order, four to a page; Absen, Kuliah
 * and Akademik have their own tabs, and the last tile opens every service.
 */
export const HOME_FEATURE_KEYS = ['assignments', 'exams', 'grades', 'finance', 'krs', 'announcements', 'skkm'];

/** The tile after Beranda's services that opens the full list. */
export const ALL_SERVICES_FEATURE: Feature = {
  key: 'services',
  title: 'Semua',
  description: 'Semua layanan WeCIC',
  icon: 'gridOutline',
  href: '/services',
};

export function featuresByKey(keys: string[]): Feature[] {
  const all = FEATURE_GROUPS.flatMap((group) => group.features);

  return keys.map((key) => all.find((feature) => feature.key === key)).filter((feature): feature is Feature => feature !== undefined);
}
