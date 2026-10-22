<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalOfflineException;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Models\PortalArchiveVersion;
use App\Models\PortalOfflineAccount;
use App\Services\Extractors\ProfileExtractor;
use App\Services\PortalAuthService;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalOfflineAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalOfflineLoginTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = '20990000004';

    private const PASSWORD = 'rahasia-portal';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RefreshPortalSessionJob::class, ResumeOfflineSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class]);
        $this->withSession(['_token' => 'known-csrf-token'])->withHeader('X-CSRF-TOKEN', 'known-csrf-token');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_online_login_remembers_a_hash_of_the_password_for_offline_access(): void
    {
        $this->portalAcceptsLogin();

        $this->login(self::PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.offline', false)
            ->assertSessionHas('campus_offline', false);

        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame(app(PortalDataOwner::class)->keyForUsername(self::USERNAME), $account->owner_hash);
        $this->assertNotSame(self::PASSWORD, $account->password_hash);
        $this->assertTrue(Hash::check(self::PASSWORD, $account->password_hash));
        $this->assertSame('Mahasiswa Arsip', $account->user_info['name']);
        $this->assertSame(self::PASSWORD, $account->credentials['password']);
    }

    public function test_the_same_password_opens_an_offline_session_while_the_portal_is_down(): void
    {
        $this->rememberOnlineLogin();
        $this->portalIsDown();

        $response = $this->login(self::PASSWORD);

        $response->assertOk()
            ->assertJsonPath('data.offline', true)
            ->assertJsonPath('data.user_info.name', 'Mahasiswa Arsip')
            ->assertSessionHas('campus_offline', true);
        $this->assertStringContainsString('mode offline', $response->json('message'));
        $this->assertTrue(app(PortalOfflineAccess::class)->isOfflineSession(session('campus_session_token')));
        Bus::assertNotDispatched(RefreshPortalSessionJob::class);
        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_a_wrong_password_while_the_portal_is_down_is_not_let_in(): void
    {
        $this->rememberOnlineLogin();
        $this->portalIsDown();

        $this->login('tebakan-salah')
            ->assertStatus(503)
            ->assertSessionMissing('campus_session_token');
    }

    public function test_offline_access_expires_after_the_configured_days(): void
    {
        $this->rememberOnlineLogin();
        $this->travel((int) config('portal.archive.offline_login_days') + 1)->days();
        $this->portalIsDown();

        $this->login(self::PASSWORD)->assertStatus(503);
    }

    public function test_offline_login_can_be_switched_off(): void
    {
        $this->rememberOnlineLogin();
        config(['portal.archive.offline_login' => false]);
        $this->portalIsDown();

        $this->login(self::PASSWORD)->assertStatus(503);
    }

    public function test_an_offline_session_reads_the_archive_and_never_builds_from_the_portal(): void
    {
        $token = $this->offlineSession();
        PortalArchiveVersion::factory()
            ->forOwner(app(PortalDataOwner::class)->keyForUsername(self::USERNAME))
            ->withPayload(['found' => true, 'entries' => [['name' => 'Seminar', 'status' => 'valid']]])
            ->create();
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        }));

        $result = app(PortalDatasetRepository::class)->read($token, 'skkm', forceFresh: true);

        $this->assertSame('archive', $result['meta']['data_origin']);
        $this->assertSame('offline', $result['meta']['status']);
        $this->assertSame('Seminar', $result['data']['entries'][0]['name']);
        Bus::assertNotDispatched(RefreshPortalDataJob::class);
    }

    public function test_data_never_archived_is_reported_as_unavailable_offline(): void
    {
        $this->offlineSession();

        $this->getJson('/api/skkm')
            ->assertStatus(503)
            ->assertJsonPath('meta.offline', true)
            ->assertJsonPath('message', 'Data ini belum tersimpan di arsip MyCIC, jadi belum bisa dibuka selama mode offline.');
    }

    public function test_actions_that_reach_the_portal_are_closed_offline(): void
    {
        $this->offlineSession();

        $this->postJson('/api/attendance', ['kode' => 'ABCD1234'])
            ->assertStatus(503)
            ->assertJsonPath('meta.offline', true);
        $this->postJson('/api/refresh', ['domains' => ['krs']])->assertStatus(503);
        $this->postJson('/api/auth/session/refresh')->assertStatus(409);
    }

    public function test_the_scraping_layer_refuses_an_offline_session(): void
    {
        $token = $this->offlineSession();

        $this->expectException(PortalOfflineException::class);

        app(ProfileExtractor::class)->extractProfileData($token);
    }

    public function test_session_check_reports_offline_mode(): void
    {
        $this->offlineSession();

        $this->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('data.offline', true);
    }

    public function test_logout_ends_the_offline_session(): void
    {
        $token = $this->offlineSession();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertFalse(app(PortalOfflineAccess::class)->isOfflineSession($token));
    }

    public function test_pages_show_the_offline_banner(): void
    {
        $this->offlineSession();

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-portal-offline="1"', false)
            ->assertSee('Mode offline');
    }

    private function rememberOnlineLogin(): void
    {
        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME]);
    }

    private function offlineSession(): string
    {
        $this->rememberOnlineLogin();
        $this->portalIsDown();
        $this->login(self::PASSWORD)->assertOk();
        // Logging in regenerates the CSRF token.
        session()->put('_token', 'known-csrf-token');

        return (string) session('campus_session_token');
    }

    private function portalAcceptsLogin(): void
    {
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andReturn([
                'user_info' => ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME],
                'cookies' => [],
                'session_data' => ['login_time' => now()->toISOString()],
            ]);
        }));
    }

    private function portalIsDown(): void
    {
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('login')->andThrow(PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection timed out'));
        }));
    }

    private function login(string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => self::USERNAME, 'password' => $password]);
    }
}
