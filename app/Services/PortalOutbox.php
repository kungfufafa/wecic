<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\FlushPortalOutboxJob;
use App\Models\PortalOutboxItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Deadline-bound writes asked for while the session is offline.
 *
 * Reads come from the archive, writes always go to the portal, and an offline
 * session cannot reach it. Instead of refusing an attendance code or an
 * upload (their windows close), the three endpoints store it here, validated
 * and with its file, and answer that it is NOT yet sent. FlushPortalOutboxJob
 * sends the queue once the session is back online (ResumeOfflineSessionJob)
 * or the student logs in online again; whatever passed its deadline by then
 * expires and is never sent. Every outcome becomes a notification.
 *
 * Deadlines come from the request when the client knows one, else from the
 * snapshots MyCIC already holds: the pending-assignments list or the meeting
 * page for an assignment, the end of the day for an attendance code (a code
 * belongs to that day's session). An exam window is not published by the
 * portal, so an exam upload without a deadline is sent whenever the portal
 * is back and the portal decides.
 */
class PortalOutbox
{
    public const CACHE_KEYS = ['/api/outbox'];

    public const SYNC_KEYS = ['outbox'];

    private const FILE_DIRECTORY = 'portal-outbox';

    /**
     * Settled items stay in the student's queue view this long.
     */
    private const RECENT_DAYS = 7;

    private const MONTHS = [
        'januari' => 1, 'jan' => 1,
        'februari' => 2, 'feb' => 2,
        'maret' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5,
        'juni' => 6, 'jun' => 6,
        'juli' => 7, 'jul' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10,
        'november' => 11, 'nov' => 11,
        'desember' => 12, 'des' => 12,
    ];

    public function __construct(
        private readonly PortalDataOwner $owner,
        private readonly PortalSnapshotStore $snapshots,
        private readonly PortalBroadcastService $broadcast
    ) {}

    public function enabled(): bool
    {
        return (bool) config('portal.archive.enabled', true)
            && (bool) config('portal.outbox.enabled', true);
    }

    public function ownerHash(string $sessionToken): string
    {
        return $this->owner->key($sessionToken);
    }

    /**
     * @param  array<string, mixed>  $formValues
     */
    public function queueAssignmentUpload(
        string $sessionToken,
        int $courseId,
        ?int $discussionId,
        ?int $meetingNumber,
        ?int $meetingId,
        UploadedFile $attachment,
        array $formValues = [],
        ?CarbonInterface $deadline = null
    ): PortalOutboxItem {
        $targetId = $discussionId ?? $meetingId;
        $pending = $this->pendingAssignment($sessionToken, $targetId);
        $meetingDetail = $pending === null ? $this->meetingDetail($sessionToken, $targetId, $courseId) : null;

        return $this->store($sessionToken, PortalOutboxItem::ACTION_ASSIGNMENT_UPLOAD, [
            'courses_id' => $courseId,
            'id' => $discussionId,
            'pertemuan' => $meetingNumber,
            'meeting_id' => $meetingId ?? $discussionId,
            'form_values' => $formValues,
        ], $attachment, 'application/pdf',
            $this->assignmentSubject($pending, $meetingDetail, $meetingNumber ?? $pending['meeting_number'] ?? null),
            $deadline ?? $this->assignmentDeadline($pending, $meetingDetail)
        );
    }

    /**
     * @param  array<string, mixed>  $formValues
     */
    public function queueExamUpload(
        string $sessionToken,
        int $examId,
        UploadedFile $attachment,
        array $formValues = [],
        ?CarbonInterface $deadline = null
    ): PortalOutboxItem {
        return $this->store($sessionToken, PortalOutboxItem::ACTION_EXAM_UPLOAD, [
            'id' => $examId,
            'form_values' => $formValues,
        ], $attachment, 'application/octet-stream', $this->examSubject($sessionToken, $examId), $deadline);
    }

    /**
     * @param  array{class_id: int, dosen_id: int, course_id: int}|null  $shortcutContext
     * @param  array<string, string>  $formValues
     */
    public function queueAttendance(
        string $sessionToken,
        ?int $courseId,
        ?int $discussionId,
        ?int $meetingNumber,
        ?array $shortcutContext,
        string $code,
        array $formValues = [],
        ?CarbonInterface $deadline = null
    ): PortalOutboxItem {
        $todayClass = $this->todayClass($sessionToken, $discussionId, $shortcutContext);
        $meetingNumber ??= isset($todayClass['attendance']['payload']['pertemuan']) ? (int) $todayClass['attendance']['payload']['pertemuan'] : null;
        $courseName = trim((string) ($todayClass['course_name'] ?? ''));
        $meetingLabel = $meetingNumber !== null ? "Pertemuan {$meetingNumber}" : 'Pertemuan hari ini';

        return $this->store($sessionToken, PortalOutboxItem::ACTION_ATTENDANCE, [
            'courseid' => $courseId,
            'modulesid' => $discussionId,
            'pertemuan' => $meetingNumber,
            'shortcut_context' => $shortcutContext,
            'kode' => $code,
            'form_values' => $formValues,
        ], null, null,
            $courseName !== '' ? "{$courseName} · {$meetingLabel}" : "Absensi {$meetingLabel}",
            $deadline ?? CarbonImmutable::now(config('app.timezone'))->setTime(23, 59, 59)
        );
    }

    /**
     * The 202 answer: what was kept, and that the portal does not have it yet.
     */
    public function queuedMessage(PortalOutboxItem $item): string
    {
        $what = match ($item->action) {
            PortalOutboxItem::ACTION_ASSIGNMENT_UPLOAD => 'File tugas',
            PortalOutboxItem::ACTION_EXAM_UPLOAD => 'Jawaban ujian',
            default => 'Kode absen',
        };
        $deadline = $item->deadline_at !== null
            ? ' Tenggatnya '.$this->formatDeadline($item->deadline_at).'; lewat dari itu MyCIC tidak akan mengirimnya.'
            : ' Kalau waktunya sudah ditutup portal, kiriman akan ditolak dan kamu diberi tahu.';

        return "Belum terkirim ke portal. {$what} disimpan di antrean MyCIC dan dikirim otomatis begitu portal kampus bisa diakses.".$deadline;
    }

    /**
     * The student's queue: everything still waiting, plus what was settled
     * in the last days so the outcome is visible where the item was queued.
     *
     * @return Collection<int, PortalOutboxItem>
     */
    public function items(string $ownerHash): Collection
    {
        return PortalOutboxItem::query()
            ->forOwner($ownerHash)
            ->where(function ($query): void {
                $query->where('status', PortalOutboxItem::STATUS_QUEUED)
                    ->orWhere('updated_at', '>=', now()->subDays(self::RECENT_DAYS));
            })
            ->orderByRaw("case when status = 'queued' then 0 else 1 end")
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Items to send, the nearest deadline first.
     *
     * @return Collection<int, PortalOutboxItem>
     */
    public function queued(string $ownerHash): Collection
    {
        return PortalOutboxItem::query()
            ->forOwner($ownerHash)
            ->queued()
            ->orderByRaw('deadline_at is null')
            ->orderBy('deadline_at')
            ->orderBy('id')
            ->get();
    }

    public function hasQueued(string $ownerHash): bool
    {
        return PortalOutboxItem::query()->forOwner($ownerHash)->queued()->exists();
    }

    /**
     * Queue a flush for a session that is (back) online, when anything waits.
     * Never throws: the outbox must not fail the login or resume it rides on.
     */
    public function flushWhenQueued(string $sessionToken, ?string $sessionId = null): bool
    {
        try {
            if (! $this->enabled() || ! $this->hasQueued($this->ownerHash($sessionToken))) {
                return false;
            }

            FlushPortalOutboxJob::dispatch($sessionToken, $sessionId);

            return true;
        } catch (\Throwable $throwable) {
            Log::warning('Portal outbox flush could not be queued', [
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Drop an item that is still waiting; a settled one stays as the record.
     */
    public function cancel(string $ownerHash, int $id): bool
    {
        $item = PortalOutboxItem::query()->forOwner($ownerHash)->queued()->find($id);

        if ($item === null) {
            return false;
        }

        $item->deleteFile();
        $item->delete();

        return true;
    }

    public function markSent(PortalOutboxItem $item): void
    {
        $this->settle($item, PortalOutboxItem::STATUS_SENT, null, now());
    }

    public function markFailed(PortalOutboxItem $item, ?string $error): void
    {
        $this->settle($item, PortalOutboxItem::STATUS_FAILED, $error ?: 'Portal menolak kiriman.');
    }

    public function markExpired(PortalOutboxItem $item): void
    {
        $this->settle($item, PortalOutboxItem::STATUS_EXPIRED, $item->deadline_at !== null
            ? 'Tenggat '.$this->formatDeadline($item->deadline_at).' sudah lewat sebelum portal kampus bisa diakses.'
            : 'Tenggat sudah lewat sebelum portal kampus bisa diakses.');
    }

    /**
     * The notification for an item's outcome (PortalNotificationCenter::publish).
     *
     * @return array{type: string, dedupe_key: string, title: string, body: string, url: string|null, urgency: string, record_key: string}
     */
    public function notificationFor(PortalOutboxItem $item): array
    {
        $label = $item->actionLabel();
        $subject = trim((string) $item->subject);
        $prefix = $subject !== '' ? $subject.' · ' : '';

        [$type, $title, $body, $urgency] = match ($item->status) {
            PortalOutboxItem::STATUS_SENT => [
                'outbox_sent',
                "{$label} terkirim ke portal",
                $prefix.'Dikirim dari antrean offline MyCIC.',
                'info',
            ],
            PortalOutboxItem::STATUS_FAILED => [
                'outbox_failed',
                "{$label} gagal terkirim ke portal",
                $prefix.rtrim((string) $item->last_error, '.').'. Kirim ulang secara manual.',
                'critical',
            ],
            PortalOutboxItem::STATUS_EXPIRED => [
                'outbox_expired',
                "{$label} tidak terkirim: tenggat sudah lewat",
                $prefix.rtrim((string) $item->last_error, '.').'. Hubungi dosen bila masih memungkinkan.',
                'critical',
            ],
            default => [
                'outbox_queued',
                "{$label} belum terkirim ke portal",
                $prefix.'Menunggu portal kampus bisa diakses.',
                'warning',
            ],
        };

        return [
            'type' => $type,
            'dedupe_key' => "outbox:{$item->id}:{$item->status}",
            'title' => $title,
            'body' => $body,
            'url' => $item->pageUrl(),
            'urgency' => $urgency,
            'record_key' => "outbox:{$item->id}",
        ];
    }

    /**
     * Other open tabs re-read the queue.
     */
    public function announce(string $sessionId, string $sessionToken, string $message): void
    {
        $this->broadcast->dataInvalidated($sessionId, $sessionToken, self::CACHE_KEYS, self::SYNC_KEYS, $message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(
        string $sessionToken,
        string $action,
        array $payload,
        ?UploadedFile $attachment,
        ?string $defaultMimeType,
        ?string $subject,
        ?CarbonInterface $deadline
    ): PortalOutboxItem {
        $filePath = null;

        if ($attachment !== null) {
            $stored = $attachment->store(self::FILE_DIRECTORY);

            if (! is_string($stored) || $stored === '') {
                throw new \RuntimeException('Lampiran gagal disimpan di antrean.');
            }

            $filePath = $stored;
            $payload['file_name'] = $attachment->getClientOriginalName();
            $payload['mime_type'] = $attachment->getMimeType() ?: $defaultMimeType;
        }

        $item = PortalOutboxItem::query()->create([
            'owner_hash' => $this->owner->key($sessionToken),
            'action' => $action,
            'payload' => $payload,
            'file_path' => $filePath,
            'subject' => $subject !== null ? mb_substr($subject, 0, 255) : null,
            'deadline_at' => $deadline,
            'status' => PortalOutboxItem::STATUS_QUEUED,
        ]);

        Log::info('Portal outbox item queued', [
            'domain' => 'campus_session',
            'event' => 'outbox_queued',
            'component' => self::class,
            'action' => $action,
            'outbox_item_id' => $item->id,
            'deadline_at' => $item->deadline_at?->toISOString(),
        ]);

        return $item;
    }

    private function settle(PortalOutboxItem $item, string $status, ?string $error, ?CarbonInterface $sentAt = null): void
    {
        $item->forceFill([
            'status' => $status,
            'attempts' => $item->attempts + 1,
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'sent_at' => $sentAt,
        ])->save();
        $item->deleteFile();

        Log::info('Portal outbox item settled', [
            'domain' => 'campus_session',
            'event' => 'outbox_'.$status,
            'component' => self::class,
            'action' => $item->action,
            'outbox_item_id' => $item->id,
            'error' => $error,
        ]);
    }

    /**
     * The pending-assignments list knows the course, the title and the deadline.
     *
     * @return array<string, mixed>|null
     */
    private function pendingAssignment(string $sessionToken, ?int $targetId): ?array
    {
        if ($targetId === null) {
            return null;
        }

        $assignments = $this->snapshots->get($sessionToken, 'assignments')['data']['assignments'] ?? [];

        foreach (is_array($assignments) ? $assignments : [] as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $ids = [$assignment['meeting_id'] ?? null, $assignment['assignment_payload']['id'] ?? null];

            if (in_array((string) $targetId, array_map(static fn (mixed $id): string => (string) $id, $ids), true)) {
                return $assignment;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function meetingDetail(string $sessionToken, ?int $meetingId, int $courseId): ?array
    {
        if ($meetingId === null) {
            return null;
        }

        $detail = $this->snapshots->get($sessionToken, 'meeting_detail', [
            'meeting_id' => (string) $meetingId,
            'courses_id' => (string) $courseId,
        ])['data'] ?? null;

        return is_array($detail) ? $detail : null;
    }

    /**
     * @param  array<string, mixed>|null  $pending
     * @param  array<string, mixed>|null  $meetingDetail
     */
    private function assignmentSubject(?array $pending, ?array $meetingDetail, mixed $meetingNumber): string
    {
        $meetingLabel = is_numeric($meetingNumber) ? 'Tugas pertemuan '.(int) $meetingNumber : 'Tugas';

        if ($pending !== null) {
            $course = trim((string) ($pending['course_name'] ?? ''));
            $title = trim((string) ($pending['material_title'] ?? ''));

            return implode(' · ', array_filter([$course, $title !== '' ? $title : $meetingLabel]));
        }

        if ($meetingDetail !== null) {
            $course = null;

            if (preg_match('/Kelas\s+([^>]+?)(?:\s*>|$)/iu', (string) ($meetingDetail['meeting_info']['breadcrumb'] ?? ''), $matches) === 1) {
                $course = trim($matches[1]);
            }

            $title = trim((string) ($meetingDetail['materials']['assignment_topic'] ?? ''));

            return implode(' · ', array_filter([$course, $title !== '' ? $title : $meetingLabel]));
        }

        return $meetingLabel;
    }

    /**
     * @param  array<string, mixed>|null  $pending
     * @param  array<string, mixed>|null  $meetingDetail
     */
    private function assignmentDeadline(?array $pending, ?array $meetingDetail): ?CarbonImmutable
    {
        if ($pending !== null) {
            $deadline = $this->parseDeadline(
                is_string($pending['deadline_date'] ?? null) ? $pending['deadline_date'] : null,
                (string) ($pending['deadline'] ?? '')
            );

            if ($deadline !== null) {
                return $deadline;
            }
        }

        $raw = trim((string) ($meetingDetail['assignment_info']['deadline'] ?? ''));

        return $raw !== '' && $raw !== ':' ? $this->parseDeadline(null, $raw) : null;
    }

    /**
     * The moment a portal deadline passes: the date (`Y-m-d`, or written
     * Indonesian-style in the text) at the time written next to it, else the
     * end of that day. Mirrors ActionCenterBuilder's reading of the same text.
     */
    private function parseDeadline(?string $date, string $raw): ?CarbonImmutable
    {
        $timezone = config('app.timezone');
        $day = null;

        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            try {
                $day = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)?->startOfDay();
            } catch (\Throwable) {
                $day = null;
            }
        }

        if ($day === null && preg_match('/(\d{1,2})\s+(\p{L}+)\.?\s+(\d{4})/iu', $raw, $matches) === 1) {
            $month = self::MONTHS[mb_strtolower($matches[2])] ?? null;

            if ($month !== null && checkdate($month, (int) $matches[1], (int) $matches[3])) {
                $day = CarbonImmutable::create((int) $matches[3], $month, (int) $matches[1], 0, 0, 0, $timezone);
            }
        }

        if ($day === null && preg_match('/(\d{4})-(\d{2})-(\d{2})/', $raw, $matches) === 1 && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            $day = CarbonImmutable::create((int) $matches[1], (int) $matches[2], (int) $matches[3], 0, 0, 0, $timezone);
        }

        if ($day === null) {
            return null;
        }

        if (preg_match('/\b\d{4}\b\D{0,12}?([01]?\d|2[0-3])[:.]([0-5]\d)(?:[:.]([0-5]\d))?\b/u', $raw, $matches) === 1) {
            return $day->setTime((int) $matches[1], (int) $matches[2], isset($matches[3]) ? (int) $matches[3] : 0);
        }

        return $day->setTime(23, 59, 59);
    }

    /**
     * Today's class the attendance belongs to, from the dashboard snapshot.
     *
     * @param  array{class_id: int, dosen_id: int, course_id: int}|null  $shortcutContext
     * @return array<string, mixed>|null
     */
    private function todayClass(string $sessionToken, ?int $discussionId, ?array $shortcutContext): ?array
    {
        $classes = $this->snapshots->get($sessionToken, 'dashboard')['data']['classes_summary']['today_classes'] ?? [];

        foreach (is_array($classes) ? $classes : [] as $class) {
            if (! is_array($class)) {
                continue;
            }

            $moduleId = $class['attendance']['payload']['modulesid'] ?? $class['attendance']['meeting_id'] ?? null;

            if ($discussionId !== null && is_numeric($moduleId) && (int) $moduleId === $discussionId) {
                return $class;
            }

            if ($shortcutContext !== null && is_numeric($class['class_id'] ?? null) && (int) $class['class_id'] === $shortcutContext['class_id']) {
                return $class;
            }
        }

        return null;
    }

    private function examSubject(string $sessionToken, int $examId): string
    {
        $exams = $this->snapshots->get($sessionToken, 'exams')['data']['exams'] ?? [];

        foreach (is_array($exams) ? $exams : [] as $exam) {
            if (is_array($exam) && (string) ($exam['id'] ?? '') === (string) $examId) {
                $course = trim((string) ($exam['course_name'] ?? ''));

                return $course !== '' ? "Ujian {$course}" : "Ujian #{$examId}";
            }
        }

        return "Ujian #{$examId}";
    }

    private function formatDeadline(CarbonInterface $deadline): string
    {
        return $deadline->copy()->setTimezone(config('app.timezone'))->locale('id')->translatedFormat('j M Y H:i');
    }
}
