<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalScrapingException;
use App\Services\Extractors\AnnouncementExtractor;
use App\Services\PortalAnnouncementFeed;
use App\Services\PortalEndpointRegistry;
use App\Services\PortalSessionManager;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalAnnouncementFeedTest extends TestCase
{
    private const TOKEN = 'fake-session-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-25 09:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_merges_the_fixture_pages_newest_first(): void
    {
        $feed = new PortalAnnouncementFeed($this->extractorForFixturePages());

        $data = $feed->fetch(self::TOKEN, 2);

        $this->assertCount(6, $data['items']);
        $this->assertSame(
            ['2026-09-17', '2026-07-20', '2026-07-02', '2026-04-13', '2026-04-13', '2026-04-13'],
            array_column($data['items'], 'published_date')
        );
        $this->assertSame([1, 1, 1, 2, 2, 2], array_column($data['items'], 'page'));
        $this->assertSame([
            'pages_loaded' => 2,
            'last_page' => 28,
            'total' => 84,
            'has_more' => true,
            'next_page' => 3,
            'complete' => true,
            'stopped_at_known' => false,
        ], $data['paging']);
        $this->assertSame('payment', $data['notice']['kind']);
        $this->assertFalse($data['notice']['is_future']);
        $this->assertSame('upstream', $data['_sync']['data_origin']);
    }

    public function test_it_marks_the_same_title_posted_twice_as_a_duplicate(): void
    {
        $items = (new PortalAnnouncementFeed($this->extractorForFixturePages()))->fetch(self::TOKEN, 2)['items'];

        $this->assertSame([null, null, null, null, $items[3]['id'], null], array_column($items, 'duplicate_of'));
    }

    public function test_different_notices_under_the_same_generic_title_both_count(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 1)->andReturn($this->page(1, [
            $this->item('payment', 'PENGUMUMAN', '2026-07-24', [['date' => '2026-08-20', 'start' => null, 'type' => 'deadline', 'context' => 'Bayar UKT']],
                'Diberitahukan kepada seluruh mahasiswa bahwa pembayaran UKT tahap 2 paling lambat tanggal 20 Agustus 2026 melalui virtual account masing-masing.'),
            $this->item('exam-card', 'PENGUMUMAN', '2026-07-21', [['date' => '2026-08-10', 'start' => null, 'type' => 'deadline', 'context' => 'Kartu UTS']],
                'Diberitahukan kepada seluruh mahasiswa bahwa kartu UTS dapat diambil di ruang BAAK paling lambat tanggal 10 Agustus 2026 dengan membawa KTM.'),
        ], 2));

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 1);

        $this->assertSame([null, null], array_column($data['items'], 'duplicate_of'));
        $this->assertSame(['2026-08-10', '2026-08-20'], array_column($data['upcoming_deadlines'], 'date'));
    }

    public function test_a_repost_with_a_corrected_date_is_still_a_duplicate(): void
    {
        $body = 'Diberitahukan kepada seluruh mahasiswa bahwa pembayaran UKT tahap 2 paling lambat tanggal %s Agustus 2026 melalui virtual account masing-masing.';
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 1)->andReturn($this->page(1, [
            $this->item('repost', 'Pembayaran UKT Tahap 2', '2026-07-24', [], sprintf($body, '22')),
            $this->item('first', 'Pembayaran UKT Tahap 2', '2026-07-21', [], sprintf($body, '20')),
        ], 2));

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 1);

        $this->assertSame([null, 'repost'], array_column($data['items'], 'duplicate_of'));
    }

    public function test_it_lists_upcoming_deadlines_with_countdowns(): void
    {
        $upcoming = (new PortalAnnouncementFeed($this->extractorForFixturePages()))->fetch(self::TOKEN, 2)['upcoming_deadlines'];

        $this->assertSame([
            ['2026-07-20', '2026-08-16', 22, true, 'semester_pendek'],
            [null, '2026-08-19', 25, false, 'semester_pendek'],
            ['2026-08-20', '2026-08-29', 35, false, 'semester_pendek'],
            ['2026-09-17', '2026-09-19', 56, false, 'krs'],
        ], array_map(
            static fn (array $deadline): array => [$deadline['start'], $deadline['date'], $deadline['days_left'], $deadline['is_ongoing'], $deadline['category']],
            $upcoming
        ));
        $this->assertSame('Pendaftaran Semester Pendek 2026', $upcoming[0]['title']);
        $this->assertSame('period', $upcoming[0]['type']);
    }

    public function test_it_merges_the_raw_portal_pages_like_the_normalized_ones(): void
    {
        $raw = (new PortalAnnouncementFeed($this->extractorForFixturePages('_raw')))->fetch(self::TOKEN.'-raw', 2);
        $normalized = (new PortalAnnouncementFeed($this->extractorForFixturePages()))->fetch(self::TOKEN, 2);

        $this->assertCount(6, $raw['items']);
        $this->assertSame(
            ['2026-09-17', '2026-07-20', '2026-07-02', '2026-04-13', '2026-04-13', '2026-04-13'],
            array_column($raw['items'], 'published_date')
        );
        $this->assertSame([1, 1, 1, 2, 2, 2], array_column($raw['items'], 'page'));
        $this->assertSame(array_column($normalized['items'], 'title'), array_column($raw['items'], 'title'));
        $this->assertSame([null, null, null, null, $raw['items'][3]['id'], null], array_column($raw['items'], 'duplicate_of'));
        $this->assertSame(['Staf BAAK'], array_values(array_unique(array_column($raw['items'], 'publisher'))));
        $this->assertSame([[], [], [], ['dosen@example.test'], ['dosen@example.test'], ['dosen@example.test']], array_column($raw['items'], 'emails'));
        $this->assertSame($normalized['paging'], $raw['paging']);
        $this->assertSame(28, $raw['paging']['last_page']);
        $this->assertSame(84, $raw['paging']['total']);
        $this->assertSame($normalized['notice'], $raw['notice']);
        $this->assertSame(
            array_map($this->deadlineSummary(...), $normalized['upcoming_deadlines']),
            array_map($this->deadlineSummary(...), $raw['upcoming_deadlines'])
        );
        $this->assertSame([22, 25, 35, 56], array_column($raw['upcoming_deadlines'], 'days_left'));
    }

    public function test_time_dependent_flags_are_recomputed_on_every_call(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)->andReturn($this->page(1, [
            $this->item('a', 'Pembayaran Tahap 2', '2026-07-01', [['date' => '2026-07-31', 'start' => null, 'type' => 'deadline', 'context' => 'Bayar', 'is_future' => true]]),
        ], 3, ['text' => 'Bayar paling lambat 31 Juli 2026', 'dates' => ['2026-07-31'], 'is_future' => true, 'kind' => 'payment']));
        $feed = new PortalAnnouncementFeed($extractor);

        $before = $feed->fetch(self::TOKEN);
        $this->travelTo(CarbonImmutable::parse('2026-08-01 08:00:00', 'Asia/Jakarta'));
        $after = $feed->fetch(self::TOKEN);

        $this->assertSame(6, $before['upcoming_deadlines'][0]['days_left']);
        $this->assertTrue($before['notice']['is_future']);
        $this->assertSame([], $after['upcoming_deadlines']);
        $this->assertFalse($after['items'][0]['deadlines'][0]['is_future']);
        $this->assertFalse($after['notice']['is_future']);
    }

    public function test_it_fetches_three_pages_by_default(): void
    {
        $extractor = $this->extractorMock();

        foreach ([1, 2, 3] as $page) {
            $extractor->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, $page)
                ->andReturn($this->page($page, [$this->item('id-'.$page, 'Pengumuman '.$page, '2026-07-0'.$page)], 84));
        }

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN);

        $this->assertSame(['id-3', 'id-2', 'id-1'], array_column($data['items'], 'id'));
        $this->assertSame(3, $data['paging']['pages_loaded']);
        $this->assertSame(4, $data['paging']['next_page']);
    }

    public function test_the_walk_is_bounded_by_the_last_page(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 1)
            ->andReturn($this->page(1, [$this->item('a', 'Satu', '2026-07-03'), $this->item('b', 'Dua', '2026-07-02'), $this->item('c', 'Tiga', '2026-07-01')], 4));
        $extractor->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 2)
            ->andReturn($this->page(2, [$this->item('d', 'Empat', '2026-06-30')], 4));
        $extractor->shouldNotReceive('extractAnnouncementsData')->with(self::TOKEN, 3);

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 5);

        $this->assertSame(['a', 'b', 'c', 'd'], array_column($data['items'], 'id'));
        $this->assertFalse($data['paging']['has_more']);
        $this->assertNull($data['paging']['next_page']);
    }

    public function test_an_unknown_last_page_stops_after_the_first_page(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 1)
            ->andReturn($this->page(1, [$this->item('a', 'Satu', '2026-07-03')], null));

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN);

        $this->assertSame(1, $data['paging']['pages_loaded']);
        $this->assertNull($data['paging']['last_page']);
        $this->assertFalse($data['paging']['has_more']);
    }

    public function test_an_announcement_shifted_to_the_next_page_appears_once(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)
            ->andReturn($this->page(1, [$this->item('new', 'Baru', '2026-07-10'), $this->item('a', 'Satu', '2026-07-03'), $this->item('b', 'Dua', '2026-07-02')], 7));
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 2)
            ->andReturn($this->page(2, [$this->item('b', 'Dua', '2026-07-02'), $this->item('c', 'Tiga', '2026-07-01'), $this->item('d', 'Empat', '2026-06-30')], 7));

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 2);

        $this->assertSame(['new', 'a', 'b', 'c', 'd'], array_column($data['items'], 'id'));
        $this->assertSame(1, $data['items'][2]['page']);
    }

    public function test_items_get_their_stable_id_across_pages(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)
            ->andReturn($this->page(1, [$this->worded('Jadwal UTS', '2026-07-10', 'Isi pertama.')], 6));
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 2)
            ->andReturn($this->page(2, [$this->worded('Libur Nasional', '2026-07-01', 'Isi kedua.')], 6));

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 2);

        $this->assertSame([
            AnnouncementExtractor::stableId('Jadwal UTS', '2026-07-10', 'BAAK'),
            AnnouncementExtractor::stableId('Libur Nasional', '2026-07-01', 'BAAK'),
        ], array_column($data['items'], 'id'));
        $this->assertSame($data['items'][0]['id'], $data['upcoming_deadlines'][0]['announcement_id']);
    }

    public function test_two_announcements_sharing_a_stable_id_on_different_pages_stay_apart(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)
            ->andReturn($this->page(1, [$this->worded('Jadwal UTS', '2026-07-10', 'Isi pertama.'), $this->worded('Surat Magang', '2026-07-03', 'Pengantar magang untuk semester ganjil.')], 6));
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 2)
            ->andReturn($this->page(2, [$this->worded('Surat Magang', '2026-07-03', 'Format baru surat keterangan masih kuliah.')], 6));

        $items = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 2)['items'];

        $this->assertCount(3, $items);
        $this->assertSame(AnnouncementExtractor::stableId('Jadwal UTS', '2026-07-10', 'BAAK'), $items[0]['id']);
        $this->assertSame([$items[1]['legacy_id'], $items[2]['legacy_id']], [$items[1]['id'], $items[2]['id']]);
        $this->assertNotSame($items[1]['id'], $items[2]['id']);
        $this->assertSame([null, null, null], array_column($items, 'duplicate_of'));
    }

    public function test_an_announcement_read_on_two_pages_under_different_ids_appears_once(): void
    {
        $original = $this->worded('Surat Magang', '2026-07-03', 'Pengantar magang untuk semester ganjil.');
        $other = $this->worded('Surat Magang', '2026-07-03', 'Format baru surat keterangan masih kuliah.');
        $extractor = $this->extractorMock();
        // Page 1 saw the pair (legacy ids); after a new post page 2 holds the original alone (stable id).
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)
            ->andReturn($this->page(1, AnnouncementExtractor::withStableIds([$other, $original]), 6));
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 2)
            ->andReturn($this->page(2, AnnouncementExtractor::withStableIds([$original]), 6));

        $items = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 2)['items'];

        $this->assertSame([$other['legacy_id'], $original['legacy_id']], array_column($items, 'id'));
        $this->assertSame([1, 1], array_column($items, 'page'));
    }

    public function test_a_page_cached_before_stable_ids_does_not_repeat_its_announcements(): void
    {
        $fresh = $this->worded('Jadwal UTS', '2026-07-10', 'Isi pertama.');
        $cached = $this->item($fresh['legacy_id'], 'Jadwal UTS', '2026-07-10', [], 'Isi pertama.');
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)->andReturn($this->page(1, [$fresh], 6));
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 2)->andReturn($this->page(2, [$cached], 6));

        $items = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 2)['items'];

        $this->assertSame([AnnouncementExtractor::stableId('Jadwal UTS', '2026-07-10', 'BAAK')], array_column($items, 'id'));
    }

    public function test_a_failing_later_page_keeps_the_pages_already_loaded(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)
            ->andReturn($this->page(1, [$this->item('a', 'Satu', '2026-07-03')], 84));
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 2)
            ->andThrow(PortalConnectionException::networkError('https://portal.example.test', 'timeout'));
        $extractor->shouldNotReceive('extractAnnouncementsData')->with(self::TOKEN, 3);

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN);

        $this->assertSame(['a'], array_column($data['items'], 'id'));
        $this->assertFalse($data['paging']['complete']);
        $this->assertSame(1, $data['paging']['pages_loaded']);
        $this->assertTrue($data['paging']['has_more']);
    }

    public function test_an_expired_session_on_a_later_page_is_rethrown(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)
            ->andReturn($this->page(1, [$this->item('a', 'Satu', '2026-07-03')], 84));
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 2)
            ->andThrow(PortalScrapingException::pageNotAccessible('https://portal.example.test'));
        $extractor->shouldNotReceive('extractAnnouncementsData')->with(self::TOKEN, 3);

        $this->expectException(PortalScrapingException::class);
        $this->expectExceptionCode(403);

        (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN);
    }

    public function test_a_failing_first_page_is_rethrown(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)
            ->andThrow(PortalScrapingException::pageNotAccessible('https://portal.example.test'));

        $this->expectException(PortalScrapingException::class);

        (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN);
    }

    public function test_known_ids_stop_the_walk_after_the_page_that_contains_them(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->once()->with(self::TOKEN, 1)
            ->andReturn($this->page(1, [$this->item('new', 'Baru', '2026-07-10'), $this->item('a', 'Satu', '2026-07-03')], 84));
        $extractor->shouldNotReceive('extractAnnouncementsData')->with(self::TOKEN, 2);

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN, 3, ['a', 'b']);

        $this->assertTrue($data['paging']['stopped_at_known']);
        $this->assertSame(1, $data['paging']['pages_loaded']);
        $this->assertSame(['new', 'a'], array_column($data['items'], 'id'));
    }

    public function test_cached_pages_are_reported_as_server_cache(): void
    {
        $extractor = $this->extractorMock();
        $extractor->shouldReceive('extractAnnouncementsData')->with(self::TOKEN, 1)
            ->andReturn(['_sync' => ['data_origin' => 'server_cache']] + $this->page(1, [], 0));

        $data = (new PortalAnnouncementFeed($extractor))->fetch(self::TOKEN);

        $this->assertSame([], $data['items']);
        $this->assertSame('server_cache', $data['_sync']['data_origin']);
    }

    /**
     * The real extractor replaying pages 1 and 2 (`$suffix` '_raw' selects
     * the raw server copies)
     */
    private function extractorForFixturePages(string $suffix = ''): AnnouncementExtractor
    {
        $sessionManager = Mockery::mock(PortalSessionManager::class);
        $sessionManager->shouldReceive('getSessionCookieJar')->andReturn(new CookieJar);

        $extractor = Mockery::mock(AnnouncementExtractor::class, [$sessionManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $extractor->shouldReceive('get')->once()->with(PortalEndpointRegistry::url('student_announcement'))
            ->andReturn((string) file_get_contents(base_path("tests/Fixtures/portal/student_announcement_page1{$suffix}.html")));
        $extractor->shouldReceive('get')->once()->with(PortalEndpointRegistry::url('student_announcement', ['page' => 2]))
            ->andReturn((string) file_get_contents(base_path("tests/Fixtures/portal/student_announcement_page2{$suffix}.html")));

        return $extractor;
    }

    /**
     * @param  array<string, mixed>  $deadline
     * @return array<string, mixed>
     */
    private function deadlineSummary(array $deadline): array
    {
        return array_diff_key($deadline, ['announcement_id' => true, 'context' => true]);
    }

    private function extractorMock(): AnnouncementExtractor&MockInterface
    {
        return Mockery::mock(AnnouncementExtractor::class);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{text: string, dates: list<string>, is_future: bool, kind: string}|null  $notice
     * @return array<string, mixed>
     */
    private function page(int $page, array $items, ?int $total, ?array $notice = null): array
    {
        $lastPage = $total === null ? null : max(1, (int) ceil($total / 3));

        return [
            'items' => $items,
            'paging' => [
                'page' => $page,
                'per_page' => 3,
                'from' => $items === [] ? null : ($page - 1) * 3 + 1,
                'to' => $items === [] ? null : ($page - 1) * 3 + count($items),
                'total' => $total,
                'last_page' => $lastPage,
                'has_previous' => $page > 1,
                'has_next' => $lastPage !== null && $page < $lastPage,
                'previous_page' => $page > 1 ? $page - 1 : null,
                'next_page' => $lastPage !== null && $page < $lastPage ? $page + 1 : null,
                'previous_url' => null,
                'next_url' => null,
                'out_of_range' => false,
            ],
            'notice' => $notice,
            'extracted_at' => now()->toISOString(),
            '_sync' => ['data_origin' => 'upstream'],
        ];
    }

    /**
     * An item as the extractor now reads it: a legacy id from its wording and,
     * for now, its stable id (the feed decides the final one).
     *
     * @return array<string, mixed>
     */
    private function worded(string $title, string $publishedDate, string $body): array
    {
        return [
            ...$this->item(AnnouncementExtractor::stableId($title, $publishedDate, 'BAAK'), $title, $publishedDate, [['date' => '2026-08-01', 'start' => null, 'type' => 'deadline', 'context' => $body]], $body),
            'legacy_id' => AnnouncementExtractor::legacyId($title, $publishedDate, 'BAAK', $body),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $deadlines
     * @return array<string, mixed>
     */
    private function item(string $id, string $title, ?string $publishedDate, array $deadlines = [], string $body = ''): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'published_at' => $publishedDate === null ? null : $publishedDate.'T00:00:00+07:00',
            'published_date' => $publishedDate,
            'published_label' => null,
            'publisher' => 'Staf BAAK',
            'publisher_unit' => 'BAAK',
            'publisher_unit_short' => 'BAAK',
            'category' => 'umum',
            'category_label' => 'Umum',
            'excerpt' => $body,
            'body_text' => $body,
            'body_html' => '',
            'links' => [],
            'emails' => [],
            'deadlines' => $deadlines,
            'steps' => [],
        ];
    }
}
