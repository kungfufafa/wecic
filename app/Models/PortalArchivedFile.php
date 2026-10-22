<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A course file (meeting material, assignment material, RPS) copied from the
 * portal, so it stays readable when the portal is down or the lecturer
 * removes it, and can feed a notebook.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string|null $course_key
 * @property string|null $course_name
 * @property string|null $meeting_id
 * @property int|null $meeting_number
 * @property string $kind
 * @property string|null $title
 * @property string $source_url
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property \Illuminate\Support\Carbon $archived_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PortalArchivedFile extends Model
{
    /** @use HasFactory<\Database\Factories\PortalArchivedFileFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_hash',
        'course_key',
        'course_name',
        'meeting_id',
        'meeting_number',
        'kind',
        'title',
        'source_url',
        'filename',
        'mime',
        'size',
        'sha256',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meeting_number' => 'integer',
            'size' => 'integer',
            'archived_at' => 'datetime',
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

    public function blobPath(): string
    {
        return 'portal-archive/'.substr($this->sha256, 0, 2).'/'.$this->sha256;
    }

    /**
     * @return array{id: int, course_key: string|null, course_name: string|null, meeting_id: string|null, meeting_number: int|null, kind: string, title: string|null, filename: string, mime: string, size: int, archived_at: string|null, download_url: string}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'course_key' => $this->course_key,
            'course_name' => $this->course_name,
            'meeting_id' => $this->meeting_id,
            'meeting_number' => $this->meeting_number,
            'kind' => $this->kind,
            'title' => $this->title,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'size' => $this->size,
            'archived_at' => $this->archived_at?->toISOString(),
            'download_url' => '/api/archive/files/'.$this->id,
        ];
    }
}
