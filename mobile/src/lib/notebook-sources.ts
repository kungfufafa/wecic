import type { NotebookSource } from '@/api/types/classes';
import type { PickedFile } from '@/lib/pick-file';

/**
 * The notebook's sources on the phone, as on the web page: what is added
 * (a photo, a file, pasted text), which ready sources the AI may use, the
 * assignment brief's shortcuts, and how often the screen asks again while
 * a source is being read.
 */

/**
 * Polling while a source is read, the web's POLL_* constants
 * (notebook/show/scripts/setup.blade.php): every 4 s, every 20 s once two
 * minutes pass without progress, and not at all after the hour in which
 * the server marks a lost reading stalled.
 */
export const POLL_FAST_MS = 4000;
export const POLL_SLOW_MS = 20_000;
export const POLL_SLOW_AFTER_MS = 2 * 60 * 1000;
export const POLL_LIMIT_MS = 60 * 60 * 1000;

/** The longest assignment brief the studio takes (`focus`, NotebookStudioRequest). */
export const BRIEF_FOCUS_MAX = 1500;

/** The longest pasted note title and text (StoreNotebookSourceRequest; the text limit is the server's default). */
export const TEXT_TITLE_MAX = 120;
export const TEXT_CONTENT_MAX = 200_000;

/** What the server reads from an upload (`notebook.uploads.mimes`), for the file picker. */
export const UPLOAD_MIME_TYPES = [
  'application/pdf',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'text/plain',
  'text/markdown',
  'image/png',
  'image/jpeg',
];

/** JPEG quality of a picked photo: no resizing library is installed, so this keeps a phone photo small enough to send. */
export const PHOTO_QUALITY = 0.7;

const UPLOAD_MIME_BY_EXTENSION: Record<string, string> = {
  pdf: 'application/pdf',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  txt: 'text/plain',
  md: 'text/markdown',
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
};

/** The MIME type of a source file from its name, for a picker that does not say. */
export function uploadMimeTypeOf(name: string): string {
  return UPLOAD_MIME_BY_EXTENSION[name.toLowerCase().split('.').pop() ?? ''] ?? 'application/octet-stream';
}

/**
 * The ids of the sources still being read, joined: a change means progress
 * (a source added, read, or read again). A file waiting for tomorrow's
 * reading budget, or stalled, makes no progress today.
 */
export function readingKey(sources: readonly NotebookSource[]): string {
  return sources
    .filter((source) => (source.status === 'pending' || source.status === 'processing') && !source.stalled && !(source.waiting && source.error))
    .map((source) => source.id)
    .join(',');
}

/**
 * How long until the screen asks again, or null to stop: nothing is being
 * read (`hasProgress` false), or an hour passed since the last progress.
 */
export function pollDelay(elapsedMs: number, hasProgress: boolean): number | null {
  if (!hasProgress || elapsedMs >= POLL_LIMIT_MS) {
    return null;
  }

  return elapsedMs < POLL_SLOW_AFTER_MS ? POLL_FAST_MS : POLL_SLOW_MS;
}

export type BriefChip = { key: number; label: string; prefix: string };

/**
 * "Tugas pertemuan N" shortcuts for the assignment brief, one per meeting
 * already read into the notebook (as the web's brief sheet), in meeting order.
 */
export function assignmentBriefChips(meetings: readonly NotebookSource[]): BriefChip[] {
  const seen = new Set<string>();

  return meetings
    .filter((source) => source.kind === 'meeting' && source.status === 'ready')
    .sort((a, b) => (a.meeting_number ?? Number.MAX_SAFE_INTEGER) - (b.meeting_number ?? Number.MAX_SAFE_INTEGER))
    .flatMap((source) => {
      const label = source.meeting_number ? `Tugas pertemuan ${source.meeting_number}` : `Tugas ${source.title ?? 'pertemuan'}`;

      if (seen.has(label)) {
        return [];
      }

      seen.add(label);

      return [{ key: source.id, label, prefix: `${label}: ` }];
    });
}

/** The brief after a shortcut: what the student wrote stays; an empty brief starts with the shortcut. */
export function briefWithChip(current: string, chip: BriefChip): string {
  return current.trim() !== '' ? current : chip.prefix;
}

/** A picked photo as the file to upload; the name carries an extension the server reads. */
export function photoFile(asset: { uri: string; fileName?: string | null; mimeType?: string | null; fileSize?: number | null; file?: Blob | null }, now: number = Date.now()): PickedFile {
  const mimeType = asset.mimeType === 'image/png' ? 'image/png' : 'image/jpeg';
  const extension = mimeType === 'image/png' ? 'png' : 'jpg';
  const base = (asset.fileName ?? '').replace(/\.[^.]*$/, '').trim();

  return {
    uri: asset.uri,
    name: `${base !== '' ? base : `foto-${now}`}.${extension}`,
    mimeType,
    size: typeof asset.fileSize === 'number' ? asset.fileSize : null,
    ...(asset.file ? { webFile: asset.file } : {}),
  };
}

/** The multipart body of an upload source: `type=upload` and the file, nothing else (no `source_ids`). */
export function buildUploadForm(file: PickedFile): FormData {
  const form = new FormData();

  form.append('type', 'upload');

  if (file.webFile) {
    form.append('file', file.webFile, file.name);
  } else {
    // React Native reads a file part from its uri.
    form.append('file', { uri: file.uri, name: file.name, type: file.mimeType } as unknown as Blob);
  }

  return form;
}

/** The ready sources the student left out of the AI's context, per notebook id. */
export type SourceSelectionStore = Record<string, number[]>;

/** At most this many notebooks keep a selection; the oldest is forgotten first. */
export const SELECTION_NOTEBOOK_LIMIT = 50;

/** A kept selection store, or an empty one for anything unreadable. */
export function parseSourceSelection(raw: unknown): SourceSelectionStore {
  if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) {
    return {};
  }

  const store: SourceSelectionStore = {};

  for (const [notebookId, ids] of Object.entries(raw as Record<string, unknown>)) {
    if (!/^\d+$/.test(notebookId) || !Array.isArray(ids)) {
      continue;
    }

    const valid = [...new Set(ids.filter((id): id is number => Number.isInteger(id) && id > 0))];

    if (valid.length > 0) {
      store[notebookId] = valid;
    }
  }

  return store;
}

/** The sources left out of one notebook's context. */
export function excludedFor(store: SourceSelectionStore, notebookId: string | number): Set<number> {
  return new Set(store[String(notebookId)] ?? []);
}

/** The store with one notebook's left-out sources; none left out removes its entry, and the newest entry goes last. */
export function withExcluded(store: SourceSelectionStore, notebookId: string | number, excluded: Iterable<number>): SourceSelectionStore {
  const key = String(notebookId);
  const { [key]: _previous, ...rest } = store;
  const ids = [...new Set(excluded)].filter((id) => Number.isInteger(id) && id > 0).sort((a, b) => a - b);

  if (ids.length === 0) {
    return rest;
  }

  const entries = Object.entries(rest);
  const kept = entries.slice(Math.max(0, entries.length - (SELECTION_NOTEBOOK_LIMIT - 1)));

  return { ...Object.fromEntries(kept), [key]: ids };
}

/**
 * The left-out sources from the choice saved on the server (`context_source_ids`), which wins over the device
 * copy: null (every source) leaves none out, a list leaves out every other source. An older server sends no
 * choice (undefined): then null, and the device copy stays.
 */
export function excludedFromChoice(choice: readonly number[] | null | undefined, sources: readonly NotebookSource[]): Set<number> | null {
  if (choice === undefined) {
    return null;
  }

  if (choice === null) {
    return new Set();
  }

  const chosen = new Set(choice);

  return new Set(sources.map((source) => source.id).filter((id) => !chosen.has(id)));
}

/** The choice to save on the server: null while nothing is left out, else every source kept (ready or not). */
export function choiceForServer(excluded: ReadonlySet<number>, sources: readonly NotebookSource[]): number[] | null {
  return excluded.size === 0 ? null : sources.map((source) => source.id).filter((id) => !excluded.has(id));
}

/** One key per saved choice, so the same choice brought back by a reload is not applied twice. */
export function choiceKey(choice: readonly number[] | null | undefined): string | null {
  if (choice === undefined) {
    return null;
  }

  return choice === null ? 'all' : choice.join(',');
}

/** Left-out sources that are still in the notebook (a deleted source is forgotten, as on the web). */
export function pruneExcluded(excluded: ReadonlySet<number>, sources: readonly NotebookSource[]): Set<number> {
  const known = new Set(sources.map((source) => source.id));

  return new Set([...excluded].filter((id) => known.has(id)));
}

/**
 * `source_ids` for a question or a studio request: none (every ready source)
 * while nothing is left out, else the ready sources the student kept.
 */
export function contextSourceIds(sources: readonly NotebookSource[], excluded: ReadonlySet<number>): number[] {
  if (excluded.size === 0) {
    return [];
  }

  return sources.filter((source) => source.status === 'ready' && !excluded.has(source.id)).map((source) => source.id);
}

/** Every ready source is left out: asking would have no context, so it is blocked. */
export function noContextChosen(sources: readonly NotebookSource[], excluded: ReadonlySet<number>): boolean {
  return excluded.size > 0 && contextSourceIds(sources, excluded).length === 0;
}

/**
 * Whether the screen shows the source chips: with two or more ready sources, and also whenever every ready
 * source is left out (e.g. the other one was deleted or is read again), so the student can see why asking is
 * blocked and choose a source again; the choice is kept on the device, so it would otherwise stay stuck.
 */
export function showsSourceSelection(sources: readonly NotebookSource[], excluded: ReadonlySet<number>): boolean {
  const readyCount = sources.filter((source) => source.status === 'ready').length;

  return readyCount > 1 || (readyCount > 0 && noContextChosen(sources, excluded));
}

/** Whether a source can be read again: a failed or stalled file (not a note or a meeting), or one waiting that may be read now. */
export function canRetrySource(source: NotebookSource, aiAvailable: boolean): boolean {
  const canRetry = (source.status === 'failed' || source.stalled === true) && source.kind !== 'text' && source.kind !== 'meeting';
  const canReadNow = source.waiting === true && Boolean(source.error) && aiAvailable;

  return canRetry || canReadNow;
}

/** "Baca sekarang" for a source waiting for its turn, else "Baca ulang". */
export function retryLabel(source: NotebookSource): string {
  return source.waiting && source.error && source.status !== 'failed' && !source.stalled ? 'Baca sekarang' : 'Baca ulang';
}

/** Strings from a list the server sent, without empty or malformed entries. */
function textList(value: unknown, limit: number): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string' && item.trim() !== '').slice(0, limit) : [];
}

/** A source's main topics (its guide), at most six; none for a source read before guides. */
export function sourceTopics(source: NotebookSource): string[] {
  return textList(source.topics, 6);
}

/** A ready source's understanding questions (its guide), at most three; none while it is not ready. */
export function sourceQuestions(source: NotebookSource): string[] {
  return source.status === 'ready' ? textList(source.suggested_questions, 3) : [];
}

/** The empty chat's starter questions (`suggestions`), at most four; none from a server from before source guides. */
export function chatSuggestions(suggestions: unknown): string[] {
  return textList(suggestions, 4);
}
