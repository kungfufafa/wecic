<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalNotificationSent;
use App\Jobs\RefreshPortalDataJob;
use App\Models\PortalNotification;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A portal change travels from a background sync to the student's bell:
 * sync → snapshot stored → diff → notification row → realtime event → API.
 */
class PortalNotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'campus-token-flow';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RefreshPortalDataJob::class]);
        $this->travelTo(CarbonImmutable::create(2026, 10, 21, 9, 0, 0, 'Asia/Jakarta'));
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isSessionUsable')->andReturnTrue();
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldReceive('laravelSessionId')->andReturn('laravel-session-flow');
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, '20990000003', 3600);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_simulated_portal_change_reaches_the_notification_center(): void
    {
        $this->mockPortal([
            'krs' => [$this->krs('pending_dpa'), $this->krs('validated')],
            'grades' => [$this->grades(null), $this->grades('A')],
        ]);

        Event::fake([PortalNotificationSent::class]);
        $this->runRefresh(['krs', 'grades']);
        $this->assertDatabaseCount('portal_notifications', 0);

        $this->travel(20)->minutes();
        $this->runRefresh(['krs', 'grades']);

        $this->assertSame(
            ['krs_validated', 'grade_released'],
            PortalNotification::query()->orderBy('id')->pluck('type')->all()
        );
        Event::assertDispatchedTimes(PortalNotificationSent::class, 2);
        Event::assertDispatched(PortalNotificationSent::class, fn (PortalNotificationSent $event): bool => $event->sessionId === 'laravel-session-flow'
            && $event->broadcastWith()['title'] === 'Nilai Kecerdasan Buatan keluar: A'
            && $event->broadcastWith()['unread_count'] === 2);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.items.0.title', 'Nilai Kecerdasan Buatan keluar: A')
            ->assertJsonPath('data.items.1.title', 'KRS sudah divalidasi DPA');
    }

    public function test_change_made_while_the_student_was_away_is_found_after_the_snapshot_expired(): void
    {
        $this->mockPortal(['krs' => [$this->krs('pending_dpa'), $this->krs('validated')]]);
        $repository = app(PortalDatasetRepository::class);
        $repository->sync(self::TOKEN, 'krs');

        // Ten days later: the 7-day snapshot is gone and the student logs in again.
        $this->travel(10)->days();
        $nextLogin = 'campus-token-after-a-break';
        app(PortalDataOwner::class)->remember($nextLogin, '20990000003', 3600);
        $this->assertNull(app(\App\Services\PortalSnapshotStore::class)->get($nextLogin, 'krs'));

        $repository->read($nextLogin, 'krs');

        $this->assertSame(['krs_validated'], PortalNotification::query()->pluck('type')->all());
    }

    /**
     * @param  list<string>  $datasets
     */
    private function runRefresh(array $datasets): void
    {
        app()->call([new RefreshPortalDataJob('laravel-session-flow', self::TOKEN, $datasets), 'handle']);
    }

    /**
     * Each call of a dataset returns its next payload.
     *
     * @param  array<string, list<array<string, mixed>>>  $responses
     */
    private function mockPortal(array $responses): void
    {
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock) use ($responses): void {
            $mock->shouldReceive('build')->andReturnUsing(function (string $token, string $dataset) use (&$responses): array {
                return array_shift($responses[$dataset]) ?? [];
            });
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function krs(string $statusCode): array
    {
        return [
            'found' => true,
            'academic_period' => ['label' => 'T.A 2026/2027 Semester Ganjil'],
            'status' => ['code' => $statusCode],
            'periods' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grades(?string $letter): array
    {
        return [
            'found' => true,
            'semesters' => [[
                'label' => 'Semester 5',
                'courses' => [['code' => 'IF503', 'name' => 'Kecerdasan Buatan', 'sks' => 3, 'letter' => $letter]],
            ]],
            'summary' => ['computed' => ['ips_series' => []]],
        ];
    }
}
