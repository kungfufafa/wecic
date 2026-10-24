<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\SyncPortalArchiveJob;
use App\Models\PortalMobileSession;
use App\Models\PortalNotification;
use App\Models\PortalOfflineAccount;
use App\Models\PortalPushToken;
use Illuminate\Console\Scheduling\Event;
use Tests\TestCase;
use Tests\Traits\InspectsScheduledQueueWorkers;

/**
 * The events the scheduler runs (routes/console.php): the fallback queue
 * workers it starts every minute, and the maintenance commands.
 */
class ScheduledQueueWorkerTest extends TestCase
{
    use InspectsScheduledQueueWorkers;

    /**
     * Queues whose jobs must never wait behind a long archive crawl or a
     * snapshot refresh: keep-alive pings, session resumes and idle-session
     * expiry.
     */
    private const LATENCY_CRITICAL_QUEUES = ['sessions'];

    public function test_latency_critical_queues_have_a_worker_of_their_own(): void
    {
        $workers = $this->scheduledQueueWorkers();

        $this->assertCount(3, $workers);

        $critical = $this->latencyCriticalWorker();
        $this->assertSame(self::LATENCY_CRITICAL_QUEUES, $this->scheduledWorkerQueues($critical));

        foreach ($workers as $worker) {
            if ($worker !== $critical) {
                $this->assertSame([], array_intersect(self::LATENCY_CRITICAL_QUEUES, $this->scheduledWorkerQueues($worker)), "Another worker also takes latency-critical queues: {$worker->command}");
            }
        }
    }

    public function test_background_crawls_never_hold_back_the_work_a_student_waits_on(): void
    {
        $queues = array_map(fn (Event $worker): array => $this->scheduledWorkerQueues($worker), $this->scheduledQueueWorkers());

        $this->assertContains(['default'], $queues);
        $this->assertContains(['background'], $queues);
        $this->assertSame('background', (new SyncPortalArchiveJob(1))->queue);
    }

    public function test_jobs_without_their_own_timeout_are_not_killed_at_the_worker_default(): void
    {
        foreach ($this->scheduledQueueWorkers() as $worker) {
            $timeout = $this->scheduledWorkerOption($worker, 'timeout');

            $this->assertNotNull($timeout, "The scheduled worker needs an explicit --timeout: queue:work kills a job after 60 s by default. {$worker->command}");
            $this->assertGreaterThanOrEqual(300, $timeout);
            // A job still running when retry_after passes is handed to a second worker.
            $this->assertLessThan((int) config('queue.connections.database.retry_after'), $timeout);
            $this->assertStringContainsString('--stop-when-empty', (string) $worker->command);
            $this->assertSame(1, $this->scheduledWorkerOption($worker, 'tries'));
        }
    }

    public function test_each_worker_runs_in_the_background_every_minute_without_overlapping_itself(): void
    {
        foreach ($this->scheduledQueueWorkers() as $worker) {
            $this->assertSame('* * * * *', $worker->expression);
            $this->assertTrue($worker->runInBackground, "A foreground worker delays every later event of the tick until its queues drain: {$worker->command}");
            $this->assertTrue($worker->withoutOverlapping);
            $this->assertNotNull($this->scheduledWorkerOption($worker, 'max-time'), "A busy worker without --max-time never ends: {$worker->command}");
            $this->assertLessThan(60, $worker->expiresAt, "A worker killed mid-run blocks its queues for {$worker->expiresAt} minutes: {$worker->command}");
        }
    }

    public function test_a_session_worker_killed_mid_run_blocks_keep_alive_for_less_than_one_ping_interval(): void
    {
        $lock = $this->latencyCriticalWorker()->expiresAt * 60;

        $this->assertLessThan((int) config('portal.session_refresh_interval'), $lock);
    }

    public function test_the_archive_sync_lock_expires_before_its_next_run(): void
    {
        $event = $this->scheduledEvent('portal:sync-archives');

        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThan(15, $event->expiresAt, 'A run killed mid-way would stop background syncs until the lock expires.');
    }

    public function test_old_notifications_offline_accounts_and_app_tokens_are_pruned_daily(): void
    {
        $event = $this->scheduledEvent('model:prune');

        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertStringContainsString(PortalNotification::class, (string) $event->command);
        $this->assertStringContainsString(PortalOfflineAccount::class, (string) $event->command);
        $this->assertStringContainsString(PortalMobileSession::class, (string) $event->command);
        $this->assertStringContainsString(PortalPushToken::class, (string) $event->command);
    }

    public function test_closed_app_reminders_are_queued_every_ten_minutes(): void
    {
        $event = $this->scheduledEvent('portal:remind-away');

        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThan(10, $event->expiresAt, 'A run killed mid-way would hold back the next one.');
    }

    public function test_stuck_uploads_are_recovered_every_five_minutes(): void
    {
        $this->assertSame('*/5 * * * *', $this->scheduledEvent('portal:recover-uploads')->expression);
    }

    private function latencyCriticalWorker(): Event
    {
        $workers = array_values(array_filter(
            $this->scheduledQueueWorkers(),
            fn (Event $worker): bool => in_array('sessions', $this->scheduledWorkerQueues($worker), true)
        ));

        $this->assertCount(1, $workers);

        return $workers[0];
    }
}
