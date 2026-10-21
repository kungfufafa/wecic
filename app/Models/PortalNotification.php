<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Something that changed on the portal (or a deadline coming up), kept per
 * student so the bell and `/notifications` survive re-logins and devices.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string $type
 * @property string|null $dataset
 * @property string|null $record_key
 * @property string $dedupe_key
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property string $urgency
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $read_at
 */
class PortalNotification extends Model
{
    /** @use HasFactory<\Database\Factories\PortalNotificationFactory> */
    use HasFactory, Prunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'owner_hash',
        'type',
        'dataset',
        'record_key',
        'dedupe_key',
        'title',
        'body',
        'url',
        'urgency',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'read_at' => 'datetime',
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
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('portal.notifications.retention_days', 90)));
    }

    /**
     * @return array{id: int, type: string, dataset: string|null, title: string, body: string|null, url: string|null, urgency: string, created_at: string|null, read_at: string|null, is_read: bool}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'dataset' => $this->dataset,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'urgency' => $this->urgency,
            'created_at' => $this->created_at?->toISOString(),
            'read_at' => $this->read_at?->toISOString(),
            'is_read' => $this->read_at !== null,
        ];
    }
}
