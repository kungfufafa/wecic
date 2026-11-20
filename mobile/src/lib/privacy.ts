import type { ApiClient } from '@/api/client';
import { webUrl } from '@/api/config';
import type { AccountDeletionResponse, BackgroundSync, BackgroundSyncResponse } from '@/api/types/admin';

/**
 * The public pages both stores link to, on the WeCIC website: the privacy
 * policy, help, and how to delete an account without the app.
 */
export const LEGAL_PAGES = {
  privacy: 'privasi',
  support: 'bantuan',
  dataDeletion: 'hapus-data',
} as const;

export type LegalPage = keyof typeof LEGAL_PAGES;

/** The full address of a public page; null without a configured server. */
export function legalPageUrl(page: LegalPage, origin?: string): string | null {
  const website = origin === undefined ? webUrl() : webUrl(origin);

  return website === null ? null : `${website}/${LEGAL_PAGES[page]}`;
}

/** What the WeCIC server does with the login, said before the student signs in. */
export const SIGN_IN_DISCLOSURE = [
  'Kata sandi dikirim ke server WeCIC untuk masuk ke portal akademik kampus atas namamu.',
  'Data portal yang kamu buka disimpan di server WeCIC supaya bisa dibuka saat portal tidak bisa diakses.',
  'Server WeCIC menyimpan hash kata sandi dan info profilmu selama 30 hari supaya arsip tetap bisa dibuka saat portal tidak bisa diakses.',
] as const;

export const BACKGROUND_SYNC_EXPLANATION =
  'Jika dinyalakan, kata sandi portal disimpan terenkripsi di server WeCIC supaya data bisa diperbarui otomatis. Kata sandi dihapus saat fitur ini dimatikan atau saat kamu keluar.';

/** What "Hapus akun & data WeCIC" erases on the server (PortalAccountEraser). */
export const ACCOUNT_DELETION_ITEMS = [
  'Arsip data portal dan berkas yang tersimpan di server WeCIC',
  'Semua notebook, sumber, dan percakapan Notebook AI',
  'Izin Notebook AI',
  'Notifikasi, antrean offline, dan riwayat ujian di WeCIC',
  'Kata sandi tersimpan untuk sinkronisasi background dan login offline',
  'Semua sesi WeCIC di semua perangkat, termasuk token notifikasi push',
] as const;

export const ACCOUNT_DELETION_UNAFFECTED =
  'Akun portal akademik kampusmu tidak terpengaruh: kamu tetap bisa masuk ke portal kampus seperti biasa. Data yang sudah dikirim ke Google Gemini tidak bisa ditarik dari Google.';

/** Whether switching background sync on needs the portal password typed again: no usable one is stored. */
export function backgroundSyncNeedsPassword(status: BackgroundSync | null | undefined): boolean {
  return status?.active !== true;
}

/**
 * Switches background sync on or off (`PATCH archive/background-sync`).
 * Off deletes the stored password at once; on needs `password` while none
 * is stored.
 *
 * @throws ApiError with the server's message (wrong password: 422 `errors.password`)
 */
export async function setBackgroundSync(api: ApiClient, enabled: boolean, password?: string): Promise<BackgroundSyncResponse> {
  const body: { enabled: boolean; password?: string } = { enabled };

  if (enabled && password !== undefined && password !== '') {
    body.password = password;
  }

  return api.patch<BackgroundSyncResponse>('archive/background-sync', body);
}

/**
 * Erases the student's WeCIC account and everything the server keeps for
 * them (`DELETE account`); the campus portal account is not touched.
 *
 * @throws ApiError with the server's message
 */
export async function deleteAccount(api: ApiClient): Promise<AccountDeletionResponse> {
  return api.delete<AccountDeletionResponse>('account', { confirm: true });
}
