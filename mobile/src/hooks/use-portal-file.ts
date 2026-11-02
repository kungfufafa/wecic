import { Directory, File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, Platform } from 'react-native';

import { webUrl } from '@/api/config';
import { useSession } from '@/auth/session-provider';
import { downloadErrorMessage, downloadPath, downloadStatus, localFileName } from '@/lib/portal-files';

const FOLDER = 'portal-files';

/**
 * Opens a portal file (an RPS, a module, an exercise, a submitted
 * assignment) from the `href` a response gives, such as
 * `../files/modul/x.pdf`: fetched through MyCIC with the student's token,
 * shown in the system preview, or offered to another app when it cannot
 * be previewed. `busyHref` is the file being fetched, for a spinner on its
 * row.
 */
export function usePortalFile() {
  const { api } = useSession();
  const [busyHref, setBusyHref] = useState<string | null>(null);

  async function download(href: string): Promise<File> {
    const folder = new Directory(Paths.cache, FOLDER);

    if (!folder.exists) {
      folder.create({ intermediates: true, idempotent: true });
    }

    // Kept under its portal name, so the extension tells the preview what it is.
    const destination = new File(folder, localFileName(href));

    return File.downloadFileAsync(api.url(downloadPath(href)), destination, { headers: api.authHeaders(), idempotent: true });
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
        if (downloadStatus(error) !== 401) {
          throw error;
        }

        // The campus session ended: any API call signs in again, then the file is fetched once more.
        await api.get('auth/session');
        file = await download(target);
      }

      if (await File.canPreview(file)) {
        await File.preview(file);
      } else if (await Sharing.isAvailableAsync()) {
        await Sharing.shareAsync(file.uri, { dialogTitle: name ?? file.name });
      } else {
        Alert.alert('File tersimpan', `${file.name} sudah diunduh, tetapi perangkat ini tidak punya aplikasi untuk membukanya.`);
      }
    } catch (error) {
      Alert.alert('Gagal membuka file', downloadErrorMessage(downloadStatus(error)));
    } finally {
      setBusyHref(null);
    }
  }

  return { open, busyHref };
}
