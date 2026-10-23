<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PortalNotificationSent;
use App\Jobs\ExpirePortalSessionJob;
use App\Jobs\RefreshPortalDataJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ScanPortalNotificationsJob;
use App\Jobs\SendPortalPushNotificationsJob;
use App\Models\PortalMobileSession;
use App\Models\PortalNotification;
use App\Models\PortalPushToken;
use App\Services\PortalDataOwner;
use App\Services\PortalNotificationCenter;
use App\Services\PortalOwnerRekeyer;
use App\Services\PortalPushDevices;
use App\Services\PortalPushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;
use Tests\Traits\LogsInWithMobileToken;

/**
 * The student's notifications reach the app as push messages through Expo,
 * even while it is closed. Expo is faked: nothing here leaves the test.
 */
class PortalPushNotificationTest extends TestCase
{
    use LogsInWithMobileToken, RefreshDatabase;

    private const EXPO_URL = 'https://exp.host/--/api/v2/push/send';

    private const OWNER = 'student_push_owner';

    private const DEVICE = 'ExponentPushToken[pushTestDevice00000001]';

    private const OTHER_DEVICE = 'ExpoPushToken[pushTestDevice00000002]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->portalAcceptsLogin();
        Bus::fake([RefreshPortalSessionJob::class, ExpirePortalSessionJob::class, ScanPortalNotificationsJob::class, RefreshPortalDataJob::class]);
        Event::fake([PortalNotificationSent::class]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_the_app_registers_its_push_token_for_the_student(): void
    {
        $token = $this->loginForToken();

        $this->withToken($token)
            ->postJson('/api/devices/push-token', ['token' => self::DEVICE, 'platform' => 'ios'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.platform', 'ios');

        $pushToken = PortalPushToken::query()->sole();
        $this->assertSame(app(PortalDataOwner::class)->keyForUsername('20990000501'), $pushToken->owner_hash);
        $this->assertSame(self::DEVICE, $pushToken->token);
        $this->assertSame(PortalMobileSession::query()->sole()->id, $pushToken->portal_mobile_session_id);
    }

    public function test_the_web_session_can_register_a_push_token_too(): void
    {
        app(PortalDataOwner::class)->remember('campus-token-web', '20990000502', 3600);
        $this->withSession(['campus_session_token' => 'campus-token-web', '_token' => 'known-csrf-token']);
        $this->instance(\App\Services\PortalSessionManager::class, Mockery::mock(\App\Services\PortalSessionManager::class, function ($mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
        }));

        $this->postJson('/api/devices/push-token', ['token' => self::DEVICE], ['X-CSRF-TOKEN' => 'known-csrf-token'])->assertOk();

        $this->assertNull(PortalPushToken::query()->sole()->portal_mobile_session_id);
    }

    public function test_registration_needs_a_session_and_an_expo_token(): void
    {
        $this->withToken('mycic_unknown')->postJson('/api/devices/push-token', ['token' => self::DEVICE])->assertUnauthorized();

        $token = $this->loginForToken();

        $this->withToken($token)->postJson('/api/devices/push-token', ['token' => 'fcm-raw-token'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Token push harus berupa token Expo (ExponentPushToken[...]).');
        $this->withToken($token)->postJson('/api/devices/push-token', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Token push perangkat wajib diisi.');
        $this->withToken($token)->postJson('/api/devices/push-token', ['token' => self::DEVICE, 'platform' => 'symbian'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Platform harus ios, android, atau web.');

        $this->assertSame(0, PortalPushToken::query()->count());
    }

    public function test_an_install_belongs_to_the_student_who_registered_it_last(): void
    {
        $devices = app(PortalPushDevices::class);
        $devices->register('student_first', self::DEVICE, 'android', null);
        $this->travel(1)->minutes();

        $devices->register('student_second', self::DEVICE, 'android', null);

        $this->assertSame('student_second', PortalPushToken::query()->sole()->owner_hash);
    }

    public function test_registering_again_keeps_the_install_from_being_pruned(): void
    {
        $devices = app(PortalPushDevices::class);
        $devices->register(self::OWNER, self::DEVICE, 'android', null);
        $devices->register(self::OWNER, self::OTHER_DEVICE, 'android', null);
        $this->travel(59)->days();
        $devices->register(self::OWNER, self::DEVICE, 'android', null);
        $this->travel(2)->days();

        $this->artisan('model:prune', ['--model' => [PortalPushToken::class]])->assertSuccessful();

        $this->assertSame([self::DEVICE], PortalPushToken::query()->pluck('token')->all());
    }

    public function test_a_student_keeps_at_most_ten_installs(): void
    {
        $devices = app(PortalPushDevices::class);

        foreach (range(1, PortalPushDevices::MAX_PER_STUDENT + 2) as $index) {
            $devices->register(self::OWNER, sprintf('ExponentPushToken[device%016d]', $index), null, null);
            $this->travel(1)->seconds();
        }

        $tokens = PortalPushToken::query()->forOwner(self::OWNER)->pluck('token')->all();
        $this->assertCount(PortalPushDevices::MAX_PER_STUDENT, $tokens);
        $this->assertNotContains(sprintf('ExponentPushToken[device%016d]', 1), $tokens);
        $this->assertNotContains(sprintf('ExponentPushToken[device%016d]', 2), $tokens);
        $this->assertContains(sprintf('ExponentPushToken[device%016d]', 12), $tokens);
    }

    public function test_the_app_can_unregister_its_own_install_only(): void
    {
        $token = $this->loginForToken();
        $this->withToken($token)->postJson('/api/devices/push-token', ['token' => self::DEVICE])->assertOk();
        PortalPushToken::factory()->forOwner('student_someone_else')->create(['token' => self::OTHER_DEVICE]);

        $this->withToken($token)->deleteJson('/api/devices/push-token', ['token' => self::OTHER_DEVICE])
            ->assertOk()
            ->assertJsonPath('data.removed', 0);
        $this->withToken($token)->deleteJson('/api/devices/push-token', ['token' => self::DEVICE])
            ->assertOk()
            ->assertJsonPath('data.removed', 1);

        $this->assertSame([self::OTHER_DEVICE], PortalPushToken::query()->pluck('token')->all());
    }

    public function test_new_notifications_are_queued_as_push_for_a_student_with_installs(): void
    {
        Bus::fake([SendPortalPushNotificationsJob::class]);
        $sessionToken = $this->studentSession('20990000503');
        $ownerHash = app(PortalDataOwner::class)->key($sessionToken);
        PortalPushToken::factory()->forOwner($ownerHash)->create();

        $created = app(PortalNotificationCenter::class)->publish($sessionToken, [
            $this->notificationInput('grade-1', 'Nilai Basis Data keluar'),
            $this->notificationInput('grade-2', 'Nilai Jaringan Komputer keluar'),
        ], 'laravel-session');

        $this->assertCount(2, $created);
        Bus::assertDispatched(SendPortalPushNotificationsJob::class, fn (SendPortalPushNotificationsJob $job): bool => $job->ownerHash === $ownerHash
            && $job->notificationIds === array_map(static fn (PortalNotification $notification): int => $notification->id, $created));

        // The same changes found again are neither stored nor pushed twice.
        app(PortalNotificationCenter::class)->publish($sessionToken, [$this->notificationInput('grade-1', 'Nilai Basis Data keluar')], 'laravel-session');
        Bus::assertDispatchedTimes(SendPortalPushNotificationsJob::class, 1);
    }

    public function test_no_push_is_queued_for_a_student_without_installs(): void
    {
        Bus::fake([SendPortalPushNotificationsJob::class]);
        $sessionToken = $this->studentSession('20990000504');
        PortalPushToken::factory()->forOwner('student_someone_else')->create();

        app(PortalNotificationCenter::class)->publish($sessionToken, [$this->notificationInput('grade-1', 'Nilai keluar')], 'laravel-session');

        Bus::assertNotDispatched(SendPortalPushNotificationsJob::class);
    }

    public function test_the_job_sends_each_notification_to_each_install_of_the_student(): void
    {
        Http::fake([self::EXPO_URL => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'ok', 'id' => 'ticket-2'],
        ]])]);
        PortalPushToken::factory()->forOwner(self::OWNER)->create(['token' => self::DEVICE]);
        PortalPushToken::factory()->forOwner('student_someone_else')->create(['token' => self::OTHER_DEVICE]);
        $notification = PortalNotification::factory()->forOwner(self::OWNER)->create([
            'type' => 'deadline_reminder',
            'title' => 'Tugas Basis Data berakhir besok',
            'body' => 'Kumpulkan sebelum 23.59.',
            'url' => '/class/91',
            'urgency' => 'critical',
        ]);
        PortalNotification::factory()->forOwner(self::OWNER)->create();

        $this->runPushJob([$notification->id]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($notification): bool {
            $messages = $request->data();

            return $request->url() === self::EXPO_URL
                && count($messages) === 1
                && $messages[0]['to'] === self::DEVICE
                && $messages[0]['title'] === 'Tugas Basis Data berakhir besok'
                && $messages[0]['body'] === 'Kumpulkan sebelum 23.59.'
                && $messages[0]['data']['url'] === '/class/91'
                && $messages[0]['data']['notification_id'] === $notification->id
                && $messages[0]['priority'] === 'high'
                && $messages[0]['badge'] === 2
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_the_expo_access_token_is_sent_when_configured(): void
    {
        config(['services.expo.access_token' => 'expo-access-token-test']);
        Http::fake([self::EXPO_URL => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        PortalPushToken::factory()->forOwner(self::OWNER)->create(['token' => self::DEVICE]);
        $notification = PortalNotification::factory()->forOwner(self::OWNER)->create(['body' => null]);

        $this->runPushJob([$notification->id]);

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer expo-access-token-test']
            && ! array_key_exists('body', $request->data()[0]));
    }

    public function test_an_install_expo_reports_as_unregistered_is_removed(): void
    {
        Http::fake([self::EXPO_URL => Http::response(['data' => [
            ['status' => 'error', 'message' => '"ExponentPushToken[...]" is not a registered push notification recipient', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'ok', 'id' => 'ticket-2'],
        ]])]);
        PortalPushToken::factory()->forOwner(self::OWNER)->create(['token' => self::DEVICE]);
        PortalPushToken::factory()->forOwner(self::OWNER)->create(['token' => self::OTHER_DEVICE]);
        $notification = PortalNotification::factory()->forOwner(self::OWNER)->create();

        $accepted = app(PortalPushNotifier::class)->send(self::OWNER, [$notification]);

        $this->assertSame(1, $accepted);
        $this->assertSame([self::OTHER_DEVICE], PortalPushToken::query()->pluck('token')->all());
    }

    public function test_a_notification_read_before_the_job_runs_is_not_pushed(): void
    {
        Http::fake();
        PortalPushToken::factory()->forOwner(self::OWNER)->create(['token' => self::DEVICE]);
        $notification = PortalNotification::factory()->forOwner(self::OWNER)->read()->create();
        $someoneElses = PortalNotification::factory()->forOwner('student_someone_else')->create();

        $this->runPushJob([$notification->id, $someoneElses->id]);

        Http::assertNothingSent();
    }

    public function test_an_expo_outage_is_thrown_for_the_job_to_retry(): void
    {
        Http::fake([self::EXPO_URL => Http::response(['errors' => [['code' => 'INTERNAL_SERVER_ERROR']]], 503)]);
        PortalPushToken::factory()->forOwner(self::OWNER)->create(['token' => self::DEVICE]);
        $notification = PortalNotification::factory()->forOwner(self::OWNER)->create();

        $this->expectException(RequestException::class);

        app(PortalPushNotifier::class)->send(self::OWNER, [$notification]);
    }

    public function test_a_request_expo_refuses_is_logged_and_dropped(): void
    {
        Http::fake([self::EXPO_URL => Http::response(['errors' => [['code' => 'VALIDATION_ERROR', 'message' => 'bad']]], 400)]);
        Log::spy();
        PortalPushToken::factory()->forOwner(self::OWNER)->create(['token' => self::DEVICE]);
        $notification = PortalNotification::factory()->forOwner(self::OWNER)->create();

        $this->assertSame(0, app(PortalPushNotifier::class)->send(self::OWNER, [$notification]));

        Log::shouldHaveReceived('warning')->with('Expo refused a push request', Mockery::on(fn (array $context): bool => $context['status'] === 400));
        $this->assertSame(1, PortalPushToken::query()->count());
    }

    public function test_the_job_is_queued_with_the_notification_workers_and_retries(): void
    {
        $job = new SendPortalPushNotificationsJob(self::OWNER, [1]);

        $this->assertSame('campus-notifications', $job->queue);
        $this->assertSame(3, $job->tries);
    }

    public function test_push_tokens_follow_the_student_to_a_new_owner_key(): void
    {
        PortalPushToken::factory()->forOwner('student_old_key')->create(['token' => self::DEVICE]);

        app(PortalOwnerRekeyer::class)->rekey('student_old_key', 'student_new_key');

        $this->assertSame('student_new_key', PortalPushToken::query()->sole()->owner_hash);
    }

    /**
     * @param  list<int>  $notificationIds
     */
    private function runPushJob(array $notificationIds): void
    {
        app()->call([new SendPortalPushNotificationsJob(self::OWNER, $notificationIds), 'handle']);
    }

    private function studentSession(string $username): string
    {
        $sessionToken = 'campus-token-'.$username;
        app(PortalDataOwner::class)->remember($sessionToken, $username, 3600);

        return $sessionToken;
    }

    /**
     * @return array{type: string, dedupe_key: string, title: string, url: string, urgency: string, dataset: string}
     */
    private function notificationInput(string $key, string $title): array
    {
        return [
            'type' => 'grade_published',
            'dedupe_key' => hash('sha256', $key),
            'title' => $title,
            'url' => '/grades',
            'urgency' => 'info',
            'dataset' => 'grades',
        ];
    }
}
