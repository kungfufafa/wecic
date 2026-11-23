<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExpirePortalSessionJob;
use App\Jobs\RefreshPortalSessionJob;
use App\Jobs\ResumeOfflineSessionJob;
use App\Models\ExamSubmissionRecord;
use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\NotebookAiSetting;
use App\Models\NotebookChunk;
use App\Models\NotebookDismissal;
use App\Models\NotebookMessage;
use App\Models\NotebookReviewCard;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use App\Models\PortalArchiveVersion;
use App\Models\PortalMobileSession;
use App\Models\PortalNotification;
use App\Models\PortalOfflineAccount;
use App\Models\PortalOutboxItem;
use App\Models\PortalPushToken;
use App\Services\PortalDataCache;
use App\Services\PortalDataOwner;
use App\Services\PortalMobileSessions;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PortalAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const ERASED = '20990000011';

    private const KEPT = '20990000022';

    private const CLASS_PARAMS = ['id' => '91', 'dosen_id' => '7', 'course_id' => '8810'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Bus::fake([RefreshPortalSessionJob::class, ExpirePortalSessionJob::class, ResumeOfflineSessionJob::class]);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_deleting_the_account_removes_everything_of_the_student_and_nothing_of_another(): void
    {
        $erased = $this->seedStudent(self::ERASED);
        $kept = $this->seedStudent(self::KEPT);

        $this->withToken($erased['bearer'])
            ->deleteJson('/api/v1/account', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.versions', 2)
            ->assertJsonPath('data.files', 1)
            ->assertJsonPath('data.notebooks', 1)
            ->assertJsonPath('data.ai_consents', 1)
            ->assertJsonPath('data.ai_settings', 1)
            ->assertJsonPath('data.notifications', 1)
            ->assertJsonPath('data.outbox', 1)
            ->assertJsonPath('data.push_tokens', 1)
            ->assertJsonPath('data.exam_records', 1)
            ->assertJsonPath('data.offline_accounts', 1)
            ->assertJsonPath('data.sessions', 3)
            ->assertJsonPath('data.mobile_sessions', 2);

        $this->assertStudentErased($erased);
        $this->assertStudentKept($kept);

        $this->withToken($erased['bearer'])->getJson('/api/v1/auth/session')->assertUnauthorized();
    }

    public function test_the_web_app_deletes_the_account_through_its_cookie_session(): void
    {
        $erased = $this->seedStudent(self::ERASED);
        $kept = $this->seedStudent(self::KEPT);

        $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => $erased['web_token']])
            ->deleteJson('/api/account', ['confirm' => true], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertOk()
            ->assertJsonPath('data.sessions', 3)
            ->assertJsonPath('data.mobile_sessions', 2)
            ->assertSessionMissing('campus_session_token');

        $this->assertStudentErased($erased);
        $this->assertStudentKept($kept);
    }

    public function test_deleting_the_account_needs_confirmation(): void
    {
        $erased = $this->seedStudent(self::ERASED);

        $this->withToken($erased['bearer'])
            ->deleteJson('/api/v1/account', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Konfirmasi dulu sebelum menghapus akun.');
        $this->withToken($erased['bearer'])
            ->deleteJson('/api/v1/account', ['confirm' => false])
            ->assertStatus(422);

        $this->assertSame(2, PortalArchiveVersion::query()->forOwner($erased['owner'])->count());
        $this->assertNotNull(app(PortalSessionManager::class)->getSession($erased['web_token']));
    }

    public function test_deleting_the_account_without_a_session_is_unauthorized(): void
    {
        $kept = $this->seedStudent(self::KEPT);

        $this->withToken('mycic_'.Str::random(48))->deleteJson('/api/v1/account', ['confirm' => true])->assertUnauthorized();
        $this->withSession(['_token' => 'known-csrf-token'])
            ->deleteJson('/api/account', ['confirm' => true], ['X-CSRF-TOKEN' => 'known-csrf-token'])
            ->assertUnauthorized();

        $this->assertStudentKept($kept);
    }

    public function test_the_account_deletion_page_needs_a_session(): void
    {
        $this->get('/account/delete')->assertRedirect('/login');
    }

    /**
     * Every kind of row and file WeCIC keeps for a student, and three
     * sessions: the web one, another device's, and a mobile app login.
     *
     * @return array{owner: string, username: string, web_token: string, other_token: string, mobile_token: string, other_laravel_session: string, mobile_laravel_session: string, bearer: string, mobile_session_id: int, tablet_session_id: int, blobs: list<string>, versions: list<int>}
     */
    private function seedStudent(string $username): array
    {
        $sessions = app(PortalSessionManager::class);
        $owner = app(PortalDataOwner::class)->keyForUsername($username);
        $webToken = $sessions->createOfflineSession($username, ['name' => 'Mahasiswa '.$username])['session_token'];
        $otherToken = $sessions->createOfflineSession($username, ['name' => 'Mahasiswa '.$username])['session_token'];
        $mobileToken = $sessions->createOfflineSession($username, ['name' => 'Mahasiswa '.$username])['session_token'];

        $otherLaravelSession = $this->storeLaravelSession(['campus_session_token' => $otherToken]);
        $sessions->rememberLaravelSessionId($otherToken, $otherLaravelSession);
        $mobileLaravelSession = $this->storeLaravelSession(['campus_session_token' => $mobileToken]);
        $issued = app(PortalMobileSessions::class)->issue($mobileLaravelSession, 'Pixel');
        PortalPushToken::factory()->forOwner($owner)->create(['portal_mobile_session_id' => $issued['mobile_session']->id]);
        $tabletLaravelSession = $this->storeLaravelSession(['campus_session_token' => $mobileToken]);
        $tablet = app(PortalMobileSessions::class)->issue($tabletLaravelSession, 'Tablet')['mobile_session'];

        $versions = [
            PortalArchiveVersion::factory()->forOwner($owner)->create()->id,
            PortalArchiveVersion::factory()->forOwner($owner)->create([
                'dataset' => 'class_detail',
                'params' => self::CLASS_PARAMS,
                'params_hash' => PortalDataCache::paramsHash(self::CLASS_PARAMS),
            ])->id,
        ];

        $file = PortalArchivedFile::factory()->forOwner($owner)->create(['sha256' => hash('sha256', $username)]);
        Storage::disk('local')->put($file->blobPath(), 'pdf '.$username);

        NotebookAiConsent::factory()->forOwner($owner)->create();
        NotebookAiSetting::factory()->forOwner($owner)->anthropic()->create();
        $notebook = Notebook::factory()->forOwner($owner)->create();
        $upload = 'notebook-uploads/'.$notebook->id.'/catatan.pdf';
        Storage::disk('local')->put($upload, 'upload '.$username);
        $source = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'kind' => NotebookSource::KIND_UPLOAD, 'disk_path' => $upload]);
        NotebookChunk::query()->create(['notebook_source_id' => $source->id, 'notebook_id' => $notebook->id, 'position' => 0, 'page' => null, 'content' => 'Isi catatan']);
        NotebookMessage::factory()->create(['notebook_id' => $notebook->id]);
        NotebookDismissal::factory()->create(['notebook_id' => $notebook->id]);
        NotebookReviewCard::factory()->create(['notebook_id' => $notebook->id]);

        PortalNotification::factory()->forOwner($owner)->create();
        $outbox = PortalOutboxItem::factory()->forOwner($owner)->withFile()->create();
        ExamSubmissionRecord::factory()->create(['username' => $username, 'record_key' => 'exam-'.$username]);
        PortalOfflineAccount::query()->create([
            'owner_hash' => $owner,
            'password_hash' => Hash::make('rahasia-'.$username),
            'credentials' => ['username' => $username, 'password' => 'rahasia-'.$username],
            'user_info' => ['name' => 'Mahasiswa '.$username],
            'background_sync_enabled_at' => now(),
            'last_online_login_at' => now(),
        ]);

        app(PortalSnapshotStore::class)->put($webToken, 'grades', ['found' => true]);
        app(PortalSnapshotStore::class)->put($webToken, 'class_detail', ['found' => true], params: self::CLASS_PARAMS);
        app(PortalDataCache::class)->remember($webToken, 'class_detail', self::CLASS_PARAMS, fn (): array => ['found' => true]);
        Cache::forever($this->baselineKey($owner), ['rules' => []]);

        return [
            'owner' => $owner,
            'username' => $username,
            'web_token' => $webToken,
            'other_token' => $otherToken,
            'mobile_token' => $mobileToken,
            'other_laravel_session' => $otherLaravelSession,
            'mobile_laravel_session' => $mobileLaravelSession,
            'bearer' => $issued['token'],
            'mobile_session_id' => $issued['mobile_session']->id,
            'tablet_session_id' => $tablet->id,
            'blobs' => [$file->blobPath(), $upload, (string) $outbox->file_path],
            'versions' => $versions,
        ];
    }

    /**
     * @param  array{owner: string, username: string, web_token: string, other_token: string, mobile_token: string, other_laravel_session: string, mobile_laravel_session: string, bearer: string, mobile_session_id: int, tablet_session_id: int, blobs: list<string>, versions: list<int>}  $student
     */
    private function assertStudentErased(array $student): void
    {
        $owner = $student['owner'];

        $this->assertSame(0, PortalArchiveVersion::query()->forOwner($owner)->count());
        $this->assertSame(0, PortalArchivedFile::query()->forOwner($owner)->count());
        $this->assertSame(0, Notebook::query()->forOwner($owner)->count());
        $this->assertSame(0, NotebookAiConsent::query()->forOwner($owner)->count());
        $this->assertSame(0, NotebookAiSetting::query()->forOwner($owner)->count());
        $this->assertSame(0, NotebookReviewCard::query()->forOwner($owner)->count());
        $this->assertSame(0, NotebookReviewCard::query()->whereNotIn('notebook_id', Notebook::query()->select('id'))->count(), 'No review card outlives its notebook.');
        $this->assertSame(0, PortalNotification::query()->forOwner($owner)->count());
        $this->assertSame(0, PortalOutboxItem::query()->forOwner($owner)->count());
        $this->assertSame(0, PortalPushToken::query()->forOwner($owner)->count());
        $this->assertSame(0, PortalOfflineAccount::query()->where('owner_hash', $owner)->count());
        $this->assertSame(0, ExamSubmissionRecord::query()->where('username', $student['username'])->count());
        $this->assertNull(PortalMobileSession::query()->find($student['mobile_session_id']));
        $this->assertNull(PortalMobileSession::query()->find($student['tablet_session_id']));
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $student['mobile_session_id'])->count());

        foreach ($student['blobs'] as $blob) {
            Storage::disk('local')->assertMissing($blob);
        }

        foreach (['web_token', 'other_token', 'mobile_token'] as $token) {
            $this->assertNull(app(PortalSessionManager::class)->getSession($student[$token]));
        }

        $this->assertSame([], app(PortalSessionManager::class)->sessionTokensForOwner($owner));
        $this->assertSame([], $this->readLaravelSession($student['other_laravel_session']));
        $this->assertSame([], $this->readLaravelSession($student['mobile_laravel_session']));

        $this->assertFalse(Cache::has($this->snapshotKey($owner, 'grades', [])));
        $this->assertFalse(Cache::has($this->snapshotKey($owner, 'class_detail', self::CLASS_PARAMS)));
        $this->assertFalse(Cache::has('portal_resource_'.md5($owner.'|class_detail|'.PortalDataCache::paramsHash(self::CLASS_PARAMS))));
        $this->assertFalse(Cache::has($this->baselineKey($owner)));
    }

    /**
     * @param  array{owner: string, username: string, web_token: string, other_token: string, mobile_token: string, other_laravel_session: string, mobile_laravel_session: string, bearer: string, mobile_session_id: int, tablet_session_id: int, blobs: list<string>, versions: list<int>}  $student
     */
    private function assertStudentKept(array $student): void
    {
        $owner = $student['owner'];

        $this->assertSame($student['versions'], PortalArchiveVersion::query()->forOwner($owner)->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, PortalArchivedFile::query()->forOwner($owner)->count());
        $this->assertSame(1, Notebook::query()->forOwner($owner)->count());
        $this->assertSame(1, NotebookAiConsent::query()->forOwner($owner)->count());
        $this->assertSame(1, NotebookAiSetting::query()->forOwner($owner)->count());
        $notebookId = Notebook::query()->forOwner($owner)->value('id');
        $this->assertSame(1, NotebookSource::query()->where('notebook_id', $notebookId)->count());
        $this->assertSame(1, NotebookChunk::query()->where('notebook_id', $notebookId)->count());
        $this->assertSame(1, NotebookMessage::query()->where('notebook_id', $notebookId)->count());
        $this->assertSame(1, NotebookDismissal::query()->where('notebook_id', $notebookId)->count());
        $this->assertSame(1, NotebookReviewCard::query()->where('notebook_id', $notebookId)->count());
        $this->assertSame(1, PortalNotification::query()->forOwner($owner)->count());
        $this->assertSame(1, PortalOutboxItem::query()->forOwner($owner)->count());
        $this->assertSame(1, PortalPushToken::query()->forOwner($owner)->count());
        $this->assertNotNull(PortalOfflineAccount::query()->where('owner_hash', $owner)->value('credentials'));
        $this->assertSame(1, ExamSubmissionRecord::query()->where('username', $student['username'])->count());
        $this->assertNotNull(PortalMobileSession::query()->find($student['mobile_session_id']));
        $this->assertNotNull(PortalMobileSession::query()->find($student['tablet_session_id']));
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $student['mobile_session_id'])->count());

        foreach ($student['blobs'] as $blob) {
            Storage::disk('local')->assertExists($blob);
        }

        foreach (['web_token', 'other_token', 'mobile_token'] as $token) {
            $this->assertNotNull(app(PortalSessionManager::class)->getSession($student[$token]));
        }

        $this->assertSame($student['other_token'], $this->readLaravelSession($student['other_laravel_session'])['campus_session_token'] ?? null);
        $this->assertSame($student['mobile_token'], $this->readLaravelSession($student['mobile_laravel_session'])['campus_session_token'] ?? null);

        $this->assertTrue(Cache::has($this->snapshotKey($owner, 'grades', [])));
        $this->assertTrue(Cache::has($this->snapshotKey($owner, 'class_detail', self::CLASS_PARAMS)));
        $this->assertTrue(Cache::has('portal_resource_'.md5($owner.'|class_detail|'.PortalDataCache::paramsHash(self::CLASS_PARAMS))));
        $this->assertTrue(Cache::has($this->baselineKey($owner)));

        $this->withToken($student['bearer'])->getJson('/api/v1/auth/session')->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storeLaravelSession(array $attributes): string
    {
        $session = clone app('session.store');
        $session->flush();
        $session->setId(Str::random(40));
        $session->put($attributes);
        $session->save();

        return $session->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function readLaravelSession(string $id): array
    {
        $payload = app('session.store')->getHandler()->read($id);

        return $payload === '' ? [] : (array) unserialize($payload);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function snapshotKey(string $owner, string $domain, array $params): string
    {
        return 'portal_snapshot_'.md5($owner.'|'.$domain.'|'.PortalDataCache::paramsHash($params));
    }

    private function baselineKey(string $owner): string
    {
        return 'portal_notify_baseline_'.md5($owner.'|class_detail|'.PortalDataCache::paramsHash(self::CLASS_PARAMS));
    }
}
