<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One distinct version of a dataset as the portal showed it to a student.
 *
 * `trusted` versions are what MyCIC serves; the newest `trusted_at` is the
 * current one. A sync that breaks an integrity guard is kept as `suspect`
 * until the student accepts it (it becomes trusted) or rejects it, or until
 * the portal shows the trusted data again (`dismissed`).
 *
 * One row per content and `sighting`: content MyCIC trusted earlier that
 * comes back and breaks a guard is a new suspect row with the next
 * `sighting`, so the earlier trusted row keeps its status and history.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string $dataset
 * @property string $params_hash
 * @property array<string, mixed>|null $params
 * @property string|null $subject
 * @property string $content_hash
 * @property int $sighting
 * @property array<string, mixed> $payload
 * @property string $status
 * @property list<array{rule: string, label: string, message: string}>|null $findings
 * @property \Illuminate\Support\Carbon|null $first_seen_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property int $seen_count
 * @property \Illuminate\Support\Carbon|null $trusted_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PortalArchiveVersion extends Model
{
    /** @use HasFactory<\Database\Factories\PortalArchiveVersionFactory> */
    use HasFactory;

    public const STATUS_TRUSTED = 'trusted';

    public const STATUS_SUSPECT = 'suspect';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'owner_hash',
        'dataset',
        'params_hash',
        'params',
        'subject',
        'content_hash',
        'sighting',
        'payload',
        'status',
        'findings',
        'first_seen_at',
        'last_seen_at',
        'seen_count',
        'trusted_at',
        'resolved_at',
    ];

    protected $hidden = [
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'payload' => 'encrypted:array',
            'findings' => 'array',
            'sighting' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'seen_count' => 'integer',
            'trusted_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDataset(Builder $query, string $dataset, string $paramsHash): Builder
    {
        return $query->where('dataset', $dataset)->where('params_hash', $paramsHash);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTrusted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_TRUSTED)->whereNotNull('trusted_at');
    }

    /**
     * Metadata only; the payload is served by the version detail endpoint.
     *
     * @return array{id: int, dataset: string, params: array<string, mixed>, subject: string|null, status: string, findings: list<array<string, mixed>>, first_seen_at: string|null, last_seen_at: string|null, seen_count: int, trusted_at: string|null, resolved_at: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'dataset' => $this->dataset,
            'params' => $this->params ?? [],
            'subject' => $this->subject,
            'status' => $this->status,
            'findings' => $this->findings ?? [],
            'first_seen_at' => $this->first_seen_at?->toISOString(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'seen_count' => $this->seen_count,
            'trusted_at' => $this->trusted_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
        ];
    }
}
