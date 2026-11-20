<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalSessionNoticeSent;
use App\Exceptions\PortalAuthenticationException;
use App\Exceptions\PortalConnectionException;
use App\Exceptions\PortalOfflineException;
use App\Http\Controllers\API\PortalAuthController;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Models\PortalArchiveVersion;
use App\Models\PortalOfflineAccount;
use App\Services\Extractors\ProfileExtractor;
use App\Services\PortalAuthService;
use App\Services\PortalBackgroundSync;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalOfflineAccess;
use App\Services\PortalPasswordService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
        // Offline login keeps only the hash; the password itself waits for the background sync opt-in.
        $this->assertNull($account->credentials);
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

    public function test_wrong_offline_passwords_count_against_the_address_while_the_portal_is_down(): void
    {
        config(['portal.security.login_failure_attempts_per_ip' => 2]);

        foreach (['20990000301', '20990000302', '20990000303'] as $username) {
            app(PortalOfflineAccess::class)->remember($username, '12345678-unik-'.$username, ['name' => 'Mahasiswa '.$username]);
        }

        $this->portalIsDown();

        // A common password sprayed over NIMs: each one is a bcrypt check against an offline hash.
        $this->postJson('/api/auth/login', ['username' => '20990000301', 'password' => '12345678'])->assertStatus(503);
        $this->postJson('/api/auth/login', ['username' => '20990000302', 'password' => '12345678'])->assertStatus(503);
        $this->postJson('/api/auth/login', ['username' => '20990000303', 'password' => '12345678'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Terlalu banyak login gagal dari jaringan ini. Coba lagi dalam beberapa menit.');
    }

    public function test_students_without_offline_access_do_not_use_up_the_address_while_the_portal_is_down(): void
    {
        config(['portal.security.login_failure_attempts_per_ip' => 2]);
        $this->rememberOnlineLogin();
        $this->portalIsDown();

        // Nothing is checked for them: a 503 is all they get, and it is not a failed login.
        foreach (['20990000401', '20990000402', '20990000403'] as $username) {
            $this->postJson('/api/auth/login', ['username' => $username, 'password' => 'password-mereka'])->assertStatus(503);
        }

        $this->login(self::PASSWORD)->assertOk()->assertJsonPath('data.offline', true);
    }

    public function test_an_offline_login_can_switch_background_sync_on_and_off_with_the_checked_password(): void
    {
        $this->rememberOnlineLogin();
        $this->portalIsDown();

        $this->login(self::PASSWORD, backgroundSync: true)
            ->assertOk()
            ->assertJsonPath('data.offline', true)
            ->assertJsonPath('data.background_sync.enabled', true)
            ->assertJsonPath('data.background_sync.active', true);
        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame(['username' => self::USERNAME, 'password' => self::PASSWORD], $account->credentials);
        // Logging in regenerates the CSRF token.
        session()->put('_token', 'known-csrf-token');

        $this->login(self::PASSWORD, backgroundSync: false)
            ->assertOk()
            ->assertJsonPath('data.offline', true)
            ->assertJsonPath('data.background_sync.enabled', false);
        $account = PortalOfflineAccount::query()->sole();
        $this->assertNull($account->credentials);
        $this->assertFalse($account->isBackgroundSyncEnabled());
    }

    public function test_an_online_login_repairs_an_offline_account_sealed_with_an_old_app_key(): void
    {
        $this->rememberOnlineLogin(backgroundSync: true);
        $oldKey = new Encrypter(random_bytes(32), 'aes-256-cbc');
        PortalOfflineAccount::query()->update([
            'user_info' => $oldKey->encryptString((string) json_encode(['name' => 'Kunci lama'])),
            'credentials' => $oldKey->encryptString((string) json_encode(['username' => self::USERNAME, 'password' => self::PASSWORD])),
        ]);

        app(PortalOfflineAccess::class)->remember(self::USERNAME, 'password-baru', ['name' => 'Mahasiswa Arsip']);

        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame(['name' => 'Mahasiswa Arsip'], $account->user_info);
        $this->assertSame(['username' => self::USERNAME, 'password' => 'password-baru'], $account->credentials);
        $this->assertSame(['name' => 'Mahasiswa Arsip'], app(PortalOfflineAccess::class)->attempt(self::USERNAME, 'password-baru'));

        // An unreadable value that is not replaced is cleared rather than kept.
        config(['portal.background_sync.enabled' => false]);
        PortalOfflineAccount::query()->update(['credentials' => $oldKey->encryptString('{}')]);
        app(PortalOfflineAccess::class)->remember(self::USERNAME, 'password-baru', ['name' => 'Mahasiswa Arsip']);
        $this->assertNull(PortalOfflineAccount::query()->sole()->credentials);

        // So does an offline session logging in again on its own.
        PortalOfflineAccount::query()->update(['user_info' => $oldKey->encryptString('{}')]);
        app(PortalOfflineAccess::class)->markOnlineLogin(self::USERNAME, ['name' => 'Mahasiswa Kembali']);
        $this->assertSame(['name' => 'Mahasiswa Kembali'], PortalOfflineAccount::query()->sole()->user_info);
    }

    public function test_a_download_opened_in_a_tab_while_offline_shows_a_page(): void
    {
        $this->offlineSession();

        $page = $this->get('/api/support/download?path=../files/modul/sample.pdf', ['Accept' => 'text/html'])
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Kamu sedang di mode offline')
            ->assertSee('href="'.e(url('/dashboard')).'"', false);
        $this->assertStringNotContainsString('"status"', (string) $page->getContent());

        $this->getJson('/api/support/download?path=../files/modul/sample.pdf')
            ->assertStatus(503)
            ->assertJsonPath('meta.offline', true);
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

    public function test_a_password_the_portal_has_rejected_no_longer_opens_an_offline_session(): void
    {
        $this->rememberOnlineLogin();
        PortalOfflineAccount::query()->sole()->forceFill(['credentials_rejected_at' => now()])->save();
        $this->portalIsDown();

        $this->login(self::PASSWORD)
            ->assertStatus(503)
            ->assertSessionMissing('campus_session_token');

        // The next online login confirms the password again.
        $this->rememberOnlineLogin();
        $this->login(self::PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.offline', true);
    }

    public function test_switching_background_sync_off_never_reopens_offline_login_for_a_rejected_password(): void
    {
        $portal = 'down';
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock) use (&$portal): void {
            $mock->shouldReceive('login')->andReturnUsing(function () use (&$portal): never {
                if ($portal === 'rejects') {
                    throw PortalAuthenticationException::invalidCredentials();
                }

                throw PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection timed out');
            });
            $mock->shouldReceive('logout');
        }));
        $this->rememberOnlineLogin(backgroundSync: true);
        $this->login(self::PASSWORD)->assertOk()->assertJsonPath('data.offline', true);
        session()->put('_token', 'known-csrf-token');

        // A background sync finds the portal no longer takes the password.
        $portal = 'rejects';
        $this->assertSame(PortalOfflineAccount::SYNC_REJECTED, app(PortalBackgroundSync::class)->syncAccount(PortalOfflineAccount::query()->sole()));

        // Switching the sync off from the offline session lifts the rejection mark...
        $this->patchJson('/api/archive/background-sync', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.rejected', false);

        // ...but the refused password still does not open MyCIC offline.
        $portal = 'down';
        $this->login(self::PASSWORD)->assertStatus(503);
        $this->assertSame('', PortalOfflineAccount::query()->sole()->password_hash);
    }

    public function test_lifting_a_rejection_without_a_new_password_drops_the_offline_password(): void
    {
        $this->rememberOnlineLogin();
        $offlineAccess = app(PortalOfflineAccess::class);
        PortalOfflineAccount::query()->sole()->forceFill(['credentials_rejected_at' => now()])->save();

        app(PortalBackgroundSync::class)->disable(app(PortalDataOwner::class)->keyForUsername(self::USERNAME));

        $this->assertNull(PortalOfflineAccount::query()->sole()->credentials_rejected_at);
        $this->assertNull($offlineAccess->attempt(self::USERNAME, self::PASSWORD));

        // Only a new online login stores a password that opens MyCIC offline again.
        $this->rememberOnlineLogin();
        $this->assertNotNull($offlineAccess->attempt(self::USERNAME, self::PASSWORD));
    }

    public function test_a_password_change_through_mycic_replaces_the_offline_password(): void
    {
        $portal = 'up';
        $this->instance(PortalAuthService::class, Mockery::mock(PortalAuthService::class, function (MockInterface $mock) use (&$portal): void {
            $mock->shouldReceive('login')->andReturnUsing(function () use (&$portal): array {
                if ($portal === 'down') {
                    throw PortalConnectionException::networkError('https://my.cic.ac.id/portal/', 'Connection timed out');
                }

                return [
                    'user_info' => ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME],
                    'cookies' => [],
                    'session_data' => ['login_time' => now()->toISOString()],
                ];
            });
        }));
        $this->instance(PortalPasswordService::class, Mockery::mock(PortalPasswordService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('changePassword')->once()->andReturn(['success' => true, 'message' => 'Password berhasil diubah.', 'data' => []]);
        }));

        $this->login(self::PASSWORD, backgroundSync: true)->assertOk()->assertJsonPath('data.offline', false);
        session()->put('_token', 'known-csrf-token');
        $this->postJson('/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'password-baru',
            'new_password_confirmation' => 'password-baru',
        ])->assertOk()->assertJsonPath('success', true);

        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame('password-baru', $account->credentials['password']);

        $portal = 'down';
        $this->login(self::PASSWORD)->assertStatus(503);
        $this->login('password-baru')
            ->assertOk()
            ->assertJsonPath('data.offline', true);
    }

    public function test_an_offline_session_outlives_the_keep_alive_interval_while_the_portal_stays_down(): void
    {
        Event::fake([PortalSessionNoticeSent::class]);
        $token = $this->offlineSession();
        $this->travel(2)->minutes();

        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('data.offline', true);
        Bus::assertNotDispatched(RefreshPortalSessionJob::class);

        // Even a keep-alive queued for it anyway leaves it alone.
        $this->travel((int) config('portal.session_refresh_interval', 1200))->seconds();
        app()->call([new RefreshPortalSessionJob($token), 'handle']);

        $this->get('/dashboard')->assertOk()->assertSee('data-portal-offline="1"', false);
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('data.offline', true);
        Bus::assertNotDispatched(RefreshPortalSessionJob::class);
        Event::assertNotDispatched(PortalSessionNoticeSent::class);
    }

    public function test_with_offline_login_switched_off_an_opted_in_login_still_keeps_what_background_sync_needs(): void
    {
        config(['portal.archive.offline_login' => false]);
        $this->portalAcceptsLogin();

        $this->login(self::PASSWORD, backgroundSync: true)->assertOk();

        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame('', $account->password_hash);
        $this->assertSame(self::PASSWORD, $account->credentials['password']);
        $this->assertTrue(app(PortalBackgroundSync::class)->status($account->owner_hash)['active']);
        $this->assertCount(1, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_nothing_is_kept_when_offline_login_and_background_sync_are_both_off(): void
    {
        config(['portal.archive.offline_login' => false, 'portal.background_sync.enabled' => false]);
        $this->portalAcceptsLogin();

        $this->login(self::PASSWORD)->assertOk();

        $this->assertSame(0, PortalOfflineAccount::query()->count());
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
            ->assertJsonPath('message', 'Data ini belum tersimpan di arsip WeCIC, jadi belum bisa dibuka selama mode offline.');
    }

    public function test_actions_that_reach_the_portal_are_closed_offline(): void
    {
        $this->offlineSession();

        $this->deleteJson('/api/assignment', ['id' => 126663, 'idx' => 29833, 'pertemuan' => 2])
            ->assertStatus(503)
            ->assertJsonPath('meta.offline', true);
        $this->postJson('/api/refresh', ['domains' => ['krs']])->assertStatus(503);
        $this->postJson('/api/auth/session/refresh')->assertStatus(409);
    }

    /**
     * Attendance and uploads are deadline-bound, so they wait in the outbox
     * instead (PortalOutboxTest covers the queue itself).
     */
    public function test_attendance_is_queued_rather_than_refused_offline(): void
    {
        $this->offlineSession();

        $this->postJson('/api/attendance', ['courseid' => 8837, 'modulesid' => 29808, 'kode' => 'ABCD1234'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued_offline')
            ->assertJsonPath('data.outbox_item.status_label', 'Belum terkirim ke portal');
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

        $response = $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-portal-offline="1"', false)
            ->assertSee('Mode offline');

        $mode = session('campus_offline_reconnect');

        $this->assertNotNull($mode);
        $response->assertSee('data-portal-offline-reconnect="'.$mode.'"', false)
            ->assertSee('data-portal-offline-reconnect-text', false)
            ->assertSee(PortalAuthController::offlineReconnectMessage($mode));
    }

    public function test_the_page_script_tells_each_reconnect_mode_like_the_server(): void
    {
        $script = (string) file_get_contents(resource_path('js/lib/offline.js'));

        foreach (['notify', 'portal_back', 'manual', 'automatic'] as $mode) {
            $this->assertStringContainsString(PortalAuthController::offlineReconnectMessage($mode), $script, $mode);
        }
    }

    private function rememberOnlineLogin(?bool $backgroundSync = null): void
    {
        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Arsip', 'student_id' => self::USERNAME], $backgroundSync);
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

    private function login(string $password, ?bool $backgroundSync = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'username' => self::USERNAME,
            'password' => $password,
            ...($backgroundSync === null ? [] : ['background_sync' => $backgroundSync]),
        ]);
    }
}
