/** Reading the loosely shaped parts of a portal exam page. */

/** The words of an alert, requirement or link the portal gave as text or as an object. */
export function textOf(value: unknown): string | null {
  if (typeof value === 'string') {
    return value.trim() === '' ? null : value.trim();
  }

  if (value !== null && typeof value === 'object') {
    const record = value as Record<string, unknown>;

    for (const key of ['message', 'text', 'label', 'title', 'name', 'file_name', 'filename']) {
      const text = record[key];

      if (typeof text === 'string' && text.trim() !== '') {
        return text.trim();
      }
    }
  }

  return null;
}

/** The file link of a submission or related link, if it has one. */
export function hrefOf(value: unknown): string | null {
  if (value === null || typeof value !== 'object') {
    return null;
  }

  const record = value as Record<string, unknown>;

  for (const key of ['href', 'file_url', 'url', 'path', 'file_path']) {
    const href = record[key];

    if (typeof href === 'string' && href.trim() !== '') {
      return href.trim();
    }
  }

  const fileInfo = record.file_info as Record<string, unknown> | undefined;

  return typeof fileInfo?.href === 'string' && fileInfo.href.trim() !== '' ? fileInfo.href.trim() : null;
}

/** "Keterangan" for `data[keterangan]`, "Catatan tambahan" for `catatan_tambahan`. */
export function fieldLabel(name: string): string {
  const inner = name.match(/\[([^\]]+)\]$/)?.[1] ?? name;
  const words = inner.replace(/[_-]+/g, ' ').trim();

  return words === '' ? name : words.charAt(0).toUpperCase() + words.slice(1);
}
