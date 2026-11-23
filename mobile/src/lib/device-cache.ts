import { Directory, File, Paths } from 'expo-file-system';
import { Platform } from 'react-native';

import { cacheFileName, cacheKeyPath, isRetained, ownerFolder, policyFor } from '@/lib/cache-policies';

/**
 * The device copies of API answers (rules in lib/cache-policies.ts), as
 * JSON files in the app's private document folder, one folder per student
 * (their NIM), so two students on one phone never see each other's data.
 * Every copy is deleted when the student signs out. Errors are swallowed:
 * a copy is a convenience, never a reason for a screen to fail. The web
 * preview keeps nothing.
 */

export type CachedAnswer<T> = { payload: T; savedAt: number };

type Record<T> = { v: 1; path: string; savedAt: number; payload: T };

const ROOT = 'mycic-cache';
const FILES = 'mycic-files';
const PREFERENCES = 'mycic-preferences';

const available = Platform.OS !== 'web';

function rootDirectory(name: string): Directory {
  return new Directory(Paths.document, name);
}

function ownerDirectory(name: string, owner: string | null | undefined): Directory | null {
  const folder = ownerFolder(owner);

  return folder === null ? null : new Directory(rootDirectory(name), folder);
}

/** The kept copy of a GET path for this student, or null (none, too old, unreadable). */
export async function readCached<T>(owner: string | null | undefined, path: string): Promise<CachedAnswer<T> | null> {
  const policy = policyFor(path);
  const directory = available ? ownerDirectory(ROOT, owner) : null;

  if (policy === null || directory === null) {
    return null;
  }

  try {
    const file = new File(directory, cacheFileName(cacheKeyPath(path)));

    if (!file.exists) {
      return null;
    }

    const record = JSON.parse(await file.text()) as Record<T>;

    if (record.v !== 1 || record.path !== cacheKeyPath(path) || !isRetained(policy, record.savedAt)) {
      file.delete();

      return null;
    }

    return { payload: record.payload, savedAt: record.savedAt };
  } catch {
    return null;
  }
}

/** Keeps the answer of a GET path for this student, when its policy keeps it. */
export async function writeCached(owner: string | null | undefined, path: string, payload: unknown, savedAt: number = Date.now()): Promise<void> {
  const directory = available ? ownerDirectory(ROOT, owner) : null;

  if (policyFor(path) === null || directory === null) {
    return;
  }

  try {
    if (!directory.exists) {
      directory.create({ intermediates: true, idempotent: true });
    }

    const record: Record<unknown> = { v: 1, path: cacheKeyPath(path), savedAt, payload };
    const file = new File(directory, cacheFileName(record.path));

    await file.write(JSON.stringify(record));
  } catch {
    // Disk full or unwritable: the screen keeps working from the network.
  }
}

/** The folder where this student's opened portal files are kept for offline reading. */
export function filesDirectory(owner: string | null | undefined): Directory | null {
  return available ? ownerDirectory(FILES, owner) : null;
}

/**
 * A small choice this student made on this device (which notebook sources
 * the AI may use), as JSON in their own folder; null when there is none or
 * it cannot be read. Deleted with everything else at sign out.
 */
export async function readOwnerPreference(owner: string | null | undefined, name: string): Promise<unknown> {
  const directory = available ? ownerDirectory(PREFERENCES, owner) : null;

  if (directory === null) {
    return null;
  }

  try {
    const file = new File(directory, `${name}.json`);

    return file.exists ? (JSON.parse(await file.text()) as unknown) : null;
  } catch {
    return null;
  }
}

/** Keeps a small choice of this student (readOwnerPreference); a failed write is ignored. */
export async function writeOwnerPreference(owner: string | null | undefined, name: string, value: unknown): Promise<void> {
  const directory = available ? ownerDirectory(PREFERENCES, owner) : null;

  if (directory === null) {
    return;
  }

  try {
    if (!directory.exists) {
      directory.create({ intermediates: true, idempotent: true });
    }

    await new File(directory, `${name}.json`).write(JSON.stringify(value));
  } catch {
    // Unwritable: the choice lasts until the app closes.
  }
}

/** Deletes every kept answer and file of every student: at sign out. */
export function clearDeviceCache(): void {
  if (!available) {
    return;
  }

  for (const name of [ROOT, FILES, PREFERENCES]) {
    try {
      const directory = rootDirectory(name);

      if (directory.exists) {
        directory.delete();
      }
    } catch {
      // Nothing left to delete.
    }
  }
}

/** Deletes what other students left on this device: at sign in. */
export function keepOnlyOwner(owner: string | null | undefined): void {
  const folder = ownerFolder(owner);

  if (!available || folder === null) {
    return;
  }

  for (const name of [ROOT, FILES, PREFERENCES]) {
    try {
      const directory = rootDirectory(name);

      if (!directory.exists) {
        continue;
      }

      for (const entry of directory.list()) {
        if (entry instanceof Directory && entry.name !== folder) {
          entry.delete();
        }
      }
    } catch {
      // A folder that cannot be listed is cleared at the next sign out.
    }
  }
}
