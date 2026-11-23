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
 * @property list<int>|null $context_source_ids The sources ticked as the AI context; null is every source.
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
        'context_source_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_params' => 'array',
            'context_source_ids' => 'array',
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
     * Course files and meetings the student removed from this notebook.
     *
     * @return HasMany<NotebookDismissal, $this>
     */
    public function dismissals(): HasMany
    {
        return $this->hasMany(NotebookDismissal::class);
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
     * How many sources the notebook may hold. A course notebook collects a
     * whole semester (a note and the course files of every meeting), so it
     * has its own, larger limit.
     */
    public function sourceLimit(): int
    {
        return $this->isCourseNotebook()
            ? max(1, (int) config('notebook.limits.max_course_sources', 80))
            : max(1, (int) config('notebook.limits.max_sources', 30));
    }

    /**
     * Sources that can still be added before the limit.
     */
    public function sourceRoom(): int
    {
        return max(0, $this->sourceLimit() - $this->sources()->count());
    }

    /**
     * The sources ticked as the AI context, as the student last saved them,
     * without the ones deleted since; null when every source is ticked.
     *
     * @return list<int>|null
     */
    public function contextSourceIds(): ?array
    {
        if (! is_array($this->context_source_ids)) {
            return null;
        }

        $saved = array_values(array_unique(array_map('intval', $this->context_source_ids)));

        if ($saved === []) {
            return [];
        }

        $existing = $this->sources()->whereKey($saved)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return array_values(array_filter($saved, static fn (int $id): bool => in_array($id, $existing, true)));
    }

    /**
     * A source added after the student chose their context is ticked, as
     * a new source is on a page that has not saved a choice.
     */
    public function includeInContext(int $sourceId): void
    {
        if (! is_array($this->context_source_ids) || in_array($sourceId, array_map('intval', $this->context_source_ids), true)) {
            return;
        }

        $this->forceFill(['context_source_ids' => [...array_map('intval', $this->context_source_ids), $sourceId]])->saveQuietly();
    }

    public function isDismissed(string $kind, ?string $reference): bool
    {
        return $reference !== null && $reference !== ''
            && $this->dismissals()->where('kind', $kind)->where('reference', $reference)->exists();
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
