<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortalOfflineAccount;
use App\Services\PortalBackgroundSync;
use App\Services\PortalDataOwner;
use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * What MyCIC keeps to act for a student (PortalOfflineAccount) does not
 * outlive its use: `model:prune` drops the stored credentials, the
 * offline-login hash and the row itself as their windows pass, and a
 * password the portal refused is never kept.
 */
class PortalOfflineAccountRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = '20990000031';

    private const PASSWORD = 'rahasia-portal';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'portal.archive.offline_login_days' => 30,
            'portal.background_sync.credential_days' => 30,
        ]);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_an_account_past_retention_is_deleted(): void
    {
        $this->account(['background_sync_enabled_at' => null, 'last_online_login_at' => now()->subDays(31)]);

        $this->prune();

        $this->assertSame(0, PortalOfflineAccount::query()->count());
    }

    public function test_an_account_within_retention_is_kept_untouched(): void
    {
        $account = $this->account(['last_online_login_at' => now()->subDays(29)]);

        $this->prune();

        $kept = PortalOfflineAccount::query()->sole();
        $this->assertSame(self::PASSWORD, $kept->credentials['password']);
        $this->assertSame($account->password_hash, $kept->password_hash);
        $this->assertSame(['name' => 'Mahasiswa Arsip'], $kept->user_info);
        $this->assertSame(0, (new PortalOfflineAccount)->prunable()->count());
    }

    public function test_an_opted_in_account_past_retention_keeps_only_its_opt_in(): void
    {
        $optedInAt = now()->subDays(40)->startOfSecond();
        $this->account([
            'background_sync_enabled_at' => $optedInAt,
            'last_online_login_at' => now()->subDays(31),
            'last_offline_login_at' => now()->subDays(32),
            'last_background_sync_at' => now()->subDays(41),
            'last_background_sync_status' => PortalOfflineAccount::SYNC_SYNCED,
            'last_course_sync_at' => now()->subDays(41),
        ]);

        $this->prune();

        $account = PortalOfflineAccount::query()->sole();
        $this->assertTrue($optedInAt->equalTo($account->background_sync_enabled_at));
        $this->assertSame('', $account->password_hash);
        $this->assertNull($account->user_info);
        $this->assertNull($account->credentials);
        $this->assertNull($account->last_offline_login_at);
        $this->assertNull($account->last_background_sync_at);
        $this->assertNull($account->last_background_sync_status);
        $this->assertNull($account->last_course_sync_at);
        $this->assertCount(0, app(PortalBackgroundSync::class)->dueAccounts());
        // An emptied opt-in is not picked up again by every later prune.
        $this->assertSame(0, (new PortalOfflineAccount)->prunable()->count());

        // The next online login still honours the opt-in.
        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Arsip']);

        $this->assertSame(self::PASSWORD, PortalOfflineAccount::query()->sole()->credentials['password']);
        $this->assertTrue(app(PortalBackgroundSync::class)->status($this->ownerHash)['active']);
    }

    public function test_credentials_go_once_their_shorter_window_has_passed(): void
    {
        config(['portal.background_sync.credential_days' => 7]);
        $this->account(['last_online_login_at' => now()->subDays(10)]);

        $this->prune();

        $account = PortalOfflineAccount::query()->sole();
        $this->assertNull($account->credentials);
        $this->assertNull(PortalOfflineAccount::query()->toBase()->value('credentials'));
        $this->assertTrue(Hash::check(self::PASSWORD, $account->password_hash));
        $this->assertSame(['name' => 'Mahasiswa Arsip'], app(PortalOfflineAccess::class)->attempt(self::USERNAME, self::PASSWORD));
        $this->assertCount(0, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_the_offline_password_goes_once_its_shorter_window_has_passed(): void
    {
        config(['portal.archive.offline_login_days' => 7]);
        $this->account(['last_online_login_at' => now()->subDays(10)]);

        $this->prune();

        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame('', $account->password_hash);
        $this->assertNull($account->user_info);
        $this->assertSame(self::PASSWORD, $account->credentials['password']);
        $this->assertCount(1, app(PortalBackgroundSync::class)->dueAccounts());
    }

    public function test_credentials_the_portal_refused_are_dropped(): void
    {
        $this->account(['password_hash' => '', 'credentials_rejected_at' => now()->subHour()]);

        $this->prune();

        $account = PortalOfflineAccount::query()->sole();
        $this->assertNull($account->credentials);
        $this->assertNotNull($account->credentials_rejected_at);
        $this->assertSame(['name' => 'Mahasiswa Arsip'], $account->user_info);
    }

    public function test_an_online_login_after_the_prune_picked_the_account_keeps_it(): void
    {
        $this->account(['last_online_login_at' => now()->subDays(31)]);
        $picked = (new PortalOfflineAccount)->prunable()->sole();

        app(PortalOfflineAccess::class)->remember(self::USERNAME, 'password-baru', ['name' => 'Mahasiswa Baru']);
        $this->assertFalse($picked->prune());

        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame('password-baru', $account->credentials['password']);
        $this->assertTrue(Hash::check('password-baru', $account->password_hash));
        $this->assertTrue($account->last_online_login_at->isToday());
    }

    public function test_a_password_refused_when_an_offline_session_resumes_is_not_kept(): void
    {
        app(PortalOfflineAccess::class)->remember(self::USERNAME, self::PASSWORD, ['name' => 'Mahasiswa Arsip'], true);
        $this->assertSame(self::PASSWORD, PortalOfflineAccount::query()->sole()->credentials['password']);

        app(PortalOfflineAccess::class)->markPasswordRejected(self::USERNAME, now());

        $account = PortalOfflineAccount::query()->sole();
        $this->assertNotNull($account->credentials_rejected_at);
        $this->assertSame('', $account->password_hash);
        $this->assertNull($account->credentials);
        $this->assertNull(PortalOfflineAccount::query()->toBase()->value('credentials'));
    }

    public function test_the_archive_page_states_how_long_the_password_is_kept(): void
    {
        config([
            'portal.archive.offline_login_days' => 45,
            'portal.background_sync.credential_days' => 14,
        ]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldReceive('isSessionUsable')->andReturnTrue();
        }));
        app(PortalDataOwner::class)->remember('token-retention', self::USERNAME, 3600);

        $this->withSession(['campus_session_token' => 'token-retention'])
            ->get('/archive')
            ->assertOk()
            ->assertSee('14 hari setelah login online terakhirmu, password terenkripsi dihapus otomatis')
            ->assertSee('45 hari sejak login itu')
            ->assertSee('(maks. 45 hari)', false)
            ->assertSee('Password yang ditolak portal langsung dihapus.');
    }

    public function test_updating_an_account_never_moves_its_last_online_login(): void
    {
        $column = collect(Schema::getColumns('portal_offline_accounts'))->firstWhere('name', 'last_online_login_at');
        $this->assertFalse($column['nullable']);
        $this->assertStringContainsStringIgnoringCase('CURRENT_TIMESTAMP', (string) $column['default']);
        $this->assertTrue(Schema::hasIndex('portal_offline_accounts', ['owner_hash'], 'unique'));

        $lastOnlineLogin = now()->subDays(20)->startOfSecond();
        $account = $this->account(['last_online_login_at' => $lastOnlineLogin]);
        $this->travel(5)->minutes();

        $account->forceFill([
            'last_background_sync_at' => now(),
            'last_background_sync_status' => PortalOfflineAccount::SYNC_SYNCED,
        ])->save();
        PortalOfflineAccount::query()->whereKey($account->getKey())->update(['last_offline_login_at' => now()]);

        $this->assertTrue($lastOnlineLogin->equalTo(PortalOfflineAccount::query()->sole()->last_online_login_at));
    }

    /**
     * MySQL 5.7 and MariaDB up to 10.9 give the table's first TIMESTAMP
     * column, when NOT NULL without a default, an implicit ON UPDATE
     * CURRENT_TIMESTAMP. The explicit default rules that out.
     */
    public function test_mysql_and_mariadb_get_an_explicit_default_without_on_update(): void
    {
        config(['database.connections.pretend-mysql' => [
            'driver' => 'mysql',
            'host' => 'portal.invalid',
            'database' => 'mycic',
            'username' => 'mycic',
            'password' => '',
            'prefix' => '',
        ]]);
        $migration = require collect(glob(database_path('migrations/*_stop_auto_update_of_last_online_login_at_on_portal_offline_accounts_table.php')))->sole();
        $defaultConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pretend-mysql');

        try {
            $queries = DB::connection('pretend-mysql')->pretend(static function () use ($migration): void {
                $migration->up();
            });
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }

        $this->assertSame(
            ['alter table `portal_offline_accounts` modify `last_online_login_at` timestamp not null default CURRENT_TIMESTAMP'],
            array_column($queries, 'query'),
        );
    }

    private function prune(): void
    {
        $this->artisan('model:prune', ['--model' => [PortalOfflineAccount::class]])->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(array $overrides = []): PortalOfflineAccount
    {
        return PortalOfflineAccount::query()->create([
            'owner_hash' => $this->ownerHash,
            'password_hash' => Hash::make(self::PASSWORD),
            'user_info' => ['name' => 'Mahasiswa Arsip'],
            'credentials' => ['username' => self::USERNAME, 'password' => self::PASSWORD],
            'background_sync_enabled_at' => now()->subDays(2),
            'last_online_login_at' => now()->subDay(),
            ...$overrides,
        ]);
    }
}
