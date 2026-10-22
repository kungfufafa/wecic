<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passage of a source's text, the unit answers cite.
 *
 * @property int $id
 * @property int $notebook_source_id
 * @property int $notebook_id
 * @property int $position
 * @property int|null $page
 * @property string $content
 */
class NotebookChunk extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'notebook_source_id',
        'notebook_id',
        'position',
        'page',
        'content',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'page' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<NotebookSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(NotebookSource::class, 'notebook_source_id');
    }
}
