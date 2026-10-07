<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotebookMessage extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'notebook_id',
        'role',
        'content',
        'action_type',
    ];

    /**
     * @return BelongsTo<Notebook, $this>
     */
    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }
}
