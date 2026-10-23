<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalNotificationSent;
use App\Jobs\ScanPortalNotificationsJob;
use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Services\ActionCenterBuilder;
use App\Services\AttendanceShortcutService;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetRepository;
use App\Services\PortalNotificationCenter;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A deadline reminder of an announcement is keyed by the announcement's id,
 * and that id changes while the deadline stays: ids stopped being built
 * from the wording, and an announcement falls back to its wording-based id
 * while another one shares its title, day and unit. Every payload here is
 * synthetic.
 */
class PortalNotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'campus-token-center';

    private const USERNAME = '20990000002';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PortalNotificationSent::class]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('laravelSessionId')->andReturn('laravel-session');
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->travelTo(CarbonImmutable::create(2026, 10, 22, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_announcement_reminder_under_a_changed_id_is_not_sent_again(): void
    {
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->andReturn([]);
        }));
        $this->instance(AttendanceShortcutService::class, Mockery::mock(AttendanceShortcutService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getTodayShortcuts')->times(3)->andReturn(['classes' => []]);
        }));
        // Before the deploy, after it, and once a same-day post with the same title appears.
        $actionCenter = Mockery::mock(ActionCenterBuilder::class);
        $actionCenter->shouldReceive('build')->times(3)->andReturn(
            ['items' => [$this->announcementDeadline('wording-based-id')]],
            ['items' => [$this->announcementDeadline('stable-id')]],
            ['items' => [$this->announcementDeadline('wording-based-id')]],
        );
        $this->instance(ActionCenterBuilder::class, $actionCenter);

        foreach (range(1, 3) as $scan) {
            app()->call([new ScanPortalNotificationsJob('laravel-session', self::TOKEN), 'handle']);
            $this->travel(10)->minutes();
        }

        $this->assertSame(['/announcement/wording-based-id'], PortalNotification::query()->pluck('url')->all());
        Event::assertDispatchedTimes(PortalNotificationSent::class, 1);
    }

    public function test_announcement_reminder_turning_critical_under_a_changed_id_is_sent(): void
    {
        $this->center()->publish(self::TOKEN, [$this->reminder('wording-based-id', 'warning')]);
        $created = $this->center()->publish(self::TOKEN, [$this->reminder('stable-id', 'critical')]);

        $this->assertCount(1, $created);
        $this->assertSame('critical', $created[0]->urgency);
    }

    public function test_announcement_deadline_that_moved_is_reminded_again(): void
    {
        $this->center()->publish(self::TOKEN, [$this->reminder('wording-based-id', 'warning')]);
        $created = $this->center()->publish(self::TOKEN, [$this->reminder('stable-id', 'warning', 'Tenggat Jumat, 30 Oktober 2026.')]);

        $this->assertCount(1, $created);
        $this->assertSame('Tenggat Jumat, 30 Oktober 2026.', $created[0]->body);
    }

    public function test_announcement_reminders_reading_alike_in_one_scan_are_sent_once(): void
    {
        $created = $this->center()->publish(self::TOKEN, [
            $this->reminder('first-id', 'warning'),
            $this->reminder('second-id', 'warning'),
        ]);

        $this->assertCount(1, $created);
    }

    public function test_other_reminders_reading_alike_are_each_sent(): void
    {
        $created = $this->center()->publish(self::TOKEN, [
            [...$this->reminder('assignment-5101', 'warning'), 'dataset' => 'assignments', 'record_key' => 'assignment-5101'],
            [...$this->reminder('assignment-5102', 'warning'), 'dataset' => 'assignments', 'record_key' => 'assignment-5102'],
        ]);

        $this->assertCount(2, $created);
    }

    public function test_rekey_moves_only_that_students_notifications_of_the_record(): void
    {
        $ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
        $announced = $this->storedNotification($ownerHash, 'announcement_new', 'old-id');
        $reminder = $this->storedNotification($ownerHash, 'deadline_reminder', 'old-id');
        $otherStudent = $this->storedNotification('another-student', 'announcement_new', 'old-id');

        $this->assertSame(1, $this->center()->rekey($ownerHash, 'announcements', 'announcement_new', 'old-id', 'new-id', '/announcement/new-id'));

        $this->assertSame(['new-id', '/announcement/new-id'], [$announced->fresh()->record_key, $announced->fresh()->url]);
        $this->assertSame(['old-id', '/announcement/old-id'], [$reminder->fresh()->record_key, $reminder->fresh()->url]);
        $this->assertSame(['old-id', '/announcement/old-id'], [$otherStudent->fresh()->record_key, $otherStudent->fresh()->url]);
    }

    public function test_rekey_never_stores_a_link_outside_mycic(): void
    {
        $ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
        $announced = $this->storedNotification($ownerHash, 'announcement_new', 'old-id');

        $this->center()->rekey($ownerHash, 'announcements', 'announcement_new', 'old-id', 'new-id', '//evil.example.test/announcement');

        $this->assertSame(['new-id', '/announcement/old-id'], [$announced->fresh()->record_key, $announced->fresh()->url]);
    }

    public function test_every_notification_linking_an_announcement_follows_its_new_id(): void
    {
        $ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
        $oldId = str_repeat('e', 40);
        $currentId = str_repeat('a', 40);
        $this->center()->publish(self::TOKEN, [$this->reminder($oldId, 'warning')]);
        $announced = $this->storedNotification($ownerHash, 'announcement_new', $oldId);
        $otherStudent = $this->storedNotification('student_someone_else', 'announcement_new', $oldId);

        $this->assertSame(2, $this->center()->rekeyAnnouncements($ownerHash, [$oldId => $currentId]));

        $reminder = PortalNotification::query()->forOwner($ownerHash)->where('type', 'deadline_reminder')->sole();
        $this->assertSame(['announcement-'.$currentId, '/announcement/'.$currentId], [$reminder->record_key, $reminder->url]);
        $this->assertSame([$currentId, '/announcement/'.$currentId], [$announced->fresh()->record_key, $announced->fresh()->url]);
        $this->assertSame('/announcement/'.$oldId, $otherStudent->fresh()->url);

        // The same deadline reminded again under the new id is not stored twice.
        $this->assertSame([], $this->center()->publish(self::TOKEN, [$this->reminder($currentId, 'warning')]));
        $this->assertSame(3, PortalNotification::query()->count());
    }

    public function test_reading_the_list_points_old_announcement_links_to_their_current_id(): void
    {
        $ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
        $unit = 'Biro Administrasi Akademik (BAAK)';
        $stableId = AnnouncementExtractor::stableId('Pengisian Her-Registrasi dan KRS', '2026-10-15', $unit);
        $legacyId = AnnouncementExtractor::legacyId('Pengisian Her-Registrasi dan KRS', '2026-10-15', $unit, 'Pengisian 19 s/d 26 Oktober 2026.');
        // Archived before stable ids: its id was the wording-based one.
        PortalArchiveVersion::factory()->forOwner($ownerHash)->withPayload(['items' => [[
            'id' => $legacyId,
            'title' => 'Pengisian Her-Registrasi dan KRS',
            'published_date' => '2026-10-15',
            'publisher_unit' => $unit,
            'body_text' => 'Pengisian 19 s/d 26 Oktober 2026.',
        ]]])->create(['dataset' => 'announcements']);
        $this->center()->publish(self::TOKEN, [$this->reminder($legacyId, 'warning')]);
        $announced = $this->storedNotification($ownerHash, 'announcement_new', $legacyId);

        $page = $this->center()->latest($ownerHash, 20);

        $this->assertSame(['/announcement/'.$stableId, '/announcement/'.$stableId], array_map(static fn (PortalNotification $notification): ?string => $notification->url, $page['items']));
        $this->assertSame([$stableId, '/announcement/'.$stableId], [$announced->fresh()->record_key, $announced->fresh()->url]);
        $this->assertSame('announcement-'.$stableId, PortalNotification::query()->where('type', 'deadline_reminder')->value('record_key'));
        $this->assertSame(2, PortalNotification::query()->count());
    }

    private function center(): PortalNotificationCenter
    {
        return app(PortalNotificationCenter::class);
    }

    /**
     * An action center item of an announcement deadline, as ActionCenterBuilder lists it.
     *
     * @return array<string, mixed>
     */
    private function announcementDeadline(string $announcementId): array
    {
        return [
            'id' => 'announcement-'.$announcementId,
            'type' => 'announcement_deadline',
            'title' => 'Pengisian Her-Registrasi dan KRS',
            'description' => 'Tenggat Senin, 26 Oktober 2026. Pengisian paling lambat 26 Oktober 2026.',
            'due_at' => '2026-10-26',
            'days_left' => 4,
            'severity' => 'warning',
            'cta_label' => 'Baca pengumuman',
            'cta_url' => '/announcement/'.$announcementId,
            'cta_external' => false,
            'source' => 'announcements',
        ];
    }

    /**
     * A deadline reminder as ScanPortalNotificationsJob builds it.
     *
     * @return array{type: string, dataset: string, record_key: string, dedupe_key: string, title: string, body: string, url: string, urgency: string}
     */
    private function reminder(string $announcementId, string $severity, string $body = 'Tenggat Senin, 26 Oktober 2026.'): array
    {
        return [
            'type' => 'deadline_reminder',
            'dataset' => 'announcements',
            'record_key' => 'announcement-'.$announcementId,
            'dedupe_key' => hash('sha256', implode("\0", ['reminder', 'announcement-'.$announcementId, '2026-10-26', $severity])),
            'title' => 'Pengisian Her-Registrasi dan KRS',
            'body' => $body,
            'url' => '/announcement/'.$announcementId,
            'urgency' => $severity,
        ];
    }

    private function storedNotification(string $ownerHash, string $type, string $recordKey): PortalNotification
    {
        return PortalNotification::query()->create([
            'owner_hash' => $ownerHash,
            'type' => $type,
            'dataset' => 'announcements',
            'record_key' => $recordKey,
            'dedupe_key' => $ownerHash.'-'.$type.'-'.$recordKey,
            'title' => 'Pengumuman: Pengisian Her-Registrasi dan KRS',
            'url' => '/announcement/'.$recordKey,
        ]);
    }
}
