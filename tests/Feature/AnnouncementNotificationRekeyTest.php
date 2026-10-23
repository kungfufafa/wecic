<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalAnnouncementIds;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Notifications stored before stable ids link announcements by their
 * wording-based id. A one-off migration points every one the archive can
 * map to the id its announcement goes by now, and the rest follow when the
 * list is read. Every payload here is synthetic.
 */
class AnnouncementNotificationRekeyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'campus-token-announcement-rekey';

    private const USERNAME = '20990000061';

    private const UNIT = 'Biro Administrasi Akademik (BAAK)';

    private const STUDENT = 'student_00000000000000aa';

    private const OTHER_STUDENT = 'student_00000000000000bb';

    private ?Migration $migration = null;

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_migration_points_every_old_announcement_link_to_its_current_id_and_a_second_run_changes_nothing(): void
    {
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $original = $this->announcement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        $edited = $this->announcement('Pembayaran UKT Tahap 2', 'Batas pembayaran diundur ke 27 Oktober.');
        $this->archive(self::STUDENT, [$this->beforeStableIds($exam), $this->beforeStableIds($original)], '2026-10-01 08:00:00');
        $this->archive(self::STUDENT, [$edited, $exam], '2026-10-10 08:00:00');
        $this->archive(self::OTHER_STUDENT, [$this->beforeStableIds($exam)], '2026-10-01 08:00:00');

        $announced = $this->notification(self::STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $reminder = $this->notification(self::STUDENT, 'deadline_reminder', 'announcement-'.$original['legacy_id'], '/announcement/'.$original['legacy_id']);
        $notArchived = $this->notification(self::STUDENT, 'announcement_new', str_repeat('c', 40), '/announcement/'.str_repeat('c', 40));
        $krs = $this->notification(self::STUDENT, 'krs_validated', null, '/krs', 'krs');
        $otherStudent = $this->notification(self::OTHER_STUDENT, 'deadline_reminder', 'announcement-'.$exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $withoutArchive = $this->notification('student_00000000000000cc', 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $dedupeKeys = PortalNotification::query()->orderBy('id')->pluck('dedupe_key')->all();

        $this->runMigration();

        $this->assertSame([$exam['id'], '/announcement/'.$exam['id']], $this->link($announced));
        // An edited announcement's first wording maps to the id it goes by now.
        $this->assertSame(['announcement-'.$edited['id'], '/announcement/'.$edited['id']], $this->link($reminder));
        $this->assertSame(['announcement-'.$exam['id'], '/announcement/'.$exam['id']], $this->link($otherStudent));
        // Unknown to the archive: left for the lazy re-key when it is read or opened.
        $this->assertSame([str_repeat('c', 40), '/announcement/'.str_repeat('c', 40)], $this->link($notArchived));
        $this->assertSame([$exam['legacy_id'], '/announcement/'.$exam['legacy_id']], $this->link($withoutArchive));
        $this->assertSame([null, '/krs'], $this->link($krs));
        // Nothing stored twice, nothing deduplicated differently.
        $this->assertSame($dedupeKeys, PortalNotification::query()->orderBy('id')->pluck('dedupe_key')->all());

        $rows = PortalNotification::query()->orderBy('id')->get(['id', 'record_key', 'url'])->toArray();
        $this->runMigration();
        $this->assertSame($rows, PortalNotification::query()->orderBy('id')->get(['id', 'record_key', 'url'])->toArray());
    }

    public function test_the_command_can_be_limited_to_some_students(): void
    {
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $this->archive(self::STUDENT, [$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $this->archive(self::OTHER_STUDENT, [$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $mine = $this->notification(self::STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $theirs = $this->notification(self::OTHER_STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);

        $this->artisan('portal:rekey-announcement-notifications', ['--owner' => [self::STUDENT]])
            ->expectsOutput('Re-keyed 1 notification(s) of 1 student(s).')
            ->assertSuccessful();

        $this->assertSame('/announcement/'.$exam['id'], $mine->fresh()->url);
        $this->assertSame('/announcement/'.$exam['legacy_id'], $theirs->fresh()->url);
    }

    public function test_a_student_that_fails_does_not_stop_the_others(): void
    {
        Log::spy();
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $this->archive(self::OTHER_STUDENT, [$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $this->notification(self::STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $theirs = $this->notification(self::OTHER_STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $this->failRekeyOf(self::STUDENT);

        $this->artisan('portal:rekey-announcement-notifications')
            ->expectsOutput('Re-keyed 1 notification(s) of 2 student(s).')
            ->expectsOutput('1 student(s) could not be re-keyed; their notifications follow when they are read or opened. Owner keys:')
            ->expectsOutput(self::STUDENT)
            ->doesntExpectOutput(self::OTHER_STUDENT)
            ->assertFailed();

        $this->assertSame('/announcement/'.$exam['id'], $theirs->fresh()->url);
        // The log names the student skipped (an owner key, never the username).
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Announcement notifications of a student could not be re-keyed'
            && $context === ['owner' => self::STUDENT, 'error' => 'archive unreadable'])->once();
    }

    public function test_the_migration_logs_the_students_it_could_not_re_key(): void
    {
        Log::spy();
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $this->archive(self::OTHER_STUDENT, [$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $this->notification(self::STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $theirs = $this->notification(self::OTHER_STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $this->failRekeyOf(self::STUDENT);

        // The migration itself still succeeds: the student left behind follows lazily.
        $this->runMigration();

        $this->assertSame('/announcement/'.$exam['id'], $theirs->fresh()->url);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_starts_with($message, 'Some announcement notifications were not re-keyed')
            && str_contains($context['output'], 'Re-keyed 1 notification(s) of 2 student(s).')
            && str_contains($context['output'], self::STUDENT)
            && ! str_contains($context['output'], self::OTHER_STUDENT))->once();
    }

    public function test_a_clean_migration_logs_nothing(): void
    {
        Log::spy();
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $this->archive(self::STUDENT, [$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $announced = $this->notification(self::STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);

        $this->runMigration();

        $this->assertSame([$exam['id'], '/announcement/'.$exam['id']], $this->link($announced));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_migration_follows_the_feed_snapshot_of_a_student_without_an_archive(): void
    {
        config(['portal.archive.enabled' => false]);
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        // The feed snapshot kept from before stable ids, nothing archived.
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'announcements', ['items' => [$this->beforeStableIds($exam)], 'paging' => ['pages_loaded' => 3, 'last_page' => 3]]);
        $reminder = $this->notification($ownerHash, 'deadline_reminder', 'announcement-'.$exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $otherStudent = $this->notification(self::OTHER_STUDENT, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);

        $this->runMigration();

        $this->assertSame(['announcement-'.$exam['id'], '/announcement/'.$exam['id']], $this->link($reminder));
        // Another student's snapshot is not this one's.
        $this->assertSame([$exam['legacy_id'], '/announcement/'.$exam['legacy_id']], $this->link($otherStudent));
    }

    public function test_the_notification_list_follows_the_feed_snapshot_without_an_archive(): void
    {
        config(['portal.archive.enabled' => false]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $announced = $this->notification($ownerHash, 'announcement_new', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $this->runMigration();
        // Seen on the feed after the migration ran, never archived.
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'announcements', ['items' => [$exam], 'paging' => ['pages_loaded' => 3, 'last_page' => 3]]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.items.0.url', '/announcement/'.$exam['id']);

        $this->assertSame([$exam['id'], '/announcement/'.$exam['id']], $this->link($announced));
    }

    public function test_the_notification_list_shows_an_old_link_pointed_to_the_current_id(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        // Archived after the migration ran: this one is only known now.
        $reminder = $this->notification($ownerHash, 'deadline_reminder', 'announcement-'.$exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $this->runMigration();
        $this->archive($ownerHash, [$exam], '2026-10-20 08:00:00');

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.items.0.url', '/announcement/'.$exam['id']);

        $this->assertSame(['announcement-'.$exam['id'], '/announcement/'.$exam['id']], $this->link($reminder));
        $this->assertSame(1, PortalNotification::query()->count());
    }

    /**
     * Re-keying `$ownerHash` throws, as an unreadable archive would.
     */
    private function failRekeyOf(string $ownerHash): void
    {
        $ids = Mockery::mock(PortalAnnouncementIds::class, [app(PortalSnapshotStore::class), app(PortalDataOwner::class)])->makePartial();
        $ids->shouldReceive('currentIds')->with($ownerHash)->andThrow(new \RuntimeException('archive unreadable'));
        $this->instance(PortalAnnouncementIds::class, $ids);
    }

    private function runMigration(): void
    {
        $this->migration ??= require database_path('migrations/2026_10_22_192151_rekey_announcement_notifications.php');
        $this->migration->up();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function archive(string $ownerHash, array $items, string $lastSeenAt): void
    {
        PortalArchiveVersion::factory()
            ->forOwner($ownerHash)
            ->withPayload(['items' => $items, 'paging' => ['pages_loaded' => 3, 'last_page' => 3]])
            ->create(['dataset' => 'announcements', 'first_seen_at' => $lastSeenAt, 'last_seen_at' => $lastSeenAt, 'trusted_at' => $lastSeenAt]);
    }

    /**
     * An announcement as the feed gives it now.
     *
     * @return array<string, mixed>
     */
    private function announcement(string $title, string $bodyText): array
    {
        return [
            'id' => AnnouncementExtractor::stableId($title, '2026-10-15', self::UNIT),
            'legacy_id' => AnnouncementExtractor::legacyId($title, '2026-10-15', self::UNIT, $bodyText),
            'title' => $title,
            'published_date' => '2026-10-15',
            'publisher_unit' => self::UNIT,
            'body_text' => $bodyText,
        ];
    }

    /**
     * The same announcement as a feed built before stable ids kept it.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function beforeStableIds(array $item): array
    {
        $item['id'] = $item['legacy_id'];
        unset($item['legacy_id']);

        return $item;
    }

    private function notification(string $ownerHash, string $type, ?string $recordKey, string $url, string $dataset = 'announcements'): PortalNotification
    {
        return PortalNotification::factory()->forOwner($ownerHash)->create([
            'type' => $type,
            'dataset' => $dataset,
            'record_key' => $recordKey,
            'url' => $url,
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function link(PortalNotification $notification): array
    {
        $notification = $notification->fresh();

        return [$notification->record_key, $notification->url];
    }
}
