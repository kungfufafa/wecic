<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's study notebook: sources (course files, meeting notes, uploads,
 * notes) and a conversation grounded in them.
 *
 * A `course` notebook is made for every class in the student's class list
 * and collects that course's meetings; a `custom` one is made by hand.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string $title
 * @property string $kind
 * @property string|null $course_key
 * @property string|null $course_name
 * @property string|null $course_code
 * @property string|null $lecturer_name
 * @property array{id?: string, dosen_id?: string, course_id?: string}|null $class_params
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Notebook extends Model
{
    /** @use HasFactory<\Database\Factories\NotebookFactory> */
    use HasFactory;

    public const KIND_COURSE = 'course';

    public const KIND_CUSTOM = 'custom';

    protected $fillable = [
        'owner_hash',
        'title',
        'kind',
        'course_key',
        'course_name',
        'course_code',
        'lecturer_name',
        'class_params',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_params' => 'array',
        ];
    }

    /**
     * @return HasMany<NotebookSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(NotebookSource::class);
    }

    /**
     * The meeting notes: one per meeting added to the notebook.
     *
     * @return HasMany<NotebookSource, $this>
     */
    public function meetingSources(): HasMany
    {
        return $this->hasMany(NotebookSource::class)->where('kind', NotebookSource::KIND_MEETING);
    }

    /**
     * @return HasMany<NotebookChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(NotebookChunk::class);
    }

    /**
     * @return HasMany<NotebookMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(NotebookMessage::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOwner(Builder $query, string $ownerHash): Builder
    {
        return $query->where('owner_hash', $ownerHash);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCourse(Builder $query, string $courseKey): Builder
    {
        return $query->where('kind', self::KIND_COURSE)->where('course_key', $courseKey);
    }

    public function isCourseNotebook(): bool
    {
        return $this->kind === self::KIND_COURSE;
    }

    /**
     * Path of the class page in MyCIC, when the class is known.
     */
    public function classUrl(): ?string
    {
        $params = $this->class_params;

        if (! is_array($params) || ! isset($params['id'])) {
            return null;
        }

        $query = http_build_query(array_filter([
            'dosen_id' => $params['dosen_id'] ?? null,
            'course_id' => $params['course_id'] ?? $this->course_key,
        ]));

        return '/class/'.rawurlencode((string) $params['id']).($query !== '' ? '?'.$query : '');
    }

    /**
     * @return array{id: int, title: string, kind: string, course_key: string|null, course_name: string|null, course_code: string|null, lecturer_name: string|null, class_url: string|null, sources_count: int, meetings_count: int, messages_count: int, created_at: string|null, updated_at: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind,
            'course_key' => $this->course_key,
            'course_name' => $this->course_name,
            'course_code' => $this->course_code,
            'lecturer_name' => $this->lecturer_name,
            'class_url' => $this->classUrl(),
            'sources_count' => (int) ($this->sources_count ?? $this->sources()->count()),
            'meetings_count' => (int) ($this->meetings_count ?? $this->meetingSources()->count()),
            'messages_count' => (int) ($this->messages_count ?? $this->messages()->count()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
