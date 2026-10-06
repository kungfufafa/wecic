<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PortalOperationStage;
use App\Services\AttendanceShortcutService;
use App\Services\Extractors\ClassesExtractor;
use App\Services\Extractors\DashboardExtractor;
use App\Services\Extractors\ProfileExtractor;
use App\Services\PortalBroadcastService;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class RefreshPortalDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $campusSessionToken,
        public readonly array $domains
    ) {
        $this->onQueue('campus-refresh');
    }

    public function handle(
        PortalSessionManager $sessionManager,
        PortalBroadcastService $broadcast,
        AttendanceShortcutService $attendanceShortcutService,
        ClassesExtractor $classesExtractor,
        ProfileExtractor $profileExtractor,
        SipandaiSubmissionService $submissionService,
        ?DashboardExtractor $dashboardExtractor = null,
        ?PortalSnapshotStore $snapshotStore = null
    ): void {
        $snapshotStore ??= app(PortalSnapshotStore::class);
        $lock = Cache::lock(
            'portal_refresh_'.$this->campusSessionToken.'_'.md5(json_encode($this->domains)),
            (int) config('portal.realtime.refresh_lock_ttl', 120)
        );

        if (! $lock->get()) {
            $broadcast->syncStatus(
                $this->sessionId,
                $this->campusSessionToken,
                PortalOperationStage::Connecting,
                'Refresh data sedang berjalan di tab atau perangkat lain.'
            );

            return;
        }

        try {
            $broadcast->syncStatus($this->sessionId, $this->campusSessionToken, PortalOperationStage::Connecting, 'Menghubungkan ke portal kampus...');

            if (! $sessionManager->validateSession($this->campusSessionToken)) {
                $broadcast->syncStatus($this->sessionId, $this->campusSessionToken, PortalOperationStage::Relogin, 'Sesi perlu login ulang.');

                return;
            }

            $broadcast->syncStatus($this->sessionId, $this->campusSessionToken, PortalOperationStage::FetchingSchedule, 'Mengambil jadwal dan data terbaru...');

            $uniqueDomains = array_values(array_unique($this->domains));
            $syncedAssignments = false;

            foreach ($uniqueDomains as $domain) {
                match ($domain) {
                    'dashboard' => (function () use ($dashboardExtractor, $attendanceShortcutService, $snapshotStore, &$syncedAssignments): void {
                        if ($dashboardExtractor !== null) {
                            $dashboardData = $dashboardExtractor->extractDashboardData($this->campusSessionToken);
                            $snapshotStore->put($this->campusSessionToken, 'dashboard', $dashboardData);
                        }

                        if (! $syncedAssignments) {
                            $pendingData = $attendanceShortcutService->getPendingAssignmentsSnapshot($this->campusSessionToken);
                            $snapshotStore->put($this->campusSessionToken, 'assignments', $pendingData);
                            $syncedAssignments = true;
                        }
                    })(),
                    'assignments' => (function () use ($attendanceShortcutService, $snapshotStore, &$syncedAssignments): void {
                        if (! $syncedAssignments) {
                            $pendingData = $attendanceShortcutService->getPendingAssignmentsSnapshot($this->campusSessionToken);
                            $snapshotStore->put($this->campusSessionToken, 'assignments', $pendingData);
                            $syncedAssignments = true;
                        }
                    })(),
                    'classes' => (function () use ($classesExtractor, $snapshotStore): void {
                        $classesData = $classesExtractor->extractClassesData($this->campusSessionToken);
                        $snapshotStore->put($this->campusSessionToken, 'classes', $classesData);
                    })(),
                    'profile' => (function () use ($profileExtractor, $snapshotStore): void {
                        $profileData = $profileExtractor->extractProfileData($this->campusSessionToken);
                        $snapshotStore->put($this->campusSessionToken, 'profile', $profileData);
                    })(),
                    'exams' => (function () use ($submissionService, $snapshotStore): void {
                        $examsData = $submissionService->getExamList($this->campusSessionToken);
                        $snapshotStore->put($this->campusSessionToken, 'exams', ['exams' => $examsData]);
                    })(),
                    default => null,
                };
            }

            $domainConfig = collect($this->domains)
                ->map(fn (string $domain): array => config('portal.realtime.domains.'.$domain, []));
            $cacheKeys = $domainConfig
                ->flatMap(fn (array $config): array => $config['cache_keys'] ?? [])
                ->unique()
                ->values()
                ->all();
            $syncKeys = $domainConfig
                ->flatMap(fn (array $config): array => $config['sync_keys'] ?? [])
                ->unique()
                ->values()
                ->all();

            $broadcast->syncStatus(
                $this->sessionId,
                $this->campusSessionToken,
                PortalOperationStage::Completed,
                'Selesai. Data terbaru siap ditampilkan.',
                $cacheKeys,
                $syncKeys
            );
            $broadcast->dataInvalidated(
                $this->sessionId,
                $this->campusSessionToken,
                $cacheKeys,
                $syncKeys,
                'Data portal diperbarui di background.'
            );
        } catch (\Throwable $throwable) {
            $broadcast->syncStatus(
                $this->sessionId,
                $this->campusSessionToken,
                PortalOperationStage::Failed,
                'Gagal memperbarui data.'
            );

            throw $throwable;
        } finally {
            $lock->release();
        }
    }
}
