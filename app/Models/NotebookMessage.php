<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question, an answer, a studio output (summary, quiz, ...) or the
 * student's own note (`note`: Markdown they write, never sent to the model
 * as conversation) of a notebook.
 *
 * @property int $id
 * @property int $notebook_id
 * @property string $role
 * @property string $kind
 * @property string $content
 * @property list<array{n: int, source_id: int, source_title: string, page: int|null, position?: int, chunk_id?: int, excerpt: string}>|null $citations
 * @property array{source_ids: list<int>, sources: list<array{id: int, title: string, meeting_number: int|null}>, total_sources: int, excerpts: int, chars: int, partial: bool, focus: string|null, mode?: string, options?: array{count?: int, difficulty?: string}, practice_from?: array{message_id: int, missed: list<int>}, practice?: array{score: int, total: int, missed: list<int>, flagged: list<int>, choices?: list<int|null>, at: string}, feedback_mode?: string, feedback_prompt?: string, from_message_id?: int, note_source?: array{id: int, title: string, at: string}, answer_excerpt?: string, truncated?: bool, provider?: string, model?: string, usage?: array{input_tokens: int|null, output_tokens: int|null, thoughts_tokens: int|null}}|null $context
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
     * Outputs whose content is JSON rather than text. `feedback` is the
     * "Cek pemahamanku" result (NotebookAssistant::feedback()), not a Studio kind.
     */
    public const STRUCTURED_KINDS = ['quiz', 'flashcards', 'glossary', 'mind_map', 'feedback'];

    public const KIND_CHAT = 'chat';

    /**
     * The student's own note: kept in Catatan, edited by them, and turned
     * into a text source on request. No AI is involved.
     */
    public const KIND_NOTE = 'note';

    public const MAX_NOTE_CHARS = 10000;

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

    public function isNote(): bool
    {
        return $this->kind === self::KIND_NOTE;
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
