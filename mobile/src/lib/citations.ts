import type { NotebookCitation } from '@/api/types/classes';
import { queryString } from '@/lib/classes';

/** A piece of an answer: text, or one citation `[n]` drawn as a chip. */
export type CitationToken = { type: 'text'; text: string } | { type: 'cite'; n: number };

/**
 * A run of citations (`[3]`, `[2][5]`, `[2, 5]`), as the server's
 * NotebookAssistant::CITATION_RUN. The server's rule also needs the run not
 * to follow a letter, digit, `_` or `]` (`arr[0]` and `matrix[2][5]` are
 * code); that is checked by hand (WORD_BEFORE), since not every JS engine
 * the app runs on has lookbehind.
 */
const CITATION_RUN = /(?:\[\d{1,4}(?:\s*[,;]\s*\d{1,4})*\])+/g;

const WORD_BEFORE = (() => {
  try {
    return new RegExp('[\\p{L}\\p{N}_\\]]', 'u');
  } catch {
    return /[0-9A-Za-z_\]À-ɏ]/;
  }
})();

/** Fenced blocks (closed or not) and inline code spans: brackets there are code, never citations. */
const CODE = /(```[\s\S]*?(?:```|$)|`[^`\n]*`)/;

/**
 * Splits an answer into text and citation chips with the server's rule, leaving code alone:
 * `'a [1][2] b'` gives `a `, chip 1, chip 2, ` b`; `'arr[0]'` stays text.
 */
export function citationTokens(text: string | null | undefined): CitationToken[] {
  const tokens: CitationToken[] = [];
  const pushText = (value: string) => {
    if (value === '') {
      return;
    }

    const last = tokens[tokens.length - 1];

    if (last?.type === 'text') {
      last.text += value;
    } else {
      tokens.push({ type: 'text', text: value });
    }
  };

  (text ?? '').split(CODE).forEach((part, index) => {
    if (index % 2 === 1) {
      pushText(part);

      return;
    }

    let from = 0;

    for (const match of part.matchAll(CITATION_RUN)) {
      const start = match.index ?? 0;
      const before = start > 0 ? part[start - 1] : '';

      if (before !== '' && WORD_BEFORE.test(before)) {
        continue;
      }

      pushText(part.slice(from, start));
      (match[0].match(/\d{1,4}/g) ?? []).forEach((digits) => tokens.push({ type: 'cite', n: Number(digits) }));
      from = start + match[0].length;
    }

    pushText(part.slice(from));
  });

  return tokens;
}

/** Markdown the assistant writes, readable as plain text, citations kept: no `**` or `#` markers, bullets as `•`. */
export function markdownText(content: string | null | undefined): string {
  return (content ?? '')
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/^#{1,6}\s+/gm, '')
    .replace(/^\s*[-*]\s+/gm, '• ')
    .trim();
}

/** Markdown the assistant writes, readable as plain text: no `**`, `#` or `[n]` markers left (code is kept as written). */
export function plainText(content: string | null | undefined): string {
  let text = '';
  let dropped = false;

  for (const token of citationTokens(markdownText(content))) {
    if (token.type === 'cite') {
      dropped = true;
      continue;
    }

    let next = token.text;

    if (dropped && /^(?:[.,;:!?\n]|$)/.test(next)) {
      // "transitif [2]." reads "transitif.", as the server words earlier answers (withoutCitations).
      text = text.replace(/[ \t]+$/, '');
    } else if (dropped && /[ \t]$/.test(text)) {
      next = next.replace(/^[ \t]+/, '');
    }

    text += next;
    dropped = false;
  }

  return (dropped ? text.replace(/[ \t]+$/, '') : text).trim();
}

/** The citation `[n]` of an output, or null when the output does not list it. */
export function findCitation(citations: NotebookCitation[] | null | undefined, n: number): NotebookCitation | null {
  return (citations ?? []).find((citation) => citation.n === n) ?? null;
}

/**
 * The params of the source screen (notebooks/[id]/sources/[sourceId]); all strings, as route params are.
 * `around` anchors the window of chunks; `cited` is the cited chunk's position, kept apart so paging
 * (which moves `around`) never moves the highlight.
 */
export type CitationSourceParams = { id: string; sourceId: string; around?: string; cited?: string; message?: string; n?: string };

/**
 * Where "Lihat sumber" goes for a citation: its source around its position, or, for a citation saved before
 * positions were kept, its message and number, for the server to find it by its excerpt. Null without a source.
 */
export function citationSourceParams(notebookId: string | number, messageId: number, citation: NotebookCitation): CitationSourceParams | null {
  if (!citation.source_id) {
    return null;
  }

  const base = { id: String(notebookId), sourceId: String(citation.source_id) };

  return Number.isInteger(citation.position) && (citation.position ?? -1) >= 0
    ? { ...base, around: String(citation.position), cited: String(citation.position) }
    : { ...base, message: String(messageId), n: String(citation.n) };
}

const digitsOf = (value: string | undefined): number | null => (value && /^\d+$/.test(value) ? Number(value) : null);

/**
 * The cited chunk's position on the source screen: the `cited` param, or, for an old citation looked up by
 * its message and number, where the server found it (`around` of the response). Null when nothing was cited
 * (a whole source opened, or a window paged to from one).
 */
export function citedPosition(params: Partial<CitationSourceParams>, responseAround: number | null | undefined): number | null {
  const cited = digitsOf(params.cited);

  if (cited !== null) {
    return cited;
  }

  if (digitsOf(params.message) !== null && digitsOf(params.n) !== null && digitsOf(params.around) === null) {
    return Number.isInteger(responseAround) ? (responseAround as number) : null;
  }

  return null;
}

/**
 * Whether the screen should say the cited chunk is gone: only on the window opened for the citation (anchored
 * at it, or looked up by message and number), not on a window paged to, where it is simply out of view.
 */
export function citationMissing(params: Partial<CitationSourceParams>, cited: number | null, chunks: readonly { position: number }[]): boolean {
  const lookedUp = digitsOf(params.message) !== null && digitsOf(params.n) !== null && digitsOf(params.around) === null;
  const anchored = digitsOf(params.cited) !== null && digitsOf(params.cited) === digitsOf(params.around);

  if (!lookedUp && !anchored) {
    return false;
  }

  return cited === null || !chunks.some((chunk) => chunk.position === cited);
}

/** The source screen's params for a pager: a new window anchor, the cited position carried along unchanged. */
export function pagedSourceParams(around: number, cited: number | null): Pick<CitationSourceParams, 'around' | 'cited' | 'message' | 'n'> {
  return { around: String(Math.max(0, Math.floor(around))), cited: cited === null ? undefined : String(cited), message: undefined, n: undefined };
}

/** The API path of a window of a source's chunks for the source screen's params. */
export function sourceChunksPath(params: Partial<CitationSourceParams>): string | null {
  if (!params.id || !params.sourceId || !/^\d+$/.test(params.id) || !/^\d+$/.test(params.sourceId)) {
    return null;
  }

  const digits = (value: string | undefined): string | null => (value && /^\d+$/.test(value) ? value : null);
  const around = digits(params.around);
  const message = digits(params.message);
  const n = digits(params.n);
  const query = around ? queryString({ around }) : message && n ? queryString({ message, n }) : '';

  return `notebooks/${params.id}/sources/${params.sourceId}/chunks${query}`;
}

/** "Halaman 3", or "Bagian 4" for a source without pages (DOCX, notes). */
export function chunkHeading(chunk: { page?: number | null; position: number }): string {
  return chunk.page ? `Halaman ${chunk.page}` : `Bagian ${chunk.position + 1}`;
}

/** Chunks under their headings: consecutive chunks of one page share "Halaman N"; each chunk without a page is its own "Bagian N". */
export function chunkGroups<T extends { page?: number | null; position: number }>(chunks: T[]): { heading: string; chunks: T[] }[] {
  const groups: { heading: string; chunks: T[] }[] = [];

  chunks.forEach((chunk) => {
    const heading = chunkHeading(chunk);
    const last = groups[groups.length - 1];

    if (last && chunk.page && last.heading === heading) {
      last.chunks.push(chunk);
    } else {
      groups.push({ heading, chunks: [chunk] });
    }
  });

  return groups;
}
