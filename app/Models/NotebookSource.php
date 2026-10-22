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
 * @property string|null $meeting_id
 * @property int|null $meeting_number
 * @property string $title
 * @property string|null $mime
 * @property string|null $disk_path
 * @property string $status
 * @property string|null $error
 * @property string|null $summary
 * @property int $char_count
 * @property int|null $page_count
 * @property bool $truncated
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

    protected $fillable = [
        'notebook_id',
        'kind',
        'portal_archived_file_id',
        'meeting_id',
        'meeting_number',
        'title',
        'mime',
        'disk_path',
        'status',
        'error',
        'summary',
        'char_count',
        'page_count',
        'truncated',
    ];

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
     * @return array{id: int, kind: string, title: string, meeting_id: string|null, meeting_number: int|null, mime: string|null, status: string, error: string|null, summary: string|null, char_count: int, page_count: int|null, truncated: bool, archived_file_id: int|null, created_at: string|null}
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
            'char_count' => $this->char_count,
            'page_count' => $this->page_count,
            'truncated' => $this->truncated,
            'archived_file_id' => $this->portal_archived_file_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
