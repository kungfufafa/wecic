import type { NotebookMessage, NotebookSource } from '@/api/types/classes';
import { NOTE_MAX_CHARS, noteShareMessage, noteSourceBlock, noteSourceOf, notesOf, noteTitle } from '@/lib/notebook-notes';
import { choiceForServer, choiceKey, excludedFromChoice } from '@/lib/notebook-sources';

const note = (id: number, content: string, extra: Partial<NotebookMessage> = {}): NotebookMessage => ({ id, role: 'user', kind: 'note', content, pinned: true, ...extra });
const source = (id: number, status: NotebookSource['status'] = 'ready'): NotebookSource => ({ id, kind: 'text', title: `Sumber ${id}`, status }) as NotebookSource;

describe('own notes', () => {
  it('lists only notes, newest first', () => {
    const messages: NotebookMessage[] = [
      note(3, 'Lama'),
      { id: 4, role: 'assistant', kind: 'chat', content: 'Jawaban' },
      note(9, 'Baru'),
      { id: 5, role: 'assistant', kind: 'summary', content: 'Ringkasan', pinned: true },
    ];

    expect(notesOf(messages).map((item) => item.id)).toEqual([9, 3]);
  });

  it('titles a note by its first line without Markdown marks, as on the web', () => {
    expect(noteTitle('\n# **Normalisasi** basis `data`\nIsi')).toBe('Normalisasi basis data');
    expect(noteTitle('- poin pertama')).toBe('poin pertama');
    expect(noteTitle('   \n ')).toBe('Catatan baru');
    expect(noteTitle(undefined)).toBe('Catatan baru');
    expect(noteTitle('a'.repeat(100))).toHaveLength(80);
    expect(NOTE_MAX_CHARS).toBe(10000);
  });

  it('knows the source a note became while it exists', () => {
    const converted = note(1, 'Isi', { context: { note_source: { id: 7, title: 'Isi', at: '2026-11-23T00:00:00Z' } } });

    expect(noteSourceOf(converted, [source(7)])?.id).toBe(7);
    expect(noteSourceOf(converted, [source(8)])).toBeNull();
    expect(noteSourceOf(note(2, 'Isi'), [source(7)])).toBeNull();
  });

  it('says why a note cannot be made a source', () => {
    const converted = note(1, 'Isi', { context: { note_source: { id: 7, title: 'Isi', at: 'x' } } });

    expect(noteSourceBlock(converted, [source(7)], 5)).toBe('Sudah jadi sumber.');
    expect(noteSourceBlock(converted, [], 5)).toBeNull();
    expect(noteSourceBlock(note(2, '  '), [], 5)).toBe('Catatan masih kosong.');
    expect(noteSourceBlock(note(2, 'Isi'), [], 0)).toBe('Notebook ini sudah mencapai batas jumlah sumber.');
    expect(noteSourceBlock(note(2, 'Isi'), [], null)).toBeNull();
  });

  it('shares the note as written', () => {
    expect(noteShareMessage(note(1, '\n## Judul\n- isi [1]\n'))).toBe('## Judul\n- isi [1]');
  });
});

describe('the context choice kept on the server', () => {
  const sources = [source(1), source(2), source(3, 'pending')];

  it('turns the saved choice into left-out sources, and keeps the device copy for an older server', () => {
    expect([...(excludedFromChoice([1, 3], sources) ?? [])]).toEqual([2]);
    expect(excludedFromChoice(null, sources)?.size).toBe(0);
    expect([...(excludedFromChoice([], sources) ?? [])]).toEqual([1, 2, 3]);
    expect(excludedFromChoice(undefined, sources)).toBeNull();
  });

  it('saves null for every source, else every source kept', () => {
    expect(choiceForServer(new Set(), sources)).toBeNull();
    expect(choiceForServer(new Set([2]), sources)).toEqual([1, 3]);
    expect(choiceForServer(new Set([1, 2, 3]), sources)).toEqual([]);
  });

  it('keys a choice so the same one is applied once', () => {
    expect(choiceKey(undefined)).toBeNull();
    expect(choiceKey(null)).toBe('all');
    expect(choiceKey([1, 3])).toBe('1,3');
    expect(choiceKey([])).toBe('');
    expect(choiceKey(choiceForServer(new Set([2]), sources))).toBe(choiceKey([1, 3]));
  });
});
