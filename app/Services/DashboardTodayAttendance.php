<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Joins today's attendance onto the dashboard schedule when it is read.
 *
 * The schedule is built from a few list pages; the attendance of each class
 * today needs that class's page and its meeting page, so it is its own
 * `dashboard` snapshot (PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS),
 * re-synced by its own background job after the schedule's. Reading it
 * never contacts the portal: a missing or stale snapshot is queued, and the
 * classes say their attendance is being checked until it is in.
 */
class DashboardTodayAttendance
{
    public function __construct(
        private readonly PortalDatasetRepository $datasets,
        private readonly DashboardTodayClassesBuilder $todayClasses,
        private readonly PortalActionProjector $projector
    ) {}

    /**
     * A forced read (`fresh=1`) re-syncs the attendance in the background
     * even when its snapshot is still fresh, as does a snapshot of another
     * day. Attendance the student marked through MyCIC moments ago stays
     * marked (PortalActionProjector) while that re-sync runs. Never throws:
     * the schedule is served without attendance instead.
     *
     * @param  array<string, mixed>  $classesSummary  `classes_summary` of the schedule snapshot.
     * @return array<string, mixed>
     */
    public function attach(
        string $sessionToken,
        array $classesSummary,
        CarbonImmutable $reference,
        bool $forceRefresh = false,
        ?string $broadcastSessionId = null
    ): array {
        if (! is_array($classesSummary['today_classes'] ?? null) || $classesSummary['today_classes'] === []) {
            return $classesSummary;
        }

        $shortcuts = null;
        $syncedAt = null;

        try {
            $read = $this->datasets->peek($sessionToken, 'dashboard', PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS, $broadcastSessionId);
            $shortcuts = $read['data'];
            $syncedAt = $read['meta']['synced_at'] ?? null;
            $status = (string) ($read['meta']['status'] ?? 'synced');

            $describesAnotherDay = $shortcuts !== null && ($shortcuts['date'] ?? null) !== $reference->toDateString();

            if (($forceRefresh || $describesAnotherDay)
                && ! in_array($status, ['syncing', 'pending'], true)
                && $this->datasets->queueEarlyResync($sessionToken, 'dashboard', PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS, $broadcastSessionId)) {
                $status = 'syncing';
            }
        } catch (\Throwable $throwable) {
            Log::warning('Today\'s attendance could not be joined onto the dashboard', [
                'error' => $throwable->getMessage(),
            ]);
            $status = 'failed';
        }

        $summary = $this->todayClasses->attachAttendance($classesSummary, $shortcuts, $status, $syncedAt);

        return $this->projector->apply($sessionToken, 'dashboard', [], ['classes_summary' => $summary])['classes_summary'];
    }
}
