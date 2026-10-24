<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalNotificationSent;
use App\Events\PortalSessionNoticeSent;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The dashboard warm-up and the first notification scan of a login share the
 * default queue, which a worker drains oldest first: the warm-up has to be
 * queued first, or the dashboard waits for a scan that crawls every class.
 */
class PortalLoginQueueOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Event::fake([PortalSessionNoticeSent::class, PortalNotificationSent::class]);
        $this->withSession(['_token' => 'known-csrf-token'])->withHeader('X-CSRF-TOKEN', 'known-csrf-token');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_login_warm_up_is_queued_ahead_of_the_first_notification_scan(): void
    {
        $this->mockLogin();

        $this->postJson('/api/auth/login', ['username' => '20990000001', 'password' => 'secret-password'])->assertOk();

        $queued = $this->queuedJobs();
        $warmUp = array_search(RefreshPortalDataJob::class, array_column($queued, 'job'), true);
        $scan = array_search(ScanPortalNotificationsJob::class, array_column($queued, 'job'), true);

        $this->assertIsInt($warmUp, 'The login queued no warm-up.');
        $this->assertIsInt($scan, 'The login queued no first notification scan.');
        $this->assertLessThan($scan, $warmUp);
        $this->assertSame('default', $queued[$warmUp]['queue']);
        $this->assertSame('default', $queued[$scan]['queue']);
    }

    public function test_the_first_scan_still_waits_for_the_warm_up_delay(): void
    {
        $this->mockLogin();

        $this->postJson('/api/auth/login', ['username' => '20990000001', 'password' => 'secret-password'])->assertOk();

        $scan = collect($this->queuedJobs())->firstWhere('job', ScanPortalNotificationsJob::class);

        $this->assertNotNull($scan);
        $this->assertGreaterThan(now()->getTimestamp(), $scan['available_at']);
    }

    /**
     * The jobs table in the order a worker takes its rows.
     *
     * @return list<array{job: string, queue: string, available_at: int}>
     */
    private function queuedJobs(): array
    {
        return DB::table('jobs')->orderBy('id')->get()
            ->map(fn (object $row): array => [
                'job' => (string) (json_decode((string) $row->payload, true)['displayName'] ?? ''),
                'queue' => (string) $row->queue,
                'available_at' => (int) $row->available_at,
            ])
            ->values()
            ->all();
    }

    private function mockLogin(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createSession')->once()->andReturn([
                'success' => true,
                'session_token' => 'campus-token-order',
                'user_info' => ['name' => 'Mahasiswa Uji'],
                'expires_at' => '2026-10-24T12:00:00+07:00',
            ]);
            $mock->shouldReceive('rememberLaravelSessionId')->once();
        }));
    }
}
