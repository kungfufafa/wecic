<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a student removed from a course notebook: a course file (by
 * its portal URL) or a meeting (by its id). The automatic paths that fill
 * course notebooks leave it out; adding it again by hand lifts it.
 *
 * @property int $id
 * @property int $notebook_id
 * @property string $kind
 * @property string $reference
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class NotebookDismissal extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookDismissalFactory> */
    use HasFactory;

    public const KIND_ARCHIVED_FILE = 'archived_file';

    public const KIND_MEETING = 'meeting';

    protected $fillable = [
        'notebook_id',
        'kind',
        'reference',
    ];

    /**
     * @return BelongsTo<Notebook, $this>
     */
    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }
}
