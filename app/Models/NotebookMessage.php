<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question, an answer, or a studio output (summary, quiz, ...) of a notebook.
 *
 * @property int $id
 * @property int $notebook_id
 * @property string $role
 * @property string $kind
 * @property string $content
 * @property list<array{n: int, source_id: int, source_title: string, page: int|null, excerpt: string}>|null $citations
 * @property array{source_ids: list<int>, sources: list<array{id: int, title: string, meeting_number: int|null}>, total_sources: int, excerpts: int, chars: int, partial: bool, focus: string|null}|null $context
 * @property bool $pinned
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class NotebookMessage extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookMessageFactory> */
    use HasFactory;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /**
     * Outputs whose content is JSON rather than text.
     */
    public const STRUCTURED_KINDS = ['quiz', 'flashcards', 'glossary', 'mind_map'];

    public const KIND_CHAT = 'chat';

    protected $fillable = [
        'notebook_id',
        'role',
        'kind',
        'content',
        'citations',
        'context',
        'pinned',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'context' => 'array',
            'pinned' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Notebook, $this>
     */
    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }

    /**
     * @return array{id: int, role: string, kind: string, content: string, data: mixed, citations: list<array<string, mixed>>, context: array<string, mixed>|null, pinned: bool, created_at: string|null}
     */
    public function toApiArray(): array
    {
        $structured = in_array($this->kind, self::STRUCTURED_KINDS, true);

        return [
            'id' => $this->id,
            'role' => $this->role,
            'kind' => $this->kind,
            'content' => $structured ? '' : $this->content,
            'data' => $structured ? json_decode($this->content, true) : null,
            'citations' => $this->citations ?? [],
            'context' => $this->context,
            'pinned' => $this->pinned,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
