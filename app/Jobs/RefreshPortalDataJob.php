<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PortalOperationStage;
use App\Exceptions\PortalException;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataCache;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-sync dataset snapshots from upstream, then tell the browser to re-read them.
 *
 * Unique per session + datasets + params, so repeated stale reads or refresh
 * clicks queue a single job. Each dataset is rebuilt with resource caches
 * bypassed and announced as soon as it is stored; the browser only re-reads
 * the stored snapshot afterwards. A schedule re-sync is followed straight
 * away by today's attendance (syncTodayAttendance()).
 */
class RefreshPortalDataJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Seconds per dataset in the timeout budget: a few portal pages, each
     * with the politeness delay, a 30 s request timeout and retries.
     */
    private const SECONDS_PER_DATASET = 75;

    private const MIN_TIMEOUT = 120;

    private const MAX_TIMEOUT = 600;

    /**
     * Worker timeout for this job, sized by its datasets (set in the
     * constructor): the worker's 60 s default would kill a login warm-up of
     * eight datasets on a slow portal.
     */
    public int $timeout = self::MIN_TIMEOUT;

    /**
     * Parameters for parameterized datasets. A plain property with a default
     * (not promoted) so jobs queued before it existed still unserialize.
     *
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * @param  list<string>  $domains  Dataset names from `portal.datasets`.
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly string $campusSessionToken,
        private readonly array $domains,
        array $params = []
    ) {
        $this->params = $params;
        $syncedDatasets = count(array_unique($domains)) + ($this->followsScheduleWithAttendance() ? 1 : 0);
        $this->timeout = max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, self::SECONDS_PER_DATASET * $syncedDatasets));
    }

    public function uniqueId(): string
    {
        $domains = array_values(array_unique($this->domains));
        sort($domains);

        return md5($this->campusSessionToken.'|'.implode(',', $domains).'|'.PortalDataCache::paramsHash($this->params));
    }

    /**
     * The sync lock window, but never shorter than the job may run: an
     * identical job must not be queued while this one is still crawling.
     */
    public function uniqueFor(): int
    {
        return max((int) config('portal.sync.lock_ttl', 300), $this->timeout);
    }

    public function handle(
        PortalDatasetRepository $datasets,
        PortalBroadcastService $broadcast,
        PortalSessionManager $sessionManager
    ): void {
        if (! $sessionManager->isSessionUsable($this->campusSessionToken)) {
            $broadcast->syncStatus($this->sessionId, $this->campusSessionToken, PortalOperationStage::Relogin, 'Sesi perlu login ulang.');

            return;
        }

        $broadcast->syncStatus($this->sessionId, $this->campusSessionToken, PortalOperationStage::FetchingSchedule, 'Mengambil jadwal dan data terbaru...');

        $syncedDatasets = [];
        $failedDatasets = [];

        foreach ($this->datasetsInSyncOrder() as $dataset) {
            if (! is_array(config("portal.datasets.{$dataset}"))) {
                continue;
            }

            try {
                $data = $datasets->sync($this->campusSessionToken, $dataset, $this->params);
                $syncedDatasets[] = $dataset;

                // Announce each dataset as soon as it is stored: a slow later
                // dataset (or a worker timeout) must not hold back the ones done.
                $this->announce($broadcast, $dataset);

                if ($dataset === 'dashboard' && $this->followsScheduleWithAttendance() && $this->hasClassesToday($data)
                    && ! $this->syncTodayAttendance($datasets, $broadcast)) {
                    $failedDatasets[] = $dataset;
                }
            } catch (\Throwable $throwable) {
                if (PortalException::indicatesExpiredSession($throwable)) {
                    $broadcast->syncStatus($this->sessionId, $this->campusSessionToken, PortalOperationStage::Relogin, 'Sesi perlu login ulang.');

                    return;
                }

                Log::warning('Background portal dataset sync failed', [
                    'dataset' => $dataset,
                    'error' => $throwable->getMessage(),
                ]);
                $failedDatasets[] = $dataset;
            }
        }

        if ($syncedDatasets !== []) {
            $keys = PortalDatasetRepository::realtimeKeys(...$syncedDatasets);

            $broadcast->syncStatus(
                $this->sessionId,
                $this->campusSessionToken,
                PortalOperationStage::Completed,
                'Selesai. Data terbaru siap ditampilkan.',
                $keys['cache_keys'],
                $keys['sync_keys']
            );
        }

        if ($failedDatasets !== []) {
            $broadcast->syncStatus(
                $this->sessionId,
                $this->campusSessionToken,
                PortalOperationStage::Failed,
                'Gagal memperbarui sebagian data. Data terakhir tetap ditampilkan.'
            );
        }
    }

    /**
     * A re-sync of the schedule (`dashboard` without params) brings today's
     * attendance (PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS) along,
     * as it did when both were one dataset: POST /api/refresh (the
     * Sinkronkan button) cannot name that snapshot, and without this the
     * refreshed schedule would be joined with attendance up to `fresh_for` old.
     */
    private function followsScheduleWithAttendance(): bool
    {
        return $this->params === [] && in_array('dashboard', $this->domains, true);
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function hasClassesToday(array $schedule): bool
    {
        $todayClasses = $schedule['classes_summary']['today_classes'] ?? null;

        return is_array($todayClasses) && $todayClasses !== [];
    }

    /**
     * Re-sync today's attendance straight after the schedule was stored and
     * announced, ahead of the other datasets: it is what a student pressing
     * Sinkronkan on the dashboard waits for, and it reads only the class and
     * meeting page of each class today. Skipped while a sync of it is queued
     * or running, moments after one finished (`force_cooldown`) or while
     * backing off after a failure. An expired session propagates.
     *
     * @return bool False when the attendance could not be synced.
     */
    private function syncTodayAttendance(PortalDatasetRepository $datasets, PortalBroadcastService $broadcast): bool
    {
        try {
            if ($datasets->resyncEarlyInline($this->campusSessionToken, 'dashboard', PortalDatasetBuilder::DASHBOARD_ATTENDANCE_PARAMS) !== null) {
                $this->announce($broadcast, 'dashboard');
            }

            return true;
        } catch (\Throwable $throwable) {
            if (PortalException::indicatesExpiredSession($throwable)) {
                throw $throwable;
            }

            Log::warning('Background sync of today\'s attendance failed', [
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    private function announce(PortalBroadcastService $broadcast, string $dataset): void
    {
        $keys = PortalDatasetRepository::realtimeKeys($dataset);
        $broadcast->dataInvalidated(
            $this->sessionId,
            $this->campusSessionToken,
            $keys['cache_keys'],
            $keys['sync_keys'],
            'Data portal diperbarui di background.'
        );
    }

    /**
     * Crawls of every class and meeting page (PortalDatasetBuilder::isCrawl())
     * run after the datasets read from a few pages, so those are stored and
     * announced without waiting for the crawl.
     *
     * @return list<string>
     */
    private function datasetsInSyncOrder(): array
    {
        $datasets = array_values(array_unique($this->domains));
        $crawls = array_values(array_filter(
            $datasets,
            fn (string $dataset): bool => PortalDatasetBuilder::isCrawl($dataset, $this->params)
        ));

        return [...array_values(array_diff($datasets, $crawls)), ...$crawls];
    }
}
