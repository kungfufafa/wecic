<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ArchiveCourseMaterialsJob;
use App\Models\PortalArchivedFile;
use App\Models\PortalArchiveVersion;
use App\Models\PortalOfflineAccount;
use App\Services\PortalClassCatalog;
use App\Services\PortalDataCache;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalArchiveApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-archive-api';

    private const USERNAME = '20990000005';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldReceive('isSessionUsable')->andReturnTrue();
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

    public function test_summary_lists_every_archived_dataset_with_its_open_suspects(): void
    {
        PortalArchiveVersion::factory()->forOwner($this->ownerHash)->create();
        PortalArchiveVersion::factory()->forOwner($this->ownerHash)->suspect()->create();
        PortalArchiveVersion::factory()->forOwner('student_someone_else')->suspect()->create();
        PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['size' => 3000]);

        $response = $this->getAsStudent('/api/archive')->assertOk();

        $skkm = collect($response->json('data.datasets'))->firstWhere('dataset', 'skkm');
        $this->assertSame(['dataset' => 'skkm', 'label' => 'SKKM', 'versions' => 2, 'groups' => 1, 'open_suspects' => 1], array_diff_key($skkm, ['last_seen_at' => true]));
        $this->assertContains('grades', array_column($response->json('data.datasets'), 'dataset'));
        $response->assertJsonPath('data.files', ['count' => 1, 'bytes' => 3000]);
    }

    public function test_versions_are_grouped_per_parameter_set_and_mark_the_current_one(): void
    {
        $classA = ['id' => '91', 'dosen_id' => '7', 'course_id' => '8810'];
        $classB = ['id' => '92', 'dosen_id' => '7', 'course_id' => '8811'];
        $older = $this->classVersion($classA, 'Basis Data', now()->subDays(3));
        $current = $this->classVersion($classA, 'Basis Data', now()->subDay());
        $this->classVersion($classB, 'Jaringan Komputer', now()->subHours(2));

        $response = $this->getAsStudent('/api/archive/class_detail?params_hash='.PortalDataCache::paramsHash($classA))->assertOk();

        $response->assertJsonPath('data.label', 'Detail kelas (presensi & tugas)')
            ->assertJsonCount(2, 'data.groups')
            ->assertJsonPath('data.versions.0.id', $current->id)
            ->assertJsonPath('data.versions.0.is_current', true)
            ->assertJsonPath('data.versions.1.id', $older->id)
            ->assertJsonPath('data.versions.1.is_current', false);
        $this->assertEqualsCanonicalizing(['Basis Data', 'Jaringan Komputer'], array_column($response->json('data.groups'), 'subject'));
    }

    public function test_a_dataset_that_is_not_archived_is_not_found(): void
    {
        $this->getAsStudent('/api/archive/dashboard')->assertNotFound();
    }

    public function test_a_version_comes_with_its_diff_against_the_trusted_baseline(): void
    {
        PortalArchiveVersion::factory()->forOwner($this->ownerHash)->withPayload(['found' => true, 'entries' => [['name' => 'Seminar', 'status' => 'valid'], ['name' => 'Lomba', 'status' => 'valid']]])->create();
        $suspect = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->suspect()->withPayload(['found' => true, 'entries' => [['name' => 'Seminar', 'status' => 'valid']]])->create();

        $this->getAsStudent('/api/archive/versions/'.$suspect->id)
            ->assertOk()
            ->assertJsonPath('data.version.status', 'suspect')
            ->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.payload.entries.0.name', 'Seminar')
            ->assertJsonPath('data.diff', [
                ['path' => 'entries › #2 › name', 'kind' => 'removed', 'old' => 'Lomba', 'new' => null],
                ['path' => 'entries › #2 › status', 'kind' => 'removed', 'old' => 'valid', 'new' => null],
            ]);
    }

    public function test_versions_of_other_students_are_not_found(): void
    {
        $foreign = PortalArchiveVersion::factory()->forOwner('student_someone_else')->suspect()->create();

        $this->getAsStudent('/api/archive/versions/'.$foreign->id)->assertNotFound();
        $this->postAsStudent('/api/archive/versions/'.$foreign->id.'/accept')->assertNotFound();
        $this->assertSame(PortalArchiveVersion::STATUS_SUSPECT, $foreign->fresh()->status);
    }

    public function test_accepting_a_held_back_version_serves_it_from_now_on(): void
    {
        PortalArchiveVersion::factory()->forOwner($this->ownerHash)->create();
        $suspect = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->suspect()->withPayload(['found' => true, 'entries' => []])->create();

        $this->postAsStudent('/api/archive/versions/'.$suspect->id.'/accept')
            ->assertOk()
            ->assertJsonPath('data.version.status', 'trusted');

        $this->assertSame(['found' => true, 'entries' => []], app(PortalSnapshotStore::class)->get(self::TOKEN, 'skkm')['data']);
        $this->postAsStudent('/api/archive/versions/'.$suspect->id.'/accept')->assertStatus(409);
    }

    public function test_rejecting_keeps_the_trusted_data_and_only_works_on_suspects(): void
    {
        $trusted = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->create();
        $suspect = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->suspect()->create();

        $this->postAsStudent('/api/archive/versions/'.$suspect->id.'/reject')
            ->assertOk()
            ->assertJsonPath('data.version.status', 'rejected');
        $this->postAsStudent('/api/archive/versions/'.$trusted->id.'/reject')->assertStatus(409);
    }

    public function test_a_version_can_be_exported_as_json(): void
    {
        $version = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->create();

        $response = $this->getAsStudent('/api/archive/versions/'.$version->id.'/export')->assertOk();

        $this->assertStringStartsWith('attachment; filename="mycic-arsip-skkm-', (string) $response->headers->get('Content-Disposition'));
        $response->assertJsonPath('label', 'SKKM')->assertJsonPath('payload.entries.0.name', 'Seminar Nasional');
    }

    public function test_deleting_the_archive_needs_confirmation_and_removes_everything_of_the_student(): void
    {
        Storage::fake('local');
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create();
        $shared = PortalArchivedFile::factory()->forOwner('student_someone_else')->create(['sha256' => $file->sha256]);
        Storage::disk('local')->put($file->blobPath(), 'pdf');
        $own = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create();
        Storage::disk('local')->put($own->blobPath(), 'pdf');
        PortalArchiveVersion::factory()->count(2)->forOwner($this->ownerHash)->create();
        PortalOfflineAccount::query()->create(['owner_hash' => $this->ownerHash, 'password_hash' => 'x', 'last_online_login_at' => now()]);

        $this->deleteAsStudent('/api/archive', [])->assertStatus(422);
        $this->deleteAsStudent('/api/archive', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('data', ['versions' => 2, 'files' => 2]);

        $this->assertSame(0, PortalArchiveVersion::query()->forOwner($this->ownerHash)->count());
        $this->assertSame(0, PortalOfflineAccount::query()->count());
        $this->assertTrue($shared->exists());
        Storage::disk('local')->assertExists($file->blobPath());
        Storage::disk('local')->assertMissing($own->blobPath());
    }

    public function test_archived_files_are_listed_per_course_and_served_to_their_owner_only(): void
    {
        Storage::fake('local');
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['filename' => 'modul-3.pdf', 'course_name' => 'Basis Data']);
        Storage::disk('local')->put($file->blobPath(), '%PDF-1.4 modul');
        $foreign = PortalArchivedFile::factory()->forOwner('student_someone_else')->create();

        $this->getAsStudent('/api/archive/files')
            ->assertOk()
            ->assertJsonPath('data.courses.0.course_name', 'Basis Data')
            ->assertJsonPath('data.courses.0.files.0.kind_label', 'Materi')
            ->assertJsonPath('data.courses.0.files.0.download_url', '/api/archive/files/'.$file->id);

        $download = $this->getAsStudent('/api/archive/files/'.$file->id.'?download=1')->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 modul', $download->baseResponse->getFile()->getContent());
        $this->getAsStudent('/api/archive/files/'.$foreign->id)->assertNotFound();
    }

    public function test_archiving_a_class_looks_it_up_in_the_students_own_class_list(): void
    {
        Queue::fake([ArchiveCourseMaterialsJob::class]);
        $this->instance(PortalClassCatalog::class, Mockery::mock(PortalClassCatalog::class, function (MockInterface $mock): void {
            $mock->shouldReceive('findById')->with(self::TOKEN, '2265')->andReturn(['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810']);
            $mock->shouldReceive('findById')->with(self::TOKEN, '9999')->andReturnNull();
        }));

        $this->postAsStudent('/api/archive/files/sync', ['id' => 2265])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');
        $this->postAsStudent('/api/archive/files/sync', ['id' => 9999])->assertNotFound();
        $this->postAsStudent('/api/archive/files/sync', [])->assertStatus(422);

        Queue::assertPushed(ArchiveCourseMaterialsJob::class, 1);
    }

    public function test_archive_pages_render(): void
    {
        $version = PortalArchiveVersion::factory()->forOwner($this->ownerHash)->suspect()->create();

        $this->withSession(['campus_session_token' => self::TOKEN])->get('/archive')->assertOk()->assertSee('Arsip MyCIC');
        $this->withSession(['campus_session_token' => self::TOKEN])->get('/archive/grades')->assertOk()->assertSee('Riwayat Nilai (KHS)');
        $this->withSession(['campus_session_token' => self::TOKEN])->get('/archive/dashboard')->assertNotFound();
        $this->withSession(['campus_session_token' => self::TOKEN])->get('/archive/versions/'.$version->id.'/print')
            ->assertOk()
            ->assertSee('Bukti arsip MyCIC')
            ->assertSee('Kegiatan SKKM berkurang dari 2 menjadi 1.');
        $foreign = PortalArchiveVersion::factory()->forOwner('student_someone_else')->create();
        $this->withSession(['campus_session_token' => self::TOKEN])->get('/archive/versions/'.$foreign->id.'/print')->assertNotFound();
    }

    /**
     * @param  array<string, string>  $params
     */
    private function classVersion(array $params, string $course, \DateTimeInterface $seenAt): PortalArchiveVersion
    {
        return PortalArchiveVersion::factory()->forOwner($this->ownerHash)->create([
            'dataset' => 'class_detail',
            'params' => $params,
            'params_hash' => PortalDataCache::paramsHash($params),
            'subject' => $course,
            'payload' => ['course_info' => ['name' => $course]],
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
            'trusted_at' => $seenAt,
        ]);
    }

    private function getAsStudent(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['campus_session_token' => self::TOKEN])->getJson($uri);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postAsStudent(string $uri, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => self::TOKEN])
            ->postJson($uri, $payload, ['X-CSRF-TOKEN' => 'known-csrf-token']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deleteAsStudent(string $uri, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => self::TOKEN])
            ->deleteJson($uri, $payload, ['X-CSRF-TOKEN' => 'known-csrf-token']);
    }
}
