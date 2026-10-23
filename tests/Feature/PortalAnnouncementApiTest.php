<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalAnnouncementFeed;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalAnnouncementApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-announcement';

    private const USERNAME = '20990000041';

    private const FEED_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const PAGE_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const LEGACY_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalDataJob::class]);
        $this->travelTo(CarbonImmutable::create(2026, 10, 20, 9, 0, 0, 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_feed_returns_items_and_recomputed_upcoming_deadlines(): void
    {
        $this->mockValidCampusSession();
        $this->instance(PortalAnnouncementFeed::class, Mockery::mock(PortalAnnouncementFeed::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->with(self::TOKEN)->andReturn([
                ...$this->feedData(),
                '_sync' => ['data_origin' => 'upstream'],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement')
            ->assertOk()
            ->assertJsonPath('data.mode', 'feed')
            ->assertJsonPath('data.items.0.id', self::FEED_ID)
            ->assertJsonPath('data.items.0.category', 'krs')
            ->assertJsonPath('data.paging.next_page', 4)
            ->assertJsonCount(1, 'data.upcoming_deadlines')
            ->assertJsonPath('data.upcoming_deadlines.0.date', '2026-10-23')
            ->assertJsonPath('data.upcoming_deadlines.0.days_left', 3)
            ->assertJsonPath('data.upcoming_deadlines.0.is_ongoing', true)
            ->assertJsonPath('meta.status', 'synced');
    }

    public function test_feed_snapshot_recomputes_time_flags_the_next_day(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $this->travelTo(CarbonImmutable::create(2026, 10, 24, 8, 0, 0, 'Asia/Jakarta'));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement')
            ->assertOk()
            // The snapshot is stale by now: still served, re-synced in the background.
            ->assertJsonPath('meta.status', 'syncing')
            ->assertJsonPath('data.upcoming_deadlines', [])
            ->assertJsonPath('data.items.0.deadlines.0.is_future', false)
            ->assertJsonPath('data.notice.is_future', false);
    }

    public function test_a_payment_notice_says_whether_its_stage_is_already_paid(): void
    {
        $this->mockValidCampusSession();
        $notice = ['text' => 'Kewajiban Pembayaran Kuliah Tahap ke 1 paling lambat 2 November 2026', 'dates' => ['2026-11-02'], 'is_future' => true, 'kind' => 'payment'];
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'notice' => $notice]);

        // No payments snapshot yet: unknown, and its build is queued in the background.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement')
            ->assertOk()
            ->assertJsonPath('data.notice.stage_paid', null);
        Bus::assertDispatched(RefreshPortalDataJob::class, fn (RefreshPortalDataJob $job): bool => in_array('payments', (fn (): array => $this->domains)->call($job), true));

        $this->snapshots()->put(self::TOKEN, 'payments', [
            'student_info' => ['academic_year' => '2026/2027'],
            'payment_history' => [[
                'no' => '1', 'paid_at' => '2026-09-05', 'paid_at_label' => '05 September 2026', 'category' => 'Pembayaran UKT',
                'amount' => 1250000, 'installment_stage' => 1, 'study_year' => 1, 'installment_amount' => 1250000,
            ]],
            'summary' => ['history_available' => true],
            'outstanding_bills' => [],
            'outstanding_summary' => ['status' => 'clear', 'count' => 0],
        ]);

        // Stage 1 is recorded as paid: the page hides this reminder (K5).
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement')
            ->assertOk()
            ->assertJsonPath('data.notice.stage_paid', true);

        // A notice without a stage number is never judged.
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement')
            ->assertOk()
            ->assertJsonPath('data.notice.stage_paid', null);
    }

    public function test_page_parameter_returns_exactly_that_portal_page(): void
    {
        $this->mockValidCampusSession();
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 4)->andReturn($this->pageData(4));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement?page=4')
            ->assertOk()
            ->assertJsonPath('data.mode', 'page')
            ->assertJsonPath('data.items.0.id', self::PAGE_ID)
            ->assertJsonPath('data.items.0.deadlines.0.is_future', true)
            ->assertJsonPath('data.paging.page', 4)
            ->assertJsonPath('data.paging.next_page', 5)
            ->assertJsonMissingPath('data.paging.next_url')
            ->assertJsonPath('meta.page', 4);

        $this->assertNotNull($this->snapshots()->get(self::TOKEN, 'announcement_page', ['page' => 4]));
    }

    public function test_page_parameter_is_validated(): void
    {
        foreach (['0' => 'Nomor halaman minimal 1.', 'dua' => 'Nomor halaman harus berupa angka.', '501' => 'Nomor halaman maksimal 500.'] as $page => $message) {
            $this->mockValidCampusSession();

            $this->withSession(['campus_session_token' => self::TOKEN])
                ->getJson("/api/announcement?page={$page}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['page'])
                ->assertJsonPath('message', $message);
        }
    }

    public function test_detail_is_resolved_from_the_feed_or_a_loaded_page(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        // Page 4 is read from the portal once ("Muat lagi"); the detail reuses that read.
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 4)->andReturn($this->pageData(4));
        }));
        $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/announcement?page=4')->assertOk();

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.self::FEED_ID)
            ->assertOk()
            ->assertJsonPath('data.title', 'Pengisian Her-Registrasi dan KRS')
            ->assertJsonPath('data.body_html', '<p>Isi pengumuman uji.</p>')
            // A portal action link never reaches the browser (K13).
            ->assertJsonCount(1, 'data.links')
            ->assertJsonPath('data.links.0.kind', 'form');

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.self::PAGE_ID)
            ->assertOk()
            ->assertJsonPath('data.title', 'Pengumuman halaman empat')
            ->assertJsonPath('meta.page', 4);
    }

    public function test_detail_past_the_feed_is_looked_up_on_the_following_portal_pages(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 4)->andReturn($this->pageData(4, str_repeat('d', 40)));
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 5)->andReturn($this->pageData(5));
        }));

        // An old notification link: the announcement moved down to portal page 5.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.self::PAGE_ID)
            ->assertOk()
            ->assertJsonPath('data.id', self::PAGE_ID)
            ->assertJsonPath('meta.page', 5);
    }

    public function test_detail_lookup_reads_only_a_few_pages_past_the_feed(): void
    {
        config(['portal.datasets.announcement_page.detail_lookup_pages' => 2]);
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'paging' => [...$this->feedData()['paging'], 'last_page' => 100, 'total' => 300]]);
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->twice()->andReturnUsing(fn (string $token, int $page): array => $this->pageData($page, str_repeat('d', 40)));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('c', 40))
            ->assertNotFound()
            ->assertJsonPath('message', 'Pengumuman tidak ditemukan. Bisa jadi sudah diperbarui atau dihapus di portal, atau sudah terlalu lama.');

        // The pages read are kept: asking again costs no portal request.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('c', 40))
            ->assertNotFound();
    }

    public function test_detail_from_a_page_loaded_past_the_lookup_window_opens_without_the_portal(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'paging' => [...$this->feedData()['paging'], 'last_page' => 28, 'total' => 84]]);
        // Pages 4-7 load on scroll and page 8 with one more "Muat pengumuman lama": each read once.
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->times(5)->andReturnUsing(
                fn (string $token, int $page): array => $this->pageData($page, $page === 8 ? self::PAGE_ID : str_repeat(dechex($page), 40))
            );
        }));

        foreach ([4, 5, 6, 7, 8] as $page) {
            $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/announcement?page='.$page)->assertOk();
        }

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.self::PAGE_ID)
            ->assertOk()
            ->assertJsonPath('data.id', self::PAGE_ID)
            ->assertJsonPath('meta.page', 8)
            ->assertJsonPath('meta.data_origin', 'snapshot');

        // Past the last loaded page the search ends: page 9 is not read from the portal.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('c', 40))
            ->assertNotFound();
    }

    public function test_detail_lookup_on_an_unreachable_portal_is_not_reported_as_missing(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->andThrow(new \RuntimeException('cURL error 28: timed out'));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.self::PAGE_ID)
            ->assertStatus(503)
            ->assertJsonPath('message', 'Pengumuman ini tidak ada di halaman terbaru, dan portal belum bisa dihubungi untuk mencarinya. Coba lagi beberapa saat.');
    }

    public function test_page_read_before_new_posts_is_not_continued_after_them(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->twice()->with(self::TOKEN, 4)->andReturn($this->pageData(4));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/announcement?page=4')->assertOk();
        $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/announcement?page=4')->assertOk();

        // Two new posts shift every page: page 4 of the new feed is read anew.
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'paging' => [...$this->feedData()['paging'], 'total' => 16]]);
        $this->withSession(['campus_session_token' => self::TOKEN])->getJson('/api/announcement?page=4')->assertOk();
    }

    public function test_detail_opens_from_an_old_link_carrying_the_wording_based_id(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', [
            ...$this->feedData(),
            'items' => [[...$this->feedData()['items'][0], 'legacy_id' => self::LEGACY_ID]],
        ]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.self::LEGACY_ID)
            ->assertOk()
            ->assertJsonPath('data.id', self::FEED_ID)
            ->assertJsonPath('data.legacy_id', self::LEGACY_ID)
            ->assertJsonPath('data.title', 'Pengisian Her-Registrasi dan KRS');
    }

    public function test_detail_opens_from_a_stable_id_given_before_a_same_day_repost(): void
    {
        $this->mockValidCampusSession();
        // A second post with the same title, day and unit made both fall back to their legacy ids.
        $newer = [...$this->announcement(str_repeat('1', 40), 'Surat Pengantar Magang'), 'legacy_id' => str_repeat('1', 40), 'body_text' => 'Versi kedua.'];
        $older = [...$this->announcement(str_repeat('2', 40), 'Surat Pengantar Magang'), 'legacy_id' => str_repeat('2', 40), 'body_text' => 'Versi pertama.'];
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'items' => [$newer, $older]]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.AnnouncementExtractor::stableId('Surat Pengantar Magang', '2026-10-15', 'Biro Administrasi Akademik (BAAK)'))
            ->assertOk()
            ->assertJsonPath('data.id', str_repeat('2', 40))
            ->assertJsonPath('data.body_text', 'Versi pertama.');
    }

    public function test_page_item_sharing_a_stable_id_with_the_feed_is_told_apart(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', [
            ...$this->feedData(),
            'items' => [[...$this->feedData()['items'][0], 'legacy_id' => self::LEGACY_ID]],
        ]);
        // Page 4 is read on its own: its announcement got the feed item's stable id.
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 4)->andReturn([
                ...$this->pageData(4, self::FEED_ID),
                'items' => [[...$this->announcement(self::FEED_ID, 'Pengisian Her-Registrasi dan KRS'), 'legacy_id' => self::PAGE_ID, 'body_text' => 'Pengumuman lain.']],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement?page=4')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', self::PAGE_ID);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.self::PAGE_ID)
            ->assertOk()
            ->assertJsonPath('data.id', self::PAGE_ID)
            ->assertJsonPath('data.body_text', 'Pengumuman lain.')
            ->assertJsonPath('meta.page', 4);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.self::FEED_ID)
            ->assertOk()
            ->assertJsonPath('data.body_text', 'Isi pengumuman uji.');
    }

    public function test_same_day_announcements_split_across_later_pages_each_open_their_own_detail(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $stableId = $this->internshipStableId();
        // Each page is read on its own, so each gives its announcement the stable id.
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock) use ($stableId): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 4)->andReturn([
                ...$this->pageData(4),
                'items' => [$this->internshipLetter($stableId, str_repeat('1', 40), 'Versi kedua.')],
            ]);
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 5)->andReturn([
                ...$this->pageData(5),
                'items' => [$this->internshipLetter($stableId, str_repeat('2', 40), 'Versi pertama.')],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement?page=4')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $stableId);

        // The one listed first keeps the stable id; the later one opens by its wording.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement?page=5')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', str_repeat('2', 40));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.$stableId)
            ->assertOk()
            ->assertJsonPath('data.body_text', 'Versi kedua.')
            ->assertJsonPath('meta.page', 4);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('2', 40))
            ->assertOk()
            ->assertJsonPath('data.id', str_repeat('2', 40))
            ->assertJsonPath('data.body_text', 'Versi pertama.')
            ->assertJsonPath('meta.page', 5);
    }

    public function test_page_item_sharing_a_stable_id_with_a_feed_pair_on_wording_ids_is_told_apart(): void
    {
        $this->mockValidCampusSession();
        $stableId = $this->internshipStableId();
        // Two posts in the feed share the stable id, so both use their wording-based ids.
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'items' => [
            $this->internshipLetter(str_repeat('1', 40), str_repeat('1', 40), 'Versi kedua.'),
            $this->internshipLetter(str_repeat('2', 40), str_repeat('2', 40), 'Versi pertama.'),
        ]]);
        // A third one, past the feed, is alone on its page and got the stable id there.
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock) use ($stableId): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 4)->andReturn([
                ...$this->pageData(4),
                'items' => [$this->internshipLetter($stableId, str_repeat('3', 40), 'Versi lama.')],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement?page=4')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', str_repeat('3', 40));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('3', 40))
            ->assertOk()
            ->assertJsonPath('data.body_text', 'Versi lama.')
            ->assertJsonPath('meta.page', 4);
    }

    public function test_announcement_read_again_on_a_later_page_keeps_its_feed_id(): void
    {
        $this->mockValidCampusSession();
        $stableId = $this->internshipStableId();
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'items' => [
            $this->internshipLetter($stableId, str_repeat('1', 40), 'Versi kedua.'),
        ]]);
        // Shifted to page 4 next to its same-day namesake, so that page gave both their wording-based ids.
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 4)->andReturn([
                ...$this->pageData(4),
                'items' => [
                    $this->internshipLetter(str_repeat('1', 40), str_repeat('1', 40), 'Versi kedua.'),
                    $this->internshipLetter(str_repeat('2', 40), str_repeat('2', 40), 'Versi pertama.'),
                ],
            ]);
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement?page=4')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $stableId)
            ->assertJsonPath('data.items.1.id', str_repeat('2', 40));
    }

    public function test_old_link_to_an_announcement_edited_after_it_left_the_feed_is_found_through_the_archive(): void
    {
        $ownerHash = $this->rememberStudent();
        $this->mockValidCampusSession();
        $original = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        $edited = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Batas pembayaran diundur ke 27 Oktober.');
        $this->archive($ownerHash, [$this->beforeStableIds($original)], '2026-10-01 08:00:00');
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        // It moved down to page 4, edited: neither its old id nor its old wording is there.
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock) use ($edited): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 4)->andReturn([...$this->pageData(4), 'items' => [$edited]]);
        }));
        $reminder = PortalNotification::factory()->forOwner($ownerHash)->create([
            'type' => 'deadline_reminder',
            'record_key' => 'announcement-'.$original['legacy_id'],
            'url' => '/announcement/'.$original['legacy_id'],
        ]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.$original['legacy_id'])
            ->assertOk()
            ->assertJsonPath('data.id', $edited['id'])
            ->assertJsonPath('data.body_text', 'Batas pembayaran diundur ke 27 Oktober.')
            ->assertJsonPath('meta.page', 4);

        // The student's notification of it follows to the id it goes by now.
        $this->assertSame(['announcement-'.$edited['id'], '/announcement/'.$edited['id']], [$reminder->fresh()->record_key, $reminder->fresh()->url]);
        $this->assertSame(1, PortalNotification::query()->count());
    }

    public function test_old_link_to_an_announcement_edited_on_the_feed_opens_without_the_portal(): void
    {
        $ownerHash = $this->rememberStudent();
        $this->mockValidCampusSession();
        $original = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        $edited = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Batas pembayaran diundur ke 27 Oktober.');
        $this->archive($ownerHash, [$this->beforeStableIds($original)], '2026-10-01 08:00:00');
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'items' => [$edited]]);
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractAnnouncementsData');
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.$original['legacy_id'])
            ->assertOk()
            ->assertJsonPath('data.id', $edited['id'])
            ->assertJsonPath('data.legacy_id', $edited['legacy_id'])
            ->assertJsonPath('meta.data_origin', 'snapshot');
    }

    public function test_announcement_past_the_pages_searched_is_served_from_the_archive(): void
    {
        $ownerHash = $this->rememberStudent();
        $this->mockValidCampusSession();
        $original = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        $edited = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Batas pembayaran diundur ke 27 Oktober.');
        $this->archive($ownerHash, [$this->beforeStableIds($original)], '2026-10-01 08:00:00');
        $this->archive($ownerHash, [$edited], '2026-10-10 08:00:00');
        // Every portal page is in the feed: there is nothing further to search.
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'paging' => [...$this->feedData()['paging'], 'pages_loaded' => 5, 'has_more' => false, 'next_page' => null]]);

        foreach ([$original['legacy_id'], $edited['id']] as $id) {
            $this->withSession(['campus_session_token' => self::TOKEN])
                ->getJson('/api/announcement/'.$id)
                ->assertOk()
                ->assertJsonPath('data.id', $edited['id'])
                ->assertJsonPath('data.body_text', 'Batas pembayaran diundur ke 27 Oktober.')
                ->assertJsonPath('meta.data_origin', 'archive')
                ->assertJsonPath('meta.status', 'synced')
                ->assertJsonPath('meta.synced_at', CarbonImmutable::parse('2026-10-10 08:00:00', config('app.timezone'))->toISOString());
        }
    }

    public function test_archived_copy_is_served_when_the_portal_cannot_be_searched(): void
    {
        $ownerHash = $this->rememberStudent();
        $this->mockValidCampusSession();
        $original = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        $this->archive($ownerHash, [$this->beforeStableIds($original)], '2026-10-01 08:00:00');
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractAnnouncementsData')->once()->andThrow(new \RuntimeException('cURL error 28: timed out'));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.$original['legacy_id'])
            ->assertOk()
            ->assertJsonPath('data.id', $original['id'])
            ->assertJsonPath('meta.data_origin', 'archive')
            ->assertJsonPath('meta.status', 'failed');
    }

    public function test_a_held_back_archived_version_is_never_served_as_a_copy(): void
    {
        $ownerHash = $this->rememberStudent();
        $this->mockValidCampusSession();
        $suspect = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Isi yang janggal.');
        $this->archive($ownerHash, [$suspect], '2026-10-10 08:00:00', PortalArchiveVersion::STATUS_SUSPECT);
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'paging' => [...$this->feedData()['paging'], 'pages_loaded' => 5, 'has_more' => false, 'next_page' => null]]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.$suspect['id'])
            ->assertNotFound();
    }

    public function test_old_link_opened_offline_is_served_from_the_archive(): void
    {
        $ownerHash = $this->rememberStudent();
        $this->mockOfflineCampusSession();
        $original = $this->wordedAnnouncement('Pembayaran UKT Tahap 2', 'Batas pembayaran 20 Oktober.');
        $this->archive($ownerHash, [$this->beforeStableIds($original)], '2026-10-01 08:00:00');
        // It left the feed; portal page 4 was never stored, and the portal is not reachable.
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractAnnouncementsData');
        }));
        $reminder = PortalNotification::factory()->forOwner($ownerHash)->create([
            'type' => 'deadline_reminder',
            'record_key' => 'announcement-'.$original['legacy_id'],
            'url' => '/announcement/'.$original['legacy_id'],
        ]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.$original['legacy_id'])
            ->assertOk()
            ->assertJsonPath('data.id', $original['id'])
            ->assertJsonPath('data.body_text', 'Batas pembayaran 20 Oktober.')
            ->assertJsonPath('meta.data_origin', 'archive')
            ->assertJsonPath('meta.status', 'offline')
            ->assertJsonPath('meta.synced_at', CarbonImmutable::parse('2026-10-01 08:00:00', config('app.timezone'))->toISOString());

        $this->assertSame(['announcement-'.$original['id'], '/announcement/'.$original['id']], [$reminder->fresh()->record_key, $reminder->fresh()->url]);
    }

    public function test_old_link_opened_offline_that_was_never_archived_says_so(): void
    {
        $this->rememberStudent();
        $this->mockOfflineCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractAnnouncementsData');
        }));

        // Page 4 would be read from the portal online: offline it is not known yet.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('c', 40))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Data ini belum tersimpan di arsip MyCIC, jadi belum bisa dibuka selama mode offline.')
            ->assertJsonPath('meta.offline', true);

        // Every portal page is in the feed: there is nothing the portal could add.
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'paging' => [...$this->feedData()['paging'], 'pages_loaded' => 5, 'has_more' => false, 'next_page' => null]]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('c', 40))
            ->assertNotFound()
            ->assertJsonPath('message', 'Pengumuman tidak ditemukan. Bisa jadi sudah diperbarui atau dihapus di portal, atau sudah terlalu lama.');
    }

    public function test_offline_lookup_searches_the_pages_stored_before(): void
    {
        config(['portal.datasets.announcement_page.detail_lookup_pages' => 1]);
        $this->rememberStudent();
        $this->mockOfflineCampusSession();
        $feed = $this->feedData();
        $this->snapshots()->put(self::TOKEN, 'announcements', $feed);
        // Pages 4 and 5 were loaded with "Muat lagi" before the portal went down.
        $this->snapshots()->put(self::TOKEN, 'announcement_page', $this->pageData(4, str_repeat('d', 40)), null, $this->pageParams(4, $feed));
        $this->snapshots()->put(self::TOKEN, 'announcement_page', $this->pageData(5), null, $this->pageParams(5, $feed));
        $this->instance(AnnouncementExtractor::class, Mockery::mock(AnnouncementExtractor::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractAnnouncementsData');
        }));

        foreach ([4 => str_repeat('d', 40), 5 => self::PAGE_ID] as $page => $id) {
            $this->withSession(['campus_session_token' => self::TOKEN])
                ->getJson('/api/announcement/'.$id)
                ->assertOk()
                ->assertJsonPath('data.id', $id)
                ->assertJsonPath('meta.page', $page)
                ->assertJsonPath('meta.data_origin', 'snapshot')
                ->assertJsonPath('meta.status', 'offline');
        }

        // Past the last stored page the search ends without the portal: every page was searched.
        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('c', 40))
            ->assertNotFound();
    }

    public function test_detail_answers_404_for_an_unknown_or_malformed_id(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', [...$this->feedData(), 'paging' => [...$this->feedData()['paging'], 'pages_loaded' => 5, 'has_more' => false, 'next_page' => null]]);

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('c', 40))
            ->assertNotFound()
            ->assertJsonPath('message', 'Pengumuman tidak ditemukan. Bisa jadi sudah diperbarui atau dihapus di portal, atau sudah terlalu lama.');

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/not-an-id')
            ->assertNotFound();
    }

    public function test_announcements_require_a_valid_campus_session(): void
    {
        $this->getJson('/api/announcement')->assertUnauthorized()->assertJsonPath('meta.campus_session_valid', false);

        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->once()->with('expired-token')->andReturnFalse();
        }));

        $this->withSession(['campus_session_token' => 'expired-token'])
            ->getJson('/api/announcement/'.self::FEED_ID)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi kampus sudah berakhir. Silakan masuk lagi.');
    }

    public function test_upstream_session_expiry_while_reading_the_feed_is_unauthorized(): void
    {
        $this->mockValidCampusSession();
        $this->instance(PortalAnnouncementFeed::class, Mockery::mock(PortalAnnouncementFeed::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetch')->once()->andThrow(PortalScrapingException::pageNotAccessible('pengumuman'));
        }));

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement')
            ->assertUnauthorized()
            ->assertSessionMissing('campus_session_token');
    }

    private function mockValidCampusSession(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
        }));
    }

    /**
     * A session opened offline (the portal was unreachable at login): only snapshots and the archive are read.
     */
    private function mockOfflineCampusSession(): void
    {
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->with(self::TOKEN)->andReturnTrue();
            $mock->shouldReceive('getSession')->with(self::TOKEN)->andReturn(['offline' => true]);
        }));
    }

    /**
     * Snapshot params of a portal page past this feed, as "Muat lagi" stores it.
     *
     * @param  array<string, mixed>  $feed
     * @return array{page: int, feed: string}
     */
    private function pageParams(int $page, array $feed): array
    {
        return ['page' => $page, 'feed' => substr(sha1($feed['paging']['total'].'|'.$feed['items'][0]['id']), 0, 16)];
    }

    private function snapshots(): PortalSnapshotStore
    {
        return app(PortalSnapshotStore::class);
    }

    /**
     * Ties the session to a student (archived), before any snapshot is stored for it.
     */
    private function rememberStudent(): string
    {
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);

        return app(PortalDataOwner::class)->key(self::TOKEN);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function archive(string $ownerHash, array $items, string $lastSeenAt, string $status = PortalArchiveVersion::STATUS_TRUSTED): void
    {
        PortalArchiveVersion::factory()
            ->forOwner($ownerHash)
            ->withPayload([...$this->feedData(), 'items' => $items])
            ->create([
                'dataset' => 'announcements',
                'status' => $status,
                'first_seen_at' => $lastSeenAt,
                'last_seen_at' => $lastSeenAt,
                'trusted_at' => $status === PortalArchiveVersion::STATUS_TRUSTED ? $lastSeenAt : null,
            ]);
    }

    /**
     * An announcement with the ids its title, day, unit and wording give it.
     *
     * @return array<string, mixed>
     */
    private function wordedAnnouncement(string $title, string $bodyText): array
    {
        $unit = 'Biro Administrasi Akademik (BAAK)';

        return [
            ...$this->announcement(AnnouncementExtractor::stableId($title, '2026-10-15', $unit), $title),
            'legacy_id' => AnnouncementExtractor::legacyId($title, '2026-10-15', $unit, $bodyText),
            'excerpt' => $bodyText,
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

    /**
     * @return array<string, mixed>
     */
    private function feedData(): array
    {
        return [
            'items' => [[
                ...$this->announcement(self::FEED_ID, 'Pengisian Her-Registrasi dan KRS'),
                'page' => 1,
                'duplicate_of' => null,
            ]],
            'paging' => ['pages_loaded' => 3, 'last_page' => 5, 'total' => 14, 'has_more' => true, 'next_page' => 4, 'complete' => true, 'stopped_at_known' => false],
            'notice' => ['text' => 'Batas pembayaran 23 Oktober 2026', 'dates' => ['2026-10-23'], 'is_future' => true, 'kind' => 'payment'],
            'upcoming_deadlines' => [
                ['announcement_id' => self::FEED_ID, 'title' => 'Pengisian Her-Registrasi dan KRS', 'category' => 'krs', 'category_label' => 'KRS & Her-Registrasi', 'date' => '2026-10-23', 'start' => '2026-10-19', 'type' => 'period', 'context' => 'Pengisian 19 s/d 23 Oktober 2026', 'days_left' => 99, 'is_ongoing' => false],
                ['announcement_id' => self::FEED_ID, 'title' => 'Pengisian Her-Registrasi dan KRS', 'category' => 'krs', 'category_label' => 'KRS & Her-Registrasi', 'date' => '2026-10-10', 'start' => null, 'type' => 'deadline', 'context' => 'Sudah lewat', 'days_left' => 0, 'is_ongoing' => false],
            ],
            'generated_at' => '2026-10-20T02:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(int $page, string $id = self::PAGE_ID): array
    {
        return [
            'items' => [$this->announcement($id, 'Pengumuman halaman empat')],
            'paging' => [
                'page' => $page, 'per_page' => 3, 'from' => 10, 'to' => 10, 'total' => 14, 'last_page' => 5,
                'has_previous' => true, 'has_next' => true, 'previous_page' => $page - 1, 'next_page' => $page + 1,
                'previous_url' => 'https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=pengumuman&page=3',
                'next_url' => 'https://my.cic.ac.id/portal/mahasiswa/?mod=student&act=pengumuman&page=5',
                'out_of_range' => false,
            ],
            'notice' => null,
            'extracted_at' => '2026-10-20T02:00:00+00:00',
        ];
    }

    private function internshipStableId(): string
    {
        return AnnouncementExtractor::stableId('Surat Pengantar Magang', '2026-10-15', 'Biro Administrasi Akademik (BAAK)');
    }

    /**
     * One of several "Surat Pengantar Magang" posts made on the same day by the same unit.
     *
     * @return array<string, mixed>
     */
    private function internshipLetter(string $id, string $legacyId, string $bodyText): array
    {
        return [...$this->announcement($id, 'Surat Pengantar Magang'), 'legacy_id' => $legacyId, 'body_text' => $bodyText];
    }

    /**
     * @return array<string, mixed>
     */
    private function announcement(string $id, string $title): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'published_at' => '2026-10-15T00:00:00+07:00',
            'published_date' => '2026-10-15',
            'published_label' => 'Kamis, 15 Oktober 2026',
            'publisher' => 'Staf BAAK',
            'publisher_unit' => 'Biro Administrasi Akademik (BAAK)',
            'publisher_unit_short' => 'BAAK',
            'category' => 'krs',
            'category_label' => 'KRS & Her-Registrasi',
            'excerpt' => 'Isi pengumuman uji.',
            'body_text' => 'Isi pengumuman uji.',
            'body_html' => '<p>Isi pengumuman uji.</p>',
            'links' => [
                ['label' => 'Formulir Google', 'url' => 'https://forms.example.test/uji', 'is_file' => false, 'is_campus_file' => false, 'kind' => 'form'],
                ['label' => 'Hapus', 'url' => 'https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdel&id=1', 'is_file' => false, 'is_campus_file' => false, 'kind' => 'link'],
            ],
            'emails' => ['baak@example.test'],
            'deadlines' => [['date' => '2026-10-23', 'start' => '2026-10-19', 'type' => 'period', 'context' => 'Pengisian 19 s/d 23 Oktober 2026', 'is_future' => true]],
            'steps' => [],
        ];
    }
}
