<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalScrapingException;
use App\Jobs\RefreshPortalDataJob;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalAnnouncementFeed;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalAnnouncementApiTest extends TestCase
{
    private const TOKEN = 'token-announcement';

    private const FEED_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const PAGE_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
        $this->snapshots()->put(self::TOKEN, 'announcement_page', $this->pageData(4), null, ['page' => 4]);

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

    public function test_detail_answers_404_for_an_unknown_or_malformed_id(): void
    {
        $this->mockValidCampusSession();
        $this->snapshots()->put(self::TOKEN, 'announcements', $this->feedData());

        $this->withSession(['campus_session_token' => self::TOKEN])
            ->getJson('/api/announcement/'.str_repeat('c', 40))
            ->assertNotFound()
            ->assertJsonPath('message', 'Pengumuman tidak ditemukan. Mungkin sudah dihapus dari portal.');

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

    private function snapshots(): PortalSnapshotStore
    {
        return app(PortalSnapshotStore::class);
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
    private function pageData(int $page): array
    {
        return [
            'items' => [$this->announcement(self::PAGE_ID, 'Pengumuman halaman empat')],
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
