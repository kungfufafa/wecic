<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\PortalArchivedFile;
use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Models\PortalOfflineAccount;
use App\Services\PortalDataOwner;
use App\Services\PortalLogContext;
use App\Services\PortalOfflineAccess;
use App\Services\PortalOwnerRekeyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PortalDataOwnerTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = '20990000007';

    private const OLD_APP_KEY = 'base64:b2xkLWFwcC1rZXktZm9yLW93bmVyLWhhc2gtdGVzdHM=';

    private const NEW_APP_KEY = 'base64:bmV3LWFwcC1rZXktZm9yLW93bmVyLWhhc2gtdGVzdHM=';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => self::OLD_APP_KEY,
            'app.previous_keys' => [],
            'portal.archive.owner_key' => null,
            'portal.archive.previous_owner_keys' => [],
        ]);
        $this->app->forgetInstance(PortalDataOwner::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_without_a_pinned_owner_key_the_owner_key_is_the_one_app_key_always_made(): void
    {
        $this->assertSame('student_'.PortalLogContext::fingerprint(self::USERNAME), app(PortalDataOwner::class)->keyForUsername(' '.self::USERNAME.' '));
        $this->assertSame($this->keyMadeWith(self::OLD_APP_KEY), app(PortalDataOwner::class)->keyForUsername(self::USERNAME));
    }

    public function test_an_owner_key_pinned_to_the_app_key_survives_its_rotation(): void
    {
        $before = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

        $this->rotateAppKey(pinOwnerKey: true);

        $this->assertSame($before, app(PortalDataOwner::class)->keyForUsername(self::USERNAME));
    }

    public function test_data_stored_under_an_earlier_app_key_moves_to_the_current_key_at_the_next_login(): void
    {
        $earlier = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
        $owned = [
            PortalArchiveVersion::factory()->forOwner($earlier)->create(),
            PortalArchivedFile::factory()->forOwner($earlier)->create(),
            PortalNotification::factory()->forOwner($earlier)->create(),
            Notebook::factory()->forOwner($earlier)->create(),
            NotebookAiConsent::factory()->forOwner($earlier)->create(),
            PortalOfflineAccount::query()->create(['owner_hash' => $earlier, 'password_hash' => Hash::make('rahasia'), 'last_online_login_at' => now()]),
        ];

        $this->rotateAppKey();
        $this->assertNull(PortalOfflineAccount::query()->where('owner_hash', $this->keyMadeWith(self::NEW_APP_KEY))->first());

        $this->assertNotNull(app(PortalOfflineAccess::class)->attempt(self::USERNAME, 'rahasia'));

        $current = $this->keyMadeWith(self::NEW_APP_KEY);
        $this->assertNotSame($earlier, $current);

        foreach ($owned as $model) {
            $this->assertSame($current, $model->fresh()->owner_hash, $model::class);
        }
    }

    public function test_an_earlier_offline_account_gives_way_to_the_current_one_and_its_background_sync_choice(): void
    {
        $earlier = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
        PortalOfflineAccount::query()->create([
            'owner_hash' => $earlier,
            'password_hash' => '',
            'credentials' => ['username' => self::USERNAME, 'password' => 'lama'],
            'background_sync_enabled_at' => now()->subDays(3),
            'last_online_login_at' => now()->subDays(3),
        ]);
        $this->rotateAppKey();
        $current = $this->keyMadeWith(self::NEW_APP_KEY);
        PortalOfflineAccount::query()->create([
            'owner_hash' => $current,
            'password_hash' => Hash::make('rahasia'),
            'last_online_login_at' => now(),
        ]);

        app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

        $account = PortalOfflineAccount::query()->sole();
        $this->assertSame($current, $account->owner_hash);
        $this->assertFalse($account->isBackgroundSyncEnabled(), 'An earlier opt-in never switches the newer row on.');
        $this->assertNull($account->credentials);
        $this->assertTrue(Hash::check('rahasia', $account->password_hash));
    }

    public function test_rows_the_current_key_already_has_are_dropped_instead_of_duplicated(): void
    {
        $earlier = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
        $this->rotateAppKey();
        $current = $this->keyMadeWith(self::NEW_APP_KEY);
        PortalNotification::factory()->forOwner($earlier)->create(['dedupe_key' => 'shared']);
        PortalNotification::factory()->forOwner($earlier)->create(['dedupe_key' => 'only-earlier']);
        PortalNotification::factory()->forOwner($current)->create(['dedupe_key' => 'shared']);
        PortalArchiveVersion::factory()->forOwner($earlier)->create(['content_hash' => str_repeat('a', 64)]);
        PortalArchiveVersion::factory()->forOwner($current)->create(['content_hash' => str_repeat('a', 64)]);
        PortalArchiveVersion::factory()->forOwner($earlier)->create(['content_hash' => str_repeat('a', 64), 'sighting' => 1, 'status' => PortalArchiveVersion::STATUS_SUSPECT]);

        app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

        $this->assertSame(0, PortalNotification::query()->where('owner_hash', $earlier)->count());
        $this->assertEqualsCanonicalizing(['shared', 'only-earlier'], PortalNotification::query()->where('owner_hash', $current)->pluck('dedupe_key')->all());
        $this->assertSame(0, PortalArchiveVersion::query()->where('owner_hash', $earlier)->count());
        $this->assertEqualsCanonicalizing([0, 1], PortalArchiveVersion::query()->where('owner_hash', $current)->pluck('sighting')->all());
    }

    public function test_listed_earlier_owner_secrets_and_the_app_key_before_pinning_are_moved_too(): void
    {
        config(['portal.archive.owner_key' => 'pinned-owner-secret-2', 'portal.archive.previous_owner_keys' => ['pinned-owner-secret-1']]);
        $this->app->forgetInstance(PortalDataOwner::class);
        $fromListedSecret = PortalNotification::factory()->forOwner($this->keyMadeWith('pinned-owner-secret-1'))->create();
        $fromAppKey = PortalNotification::factory()->forOwner($this->keyMadeWith(self::OLD_APP_KEY))->create();

        $current = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);

        $this->assertSame($this->keyMadeWith('pinned-owner-secret-2'), $current);
        $this->assertSame($current, $fromListedSecret->fresh()->owner_hash);
        $this->assertSame($current, $fromAppKey->fresh()->owner_hash);
    }

    public function test_a_move_that_fails_never_fails_the_login_and_is_tried_again(): void
    {
        $this->rotateAppKey();
        $this->instance(PortalOwnerRekeyer::class, Mockery::mock(PortalOwnerRekeyer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('rekey')->twice()->andThrow(new RuntimeException('database is locked'));
        }));
        $this->app->forgetInstance(PortalDataOwner::class);

        $this->assertSame($this->keyMadeWith(self::NEW_APP_KEY), app(PortalDataOwner::class)->keyForUsername(self::USERNAME));
        $this->assertSame($this->keyMadeWith(self::NEW_APP_KEY), app(PortalDataOwner::class)->keyForUsername(self::USERNAME));
    }

    public function test_without_earlier_secrets_nothing_is_looked_up(): void
    {
        $this->instance(PortalOwnerRekeyer::class, Mockery::mock(PortalOwnerRekeyer::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('rekey');
        }));
        $this->app->forgetInstance(PortalDataOwner::class);

        $this->assertSame($this->keyMadeWith(self::OLD_APP_KEY), app(PortalDataOwner::class)->keyForUsername(self::USERNAME));
    }

    /**
     * APP_KEY rotated the way config/app.php supports it: the old key stays
     * in APP_PREVIOUS_KEYS, optionally with the owner key pinned to it first.
     */
    private function rotateAppKey(bool $pinOwnerKey = false): void
    {
        config([
            'app.key' => self::NEW_APP_KEY,
            'app.previous_keys' => [self::OLD_APP_KEY],
            'portal.archive.owner_key' => $pinOwnerKey ? self::OLD_APP_KEY : null,
        ]);
        $this->app->forgetInstance(PortalDataOwner::class);
        $this->app->forgetInstance(PortalOfflineAccess::class);
    }

    private function keyMadeWith(string $secret): string
    {
        return 'student_'.substr(hash_hmac('sha256', self::USERNAME, $secret), 0, 16);
    }
}
