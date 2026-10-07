<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notebook extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookFactory> */
    use HasFactory;

    protected $fillable = [
        'username',
        'title',
        'course_id',
        'course_name',
        'meeting_id',
        'meeting_number',
        'sources',
        'draft_content',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'notes' => 'array',
        ];
    }

    /**
     * @return HasMany<NotebookMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(NotebookMessage::class)->orderBy('id');
    }
}
