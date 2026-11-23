<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotebookChunk;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;

/**
 * The text of a source as the AI read it, a window of chunks at a time,
 * so a citation can be opened where it points. Reads stored chunks only:
 * nothing is sent to the AI provider.
 */
class NotebookSourceViewer
{
    /**
     * Chunks per window.
     */
    private const WINDOW = 40;

    /**
     * Chunks kept before the cited one, so it is read in its context.
     */
    private const LEAD = 15;

    /**
     * The chunks around `$around` (the start of the source when null).
     *
     * @return array{chunks: list<array{position: int, page: int|null, content: string}>, has_before: bool, has_after: bool}
     */
    public function window(NotebookSource $source, ?int $around): array
    {
        $around ??= 0;
        $columns = ['id', 'position', 'page', 'content'];

        $before = $source->chunks()->where('position', '<', $around)->orderByDesc('position')->limit(self::WINDOW + 1)->get($columns);
        $after = $source->chunks()->where('position', '>=', $around)->orderBy('position')->limit(self::WINDOW + 1)->get($columns);

        $takeBefore = min($before->count(), max(self::LEAD, self::WINDOW - $after->count()));
        $takeAfter = min($after->count(), self::WINDOW - $takeBefore);

        $chunks = $before->take($takeBefore)->reverse()->concat($after->take($takeAfter))->values();

        return [
            'chunks' => $chunks->map(static fn (NotebookChunk $chunk): array => [
                'position' => $chunk->position,
                'page' => $chunk->page,
                'content' => $chunk->content,
            ])->all(),
            'has_before' => $before->count() > $takeBefore,
            'has_after' => $after->count() > $takeAfter,
        ];
    }

    /**
     * Where citation `[$number]` of `$message` points in `$source`: its
     * stored position, or, for citations saved before positions were
     * kept, the chunk whose text starts with the cited excerpt (chunks
     * are stored in reading order, so the first match by id is the first in
     * the source).
     */
    public function citedPosition(NotebookMessage $message, int $number, NotebookSource $source): ?int
    {
        $citation = collect($message->citations ?? [])
            ->first(static fn (array $item): bool => $item['n'] === $number && $item['source_id'] === $source->id);

        if ($citation === null) {
            return null;
        }

        if (isset($citation['position'])) {
            return $citation['position'];
        }

        $excerpt = trim($citation['excerpt']);
        $prefix = trim(mb_substr($excerpt, -1) === '…' ? mb_substr($excerpt, 0, -1) : $excerpt);

        if ($prefix === '') {
            return null;
        }

        foreach ($source->chunks()->select(['id', 'position', 'content'])->lazyById(200) as $chunk) {
            if (str_starts_with(trim($chunk->content), $prefix)) {
                return $chunk->position;
            }
        }

        return null;
    }
}
