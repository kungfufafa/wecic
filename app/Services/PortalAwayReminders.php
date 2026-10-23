<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalPushToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Time-based reminders for a student whose app is closed: the reminder scan
 * of a session (ScanPortalNotificationsJob) only runs while the student
 * uses it, so without this a deadline turning critical or a class starting
 * would never reach the phone once the session idled out.
 *
 * Only students with a registered app install (PortalPushToken) are
 * reminded, and only while no session of theirs was used within
 * `portal.session_refresh_interval` (that session's own scan reminds them
 * then). Nothing here contacts the portal: the reminders are built from
 * the student's stored snapshots and archive (kept fresh by background
 * sync) through a short-lived read-only token that is marked offline, so
 * no dataset build is ever queued for it. Snapshots older than
 * `portal.mobile.away_reminder_max_data_age` remind nobody: their
 * deadlines may already be met.
 *
 * Deadline reminders are the session scan's (PortalReminderBuilder), so one
 * the session already sent is not sent again. Whether a class's attendance
 * is open needs the meeting page, so a class in session is only announced as
 * started (`class_started`), once per class and day.
 */
class PortalAwayReminders
{
    /**
     * Seconds the read-only token lives; one run takes well under that.
     */
    private const TOKEN_TTL = 300;

    public function __construct(
        private readonly PortalDataOwner $owner,
        private readonly PortalOfflineAccess $offlineAccess,
        private readonly PortalDatasetRepository $datasets,
        private readonly ActionCenterBuilder $actionCenter,
        private readonly DashboardTodayClassesBuilder $todayClasses,
        private readonly PortalReminderBuilder $reminders,
        private readonly PortalNotificationCenter $notifications
    ) {}

    public function enabled(): bool
    {
        return (bool) config('portal.mobile.away_reminders', true);
    }

    /**
     * Owner keys of the students with at least one registered app install.
     *
     * @return list<string>
     */
    public function owners(): array
    {
        return PortalPushToken::query()->distinct()->orderBy('owner_hash')->pluck('owner_hash')->all();
    }

    /**
     * @return int Reminders that were new (and so are pushed).
     */
    public function remind(string $ownerHash, ?CarbonImmutable $now = null): int
    {
        if (! PortalPushToken::query()->forOwner($ownerHash)->exists() || $this->usesTheApp($ownerHash)) {
            return 0;
        }

        $now = ($now ?? CarbonImmutable::now(config('app.timezone')))->setTimezone((string) config('app.timezone'));
        $token = 'away_'.Str::random(40);
        $this->owner->rememberKey($token, $ownerHash, self::TOKEN_TTL);
        $this->offlineAccess->markOfflineSession($token, self::TOKEN_TTL);

        try {
            return count($this->notifications->publish($token, [
                ...$this->deadlineReminders($token, $now),
                ...$this->classStartedReminders($token, $now),
            ]));
        } finally {
            $this->offlineAccess->endOfflineSession($token);
            $this->owner->forget($token);
        }
    }

    /**
     * A session the student used lately has its own reminder scan.
     */
    private function usesTheApp(string $ownerHash): bool
    {
        $sessions = app(PortalSessionManager::class);
        $sessionToken = $sessions->activeOnlineSessionTokenForOwner($ownerHash);

        return $sessionToken !== null
            && $sessions->wasActiveWithin($sessionToken, (int) config('portal.session_refresh_interval', 1200));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deadlineReminders(string $token, CarbonImmutable $now): array
    {
        $actionCenter = $this->actionCenter->build($token, null, $now);
        $sources = $actionCenter['meta']['sources'];

        return $this->reminders->deadlineReminders(array_values(array_filter(
            $actionCenter['items'],
            fn (array $item): bool => $this->isRecent($sources[$item['source']]['synced_at'] ?? null, $now)
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function classStartedReminders(string $token, CarbonImmutable $now): array
    {
        $dashboard = $this->datasets->peek($token, 'dashboard', [], null, false);
        $classesSummary = $dashboard['data']['classes_summary'] ?? null;

        if (! is_array($classesSummary) || ! $this->isRecent($dashboard['meta']['synced_at'] ?? null, $now)) {
            return [];
        }

        // A schedule built on another day has no class in session today.
        $classesSummary = $this->todayClasses->refreshActiveState($classesSummary, $now);
        $todayClasses = is_array($classesSummary['today_classes'] ?? null) ? $classesSummary['today_classes'] : [];

        return $this->reminders->classStartedReminders($todayClasses, $now->toDateString());
    }

    private function isRecent(mixed $syncedAt, CarbonImmutable $now): bool
    {
        if (! is_string($syncedAt) || $syncedAt === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($syncedAt)->greaterThanOrEqualTo(
                $now->subSeconds(max(0, (int) config('portal.mobile.away_reminder_max_data_age', 86400)))
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
