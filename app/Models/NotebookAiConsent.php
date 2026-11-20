<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A student's consent to send Notebook content (questions, source text,
 * PDFs and images) to Google Gemini. Without a `granted_at`, the Notebook
 * still keeps and shows sources but never sends anything to the model.
 *
 * @property int $id
 * @property string $owner_hash
 * @property \Illuminate\Support\Carbon|null $granted_at
 * @property \Illuminate\Support\Carbon|null $withdrawn_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class NotebookAiConsent extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookAiConsentFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_hash',
        'granted_at',
        'withdrawn_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOwner(Builder $query, string $ownerHash): Builder
    {
        return $query->where('owner_hash', $ownerHash);
    }
}
