import * as DocumentPicker from 'expo-document-picker';

export type PickedFile = {
  uri: string;
  name: string;
  mimeType: string;
  size: number | null;
  /** The browser's own file, in the web preview, where a uri cannot be uploaded. */
  webFile?: Blob;
};

/**
 * Lets the student choose one file of the given MIME types from the
 * device; null when they cancel. The file is copied to the app's cache,
 * so it can still be read while it uploads.
 */
export async function pickFile(mimeTypes: string[]): Promise<PickedFile | null> {
  const result = await DocumentPicker.getDocumentAsync({
    type: mimeTypes.length > 0 ? mimeTypes : '*/*',
    copyToCacheDirectory: true,
    multiple: false,
  });

  const asset = result.canceled ? null : result.assets[0];

  if (!asset) {
    return null;
  }

  return {
    uri: asset.uri,
    name: asset.name,
    mimeType: asset.mimeType ?? mimeTypeOf(asset.name),
    size: typeof asset.size === 'number' ? asset.size : null,
    ...(asset.file ? { webFile: asset.file } : {}),
  };
}

/** The MIME type of the files MyCIC uploads, from the name. */
export function mimeTypeOf(name: string): string {
  const extension = name.toLowerCase().split('.').pop() ?? '';

  return (
    {
      pdf: 'application/pdf',
      zip: 'application/zip',
      rar: 'application/vnd.rar',
    }[extension] ?? 'application/octet-stream'
  );
}

/** "1,2 MB" for a size in bytes. */
export function formatBytes(bytes: number | null | undefined): string {
  if (!bytes || bytes <= 0) {
    return '';
  }

  if (bytes < 1024 * 1024) {
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }

  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
