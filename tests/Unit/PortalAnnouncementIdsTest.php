<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalAnnouncementIds;
use App\Services\PortalDataOwner;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Links stored before stable ids carry an announcement's wording-based id.
 * The archive keeps every wording the student was shown, so each of those
 * ids maps to the id the announcement goes by now. Every payload here is
 * synthetic.
 */
class PortalAnnouncementIdsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'campus-token-announcement-ids';

    private const USERNAME = '20990000031';

    private const UNIT = 'Biro Administrasi Akademik (BAAK)';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 10, 22, 9, 0, 0, 'Asia/Jakarta'));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->key(self::TOKEN);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_an_announcement_archived_before_stable_ids_maps_its_wording_id_to_its_stable_id(): void
    {
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $this->archive([$this->beforeStableIds($exam)], '2026-10-01 08:00:00');

        $this->assertSame([$exam['legacy_id'] => $exam['id']], $this->ids()->currentIds($this->ownerHash));
    }

    public function test_every_wording_of_an_edited_announcement_maps_to_its_stable_id(): void
    {
        $original = $this->announcement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        $edited = $this->announcement('Pembayaran UKT Tahap 2', 'Batas pembayaran diundur ke 27 Oktober.');
        $this->archive([$this->beforeStableIds($original)], '2026-10-01 08:00:00');
        $this->archive([$edited], '2026-10-10 08:00:00');

        $this->assertSame($edited['id'], $original['id']);
        $this->assertSame([
            $original['legacy_id'] => $original['id'],
            $edited['legacy_id'] => $edited['id'],
        ], $this->ids()->currentIds($this->ownerHash));
    }

    public function test_the_newest_sighting_decides_when_an_announcement_falls_back_to_its_wording_id(): void
    {
        $first = $this->announcement('Surat Pengantar Magang', 'Gelombang pertama.');
        $second = $this->announcement('Surat Pengantar Magang', 'Gelombang kedua.');
        $this->archive([$this->beforeStableIds($first)], '2026-10-01 08:00:00');
        // A second post with the same title, day and unit: both go by their wording-based ids.
        $this->archive([$this->beforeStableIds($second), $this->beforeStableIds($first)], '2026-10-05 08:00:00');

        $this->assertSame([], $this->ids()->currentIds($this->ownerHash));

        // The second one is taken down: the first goes by its stable id again.
        $this->archive([$this->beforeStableIds($first)], '2026-10-08 08:00:00');

        $this->assertSame([$first['legacy_id'] => $first['id']], $this->ids()->currentIds($this->ownerHash));
    }

    public function test_feed_items_given_are_newer_than_the_archive(): void
    {
        $first = $this->announcement('Surat Pengantar Magang', 'Gelombang pertama.');
        $second = $this->announcement('Surat Pengantar Magang', 'Gelombang kedua.');
        $this->archive([$this->beforeStableIds($first)], '2026-10-01 08:00:00');

        $feed = AnnouncementExtractor::withStableIds([$second, $first]);

        $this->assertSame([], $this->ids()->currentIds($this->ownerHash, $feed));
        $this->assertSame([$first['legacy_id'] => $first['id']], $this->ids()->currentIds($this->ownerHash));
    }

    public function test_the_feed_snapshot_is_read_by_owner_key_as_the_newest_sighting(): void
    {
        $first = $this->announcement('Surat Pengantar Magang', 'Gelombang pertama.');
        $second = $this->announcement('Surat Pengantar Magang', 'Gelombang kedua.');
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        // Without a campus session (bulk re-key, list read), and with nothing archived.
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'announcements', ['items' => [$this->beforeStableIds($exam)]]);

        $this->assertSame([$exam['legacy_id'] => $exam['id']], $this->ids()->currentIds($this->ownerHash));
        $this->assertSame([], $this->ids()->currentIds('student_someone_else'));

        // Newer than the archive: a same-day repost on the feed now gives both their wording ids.
        $this->archive([$this->beforeStableIds($first)], '2026-10-01 08:00:00');
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'announcements', ['items' => AnnouncementExtractor::withStableIds([$second, $first])]);

        $this->assertSame([], $this->ids()->currentIds($this->ownerHash));
    }

    public function test_a_feed_synced_again_unchanged_reuses_the_ids_worked_out(): void
    {
        Log::spy();
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $holiday = $this->announcement('Libur Nasional', 'Kampus libur 28 Oktober.');
        $older = $this->archive([$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $newest = $this->archive([$this->beforeStableIds($holiday), $this->beforeStableIds($exam)], '2026-10-02 08:00:00');
        $expected = [$exam['legacy_id'] => $exam['id'], $holiday['legacy_id'] => $holiday['id']];
        $this->assertEqualsCanonicalizing($expected, $this->ids()->currentIds($this->ownerHash));

        // PortalDataArchive::touch(): the newest version is seen again, only its last sighting moves.
        $newest->forceFill(['last_seen_at' => '2026-10-03 08:00:00', 'seen_count' => 2])->save();
        // Were the payloads read again, these could no longer be decrypted and their ids would be lost.
        DB::table('portal_archive_versions')->whereIn('id', [$older->id, $newest->id])->update(['payload' => 'not-encrypted']);

        $this->assertEqualsCanonicalizing($expected, $this->ids()->currentIds($this->ownerHash));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_an_older_version_seen_again_decides_again(): void
    {
        $first = $this->announcement('Surat Pengantar Magang', 'Gelombang pertama.');
        $second = $this->announcement('Surat Pengantar Magang', 'Gelombang kedua.');
        $alone = $this->archive([$this->beforeStableIds($first)], '2026-10-01 08:00:00');
        $this->archive([$this->beforeStableIds($second), $this->beforeStableIds($first)], '2026-10-05 08:00:00');
        $this->assertSame([], $this->ids()->currentIds($this->ownerHash));

        // The repost is taken down: the portal shows exactly what it showed first, so that version is seen again.
        $alone->forceFill(['last_seen_at' => '2026-10-08 08:00:00', 'seen_count' => 2])->save();

        $this->assertSame([$first['legacy_id'] => $first['id']], $this->ids()->currentIds($this->ownerHash));
    }

    public function test_another_students_archive_and_an_unknown_owner_map_nothing(): void
    {
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $this->archive([$this->beforeStableIds($exam)], '2026-10-01 08:00:00', 'student_someone_else');

        $this->assertSame([], $this->ids()->currentIds($this->ownerHash));
        $this->assertSame([], $this->ids()->currentIds('session_0123456789abcdef'));
    }

    public function test_a_version_that_cannot_be_decrypted_is_skipped(): void
    {
        Log::spy();
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $holiday = $this->announcement('Libur Nasional', 'Kampus libur 28 Oktober.');
        $this->archive([$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $unreadable = $this->archive([$this->beforeStableIds($holiday)], '2026-10-02 08:00:00');
        DB::table('portal_archive_versions')->where('id', $unreadable->id)->update(['payload' => 'not-encrypted']);

        $this->assertSame([$exam['legacy_id'] => $exam['id']], $this->ids()->currentIds($this->ownerHash));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Archived announcements could not be decrypted');
    }

    public function test_a_new_archived_version_is_read_at_once(): void
    {
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $edited = $this->announcement('Jadwal UTS Ganjil', 'UTS diundur ke 2 November.');
        $this->archive([$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $this->assertSame([$exam['legacy_id'] => $exam['id']], $this->ids()->currentIds($this->ownerHash));

        $this->archive([$edited], '2026-10-03 08:00:00');

        $this->assertSame($edited['id'], $this->ids()->currentIds($this->ownerHash)[$edited['legacy_id']] ?? null);
    }

    public function test_current_id_of_a_link(): void
    {
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $holiday = $this->announcement('Libur Nasional', 'Kampus libur 28 Oktober.');
        $this->archive([$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'announcements', ['items' => [$holiday]]);

        // From the archive, and from the feed snapshot.
        $this->assertSame($exam['id'], $this->ids()->currentId(self::TOKEN, $exam['legacy_id']));
        $this->assertSame($holiday['id'], $this->ids()->currentId(self::TOKEN, $holiday['legacy_id']));
        // Ids that are current, unknown or malformed have nothing to follow.
        $this->assertNull($this->ids()->currentId(self::TOKEN, $holiday['id']));
        $this->assertNull($this->ids()->currentId(self::TOKEN, $exam['id']));
        $this->assertNull($this->ids()->currentId(self::TOKEN, str_repeat('c', 40)));
        $this->assertNull($this->ids()->currentId(self::TOKEN, 'not-an-id'));
    }

    public function test_the_newest_trusted_copy_is_found_by_any_id_it_went_by(): void
    {
        $original = $this->announcement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        $edited = $this->announcement('Pembayaran UKT Tahap 2', 'Batas pembayaran diundur ke 27 Oktober.');
        $this->archive([$this->beforeStableIds($original)], '2026-10-01 08:00:00');
        $this->archive([$edited], '2026-10-10 08:00:00');
        $this->archive([$this->announcement('Pembayaran UKT Tahap 2', 'Isi yang janggal.')], '2026-10-12 08:00:00', status: PortalArchiveVersion::STATUS_SUSPECT);

        $byCurrentId = $this->ids()->archivedCopy(self::TOKEN, [$edited['id'], $original['legacy_id']]);
        $byOldWording = $this->ids()->archivedCopy(self::TOKEN, [$original['legacy_id']]);

        $this->assertSame('Batas pembayaran diundur ke 27 Oktober.', $byCurrentId['item']['body_text']);
        $this->assertSame($edited['id'], $byCurrentId['item']['id']);
        $this->assertSame(CarbonImmutable::parse('2026-10-10 08:00:00', config('app.timezone'))->toISOString(), $byCurrentId['seen_at']);
        // The version that still had that wording; an item kept before stable ids gets its current id.
        $this->assertSame('Batas pembayaran 20 Oktober.', $byOldWording['item']['body_text']);
        $this->assertSame($original['id'], $byOldWording['item']['id']);
        $this->assertNull($this->ids()->archivedCopy(self::TOKEN, [str_repeat('c', 40)]));
    }

    public function test_every_notification_linking_an_old_id_follows_it_and_nothing_else_changes(): void
    {
        $oldId = str_repeat('e', 40);
        $currentId = str_repeat('a', 40);
        $announced = $this->notification($this->ownerHash, 'announcement_new', 'announcements', $oldId, '/announcement/'.$oldId);
        $reminder = $this->notification($this->ownerHash, 'deadline_reminder', 'announcements', 'announcement-'.$oldId, '/announcement/'.$oldId.'?from=reminder#tenggat');
        $suspect = $this->notification($this->ownerHash, 'archive_suspect', 'announcements', 'version:7', '/archive/announcements?version=7');
        $elsewhere = $this->notification($this->ownerHash, 'portal_context_changed', 'portal_context', $oldId, '/announcement/'.$oldId);
        $otherAnnouncement = $this->notification($this->ownerHash, 'announcement_new', 'announcements', str_repeat('b', 40), '/announcement/'.str_repeat('b', 40));
        $otherStudent = $this->notification('student_someone_else', 'announcement_new', 'announcements', $oldId, '/announcement/'.$oldId);
        $dedupeKeys = PortalNotification::query()->orderBy('id')->pluck('dedupe_key')->all();

        $this->assertSame(3, $this->ids()->rekeyNotifications($this->ownerHash, [$oldId => $currentId]));

        $this->assertSame([$currentId, '/announcement/'.$currentId], [$announced->fresh()->record_key, $announced->fresh()->url]);
        $this->assertSame(['announcement-'.$currentId, '/announcement/'.$currentId.'?from=reminder#tenggat'], [$reminder->fresh()->record_key, $reminder->fresh()->url]);
        // Linked only by its link: the record key is another dataset's.
        $this->assertSame([$oldId, '/announcement/'.$currentId], [$elsewhere->fresh()->record_key, $elsewhere->fresh()->url]);
        $this->assertSame(['version:7', '/archive/announcements?version=7'], [$suspect->fresh()->record_key, $suspect->fresh()->url]);
        $this->assertSame('/announcement/'.str_repeat('b', 40), $otherAnnouncement->fresh()->url);
        $this->assertSame([$oldId, '/announcement/'.$oldId], [$otherStudent->fresh()->record_key, $otherStudent->fresh()->url]);
        $this->assertSame($dedupeKeys, PortalNotification::query()->orderBy('id')->pluck('dedupe_key')->all());

        // Running it again changes nothing.
        $this->assertSame(0, $this->ids()->rekeyNotifications($this->ownerHash, [$oldId => $currentId]));
    }

    public function test_malformed_or_unchanged_ids_rekey_nothing(): void
    {
        $oldId = str_repeat('e', 40);
        $announced = $this->notification($this->ownerHash, 'announcement_new', 'announcements', $oldId, '/announcement/'.$oldId);

        $this->assertSame(0, $this->ids()->rekeyNotifications($this->ownerHash, [$oldId => $oldId, 'not-an-id' => str_repeat('a', 40), $oldId.'x' => str_repeat('a', 40)]));
        $this->assertSame(0, $this->ids()->rekeyNotifications($this->ownerHash, [$oldId => '/evil']));
        $this->assertSame('/announcement/'.$oldId, $announced->fresh()->url);
    }

    public function test_notifications_being_read_follow_the_archive_and_others_are_left_for_later(): void
    {
        $exam = $this->announcement('Jadwal UTS Ganjil', 'UTS mulai 26 Oktober.');
        $this->archive([$this->beforeStableIds($exam)], '2026-10-01 08:00:00');
        $read = $this->notification($this->ownerHash, 'deadline_reminder', 'announcements', 'announcement-'.$exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $notRead = $this->notification($this->ownerHash, 'announcement_new', 'announcements', $exam['legacy_id'], '/announcement/'.$exam['legacy_id']);
        $unrelated = $this->notification($this->ownerHash, 'krs_validated', 'krs', null, '/krs');

        $this->assertSame(1, $this->ids()->followIn($this->ownerHash, [$read, $unrelated]));

        // The model read is updated in place.
        $this->assertSame('/announcement/'.$exam['id'], $read->url);
        $this->assertSame('/announcement/'.$exam['id'], $read->fresh()->url);
        $this->assertSame('/announcement/'.$exam['legacy_id'], $notRead->fresh()->url);
        $this->assertSame(0, $this->ids()->followIn($this->ownerHash, [$unrelated]));
    }

    private function ids(): PortalAnnouncementIds
    {
        return app(PortalAnnouncementIds::class);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function archive(array $items, string $lastSeenAt, ?string $ownerHash = null, string $status = PortalArchiveVersion::STATUS_TRUSTED): PortalArchiveVersion
    {
        return PortalArchiveVersion::factory()
            ->forOwner($ownerHash ?? $this->ownerHash)
            ->withPayload(['items' => $items, 'paging' => ['pages_loaded' => 3, 'last_page' => 3]])
            ->create([
                'dataset' => 'announcements',
                'status' => $status,
                'first_seen_at' => $lastSeenAt,
                'last_seen_at' => $lastSeenAt,
                'trusted_at' => $status === PortalArchiveVersion::STATUS_TRUSTED ? $lastSeenAt : null,
            ]);
    }

    /**
     * An announcement as the feed gives it now: stable id, wording-based id kept as `legacy_id`.
     *
     * @return array<string, mixed>
     */
    private function announcement(string $title, string $body): array
    {
        return [
            'id' => AnnouncementExtractor::stableId($title, '2026-10-15', self::UNIT),
            'legacy_id' => AnnouncementExtractor::legacyId($title, '2026-10-15', self::UNIT, $body),
            'title' => $title,
            'published_date' => '2026-10-15',
            'publisher_unit' => self::UNIT,
            'publisher_unit_short' => 'BAAK',
            'excerpt' => $body,
            'body_text' => $body,
            'body_html' => '<p>'.$body.'</p>',
            'deadlines' => [],
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

    private function notification(string $ownerHash, string $type, ?string $dataset, ?string $recordKey, ?string $url): PortalNotification
    {
        return PortalNotification::factory()->forOwner($ownerHash)->create([
            'type' => $type,
            'dataset' => $dataset,
            'record_key' => $recordKey,
            'url' => $url,
        ]);
    }
}
