<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalSnapshotStore;
use Tests\TestCase;

class PortalSnapshotStoreTest extends TestCase
{
    private PortalSnapshotStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PortalSnapshotStore;
    }

    public function test_it_saves_and_retrieves_snapshot(): void
    {
        $token = 'test-session-token';
        $domain = 'dashboard';
        $data = ['user_info' => ['name' => 'John Doe'], 'status' => 'success'];

        $this->assertNull($this->store->get($token, $domain));
        $this->assertFalse($this->store->has($token, $domain));

        $this->store->put($token, $domain, $data);

        $this->assertTrue($this->store->has($token, $domain));
        $snapshot = $this->store->get($token, $domain);

        $this->assertNotNull($snapshot);
        $this->assertSame($data, $snapshot['data']);
        $this->assertSame('synced', $snapshot['status']);
        $this->assertSame('campus_portal', $snapshot['source']);
        $this->assertFalse($this->store->isStale($token, $domain, 600));
    }

    public function test_it_marks_snapshot_as_syncing(): void
    {
        $token = 'test-session-token';
        $domain = 'assignments';
        $this->store->put($token, $domain, ['assignments' => []]);

        $this->store->markSyncing($token, $domain);

        $snapshot = $this->store->get($token, $domain);
        $this->assertNotNull($snapshot);
        $this->assertSame('syncing', $snapshot['status']);
    }

    public function test_restoring_the_sync_state_puts_back_a_failure_after_an_unqueued_sync(): void
    {
        $this->store->put('token', 'exams', ['exams' => []]);
        $this->store->markFailed('token', 'exams', 'Portal down');
        $before = $this->store->get('token', 'exams');

        $this->store->markSyncing('token', 'exams');
        $this->store->restoreSyncState('token', 'exams', $before);

        $restored = $this->store->get('token', 'exams');
        $this->assertSame('failed', $restored['status']);
        $this->assertSame($before['failed_at'], $restored['failed_at']);
        $this->assertSame('Portal down', $restored['error']);
        $this->assertArrayNotHasKey('syncing_since', $restored);
        $this->assertSame(['exams' => []], $restored['data']);
    }

    public function test_restoring_the_sync_state_leaves_a_sync_that_finished_meanwhile_alone(): void
    {
        $this->store->put('token', 'exams', ['exams' => ['old']]);
        $before = $this->store->get('token', 'exams');

        $this->travel(5)->seconds();
        $this->store->put('token', 'exams', ['exams' => ['new']]);
        $this->store->markSyncing('token', 'exams');
        $this->store->restoreSyncState('token', 'exams', $before);

        $this->assertSame('syncing', $this->store->get('token', 'exams')['status']);
        $this->assertSame(['exams' => ['new']], $this->store->get('token', 'exams')['data']);

        // Nothing to restore without a snapshot.
        $this->store->restoreSyncState('token', 'missing', $before);
        $this->assertNull($this->store->get('token', 'missing'));
    }

    public function test_it_detects_stale_snapshot(): void
    {
        $token = 'test-session-token';
        $domain = 'dashboard';
        $syncedAt = now()->subMinutes(15)->toISOString();

        $this->store->put($token, $domain, ['test' => true], $syncedAt);

        $this->assertTrue($this->store->isStale($token, $domain, 600));
        $this->assertFalse($this->store->isStale($token, $domain, 1200));
    }

    public function test_it_forgets_snapshot(): void
    {
        $token = 'test-session-token';
        $domain = 'classes';
        $this->store->put($token, $domain, ['classes' => []]);

        $this->assertTrue($this->store->has($token, $domain));
        $this->store->forget($token, $domain);
        $this->assertFalse($this->store->has($token, $domain));
    }
}
