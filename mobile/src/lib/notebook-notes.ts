import type { NotebookMessage, NotebookSource } from '@/api/types/classes';

/** The student's own notes (NotebookMessage::MAX_NOTE_CHARS on the server). */
export const NOTE_MAX_CHARS = 10000;

/** The student's own notes (`kind: note`), newest first. */
export function notesOf(messages: readonly NotebookMessage[]): NotebookMessage[] {
  return messages.filter((message) => message.kind === 'note').sort((a, b) => b.id - a.id);
}

/** A note's first line without its Markdown marks (as on the web), else "Catatan baru". */
export function noteTitle(content: string | null | undefined): string {
  const line = String(content ?? '')
    .split('\n')
    .map((text) =>
      text
        .replace(/^\s{0,3}(?:#{1,6}\s+|[-*+]\s+|\d+[.)]\s+|>\s*)/, '')
        .replace(/[*_`]+/g, '')
        .trim(),
    )
    .find((text) => text !== '');

  if (!line) {
    return 'Catatan baru';
  }

  return line.length > 80 ? `${line.slice(0, 79)}…` : line;
}

/** The source a note became ("Jadikan sumber"), while it is still in the notebook. */
export function noteSourceOf(note: NotebookMessage, sources: readonly NotebookSource[]): NotebookSource | null {
  const id = Number(note.context?.note_source?.id);

  return id ? sources.find((source) => source.id === id) ?? null : null;
}

/**
 * Why "Jadikan sumber" cannot run now, or null when it can: an empty note,
 * a note that is a source already, or a full notebook (the server says so too).
 */
export function noteSourceBlock(note: NotebookMessage, sources: readonly NotebookSource[], room: number | null): string | null {
  if (noteSourceOf(note, sources)) {
    return 'Sudah jadi sumber.';
  }

  if (String(note.content ?? '').trim() === '') {
    return 'Catatan masih kosong.';
  }

  return room !== null && room <= 0 ? 'Notebook ini sudah mencapai batas jumlah sumber.' : null;
}

/** What Share sends: the note as written (Markdown), led by nothing else. */
export function noteShareMessage(note: NotebookMessage): string {
  return String(note.content ?? '').trim();
}
