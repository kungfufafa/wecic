import { scanFromURLAsync } from 'expo-camera';
import * as ImagePicker from 'expo-image-picker';

export type PhotoScan = { status: 'read'; text: string } | { status: 'canceled' } | { status: 'unreadable' };

/**
 * Lets the student choose a photo (a screenshot of the lecturer's QR, a
 * picture of the projector) and reads the QR in it, as the web's
 * "unggah foto QR" does.
 */
export async function scanQrFromPhoto(): Promise<PhotoScan> {
  const picked = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 1, allowsMultipleSelection: false });
  const uri = picked.canceled ? null : (picked.assets[0]?.uri ?? null);

  if (uri === null) {
    return { status: 'canceled' };
  }

  try {
    const results = await scanFromURLAsync(uri, ['qr']);
    const text = results.find((result) => typeof result.data === 'string' && result.data.trim() !== '')?.data ?? null;

    return text === null ? { status: 'unreadable' } : { status: 'read', text };
  } catch {
    return { status: 'unreadable' };
  }
}
