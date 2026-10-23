<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Announcement ids no longer change when staff edit the wording; links and
 * read marks saved under the old wording-based id (`legacy_id`) keep working.
 */
class AnnouncementPagesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-announcement-pages';

    private const USERNAME = '20990000051';

    private const UNIT = 'Biro Administrasi Akademik (BAAK)';

    private const LEGACY_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_list_counts_an_announcement_read_under_its_old_id_as_read(): void
    {
        $this->mockValidCampusSession();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement')
            ->assertOk()
            ->assertSee('const isRead = (item) => app.isAnnouncementRead(state.readIds, item);', false)
            ->assertSee('const unread = !isRead(item);', false)
            ->assertSee('const unreadCount = items.filter((item) => !isRead(item)).length;', false);
    }

    /**
     * The behaviour itself runs in tests/js/announcement-pages.test.mjs; these
     * pin the calls that run it on each page.
     */
    public function test_every_page_moves_read_marks_saved_under_the_old_id_to_the_stable_id(): void
    {
        $this->mockValidCampusSession();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement')
            ->assertOk()
            ->assertSee('const { ids, changed } = app.migrateAnnouncementReadIds(Array.from(state.readIds), items);', false)
            ->assertSeeInOrder(['if (!state.readLoaded) {', 'loadReadIds(allItems());', '} else {', 'migrateReadIds(allItems());'], false)
            ->assertSeeInOrder(['added = appendPage(payload);', 'migrateReadIds(allItems());'], false);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement/'.self::LEGACY_ID)
            ->assertOk()
            ->assertSee('const { ids, changed } = app.migrateAnnouncementReadIds(stored?.ids, [item]);', false)
            ->assertSee('markRead(item);', false)
            ->assertSee('const { record, changed } = app.migrateAnnouncementRecord(storage.read(STEPS_KEY), item);', false)
            ->assertSee('const done = new Set(readSteps(item));', false);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('const { ids, changed } = app.migrateAnnouncementReadIds(stored?.ids, feedItems);', false)
            ->assertSee('const readIds = readAnnouncementIds(feedItems);', false)
            ->assertSee('const isNew = !app.isAnnouncementRead(readIds, item) &&', false)
            ->assertSee('read: items.map((item) => app.isAnnouncementRead(readIds, item))', false);
    }

    public function test_list_shows_an_announcement_once_even_under_two_ids(): void
    {
        $this->mockValidCampusSession();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement')
            ->assertOk()
            ->assertSee('const itemKey = (item) => item.legacy_id || item.id;', false)
            ->assertSee('seen.has(itemKey(item))', false)
            ->assertSee('const known = new Set(allItems().map(itemKey));', false);
    }

    public function test_list_gives_a_later_page_item_its_own_id_when_an_earlier_one_has_it(): void
    {
        $this->mockValidCampusSession();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement')
            ->assertOk()
            ->assertSee('const knownIds = new Set(allItems().map((item) => item.id));', false)
            ->assertSee('const entry = knownIds.has(item.id) && item.legacy_id ? { ...item, id: item.legacy_id } : item;', false)
            ->assertSee('state.extraItems.push(entry);', false)
            ->assertSee('knownIds.add(entry.id);', false);
    }

    public function test_detail_page_opens_an_old_link_and_shows_the_current_address(): void
    {
        $this->mockValidCampusSession();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement/'.self::LEGACY_ID)
            ->assertOk()
            ->assertSee('data-announcement-id="'.self::LEGACY_ID.'"', false)
            ->assertSee('items.find((entry) => entry?.id === announcementId) || items.find((entry) => entry?.legacy_id === announcementId)', false)
            ->assertSee('showCanonicalUrl(item);', false)
            ->assertSee("window.history.replaceState(window.history.state, '', `/announcement/\${item.id}\${window.location.search}\${window.location.hash}`);", false);
    }

    public function test_old_link_redirects_to_the_id_the_announcement_goes_by_now(): void
    {
        $ownerHash = $this->rememberStudent();
        $this->mockValidCampusSession();
        $original = $this->announcement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        // Archived before stable ids, then it left the feed.
        PortalArchiveVersion::factory()->forOwner($ownerHash)->withPayload(['items' => [[...$original, 'id' => $original['legacy_id'], 'legacy_id' => null]]])->create(['dataset' => 'announcements']);
        $announced = PortalNotification::factory()->forOwner($ownerHash)->create([
            'type' => 'announcement_new',
            'record_key' => $original['legacy_id'],
            'url' => '/announcement/'.$original['legacy_id'],
        ]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement/'.$original['legacy_id'].'?from=notification')
            ->assertRedirect('/announcement/'.$original['id'].'?from=notification');

        $this->assertSame([$original['id'], '/announcement/'.$original['id']], [$announced->fresh()->record_key, $announced->fresh()->url]);
    }

    public function test_old_link_to_an_announcement_on_the_feed_redirects_to_its_current_id(): void
    {
        $this->rememberStudent();
        $this->mockValidCampusSession();
        $edited = $this->announcement('Pembayaran UKT Tahap 2', 'Batas pembayaran diundur ke 27 Oktober.');
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'announcements', ['items' => [$edited]]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement/'.$edited['legacy_id'])
            ->assertRedirect('/announcement/'.$edited['id']);

        // The id it goes by now opens the page.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement/'.$edited['id'])
            ->assertOk()
            ->assertSee('data-announcement-id="'.$edited['id'].'"', false);
    }

    public function test_an_announcement_falling_back_to_its_wording_id_is_not_redirected(): void
    {
        $this->rememberStudent();
        $this->mockValidCampusSession();
        $first = $this->announcement('Surat Pengantar Magang', 'Gelombang pertama.');
        $second = $this->announcement('Surat Pengantar Magang', 'Gelombang kedua.');
        // Same title, day and unit: both go by their wording-based ids.
        app(PortalSnapshotStore::class)->put(self::TOKEN, 'announcements', ['items' => AnnouncementExtractor::withStableIds([$second, $first])]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->get('/announcement/'.$first['legacy_id'])
            ->assertOk()
            ->assertSee('data-announcement-id="'.$first['legacy_id'].'"', false);
    }

    private function rememberStudent(): string
    {
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);

        return app(PortalDataOwner::class)->key(self::TOKEN);
    }

    /**
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

    private function mockValidCampusSession(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
        }));
    }
}
