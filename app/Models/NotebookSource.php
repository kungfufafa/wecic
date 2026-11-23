<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One source of a notebook. Its text is read once (`pending` → `processing`
 * → `ready` or `failed`) and kept as chunks the notebook answers from.
 *
 * @property int $id
 * @property int $notebook_id
 * @property string $kind
 * @property int|null $portal_archived_file_id
 * @property string|null $archived_source_url
 * @property string|null $meeting_id
 * @property int|null $meeting_number
 * @property string $title
 * @property string|null $mime
 * @property string|null $disk_path
 * @property string $status
 * @property string|null $error
 * @property string|null $summary
 * @property list<string>|null $topics
 * @property list<string>|null $suggested_questions
 * @property int $char_count
 * @property int|null $page_count
 * @property bool $truncated
 * @property \Illuminate\Support\Carbon|null $queued_at
 * @property \Illuminate\Support\Carbon|null $requested_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class NotebookSource extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookSourceFactory> */
    use HasFactory;

    public const KIND_ARCHIVED_FILE = 'archived_file';

    public const KIND_UPLOAD = 'upload';

    public const KIND_TEXT = 'text';

    /**
     * The note MyCIC writes for a meeting of a course notebook: topic,
     * outline, schedule and assignment of that meeting.
     */
    public const KIND_MEETING = 'meeting';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /**
     * A reading still processing after this long has lost its job (the
     * worker died): the job itself times out after 300 s, so the student
     * may start it again.
     */
    public const STALLED_AFTER_SECONDS = 900;

    /**
     * A queued reading that has not started after this long has lost its
     * job (the queue was cleared). Far longer than a busy queue's backlog:
     * a notebook only keeps a few readings queued at a time
     * (`notebook.limits.queued_readings`), and the job's unique lock lasts
     * as long (IngestNotebookSourceJob::uniqueFor()).
     */
    public const QUEUED_STALLED_AFTER_SECONDS = 3600;

    protected $fillable = [
        'notebook_id',
        'kind',
        'portal_archived_file_id',
        'archived_source_url',
        'meeting_id',
        'meeting_number',
        'title',
        'mime',
        'disk_path',
        'status',
        'error',
        'summary',
        'topics',
        'suggested_questions',
        'char_count',
        'page_count',
        'truncated',
        'queued_at',
        'requested_at',
    ];

    /**
     * A new source joins the notebook's saved AI context (Notebook::includeInContext()).
     */
    protected static function booted(): void
    {
        static::created(static function (self $source): void {
            Notebook::query()->whereKey($source->notebook_id)->whereNotNull('context_source_ids')->first()?->includeInContext($source->id);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meeting_number' => 'integer',
            'char_count' => 'integer',
            'page_count' => 'integer',
            'truncated' => 'boolean',
            'topics' => 'array',
            'suggested_questions' => 'array',
            'queued_at' => 'datetime',
            'requested_at' => 'datetime',
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
     * @return BelongsTo<PortalArchivedFile, $this>
     */
    public function archivedFile(): BelongsTo
    {
        return $this->belongsTo(PortalArchivedFile::class, 'portal_archived_file_id');
    }

    /**
     * @return HasMany<NotebookChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(NotebookChunk::class);
    }

    /**
     * Queued or processing for so long that its reading job must be gone.
     * A source waiting to be queued (no `queued_at`) is not stalled.
     */
    public function isStalled(): bool
    {
        return match ($this->status) {
            self::STATUS_PROCESSING => $this->updated_at !== null && $this->updated_at->lessThan(now()->subSeconds(self::STALLED_AFTER_SECONDS)),
            self::STATUS_PENDING => $this->queued_at !== null && $this->queued_at->lessThan(now()->subSeconds(self::QUEUED_STALLED_AFTER_SECONDS)),
            default => false,
        };
    }

    /**
     * Waits for a reading slot (or, for a course file that joined on its
     * own, the next day's reading budget) to be queued.
     */
    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->queued_at === null;
    }

    /**
     * The student asked for this source to be read (added it, or read it
     * now), so it is read with the student's own reading budget.
     */
    public function isRequested(): bool
    {
        return $this->requested_at !== null;
    }

    /**
     * The source's understanding questions, none for a source read before
     * source guides or without the model.
     *
     * @return list<string>
     */
    public function suggestedQuestions(): array
    {
        return self::strings($this->suggested_questions);
    }

    /**
     * @param  array<mixed>|null  $values
     * @return list<string>
     */
    private static function strings(?array $values): array
    {
        return array_values(array_filter($values ?? [], static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    /**
     * `topics` and `suggested_questions` are empty lists for a source read
     * before source guides, or without the model.
     *
     * @return array{id: int, kind: string, title: string, meeting_id: string|null, meeting_number: int|null, mime: string|null, status: string, error: string|null, summary: string|null, topics: list<string>, suggested_questions: list<string>, char_count: int, page_count: int|null, truncated: bool, stalled: bool, waiting: bool, archived_file_id: int|null, created_at: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'meeting_id' => $this->meeting_id,
            'meeting_number' => $this->meeting_number,
            'mime' => $this->mime,
            'status' => $this->status,
            'error' => $this->error,
            'summary' => $this->summary,
            'topics' => self::strings($this->topics),
            'suggested_questions' => $this->suggestedQuestions(),
            'char_count' => $this->char_count,
            'page_count' => $this->page_count,
            'truncated' => $this->truncated,
            'stalled' => $this->isStalled(),
            'waiting' => $this->isWaiting(),
            'archived_file_id' => $this->portal_archived_file_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
