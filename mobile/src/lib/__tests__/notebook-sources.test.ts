import type { NotebookSource } from '@/api/types/classes';
import {
  assignmentBriefChips,
  BRIEF_FOCUS_MAX,
  briefWithChip,
  buildUploadForm,
  canRetrySource,
  chatSuggestions,
  contextSourceIds,
  excludedFor,
  noContextChosen,
  parseSourceSelection,
  photoFile,
  pollDelay,
  POLL_FAST_MS,
  POLL_LIMIT_MS,
  POLL_SLOW_AFTER_MS,
  POLL_SLOW_MS,
  pruneExcluded,
  readingKey,
  retryLabel,
  SELECTION_NOTEBOOK_LIMIT,
  showsSourceSelection,
  sourceQuestions,
  sourceTopics,
  uploadMimeTypeOf,
  withExcluded,
} from '@/lib/notebook-sources';

const MINUTE = 60 * 1000;

function source(id: number, fields: Partial<NotebookSource> = {}): NotebookSource {
  return { id, kind: 'upload', title: `Sumber ${id}`, status: 'ready', ...fields };
}

/** React Native's FormData keeps a file part as { uri, name, type }; Node's (in jest) would turn it into text. */
class RecordingFormData {
  parts: [string, unknown, string | undefined][] = [];

  append(name: string, value: unknown, fileName?: string): void {
    this.parts.push([name, value, fileName]);
  }
}

function partsOf(form: FormData): [string, unknown, string | undefined][] {
  return (form as unknown as RecordingFormData).parts;
}

describe('pollDelay', () => {
  it('mirrors the web POLL_* constants', () => {
    expect(POLL_FAST_MS).toBe(4000);
    expect(POLL_SLOW_MS).toBe(20_000);
    expect(POLL_SLOW_AFTER_MS).toBe(2 * MINUTE);
    expect(POLL_LIMIT_MS).toBe(60 * MINUTE);
  });

  it('asks every 4 s for two minutes, then every 20 s, and stops after an hour', () => {
    expect(pollDelay(0, true)).toBe(4000);
    expect(pollDelay(2 * MINUTE - 1, true)).toBe(4000);
    expect(pollDelay(2 * MINUTE, true)).toBe(20_000);
    expect(pollDelay(60 * MINUTE - 1, true)).toBe(20_000);
    expect(pollDelay(60 * MINUTE, true)).toBeNull();
    expect(pollDelay(3 * 60 * MINUTE, true)).toBeNull();
  });

  it('does not ask while nothing is being read', () => {
    expect(pollDelay(0, false)).toBeNull();
    expect(pollDelay(5 * MINUTE, false)).toBeNull();
  });
});

describe('readingKey', () => {
  it('lists sources being read, leaving out stalled ones and ones waiting for tomorrow', () => {
    expect(
      readingKey([
        source(1, { status: 'pending' }),
        source(2, { status: 'processing' }),
        source(3, { status: 'ready' }),
        source(4, { status: 'failed' }),
        source(5, { status: 'processing', stalled: true }),
        source(6, { status: 'pending', waiting: true, error: 'Kuota baca hari ini habis.' }),
        source(7, { status: 'pending', waiting: true }),
      ]),
    ).toBe('1,2,7');
    expect(readingKey([source(1)])).toBe('');
  });
});

describe('assignmentBriefChips', () => {
  it('offers "Tugas pertemuan N" for each meeting already read, in meeting order', () => {
    const chips = assignmentBriefChips([
      source(10, { kind: 'meeting', meeting_number: 7 }),
      source(11, { kind: 'meeting', meeting_number: 3 }),
      source(12, { kind: 'meeting', meeting_number: 5, status: 'processing' }),
      source(13, { kind: 'upload', meeting_number: 2 }),
      source(14, { kind: 'meeting', meeting_number: null, title: 'Kuliah tamu' }),
      source(15, { kind: 'meeting', meeting_number: 3 }),
    ]);

    expect(chips).toEqual([
      { key: 11, label: 'Tugas pertemuan 3', prefix: 'Tugas pertemuan 3: ' },
      { key: 10, label: 'Tugas pertemuan 7', prefix: 'Tugas pertemuan 7: ' },
      { key: 14, label: 'Tugas Kuliah tamu', prefix: 'Tugas Kuliah tamu: ' },
    ]);
  });

  it('offers nothing without meetings', () => {
    expect(assignmentBriefChips([])).toEqual([]);
    expect(assignmentBriefChips([source(1)])).toEqual([]);
  });

  it('starts an empty brief with the shortcut and keeps what the student wrote', () => {
    const chip = { key: 1, label: 'Tugas pertemuan 3', prefix: 'Tugas pertemuan 3: ' };

    expect(briefWithChip('', chip)).toBe('Tugas pertemuan 3: ');
    expect(briefWithChip('   ', chip)).toBe('Tugas pertemuan 3: ');
    expect(briefWithChip('Makalah 5 halaman', chip)).toBe('Makalah 5 halaman');
    expect(BRIEF_FOCUS_MAX).toBe(1500);
  });
});

describe('buildUploadForm', () => {
  const nativeFormData = globalThis.FormData;

  beforeEach(() => {
    globalThis.FormData = RecordingFormData as unknown as typeof FormData;
  });

  afterEach(() => {
    globalThis.FormData = nativeFormData;
  });

  it('sends type=upload and the file from its uri, without source_ids', () => {
    const parts = partsOf(buildUploadForm({ uri: 'file:///cache/modul.pdf', name: 'modul.pdf', mimeType: 'application/pdf', size: 1200 }));

    expect(parts).toEqual([
      ['type', 'upload', undefined],
      ['file', { uri: 'file:///cache/modul.pdf', name: 'modul.pdf', type: 'application/pdf' }, undefined],
    ]);
  });

  it('sends the browser file itself in the web preview', () => {
    const webFile = { size: 3 } as unknown as Blob;
    const parts = partsOf(buildUploadForm({ uri: 'blob:x', name: 'catatan.txt', mimeType: 'text/plain', size: 3, webFile }));

    expect(parts).toEqual([
      ['type', 'upload', undefined],
      ['file', webFile, 'catatan.txt'],
    ]);
  });

  it('names a picked photo with an extension the server reads', () => {
    expect(photoFile({ uri: 'file:///p.jpg', fileName: 'IMG_0042.HEIC', mimeType: 'image/jpeg', fileSize: 900 })).toEqual({
      uri: 'file:///p.jpg',
      name: 'IMG_0042.jpg',
      mimeType: 'image/jpeg',
      size: 900,
    });
    expect(photoFile({ uri: 'file:///p.png', fileName: null, mimeType: 'image/png' }, 1732300000000)).toMatchObject({ name: 'foto-1732300000000.png', mimeType: 'image/png', size: null });
    expect(photoFile({ uri: 'file:///p', mimeType: null }, 5)).toMatchObject({ name: 'foto-5.jpg', mimeType: 'image/jpeg' });
  });

  it('knows the MIME type of every source format', () => {
    expect(uploadMimeTypeOf('Bab 1.DOCX')).toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect(uploadMimeTypeOf('catatan.md')).toBe('text/markdown');
    expect(uploadMimeTypeOf('program.exe')).toBe('application/octet-stream');
  });
});

describe('source selection', () => {
  const sources = [source(1), source(2), source(3, { status: 'processing' })];

  it('sends no source_ids while nothing is left out, else the ready sources kept', () => {
    expect(contextSourceIds(sources, new Set())).toEqual([]);
    expect(contextSourceIds(sources, new Set([2]))).toEqual([1]);
    expect(noContextChosen(sources, new Set())).toBe(false);
    expect(noContextChosen(sources, new Set([1, 2]))).toBe(true);
  });

  it('keeps each notebook its own left-out sources, and forgets a notebook with none', () => {
    let store = withExcluded({}, 7, [3, 2, 2]);
    store = withExcluded(store, '9', [5]);

    expect(store).toEqual({ '7': [2, 3], '9': [5] });
    expect([...excludedFor(store, '7')]).toEqual([2, 3]);
    expect(excludedFor(store, 8).size).toBe(0);
    expect(withExcluded(store, 7, [])).toEqual({ '9': [5] });
  });

  it('reads back what it wrote and ignores anything unreadable', () => {
    const store = withExcluded(withExcluded({}, 7, [2]), 9, [4, 5]);

    expect(parseSourceSelection(JSON.parse(JSON.stringify(store)))).toEqual(store);
    expect(parseSourceSelection(null)).toEqual({});
    expect(parseSourceSelection('7')).toEqual({});
    expect(parseSourceSelection([1, 2])).toEqual({});
    expect(parseSourceSelection({ '7': [2, -1, 'x', 2.5, 2], '../x': [1], '9': 'nope', '10': [] })).toEqual({ '7': [2] });
  });

  it(`keeps at most ${SELECTION_NOTEBOOK_LIMIT} notebooks, forgetting the oldest`, () => {
    let store = {};

    for (let notebook = 1; notebook <= SELECTION_NOTEBOOK_LIMIT + 2; notebook++) {
      store = withExcluded(store, notebook, [1]);
    }

    expect(Object.keys(store)).toHaveLength(SELECTION_NOTEBOOK_LIMIT);
    expect(store).not.toHaveProperty('1');
    expect(store).not.toHaveProperty('2');
    expect(store).toHaveProperty(String(SELECTION_NOTEBOOK_LIMIT + 2));
  });

  it('forgets deleted sources', () => {
    expect([...pruneExcluded(new Set([1, 4]), sources)]).toEqual([1]);
  });

  it('shows the chips with two ready sources, and whenever every ready source is left out', () => {
    expect(showsSourceSelection(sources, new Set())).toBe(true);
    expect(showsSourceSelection([source(1)], new Set())).toBe(false);
    expect(showsSourceSelection([source(1), source(2, { status: 'processing' })], new Set([2]))).toBe(false);

    // B was left out, then A deleted: B is the only ready source and still left out, so the chips stay to undo it.
    const afterDelete = [source(2)];
    const excluded = pruneExcluded(new Set([2]), afterDelete);
    expect(noContextChosen(afterDelete, excluded)).toBe(true);
    expect(showsSourceSelection(afterDelete, excluded)).toBe(true);

    expect(showsSourceSelection([source(1, { status: 'processing' })], new Set([1]))).toBe(false);
  });
});

describe('source actions', () => {
  it('reads again a failed or stalled file, never a note or a meeting', () => {
    expect(canRetrySource(source(1, { status: 'failed' }), true)).toBe(true);
    expect(canRetrySource(source(1, { status: 'processing', stalled: true }), true)).toBe(true);
    expect(canRetrySource(source(1, { status: 'failed', kind: 'text' }), true)).toBe(false);
    expect(canRetrySource(source(1, { status: 'failed', kind: 'meeting' }), true)).toBe(false);
    expect(canRetrySource(source(1), true)).toBe(false);
    expect(retryLabel(source(1, { status: 'failed' }))).toBe('Baca ulang');
  });

  it('reads now a file waiting for tomorrow, only while the AI is available', () => {
    const waiting = source(1, { status: 'pending', waiting: true, error: 'Kuota baca hari ini habis.' });

    expect(canRetrySource(waiting, true)).toBe(true);
    expect(canRetrySource(waiting, false)).toBe(false);
    expect(retryLabel(waiting)).toBe('Baca sekarang');
  });
});

describe('source guide', () => {
  it('reads topics and questions, dropping malformed entries', () => {
    const guided = source(1, {
      topics: ['Normalisasi', '', 7 as unknown as string, 'Redundansi'],
      suggested_questions: ['Mengapa 1NF?', 'Apa beda 2NF dan 3NF?', 'Bagaimana contohnya?', 'Keempat?'],
    });

    expect(sourceTopics(guided)).toEqual(['Normalisasi', 'Redundansi']);
    expect(sourceQuestions(guided)).toEqual(['Mengapa 1NF?', 'Apa beda 2NF dan 3NF?', 'Bagaimana contohnya?']);
  });

  it('has no guide for a source read before guides, and no questions while it is read', () => {
    expect(sourceTopics(source(2))).toEqual([]);
    expect(sourceQuestions(source(2))).toEqual([]);
    expect(sourceQuestions(source(3, { status: 'processing', suggested_questions: ['Mengapa?'] }))).toEqual([]);
  });

  it('takes at most four chat suggestions, none from an older server', () => {
    expect(chatSuggestions(['a?', 'b?', '', 'c?', 'd?', 'e?'])).toEqual(['a?', 'b?', 'c?', 'd?']);
    expect(chatSuggestions(undefined)).toEqual([]);
    expect(chatSuggestions('a?')).toEqual([]);
  });
});
