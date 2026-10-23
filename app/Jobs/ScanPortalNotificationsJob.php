<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PortalException;
use App\Services\ActionCenterBuilder;
use App\Services\AttendanceShortcutService;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetRepository;
use App\Services\PortalNotificationCenter;
use App\Services\PortalReminderBuilder;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Time-based reminders: deadlines coming up and a class whose attendance is
 * open right now. Changes in portal data (new assignment, exam opened, new
 * grade, ...) are not decided here: re-syncing the datasets lets their
 * `notify` rules raise those (PortalSnapshotDiffNotifier).
 *
 * Reminders are keyed by student and record, never by login, so logging in
 * again does not repeat them.
 *
 * A scan schedules the next one (`followUp`) while its session lives, every
 * `portal.realtime.notifications.scan_interval` seconds or at the start of
 * today's next class, whichever comes first, so a class that opens or a
 * deadline that turns critical mid-session is still reminded. At most one
 * follow-up is pending per session; a follow-up skips the scan while the
 * student is away (keep-alive warm-up rule) and the chain ends with the
 * session. A follow-up builds its reminders from the stored snapshots and
 * only re-syncs a dataset older than
 * `portal.realtime.notifications.follow_up_resync_after`: re-syncing
 * assignments is a crawl of every class and meeting page, too heavy to
 * repeat every few minutes for every active student.
 */
class ScanPortalNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds before the worker kills a scan: on a slow portal, re-syncing
     * assignments and exams and reading today's class pages takes minutes.
     */
    public int $timeout = 300;

    /**
     * Datasets whose changes matter within hours: re-synced on a first scan,
     * and on a follow-up once older than `follow_up_resync_after`.
     */
    private const TIME_SENSITIVE_DATASETS = ['assignments', 'exams'];

    /**
     * Seconds after a class starts before its follow-up scan, so the class
     * already counts as in session.
     */
    private const CLASS_START_GRACE = 30;

    private const MIN_FOLLOW_UP_DELAY = 60;

    /**
     * A scan queued by an earlier scan. Declared with a default, not
     * promoted, so a payload queued before the flag existed still reads as
     * a first scan instead of failing on an uninitialized property.
     */
    private bool $followUp = false;

    public function __construct(
        private readonly string $sessionId,
        private readonly string $campusSessionToken,
        bool $followUp = false
    ) {
        $this->followUp = $followUp;

        // Lower priority than data refreshes: a slow scan must not delay the
        // dashboard the student is waiting for after login.
        $this->onQueue('campus-notifications');
    }

    public function handle(
        PortalDatasetRepository $datasets,
        ActionCenterBuilder $actionCenter,
        AttendanceShortcutService $attendanceShortcutService,
        PortalNotificationCenter $notifications,
        PortalDataOwner $owner,
        PortalSessionManager $sessions,
        PortalSnapshotStore $snapshots,
        PortalReminderBuilder $reminders
    ): void {
        if ($this->followUp) {
            Cache::forget($this->followUpKey());

            if (! $this->sessionCanBeScanned($sessions)) {
                return;
            }

            if (! $sessions->wasActiveWithin($this->campusSessionToken, (int) config('portal.session_refresh_interval', 1200))) {
                $this->scheduleFollowUp($sessions);

                return;
            }
        }

        $classes = $this->scan($datasets, $actionCenter, $attendanceShortcutService, $notifications, $owner, $snapshots, $reminders);

        if ($classes !== null) {
            $this->scheduleFollowUp($sessions, $classes);
        }
    }

    /**
     * The worker killed or failed the scan: keep the reminders coming.
     */
    public function failed(?\Throwable $exception): void
    {
        try {
            $this->scheduleFollowUp(app(PortalSessionManager::class));
        } catch (\Throwable $throwable) {
            Log::warning('Notification scan follow-up could not be scheduled', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>|null Today's classes, or null when the campus session expired.
     */
    private function scan(
        PortalDatasetRepository $datasets,
        ActionCenterBuilder $actionCenter,
        AttendanceShortcutService $attendanceShortcutService,
        PortalNotificationCenter $notifications,
        PortalDataOwner $owner,
        PortalSnapshotStore $snapshots,
        PortalReminderBuilder $reminders
    ): ?array {
        $lock = Cache::lock(
            'portal_notification_scan_'.$owner->key($this->campusSessionToken),
            max((int) config('portal.realtime.notifications.scan_lock_ttl', 300), $this->timeout)
        );

        if (! $lock->get()) {
            return [];
        }

        try {
            foreach (self::TIME_SENSITIVE_DATASETS as $dataset) {
                if ($this->followUp && ! $snapshots->isStale($this->campusSessionToken, $dataset, $this->followUpResyncAfter())) {
                    continue;
                }

                try {
                    $datasets->ensureFresh($this->campusSessionToken, $dataset);
                } catch (\Throwable $throwable) {
                    if (PortalException::indicatesExpiredSession($throwable)) {
                        return null;
                    }

                    // The last snapshot still feeds the reminders below.
                    Log::info('Notification scan could not refresh a dataset', [
                        'dataset' => $dataset,
                        'error' => $throwable->getMessage(),
                    ]);
                }
            }

            $notifications->publish(
                $this->campusSessionToken,
                $reminders->deadlineReminders($actionCenter->build($this->campusSessionToken, $this->sessionId)['items']),
                $this->sessionId
            );

            $classes = $attendanceShortcutService->getTodayShortcuts($this->campusSessionToken)['classes'] ?? [];

            $notifications->publish(
                $this->campusSessionToken,
                $reminders->attendanceReminders($classes),
                $this->sessionId
            );

            return is_array($classes) ? array_values($classes) : [];
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue the next scan unless one is already pending for this session. A
     * synchronous queue would run it at once, over and over, so the chain
     * only exists on a real queue.
     *
     * @param  list<array<string, mixed>>  $classes
     */
    private function scheduleFollowUp(PortalSessionManager $sessions, array $classes = []): void
    {
        if (Queue::connection($this->connection) instanceof SyncQueue || ! $this->sessionCanBeScanned($sessions)) {
            return;
        }

        $delay = $this->followUpDelay($classes);

        if (! Cache::add($this->followUpKey(), true, $delay + $this->timeout + 600)) {
            return;
        }

        self::dispatch($this->sessionId, $this->campusSessionToken, true)->delay(now()->addSeconds($delay));
    }

    private function sessionCanBeScanned(PortalSessionManager $sessions): bool
    {
        return $sessions->isSessionUsable($this->campusSessionToken)
            && ! $sessions->isBackgroundSession($this->campusSessionToken);
    }

    /**
     * The scan interval, or sooner when one of today's classes starts first.
     *
     * @param  list<array<string, mixed>>  $classes
     */
    private function followUpDelay(array $classes): int
    {
        $delay = max(self::MIN_FOLLOW_UP_DELAY, (int) config('portal.realtime.notifications.scan_interval', 600));
        $now = CarbonImmutable::now(config('app.timezone'));

        foreach ($classes as $class) {
            $start = $class['schedule']['start_time'] ?? null;

            if (! is_string($start) || preg_match('/^(\d{1,2}):(\d{2})/', $start, $matches) !== 1) {
                continue;
            }

            $untilStart = $now->setTime((int) $matches[1], (int) $matches[2])->getTimestamp() - $now->getTimestamp();

            if ($untilStart > 0) {
                $delay = min($delay, $untilStart + self::CLASS_START_GRACE);
            }
        }

        return max(self::MIN_FOLLOW_UP_DELAY, $delay);
    }

    /**
     * Seconds a snapshot may age before a follow-up scan re-syncs it.
     */
    private function followUpResyncAfter(): int
    {
        return max(0, (int) config('portal.realtime.notifications.follow_up_resync_after', 3600));
    }

    private function followUpKey(): string
    {
        return 'portal_notification_scan_next_'.hash('sha256', $this->campusSessionToken);
    }
}
