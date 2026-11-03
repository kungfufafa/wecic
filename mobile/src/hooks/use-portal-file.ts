import { Directory, File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, Platform } from 'react-native';

import { webUrl } from '@/api/config';
import { useSession } from '@/auth/session-provider';
import { filesDirectory } from '@/lib/device-cache';
import { downloadErrorMessage, downloadPath, downloadStatus, localFileName } from '@/lib/portal-files';

/**
 * Opens a portal file (an RPS, a module, an exercise, a submitted
 * assignment) from the `href` a response gives, such as
 * `../files/modul/x.pdf`: fetched through MyCIC with the student's token,
 * shown in the system preview, or offered to another app when it cannot
 * be previewed. Each file is kept in the student's own folder, so a file
 * opened once opens again without internet; the folder goes at sign out.
 * `busyHref` is the file being fetched, for a spinner on its row.
 */
export function usePortalFile() {
  const { api, state } = useSession();
  const owner = state.status === 'signedIn' ? state.owner : null;
  const [busyHref, setBusyHref] = useState<string | null>(null);

  /** Where this file is kept: the student's folder, or the cache without an owner. */
  function keptFile(href: string): File {
    const folder = filesDirectory(owner) ?? new Directory(Paths.cache, 'portal-files');

    if (!folder.exists) {
      folder.create({ intermediates: true, idempotent: true });
    }

    return new File(folder, localFileName(href));
  }

  async function download(href: string): Promise<File> {
    // Kept under its portal name, so the extension tells the preview what it is.
    const kept = keptFile(href);
    // Fetched beside it first: a download cut off halfway never replaces the copy that opened before.
    const part = new File(kept.parentDirectory, `${kept.name}.download`);
    await File.downloadFileAsync(api.url(downloadPath(href)), part, { headers: api.authHeaders(), idempotent: true });

    if (kept.exists) {
      kept.delete();
    }

    await part.move(kept);

    return kept;
  }

  async function show(file: File, name?: string | null): Promise<void> {
    if (await File.canPreview(file)) {
      await File.preview(file);
    } else if (await Sharing.isAvailableAsync()) {
      await Sharing.shareAsync(file.uri, { dialogTitle: name ?? file.name });
    } else {
      Alert.alert('File tersimpan', `${file.name} sudah diunduh, tetapi perangkat ini tidak punya aplikasi untuk membukanya.`);
    }
  }

  async function open(href: string | null | undefined, name?: string | null): Promise<void> {
    const target = href?.trim() ?? '';

    if (target === '' || busyHref !== null) {
      return;
    }

    // The web preview cannot keep files: the MyCIC website opens them instead.
    if (Platform.OS === 'web') {
      const website = webUrl();

      if (website) {
        void WebBrowser.openBrowserAsync(`${website}/api/${downloadPath(target, name)}`);
      }

      return;
    }

    setBusyHref(target);

    try {
      let file: File;

      try {
        file = await download(target);
      } catch (error) {
        const status = downloadStatus(error);
        const kept = keptFile(target);

        // No connection: the copy opened before still opens.
        if (status === 0 && kept.exists) {
          await show(kept, name);

          return;
        }

        if (status !== 401) {
          throw error;
        }

        // The campus session ended: any API call signs in again, then the file is fetched once more.
        await api.get('auth/session');
        file = await download(target);
      }

      await show(file, name);
    } catch (error) {
      const status = downloadStatus(error);

      Alert.alert(
        'Gagal membuka file',
        status === 0 ? 'File ini belum pernah dibuka di perangkat ini. Sambungkan internet untuk mengunduhnya.' : downloadErrorMessage(status),
      );
    } finally {
      setBusyHref(null);
    }
  }

  return { open, busyHref };
}
