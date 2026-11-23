<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\NotebookReviewScheduler;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card of the student's "Ulasan harian" (spaced repetition), saved from a
 * flashcards output or from the questions they missed in a quiz. Its schedule
 * comes from NotebookReviewScheduler; no AI is involved.
 *
 * @property int $id
 * @property int $notebook_id
 * @property int|null $notebook_message_id
 * @property int|null $item_index
 * @property string $front
 * @property string $back
 * @property list<array{n: int, source_id: int, source_title: string, page: int|null, position?: int, chunk_id?: int, excerpt: string}>|null $citations
 * @property string|null $topic
 * @property int|null $meeting_number
 * @property \Illuminate\Support\Carbon $due_at
 * @property int $interval_days
 * @property float $ease
 * @property int $reps
 * @property int $lapses
 * @property \Illuminate\Support\Carbon|null $first_reviewed_at
 * @property \Illuminate\Support\Carbon|null $last_reviewed_at
 * @property bool $suspended
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class NotebookReviewCard extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookReviewCardFactory> */
    use HasFactory;

    public const MAX_SIDE_CHARS = 2000;

    protected $fillable = [
        'notebook_id',
        'notebook_message_id',
        'item_index',
        'front',
        'back',
        'citations',
        'topic',
        'meeting_number',
        'due_at',
        'interval_days',
        'ease',
        'reps',
        'lapses',
        'first_reviewed_at',
        'last_reviewed_at',
        'suspended',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'due_at' => 'datetime',
            'interval_days' => 'integer',
            'ease' => 'float',
            'reps' => 'integer',
            'lapses' => 'integer',
            'item_index' => 'integer',
            'meeting_number' => 'integer',
            'first_reviewed_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'suspended' => 'boolean',
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
     * @return BelongsTo<NotebookMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(NotebookMessage::class, 'notebook_message_id');
    }

    /**
     * Cards of every notebook the student owns.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOwner(Builder $query, string $ownerHash): Builder
    {
        return $query->whereIn('notebook_id', Notebook::query()->forOwner($ownerHash)->select('id'));
    }

    /**
     * Cards that can be reviewed now (not suspended, due).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, CarbonInterface $now): Builder
    {
        return $query->where('suspended', false)->where('due_at', '<=', $now);
    }

    public function isNew(): bool
    {
        return $this->first_reviewed_at === null;
    }

    /**
     * @return array{interval_days: int, ease: float, reps: int, lapses: int}
     */
    public function schedulingState(): array
    {
        return [
            'interval_days' => $this->interval_days,
            'ease' => $this->ease,
            'reps' => $this->reps,
            'lapses' => $this->lapses,
        ];
    }

    /**
     * @return array{id: int, notebook_id: int, notebook_title: string|null, notebook_message_id: int|null, front: string, back: string, citations: list<array<string, mixed>>, topic: string|null, meeting_number: int|null, due_at: string, interval_days: int, ease: float, reps: int, lapses: int, last_reviewed_at: string|null, suspended: bool, is_new: bool, next: array{lagi: int, sulit: int, bisa: int, mudah: int}}
     */
    public function toApiArray(?CarbonInterface $now = null): array
    {
        return [
            'id' => $this->id,
            'notebook_id' => $this->notebook_id,
            'notebook_title' => $this->relationLoaded('notebook') ? $this->notebook?->title : null,
            'notebook_message_id' => $this->notebook_message_id,
            'front' => $this->front,
            'back' => $this->back,
            'citations' => $this->citations ?? [],
            'topic' => $this->topic,
            'meeting_number' => $this->meeting_number,
            'due_at' => $this->due_at->toISOString(),
            'interval_days' => $this->interval_days,
            'ease' => $this->ease,
            'reps' => $this->reps,
            'lapses' => $this->lapses,
            'last_reviewed_at' => $this->last_reviewed_at?->toISOString(),
            'suspended' => $this->suspended,
            'is_new' => $this->isNew(),
            'next' => app(NotebookReviewScheduler::class)->preview($this->schedulingState(), $now ?? now()),
        ];
    }
}
