import {
  chunkGroups,
  chunkHeading,
  citationMissing,
  citationSourceParams,
  citationTokens,
  citedPosition,
  findCitation,
  pagedSourceParams,
  plainText,
  sourceChunksPath,
} from '@/lib/citations';

describe('citation tokens', () => {
  it('turns a run of citations into chips between the text', () => {
    expect(citationTokens('a [1][2] b')).toEqual([
      { type: 'text', text: 'a ' },
      { type: 'cite', n: 1 },
      { type: 'cite', n: 2 },
      { type: 'text', text: ' b' },
    ]);
  });

  it('splits a comma list and reads a citation at the start', () => {
    expect(citationTokens('[3] lalu [2, 5].')).toEqual([
      { type: 'cite', n: 3 },
      { type: 'text', text: ' lalu ' },
      { type: 'cite', n: 2 },
      { type: 'cite', n: 5 },
      { type: 'text', text: '.' },
    ]);
  });

  it('keeps brackets after a word, a digit or another bracket as text, as the server does', () => {
    expect(citationTokens('arr[0]')).toEqual([{ type: 'text', text: 'arr[0]' }]);
    expect(citationTokens('matrix[2][5] dan x1[3]')).toEqual([{ type: 'text', text: 'matrix[2][5] dan x1[3]' }]);
    expect(citationTokens('kata[1] dan é[2]')).toEqual([{ type: 'text', text: 'kata[1] dan é[2]' }]);
  });

  it('leaves code spans and fenced blocks alone', () => {
    expect(citationTokens('Pakai `a = [1]` [4]')).toEqual([
      { type: 'text', text: 'Pakai `a = [1]` ' },
      { type: 'cite', n: 4 },
    ]);
    expect(citationTokens('```\nx = [1]\n```')).toEqual([{ type: 'text', text: '```\nx = [1]\n```' }]);
  });

  it('copes with empty content', () => {
    expect(citationTokens(null)).toEqual([]);
    expect(citationTokens('')).toEqual([]);
  });
});

describe('plain text', () => {
  it('no longer leaks citation numbers', () => {
    expect(plainText('3NF menghapus ketergantungan transitif [2]. Tujuannya mengurangi redundansi [1][3].')).toBe(
      '3NF menghapus ketergantungan transitif. Tujuannya mengurangi redundansi.',
    );
    expect(plainText('a [1] b')).toBe('a b');
    expect(plainText('**Penting** [1]')).toBe('Penting');
  });

  it('keeps markdown cleanup and brackets that are code', () => {
    expect(plainText('## Judul\n- satu [1]\n- arr[0]')).toBe('Judul\n• satu\n• arr[0]');
  });
});

describe('opening a citation in its source', () => {
  const citation = { n: 2, source_id: 4, source_title: 'Modul', page: 3, position: 12, chunk_id: 90, excerpt: 'Teks.' };

  it('finds a citation by its number', () => {
    expect(findCitation([citation], 2)).toBe(citation);
    expect(findCitation([citation], 9)).toBeNull();
    expect(findCitation(undefined, 2)).toBeNull();
  });

  it('opens around the position, or by message and number for an old citation', () => {
    expect(citationSourceParams(7, 40, citation)).toEqual({ id: '7', sourceId: '4', around: '12', cited: '12' });
    expect(citationSourceParams('7', 40, { n: 2, source_id: 4, excerpt: 'Lama.' })).toEqual({ id: '7', sourceId: '4', message: '40', n: '2' });
    expect(citationSourceParams(7, 40, { n: 2 })).toBeNull();
  });

  it('keeps the cited chunk when paging, not the pager anchor', () => {
    const opened = { id: '7', sourceId: '4', around: '50', cited: '50' };
    expect(citedPosition(opened, 50)).toBe(50);
    expect(citationMissing(opened, 50, [{ position: 49 }, { position: 50 }])).toBe(false);
    expect(citationMissing(opened, 50, [{ position: 0 }])).toBe(true);

    const earlier = { ...opened, ...pagedSourceParams(10, 50) };
    expect(earlier).toEqual({ id: '7', sourceId: '4', around: '10', cited: '50', message: undefined, n: undefined });
    expect(citedPosition(earlier, 10)).toBe(50);
    expect(citationMissing(earlier, 50, [{ position: 10 }, { position: 11 }])).toBe(false);
    expect(sourceChunksPath(earlier)).toBe('notebooks/7/sources/4/chunks?around=10');

    expect(pagedSourceParams(-5, null)).toEqual({ around: '0', cited: undefined, message: undefined, n: undefined });
  });

  it('takes an old citation where the server found it, and carries that through paging', () => {
    const lookup = { id: '7', sourceId: '4', message: '40', n: '2' };
    expect(citedPosition(lookup, 33)).toBe(33);
    expect(citationMissing(lookup, 33, [{ position: 33 }])).toBe(false);
    expect(citedPosition(lookup, null)).toBeNull();
    expect(citationMissing(lookup, null, [{ position: 0 }])).toBe(true);

    const paged = { ...lookup, ...pagedSourceParams(73, citedPosition(lookup, 33)) };
    expect(citedPosition(paged, 73)).toBe(33);
    expect(citationMissing(paged, 33, [{ position: 73 }])).toBe(false);
  });

  it('cites nothing on a whole source or a window paged to from one', () => {
    expect(citedPosition({ id: '7', sourceId: '4' }, null)).toBeNull();
    expect(citedPosition({ id: '7', sourceId: '4', ...pagedSourceParams(41, null) }, 41)).toBeNull();
    expect(citationMissing({ id: '7', sourceId: '4', around: '41' }, null, [])).toBe(false);
    expect(citedPosition({ id: '7', sourceId: '4', cited: '1&x' }, 5)).toBeNull();
  });

  it('asks the chunks endpoint with only numeric params', () => {
    expect(sourceChunksPath({ id: '7', sourceId: '4', around: '12' })).toBe('notebooks/7/sources/4/chunks?around=12');
    expect(sourceChunksPath({ id: '7', sourceId: '4', message: '40', n: '2' })).toBe('notebooks/7/sources/4/chunks?message=40&n=2');
    expect(sourceChunksPath({ id: '7', sourceId: '4' })).toBe('notebooks/7/sources/4/chunks');
    expect(sourceChunksPath({ id: '7', sourceId: '4', around: '1&x=2' })).toBe('notebooks/7/sources/4/chunks');
    expect(sourceChunksPath({ id: '7/../8', sourceId: '4' })).toBeNull();
  });

  it('groups chunks under Halaman N, or Bagian N without pages', () => {
    expect(chunkHeading({ page: null, position: 3 })).toBe('Bagian 4');
    expect(
      chunkGroups([
        { page: 2, position: 4 },
        { page: 2, position: 5 },
        { page: 3, position: 6 },
      ]).map((group) => [group.heading, group.chunks.length]),
    ).toEqual([
      ['Halaman 2', 2],
      ['Halaman 3', 1],
    ]);
    expect(chunkGroups([{ page: null, position: 0 }, { page: null, position: 1 }]).map((group) => group.heading)).toEqual(['Bagian 1', 'Bagian 2']);
  });
});
