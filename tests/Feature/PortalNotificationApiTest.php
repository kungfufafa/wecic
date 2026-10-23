<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class PortalNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-notifications';

    private const USERNAME = '20990000002';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_index_lists_the_students_notifications_newest_first(): void
    {
        $older = PortalNotification::factory()->forOwner($this->ownerHash)->read()->create(['title' => 'Nilai Basis Data keluar: A']);
        $newer = PortalNotification::factory()->forOwner($this->ownerHash)->create([
            'type' => 'krs_validated',
            'dataset' => 'krs',
            'title' => 'KRS sudah divalidasi DPA',
            'body' => 'KRS T.A 2026/2027 Semester Ganjil sudah disetujui.',
            'url' => '/krs',
        ]);
        PortalNotification::factory()->forOwner('student_someone_else')->create();

        $response = $this->getAsStudent('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.has_more', false)
            ->assertJsonPath('data.next_before', null)
            ->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.0.title', 'KRS sudah divalidasi DPA')
            ->assertJsonPath('data.items.0.url', '/krs')
            ->assertJsonPath('data.items.0.is_read', false)
            ->assertJsonPath('data.items.1.id', $older->id)
            ->assertJsonPath('data.items.1.is_read', true)
            ->assertJsonPath('meta.campus_session_valid', true)
            ->assertJsonCount(2, 'data.items');
        $this->assertSame(
            ['id', 'type', 'dataset', 'title', 'body', 'url', 'urgency', 'created_at', 'read_at', 'is_read'],
            array_keys($response->json('data.items.0'))
        );
    }

    public function test_index_pages_with_before(): void
    {
        $ids = PortalNotification::factory()->count(5)->forOwner($this->ownerHash)->create()->pluck('id')->all();

        $first = $this->getAsStudent('/api/notifications?limit=2');
        $first->assertOk()
            ->assertJsonPath('data.has_more', true)
            ->assertJsonPath('data.next_before', $ids[3])
            ->assertJsonPath('data.items.*.id', [$ids[4], $ids[3]]);

        $this->getAsStudent('/api/notifications?limit=2&before='.$ids[3])
            ->assertOk()
            ->assertJsonPath('data.items.*.id', [$ids[2], $ids[1]]);

        $this->getAsStudent('/api/notifications?limit=2&before='.$ids[1])
            ->assertOk()
            ->assertJsonPath('data.has_more', false)
            ->assertJsonPath('data.items.*.id', [$ids[0]]);
    }

    public function test_index_can_show_only_unread(): void
    {
        PortalNotification::factory()->forOwner($this->ownerHash)->read()->create();
        $unread = PortalNotification::factory()->forOwner($this->ownerHash)->create();

        $this->getAsStudent('/api/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('data.items.*.id', [$unread->id]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidIndexQueries(): array
    {
        return [
            'limit zero' => ['limit=0', 'Jumlah notifikasi minimal 1.'],
            'limit too large' => ['limit=51', 'Jumlah notifikasi maksimal 50.'],
            'limit not a number' => ['limit=abc', 'Jumlah notifikasi harus berupa angka.'],
            'before not a number' => ['before=xyz', 'Penanda halaman harus berupa angka.'],
            'unread not boolean' => ['unread=maybe', 'Filter belum dibaca harus true atau false.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIndexQueries')]
    public function test_index_rejects_invalid_parameters(string $query, string $message): void
    {
        $this->getAsStudent('/api/notifications?'.$query)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', $message);
    }

    public function test_unread_count_for_the_bell(): void
    {
        PortalNotification::factory()->count(3)->forOwner($this->ownerHash)->create();
        PortalNotification::factory()->forOwner($this->ownerHash)->read()->create();
        PortalNotification::factory()->forOwner('student_someone_else')->create();

        $this->getAsStudent('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3);
    }

    public function test_mark_read_only_touches_the_students_own_notifications(): void
    {
        $mine = PortalNotification::factory()->forOwner($this->ownerHash)->create();
        $alsoMine = PortalNotification::factory()->forOwner($this->ownerHash)->create();
        $theirs = PortalNotification::factory()->forOwner('student_someone_else')->create();

        $this->postAsStudent('/api/notifications/read', ['ids' => [$mine->id, $theirs->id]])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.unread_count', 1);

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($alsoMine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_mark_all_read(): void
    {
        PortalNotification::factory()->count(3)->forOwner($this->ownerHash)->create();
        $theirs = PortalNotification::factory()->forOwner('student_someone_else')->create();

        $this->postAsStudent('/api/notifications/read', ['all' => true])
            ->assertOk()
            ->assertJsonPath('data.updated', 3)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_mark_all_read_leaves_notifications_the_page_never_showed(): void
    {
        $shown = PortalNotification::factory()->count(2)->forOwner($this->ownerHash)->create();
        $arrivedLater = PortalNotification::factory()->forOwner($this->ownerHash)->create(['title' => 'Ujian Basis Data dibuka', 'urgency' => 'critical']);

        $this->postAsStudent('/api/notifications/read', ['all' => true, 'up_to' => $shown->max('id')])
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.unread_count', 1);

        $this->assertNull($arrivedLater->fresh()->read_at);
        $shown->each(fn (PortalNotification $notification) => $this->assertNotNull($notification->fresh()->read_at));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidReadPayloads(): array
    {
        return [
            'nothing selected' => [[], 'Pilih notifikasi yang ingin ditandai, atau kirim all=true.'],
            'empty ids' => [['ids' => []], 'Pilih notifikasi yang ingin ditandai, atau kirim all=true.'],
            'id not a number' => [['ids' => ['abc']], 'Id notifikasi harus berupa angka.'],
            'all false' => [['all' => false], 'Nilai all harus true.'],
            'too many ids' => [['ids' => range(1, 101)], 'Maksimal 100 notifikasi sekaligus.'],
            'up_to not a number' => [['all' => true, 'up_to' => 'terbaru'], 'Batas notifikasi harus berupa angka.'],
            'up_to zero' => [['all' => true, 'up_to' => 0], 'Batas notifikasi tidak valid.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReadPayloads')]
    public function test_mark_read_rejects_invalid_payloads(array $payload, string $message): void
    {
        $this->postAsStudent('/api/notifications/read', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', $message);
    }

    public function test_notifications_need_a_campus_session(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401)->assertJsonPath('meta.campus_session_valid', false);
        $this->getJson('/api/notifications/unread-count')->assertStatus(401);
        $this->withSession(['_token' => 'known-csrf-token'])
            ->postJson('/api/notifications/read', ['all' => true], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertStatus(401);
    }

    public function test_notifications_follow_the_student_across_logins(): void
    {
        PortalNotification::factory()->forOwner($this->ownerHash)->create();
        app(PortalDataOwner::class)->remember('token-next-login', self::USERNAME, 3600);

        $this->withSession(['campus_session_token' => 'token-next-login'])
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_header_bell_links_to_the_notification_page_with_a_badge_slot(): void
    {
        $response = $this->withSession(['campus_session_token' => self::TOKEN])->get('/dashboard');
        $response->assertOk();
        $bell = (new Crawler((string) $response->getContent()))->filter('header [data-notification-bell]');

        $this->assertCount(1, $bell);
        $this->assertSame('/notifications', parse_url((string) $bell->attr('href'), PHP_URL_PATH));
        $this->assertCount(1, $bell->filter('[data-nav-badge="notifications"]'));
        $this->assertStringContainsString('Notifikasi', $bell->text());
    }

    public function test_notification_page_marks_the_bell_as_current(): void
    {
        $response = $this->withSession(['campus_session_token' => self::TOKEN])->get('/notifications');

        $response->assertOk()
            ->assertSee('data-page="notification-index"', false)
            ->assertSee('Tandai semua sudah dibaca');
        $bell = (new Crawler((string) $response->getContent()))->filter('header [data-notification-bell]');
        $this->assertSame('page', $bell->attr('aria-current'));
    }

    public function test_notification_page_labels_every_notification_type(): void
    {
        $content = (string) $this->withSession(['campus_session_token' => self::TOKEN])->get('/notifications')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/const TYPE_LABELS = \{(.*?)\};/s', $content, $labels));
        preg_match_all('/^\s*([a-z_]+):/m', $labels[1], $labelledTypes);
        $configuredTypes = collect(config('portal.datasets'))
            ->flatMap(static fn (array $dataset): array => array_column($dataset['notify'] ?? [], 'type'))
            ->push('deadline_reminder', 'attendance_open', 'archive_suspect', 'archive_class_not_run')
            ->unique();

        foreach ($configuredTypes as $type) {
            $this->assertContains($type, $labelledTypes[1], "Notification type {$type} has no label on the page.");
        }
    }

    public function test_mark_all_stays_available_in_portal_offline_mode(): void
    {
        $content = (string) $this->withSession(['campus_session_token' => self::TOKEN, 'campus_offline' => true])
            ->get('/notifications')
            ->assertOk()
            ->getContent();
        $markAll = (new Crawler($content))->filter('#notification-mark-all');

        $this->assertCount(1, $markAll);
        // Marking read never reaches the portal; only a missing connection disables it.
        $this->assertNull($markAll->attr('data-online-required'));
    }

    public function test_unread_filter_pages_through_the_servers_unread_list(): void
    {
        $content = (string) $this->withSession(['campus_session_token' => self::TOKEN])->get('/notifications?filter=unread')->assertOk()->getContent();

        $this->assertStringContainsString("query.set('unread', '1')", $content);
        $this->assertStringContainsString('up_to: upTo', $content);
    }

    public function test_a_filter_not_loaded_yet_shows_the_skeleton_instead_of_the_other_filters_list(): void
    {
        $content = (string) $this->withSession(['campus_session_token' => self::TOKEN])->get('/notifications?filter=unread')->assertOk()->getContent();

        $this->assertStringContainsString('const skeletonMarkup = listHost.innerHTML;', $content);
        $this->assertStringContainsString('listHost.innerHTML = skeletonMarkup;', $content);
        // A filter whose first load failed keeps its error state, also when the other filter's list was on screen.
        $this->assertStringContainsString('if (mode !== modeOf(state.unreadOnly) || Number.isFinite(coverageFloor())) {', $content);
    }

    public function test_a_refresh_is_merged_only_into_the_range_its_own_filter_received(): void
    {
        $content = (string) $this->withSession(['campus_session_token' => self::TOKEN])->get('/notifications')->assertOk()->getContent();

        $this->assertStringContainsString("const covered = viewRange(mode === 'unread');", $content);
        $this->assertStringContainsString('lowestId <= covered.top', $content);
        // Ids another filter received never close a gap in this one.
        $this->assertStringNotContainsString('lowestId > maxKnownId()', $content);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function getAsStudent(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['campus_session_token' => self::TOKEN])->getJson($uri);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function postAsStudent(string $uri, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => self::TOKEN])
            ->postJson($uri, $payload, ['X-CSRF-TOKEN' => 'known-csrf-token']);
    }
}
