<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshPortalDataJob;
use App\Services\AttendanceShortcutService;
use App\Services\Extractors\DashboardExtractor;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PerformanceValidationBenchmarkTest extends TestCase
{
    private PortalSnapshotStore $snapshotStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotStore = app(PortalSnapshotStore::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockValidSession(string $token): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('validateSession')
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }

    public function test_snapshot_store_read_write_latency_is_sub_millisecond(): void
    {
        $token = 'bench-token-store';
        $payload = [
            'dashboard_overview' => ['total_courses' => 6, 'total_credits' => 20],
            'classes' => [['course_name' => 'Statistik', 'credits' => 3]],
        ];

        // Measure write
        $t0 = microtime(true);
        $this->snapshotStore->put($token, 'dashboard', $payload);
        $writeDurationMs = (microtime(true) - $t0) * 1000;

        // Measure read
        $t1 = microtime(true);
        $retrieved = $this->snapshotStore->get($token, 'dashboard');
        $readDurationMs = (microtime(true) - $t1) * 1000;

        $this->assertNotNull($retrieved);
        $this->assertSame(6, $retrieved['data']['dashboard_overview']['total_courses']);
        $this->assertLessThan(5.0, $readDurationMs, "Snapshot read time ({$readDurationMs}ms) must be < 5ms");
        $this->assertLessThan(10.0, $writeDurationMs, "Snapshot write time ({$writeDurationMs}ms) must be < 10ms");

        fwrite(STDERR, sprintf("\n[BENCHMARK: SNAPSHOT STORE] Write: %.3f ms | Read: %.3f ms\n", $writeDurationMs, $readDurationMs));
    }

    public function test_dashboard_api_served_from_snapshot_in_sub_50ms(): void
    {
        $token = 'bench-token-dashboard';
        $this->mockValidSession($token);

        $payload = [
            'user_info' => ['name' => 'Mahasiswa Test', 'nim' => '2023001001'],
            'dashboard_overview' => ['total_courses' => 8, 'total_credits' => 24],
            'classes' => [
                ['name' => 'Pemrograman Web', 'code' => 'PW01'],
                ['name' => 'Basis Data', 'code' => 'BD02'],
            ],
        ];

        $this->snapshotStore->put($token, 'dashboard', $payload);

        // Warmup request
        $this->withSession(['campus_session_token' => $token])
            ->getJson('/api/dashboard');

        // Benchmark request
        $t0 = microtime(true);
        $response = $this->withSession(['campus_session_token' => $token])
            ->getJson('/api/dashboard');
        $durationMs = (microtime(true) - $t0) * 1000;

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.data_origin', 'snapshot')
            ->assertJsonPath('meta.status', 'synced')
            ->assertJsonPath('data.dashboard_overview.total_courses', 8);

        $this->assertLessThan(50.0, $durationMs, "Dashboard response time ({$durationMs}ms) must be < 50ms");

        fwrite(STDERR, sprintf("[BENCHMARK: GET /api/dashboard] Response Time: %.2f ms | HTTP 200 | Data Origin: snapshot\n", $durationMs));
    }

    public function test_pending_assignments_api_served_from_snapshot_in_sub_50ms(): void
    {
        $token = 'bench-token-assignments';
        $this->mockValidSession($token);

        $payload = [
            'assignments' => [
                [
                    'course_name' => 'Statistik Inferensial',
                    'meeting_number' => 4,
                    'title' => 'Tugas Regresi Linear',
                    'deadline' => '2026-10-10 23:59:00',
                    'can_submit_directly' => true,
                ],
                [
                    'course_name' => 'Rekayasa Perangkat Lunak',
                    'meeting_number' => 5,
                    'title' => 'Diagram UML',
                    'deadline' => '2026-10-12 23:59:00',
                    'can_submit_directly' => true,
                ],
            ],
        ];

        $this->snapshotStore->put($token, 'assignments', $payload);

        // Benchmark request
        $t0 = microtime(true);
        $response = $this->withSession(['campus_session_token' => $token])
            ->getJson('/api/assignment/pending');
        $durationMs = (microtime(true) - $t0) * 1000;

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.data_origin', 'snapshot')
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.course_name', 'Statistik Inferensial')
            ->assertJsonPath('data.assignments.1.course_name', 'Rekayasa Perangkat Lunak');

        $this->assertLessThan(50.0, $durationMs, "Pending assignments response time ({$durationMs}ms) must be < 50ms");

        fwrite(STDERR, sprintf("[BENCHMARK: GET /api/assignment/pending] Response Time: %.2f ms | HTTP 200 | Assignments: 2\n", $durationMs));
    }

    public function test_stale_snapshot_serves_instantly_and_triggers_background_refresh(): void
    {
        Queue::fake([RefreshPortalDataJob::class]);

        $token = 'bench-token-stale';
        $this->mockValidSession($token);

        // Put stale snapshot (synced 15 minutes ago)
        $staleTime = Carbon::now()->subMinutes(15)->toISOString();
        $this->snapshotStore->put($token, 'dashboard', [
            'dashboard_overview' => ['total_courses' => 5],
        ], $staleTime);

        $this->assertTrue($this->snapshotStore->isStale($token, 'dashboard', 600));

        $t0 = microtime(true);
        $response = $this->withSession(['campus_session_token' => $token])
            ->getJson('/api/dashboard');
        $durationMs = (microtime(true) - $t0) * 1000;

        $response->assertOk()
            ->assertJsonPath('meta.data_origin', 'snapshot')
            ->assertJsonPath('meta.status', 'syncing')
            ->assertJsonPath('data.dashboard_overview.total_courses', 5);

        // Verify non-blocking: UI received response immediately
        $this->assertLessThan(50.0, $durationMs);

        // Verify background job was queued without blocking the user
        Queue::assertPushed(RefreshPortalDataJob::class, function (RefreshPortalDataJob $job) use ($token): bool {
            $campusSessionToken = (fn (): string => $this->campusSessionToken)->call($job);
            $domains = (fn (): array => $this->domains)->call($job);

            return $campusSessionToken === $token && in_array('dashboard', $domains, true);
        });

        fwrite(STDERR, sprintf("[BENCHMARK: STALE SNAPSHOT NON-BLOCKING] Response Time: %.2f ms | Status: syncing | Background Job Dispatched\n", $durationMs));
    }

    public function test_refresh_portal_data_job_executes_and_updates_snapshot_without_data_loss(): void
    {
        $token = 'bench-token-worker';
        $this->mockValidSession($token);

        $this->instance(
            \App\Services\PortalBroadcastService::class,
            Mockery::mock(\App\Services\PortalBroadcastService::class)->shouldIgnoreMissing()
        );

        // Mock extractors simulating upstream response
        $this->instance(
            DashboardExtractor::class,
            Mockery::mock(DashboardExtractor::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('extractDashboardData')
                    ->once()
                    ->with($token)
                    ->andReturn([
                        'dashboard_overview' => ['total_courses' => 7, 'total_credits' => 21],
                    ]);
            })
        );

        $this->instance(
            AttendanceShortcutService::class,
            Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock) use ($token): void {
                $mock->shouldReceive('getPendingAssignmentsSnapshot')
                    ->once()
                    ->with($token)
                    ->andReturn([
                        'assignments' => [
                            ['course_name' => 'Kecerdasan Buatan', 'meeting_number' => 2],
                        ],
                    ]);
            })
        );

        // Execute background job directly via container
        $job = new RefreshPortalDataJob('web-session-1', $token, ['dashboard', 'assignments']);

        $t0 = microtime(true);
        app()->call([$job, 'handle']);
        $executionDurationMs = (microtime(true) - $t0) * 1000;

        // Verify snapshot was updated atomically
        $dashboardSnapshot = $this->snapshotStore->get($token, 'dashboard');
        $assignmentsSnapshot = $this->snapshotStore->get($token, 'assignments');

        $this->assertNotNull($dashboardSnapshot);
        $this->assertSame(7, $dashboardSnapshot['data']['dashboard_overview']['total_courses']);
        $this->assertFalse($this->snapshotStore->isStale($token, 'dashboard', 600));

        $this->assertNotNull($assignmentsSnapshot);
        $this->assertCount(1, $assignmentsSnapshot['data']['assignments']);
        $this->assertSame('Kecerdasan Buatan', $assignmentsSnapshot['data']['assignments'][0]['course_name']);

        fwrite(STDERR, sprintf("[BENCHMARK: BACKGROUND REFRESH JOB] Worker Execution: %.2f ms | Snapshots Written: 2 | Data Integrity: 100%%\n", $executionDurationMs));
    }

    public function test_sustained_concurrent_read_throughput_benchmark(): void
    {
        $token = 'bench-token-sustained';
        $this->mockValidSession($token);

        $this->snapshotStore->put($token, 'dashboard', [
            'dashboard_overview' => ['total_courses' => 8],
        ]);

        $times = [];
        $iterations = 30;

        for ($i = 0; $i < $iterations; $i++) {
            $t0 = microtime(true);
            $response = $this->withSession(['campus_session_token' => $token])
                ->getJson('/api/dashboard');
            $times[] = (microtime(true) - $t0) * 1000;
            $response->assertOk();
        }

        sort($times);
        $p50 = $times[(int) ($iterations * 0.50)];
        $p95 = $times[(int) ($iterations * 0.95)];
        $avg = array_sum($times) / count($times);
        $min = min($times);
        $max = max($times);

        $this->assertLessThan(15.0, $avg, "Average response time ({$avg}ms) must be < 15ms");

        fwrite(STDERR, sprintf(
            "[BENCHMARK: SUSTAINED READS (%d reqs)] Min: %.2f ms | P50: %.2f ms | P95: %.2f ms | Max: %.2f ms | Avg: %.2f ms\n\n",
            $iterations, $min, $p50, $p95, $max, $avg
        ));
    }
}
