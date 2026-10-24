<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PortalMobileSession;
use App\Models\PortalNotification;
use App\Models\PortalOfflineAccount;
use App\Models\PortalPushToken;
use Illuminate\Console\Scheduling\Event;
use Tests\TestCase;
use Tests\Traits\InspectsScheduledEvents;

/**
 * The events the scheduler runs (routes/console.php). Horizon runs the
 * queues itself, so no queue worker is scheduled.
 */
class ScheduledEventsTest extends TestCase
{
    use InspectsScheduledEvents;

    public function test_no_queue_worker_is_scheduled_next_to_horizon(): void
    {
        $workers = array_filter(
            $this->scheduledEvents(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'queue:work') || str_contains((string) $event->command, 'queue:listen')
        );

        $this->assertSame([], $workers);
    }

    public function test_horizon_snapshots_its_metrics_every_five_minutes(): void
    {
        $this->assertSame('*/5 * * * *', $this->scheduledEvent('horizon:snapshot')->expression);
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
}
