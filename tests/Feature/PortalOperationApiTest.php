<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PortalOperationStage;
use App\Services\PortalOperationTracker;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalOperationApiTest extends TestCase
{
    private const OPERATION_ID = '9b2f1c1e-5d0a-4c3b-8a43-2f6a2d1c9e10';

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();

        parent::tearDown();
    }

    public function test_operation_status_is_returned_to_the_session_that_started_it(): void
    {
        $this->mockValidCampusSession('token-op');

        $sessionId = Str::random(40);

        $tracker = app(PortalOperationTracker::class);
        $tracker->start(self::OPERATION_ID, 'assignment_upload', $sessionId);
        $tracker->progress(
            self::OPERATION_ID,
            PortalOperationStage::Completed,
            'Tugas terkirim ke portal.',
            100,
            cacheKeys: ['/api/assignment/pending'],
            syncKeys: ['dashboard-pending-assignments']
        );

        $this->withSession(['campus_session_token' => 'token-op'])
            ->withCredentials()
            ->withCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/operations/'.self::OPERATION_ID)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.percent', 100)
            ->assertJsonPath('data.cache_keys', ['/api/assignment/pending'])
            ->assertJsonPath('data.sync_keys', ['dashboard-pending-assignments']);
    }

    public function test_operation_of_another_session_is_not_found(): void
    {
        $this->mockValidCampusSession('token-op');

        app(PortalOperationTracker::class)->start(self::OPERATION_ID, 'assignment_upload', 'another-session');

        $this->withSession(['campus_session_token' => 'token-op'])
            ->getJson('/api/operations/'.self::OPERATION_ID)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    private function mockValidCampusSession(string $token): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('validateSession')->with($token)->andReturnTrue();
                $mock->shouldIgnoreMissing();
            })
        );
    }
}
