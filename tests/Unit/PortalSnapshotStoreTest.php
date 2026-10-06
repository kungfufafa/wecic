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
