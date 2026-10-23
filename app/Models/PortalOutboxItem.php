<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;

/**
 * A write action a student asked for while their session was offline, kept
 * until the portal is reachable again (see PortalOutbox). Nothing here has
 * reached the portal until `status` says `sent`.
 *
 * @property int $id
 * @property string $owner_hash
 * @property string $action
 * @property array<string, mixed> $payload
 * @property string|null $file_path
 * @property string|null $subject
 * @property \Illuminate\Support\Carbon|null $deadline_at
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PortalOutboxItem extends Model
{
    /** @use HasFactory<\Database\Factories\PortalOutboxItemFactory> */
    use HasFactory, Prunable;

    public const ACTION_ASSIGNMENT_UPLOAD = 'assignment_upload';

    public const ACTION_EXAM_UPLOAD = 'exam_upload';

    public const ACTION_ATTENDANCE = 'attendance';

    public const ACTIONS = [self::ACTION_ASSIGNMENT_UPLOAD, self::ACTION_EXAM_UPLOAD, self::ACTION_ATTENDANCE];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_QUEUED, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_EXPIRED];

    private const ACTION_LABELS = [
        self::ACTION_ASSIGNMENT_UPLOAD => 'Upload tugas',
        self::ACTION_EXAM_UPLOAD => 'Upload ujian',
        self::ACTION_ATTENDANCE => 'Absensi',
    ];

    /**
     * Wording the student sees. A queued item never reads as received by the
     * portal: deadlines close, and only `sent` means the portal has it.
     */
    private const STATUS_LABELS = [
        self::STATUS_QUEUED => 'Belum terkirim ke portal',
        self::STATUS_SENT => 'Terkirim ke portal',
        self::STATUS_FAILED => 'Gagal terkirim ke portal',
        self::STATUS_EXPIRED => 'Tenggat lewat, tidak terkirim',
    ];

    protected $fillable = [
        'owner_hash',
        'action',
        'payload',
        'file_path',
        'subject',
        'deadline_at',
        'status',
        'attempts',
        'last_error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'deadline_at' => 'datetime',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
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
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function deadlinePassed(?CarbonInterface $now = null): bool
    {
        return $this->deadline_at !== null && $this->deadline_at->lessThanOrEqualTo($now ?? now());
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function fileName(): ?string
    {
        $name = $this->payload['file_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The MyCIC page the item belongs to, for notifications and the queue.
     */
    public function pageUrl(): ?string
    {
        $payload = $this->payload;

        return match ($this->action) {
            self::ACTION_ASSIGNMENT_UPLOAD => $this->meetingUrl($payload['meeting_id'] ?? $payload['id'] ?? null, $payload['courses_id'] ?? null),
            self::ACTION_ATTENDANCE => $this->meetingUrl($payload['modulesid'] ?? null, $payload['courseid'] ?? null) ?? '/dashboard',
            self::ACTION_EXAM_UPLOAD => isset($payload['id']) ? sprintf('/exam/%d', (int) $payload['id']) : '/exam',
            default => null,
        };
    }

    /**
     * Keys a page matches its own items with (`meeting:<id>`, `course:<id>`,
     * `exam:<id>`), so the meeting page lists only this meeting's queue.
     *
     * @return list<string>
     */
    public function matchKeys(): array
    {
        $payload = $this->payload;
        $candidates = match ($this->action) {
            self::ACTION_ASSIGNMENT_UPLOAD => [
                'meeting' => [$payload['meeting_id'] ?? null, $payload['id'] ?? null],
                'course' => [$payload['courses_id'] ?? null],
            ],
            self::ACTION_ATTENDANCE => [
                'meeting' => [$payload['modulesid'] ?? null],
                'course' => [$payload['courseid'] ?? null, $payload['shortcut_context']['course_id'] ?? null],
                'class' => [$payload['shortcut_context']['class_id'] ?? null],
            ],
            self::ACTION_EXAM_UPLOAD => ['exam' => [$payload['id'] ?? null]],
            default => [],
        };
        $keys = ['action:'.$this->action];

        foreach ($candidates as $prefix => $values) {
            foreach ($values as $value) {
                if (is_numeric($value)) {
                    $keys[] = $prefix.':'.(int) $value;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Drop the stored attachment; the row stays as the record of what happened.
     */
    public function deleteFile(): void
    {
        if ($this->file_path === null) {
            return;
        }

        Storage::delete($this->file_path);
        $this->forceFill(['file_path' => null])->save();
    }

    /**
     * Settled items older than `portal.outbox.retention_days`; a queued item
     * is never pruned, whatever its age.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', '!=', self::STATUS_QUEUED)
            ->where('updated_at', '<', now()->subDays((int) config('portal.outbox.retention_days', 30)));
    }

    protected function pruning(): void
    {
        if ($this->file_path !== null) {
            Storage::delete($this->file_path);
        }
    }

    /**
     * @return array{id: int, action: string, action_label: string, subject: string|null, url: string|null, file_name: string|null, deadline_at: string|null, deadline_passed: bool, status: string, status_label: string, attempts: int, last_error: string|null, match_keys: list<string>, created_at: string|null, sent_at: string|null, updated_at: string|null}
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->actionLabel(),
            'subject' => $this->subject,
            'url' => $this->pageUrl(),
            'file_name' => $this->fileName(),
            'deadline_at' => $this->deadline_at?->toISOString(),
            'deadline_passed' => $this->deadlinePassed(),
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'attempts' => $this->attempts,
            'last_error' => $this->last_error,
            'match_keys' => $this->matchKeys(),
            'created_at' => $this->created_at?->toISOString(),
            'sent_at' => $this->sent_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function meetingUrl(mixed $meetingId, mixed $coursesId): ?string
    {
        if (! is_numeric($meetingId)) {
            return null;
        }

        return sprintf('/meeting/%d', (int) $meetingId).(is_numeric($coursesId) ? sprintf('?courses_id=%d', (int) $coursesId) : '');
    }
}
