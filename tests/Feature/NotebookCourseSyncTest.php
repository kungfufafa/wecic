<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ArchivePortalFilesJob;
use App\Jobs\SyncCourseNotebookJob;
use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\NotebookChunk;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use App\Services\NotebookCourseSync;
use App\Services\NotebookLibrary;
use App\Services\PortalArchivedFileStore;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetBuilder;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Course notebooks: one per class, made on their own, filled meeting by
 * meeting, questioned with a chosen and measured context.
 */
class NotebookCourseSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-course-notebook';

    private const USERNAME = '20990000008';

    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/*';

    private const CLASS_PARAMS = ['id' => '2265', 'dosen_id' => '5240', 'course_id' => '8810'];

    private const MEETING_PARAMS = ['meeting_id' => '445', 'courses_id' => '8810'];

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.gemini.key' => null,
            'services.gemini.model' => 'gemini-test-flash',
            'services.gemini.fallback_model' => null,
            'services.gemini.retry_delay_ms' => 0,
        ]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
            $mock->shouldReceive('isSessionUsable')->andReturnTrue();
            // A student's own session: files a meeting links to are queued for the archive.
            $mock->shouldReceive('isBackgroundSession')->andReturnFalse();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->create();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();

        parent::tearDown();
    }

    public function test_course_notebooks_are_made_from_the_stored_class_list(): void
    {
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $this->classList());
        Notebook::factory()->forOwner($this->ownerHash)->create(['title' => 'Persiapan UTS']);

        $response = $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.course_notebooks', true);

        $courses = collect($response->json('data.notebooks'))->where('kind', 'course')->keyBy('course_key');
        $this->assertSame(['8810', '8811'], $courses->pluck('course_key')->sort()->values()->all());
        $this->assertSame('Basis Data', $courses['8810']['title']);
        $this->assertSame('UC01024011', $courses['8810']['course_code']);
        $this->assertSame('Dosen A', $courses['8810']['lecturer_name']);
        $this->assertSame('/class/2265?dosen_id=5240&course_id=8810', $courses['8810']['class_url']);
        $this->assertSame(0, $courses['8810']['meetings_count']);
        $this->assertSame(['custom'], collect($response->json('data.notebooks'))->where('title', 'Persiapan UTS')->pluck('kind')->all());

        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonCount(3, 'data.notebooks');
        $this->assertSame(0, Notebook::query()->where('owner_hash', '!=', $this->ownerHash)->count());
    }

    public function test_the_class_list_background_sync_keeps_fresh_wins_over_an_old_lms_class_list(): void
    {
        // A week-old LMS snapshot from the parser that read the whole lecturer cell.
        $stale = $this->classList();
        $stale['classes'][0]['lecturer_info']['name'] = 'Dosen A Kontak Dosen : 081234567890 Email Dosen : dosen.a@example.com';
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $stale);
        $this->snapshots()->put(self::TOKEN, 'classes', $this->classList());

        $courses = collect($this->getAsStudent('/api/notebooks')->assertOk()->json('data.notebooks'))->keyBy('course_key');

        $this->assertSame('Dosen A', $courses['8810']['lecturer_name']);
        $this->assertSame('Dosen A', app(NotebookCourseSync::class)->describeCourse(self::TOKEN, '8810')['lecturer_name']);
    }

    public function test_syncing_the_class_list_makes_the_course_notebooks(): void
    {
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->with(self::TOKEN, 'lms_classes', [])->andReturn($this->classList());
        }));

        app(PortalDatasetRepository::class)->sync(self::TOKEN, 'lms_classes');

        $this->assertSame(['8810', '8811'], Notebook::query()->forOwner($this->ownerHash)->where('kind', 'course')->orderBy('course_key')->pluck('course_key')->all());
        $this->assertSame(['id' => '2266', 'dosen_id' => '5241', 'course_id' => '8811'], Notebook::query()->forOwner($this->ownerHash)->course('8811')->sole()->class_params);
    }

    public function test_a_meeting_joins_its_course_notebook_with_a_note_and_its_archived_files(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $this->classList());
        $this->snapshots()->put(self::TOKEN, 'meeting_detail', $this->meeting(), params: self::MEETING_PARAMS);
        $modul = $this->archivedText(['meeting_id' => '445', 'meeting_number' => 3, 'kind' => 'modul', 'title' => 'Normalisasi', 'source_url' => $this->fileUrl('modul/abc-3-mod.pdf')], 'Normalisasi mengurangi redundansi.');
        $this->archivedText(['meeting_id' => null, 'meeting_number' => null, 'kind' => 'rps', 'title' => 'RPS', 'source_url' => $this->fileUrl('sap/rps.pdf')], 'Capaian pembelajaran.');
        $this->archivedText(['course_key' => '8811', 'meeting_id' => '900', 'kind' => 'modul', 'source_url' => $this->fileUrl('modul/other.pdf')], 'Mata kuliah lain.');

        $response = $this->sendAsStudent('POST', '/api/notebooks/meetings', self::MEETING_PARAMS)
            ->assertCreated()
            ->assertJsonPath('message', 'Pertemuan masuk ke notebook.')
            ->assertJsonPath('data.notebook.kind', 'course')
            ->assertJsonPath('data.notebook.course_name', 'Basis Data')
            ->assertJsonPath('data.notebook.meetings_count', 1)
            ->assertJsonPath('data.meeting.meeting_number', 3)
            ->assertJsonPath('data.meeting.title', 'Pertemuan 3 · Normalisasi Data')
            ->assertJsonPath('data.meeting.queued_files', 1);

        $notebook = Notebook::query()->forOwner($this->ownerHash)->course('8810')->sole();
        $sources = $notebook->sources()->orderBy('id')->get();
        $this->assertSame(['meeting', 'archived_file', 'archived_file'], $sources->pluck('kind')->all());
        $this->assertSame(['ready', 'ready', 'ready'], $sources->pluck('status')->all());
        $this->assertSame($modul->id, $sources[1]->portal_archived_file_id);
        $this->assertSame('445', $sources[1]->meeting_id);
        $this->assertSame(3, $sources[1]->meeting_number);
        $this->assertSame(3, count($response->json('data.sources')));

        $note = NotebookChunk::query()->where('notebook_source_id', $sources[0]->id)->sole()->content;
        $this->assertStringContainsString('Pertemuan 3 · Normalisasi Data', $note);
        $this->assertStringContainsString('Mata kuliah: Basis Data (UC01024011)', $note);
        $this->assertStringContainsString('Dosen: Dosen A', $note);
        $this->assertStringContainsString('Jadwal: Kamis, 09 April 2026 pukul 08:00', $note);
        $this->assertStringContainsString("Uraian pokok pembahasan:\n1. Anomali data\n2. Bentuk normal 1NF-3NF", $note);
        $this->assertStringContainsString('Tenggat (due date): Kamis, 16 April 2026', $note);
        $this->assertStringContainsString('Batas waktu unggah: 16 April 2026 23:59', $note);
        Queue::assertPushed(ArchivePortalFilesJob::class, 1);

        $this->sendAsStudent('POST', '/api/notebooks/meetings', self::MEETING_PARAMS)
            ->assertCreated()
            ->assertJsonPath('message', 'Catatan pertemuan diperbarui di notebook.');
        $this->assertSame(3, $notebook->sources()->count());
        $this->assertSame(1, NotebookChunk::query()->where('notebook_source_id', $sources[0]->id)->count());
    }

    public function test_a_meeting_read_from_the_portal_joins_its_course_notebook_on_its_own(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $this->classList());
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->andReturnUsing(fn (string $token, string $dataset, array $params): array => $params['meeting_id'] === '999'
                ? ['found' => false, 'materials' => [], 'rps_info' => []]
                : $this->meeting());
        }));

        app(PortalDatasetRepository::class)->sync(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS);

        $notebook = Notebook::query()->forOwner($this->ownerHash)->course('8810')->sole();
        $this->assertSame('UC01024011', $notebook->course_code);
        $this->assertSame(['Pertemuan 3 · Normalisasi Data'], $notebook->meetingSources()->pluck('title')->all());
        $this->assertSame('ready', $notebook->meetingSources()->value('status'));
        Queue::assertPushed(ArchivePortalFilesJob::class, 1);

        app(PortalDatasetRepository::class)->sync(self::TOKEN, 'meeting_detail', ['meeting_id' => '999', 'courses_id' => '8810']);
        $this->assertSame(1, $notebook->sources()->count());

        config(['notebook.course_notebooks.auto_add_opened_meetings' => false]);
        app(PortalDatasetRepository::class)->sync(self::TOKEN, 'meeting_detail', ['meeting_id' => '446', 'courses_id' => '8811']);
        $this->assertNull(Notebook::query()->forOwner($this->ownerHash)->course('8811')->first());
    }

    public function test_a_meeting_that_was_never_opened_is_read_from_the_portal(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $this->instance(PortalDatasetBuilder::class, Mockery::mock(PortalDatasetBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->andReturnUsing(fn (string $token, string $dataset, array $params): array => $params['meeting_id'] === '999'
                ? ['found' => false, 'materials' => [], 'rps_info' => []]
                : $this->meeting());
        }));

        $this->sendAsStudent('POST', '/api/notebooks/meetings', self::MEETING_PARAMS)
            ->assertCreated()
            ->assertJsonPath('data.notebook.course_name', 'Basis Data')
            ->assertJsonPath('data.notebook.course_code', null);
        $this->assertNotNull($this->snapshots()->get(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS));

        $this->sendAsStudent('POST', '/api/notebooks/meetings', ['meeting_id' => '999', 'courses_id' => '8810'])
            ->assertNotFound();
        $this->sendAsStudent('POST', '/api/notebooks/meetings', ['meeting_id' => 'abc', 'courses_id' => '8810'])
            ->assertStatus(422);
    }

    public function test_an_archived_file_attaches_itself_to_the_notebook_that_tracks_its_meeting(): void
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        app(NotebookLibrary::class)->addMeetingNote($notebook, '445', 3, 'Pertemuan 3', 'Catatan pertemuan 3.');
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('downloadCampusFile')->andReturnUsing(fn (string $token, string $url): array => [
                'filename' => basename($url, '.pdf').'.txt',
                'content_type' => 'text/plain',
                'contents' => 'Isi '.basename($url),
            ]);
        }));
        $store = app(PortalArchivedFileStore::class);
        $item = static fn (string $kind, string $url, ?string $meetingId, ?int $number): array => ['kind' => $kind, 'source_url' => $url, 'title' => null, 'course_key' => '8810', 'meeting_id' => $meetingId, 'meeting_number' => $number];

        $tracked = $store->store(self::TOKEN, $item('modul', $this->fileUrl('modul/p3.pdf'), '445', 3), 'Basis Data');
        $store->store(self::TOKEN, $item('modul', $this->fileUrl('modul/p4.pdf'), '446', 4), 'Basis Data');
        $rps = $store->store(self::TOKEN, $item('rps', $this->fileUrl('sap/rps.pdf'), null, null), 'Basis Data');

        $this->assertEqualsCanonicalizing([$tracked->id, $rps->id], $notebook->sources()->whereNotNull('portal_archived_file_id')->pluck('portal_archived_file_id')->all());
        // Archiving may run in a background sync: the file is read once the notebook is opened.
        $this->assertSame('pending', $notebook->sources()->where('portal_archived_file_id', $tracked->id)->value('status'));

        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();

        $this->assertSame('ready', $notebook->sources()->where('portal_archived_file_id', $tracked->id)->value('status'));
        $this->assertSame('Isi p3.pdf', NotebookChunk::query()->where('notebook_source_id', $notebook->sources()->where('portal_archived_file_id', $tracked->id)->value('id'))->value('content'));
    }

    public function test_meetings_of_the_course_are_listed_with_their_state(): void
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        app(NotebookLibrary::class)->addMeetingNote($notebook, '445', 3, 'Pertemuan 3', 'Catatan.');
        PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['course_key' => '8810', 'meeting_id' => '446', 'meeting_number' => 4]);
        $this->snapshots()->put(self::TOKEN, 'class_detail', [
            'course_info' => ['name' => 'Basis Data'],
            'course_materials' => [
                ['title' => 'Pertemuan 3 : Normalisasi', 'meeting_number' => 3, 'date' => 'Kamis, 09 April 2026', 'time' => '08:00', 'description' => 'Anomali dan bentuk normal', 'join_link' => ['href' => '?mod=sipandai&act=discussion&id=445&courses_id=8810']],
                ['title' => 'Pertemuan 4 : Trigger', 'meeting_number' => 4, 'join_link' => ['href' => '?mod=sipandai&amp;act=discussion&amp;id=446&amp;courses_id=8810']],
                ['title' => 'Tanpa link'],
            ],
        ], params: self::CLASS_PARAMS);

        $this->getAsStudent("/api/notebooks/{$notebook->id}/meetings")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonCount(2, 'data.meetings')
            ->assertJsonPath('data.meetings.0.meeting_id', '445')
            ->assertJsonPath('data.meetings.0.added', true)
            ->assertJsonPath('data.meetings.0.title', 'Pertemuan 3 : Normalisasi')
            ->assertJsonPath('data.meetings.0.date', 'Kamis, 09 April 2026')
            ->assertJsonPath('data.meetings.1.meeting_id', '446')
            ->assertJsonPath('data.meetings.1.added', false)
            ->assertJsonPath('data.meetings.1.archived_files', 1)
            ->assertJsonPath('data.meetings.1.meeting_url', '/meeting/446?courses_id=8810');
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonCount(2, 'data.meetings.meetings');

        $unknownClass = Notebook::factory()->forOwner($this->ownerHash)->course('8811')->create(['class_params' => ['id' => '2266', 'dosen_id' => '5241', 'course_id' => '8811']]);
        $this->getAsStudent("/api/notebooks/{$unknownClass->id}/meetings")->assertOk()->assertJsonPath('data.available', false)->assertJsonCount(0, 'data.meetings');

        $custom = Notebook::factory()->forOwner($this->ownerHash)->create();
        $this->getAsStudent("/api/notebooks/{$custom->id}/meetings")->assertStatus(422);
        $this->getAsStudent("/api/notebooks/{$custom->id}")->assertOk()->assertJsonPath('data.meetings', null);
    }

    public function test_the_course_notebook_can_be_opened_by_course_key(): void
    {
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $this->classList());

        $created = $this->sendAsStudent('POST', '/api/notebooks/course', ['course_key' => '8810'])
            ->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.notebook.course_code', 'UC01024011');
        $this->sendAsStudent('POST', '/api/notebooks/course', ['course_key' => '8810'])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.notebook.id', $created->json('data.notebook.id'));

        PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['course_key' => '7777', 'course_name' => 'Hanya di arsip']);
        $this->sendAsStudent('POST', '/api/notebooks/course', ['course_key' => '7777'])->assertCreated()->assertJsonPath('data.notebook.title', 'Hanya di arsip');
        $this->sendAsStudent('POST', '/api/notebooks/course', ['course_key' => '6666'])->assertNotFound();
    }

    public function test_questions_can_be_limited_to_chosen_sources_and_record_their_context(): void
    {
        config(['services.gemini.key' => 'test-gemini-key']);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $normalisasi = $this->readySource($notebook, 'Modul P3', 3, 'Normalisasi mengurangi redundansi.');
        $trigger = $this->readySource($notebook, 'Modul P4', 4, 'Trigger berjalan otomatis saat INSERT.');
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Trigger berjalan otomatis [1].']]], 'finishReason' => 'STOP']]])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Kapan trigger jalan?', 'source_ids' => [$trigger->id]])
            ->assertCreated()
            ->assertJsonPath('data.answer.citations.0.source_id', $trigger->id)
            ->assertJsonPath('data.answer.context.source_ids', [$trigger->id])
            ->assertJsonPath('data.answer.context.sources.0.meeting_number', 4)
            ->assertJsonPath('data.answer.context.total_sources', 2)
            ->assertJsonPath('data.answer.context.excerpts', 1)
            ->assertJsonPath('data.answer.context.partial', false)
            ->assertJsonPath('data.answer.context.focus', null);
        Http::assertSent(function (Request $request): bool {
            $prompt = $request['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($prompt, 'Trigger berjalan') && ! str_contains($prompt, 'Normalisasi');
        });

        $pending = NotebookSource::factory()->pending()->create(['notebook_id' => $notebook->id]);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?', 'source_ids' => [$pending->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sumber yang dipilih belum ada yang siap dibaca. Pilih sumber lain atau tunggu sebentar.');
        $this->assertSame(2, NotebookMessage::query()->count());
        $this->assertNotNull($normalisasi->fresh());
    }

    public function test_an_assignment_outline_is_built_around_the_focus(): void
    {
        config(['services.gemini.key' => 'test-gemini-key']);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $this->readySource($notebook, 'Modul P3', 3, 'Normalisasi mengurangi redundansi.');
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => "## Pemahaman tugas\nMakalah normalisasi [1].\n## Kerangka\n- Pendahuluan [1]"]]], 'finishReason' => 'STOP']]])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'assignment_plan', 'focus' => 'Makalah 5 halaman tentang normalisasi'])
            ->assertCreated()
            ->assertJsonPath('message', 'Kerangka tugas dibuat.')
            ->assertJsonPath('data.message.kind', 'assignment_plan')
            ->assertJsonPath('data.message.pinned', true)
            ->assertJsonPath('data.message.citations.0.n', 1)
            ->assertJsonPath('data.message.context.focus', 'Makalah 5 halaman tentang normalisasi')
            ->assertJsonPath('data.message.context.sources.0.title', 'Modul P3');
        Http::assertSent(function (Request $request): bool {
            $prompt = $request['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($prompt, 'Instruksi/fokus tugas dari mahasiswa: Makalah 5 halaman tentang normalisasi')
                && str_contains($prompt, '## Checklist sebelum dikumpulkan')
                && ($request['generationConfig']['responseMimeType'] ?? null) === null;
        });
    }

    public function test_emptying_a_course_notebook_keeps_it(): void
    {
        $course = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        app(NotebookLibrary::class)->addMeetingNote($course, '445', 3, 'Pertemuan 3', 'Catatan.');
        NotebookMessage::factory()->create(['notebook_id' => $course->id]);
        $custom = Notebook::factory()->forOwner($this->ownerHash)->create();

        $this->sendAsStudent('DELETE', "/api/notebooks/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.emptied', true);
        $this->assertNotNull($course->fresh());
        $this->assertSame(0, $course->sources()->count());
        $this->assertSame(0, $course->messages()->count());
        $this->assertSame(0, NotebookChunk::query()->count());

        $this->sendAsStudent('DELETE', "/api/notebooks/{$custom->id}")->assertOk()->assertJsonPath('data.emptied', false);
        $this->assertNull($custom->fresh());
    }

    public function test_the_sync_job_adds_every_meeting_of_the_class(): void
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create(['course_code' => null, 'lecturer_name' => null]);
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'class_detail', self::CLASS_PARAMS)->andReturn([
                'course_info' => ['name' => 'Basis Data', 'code' => 'UC01024011'],
                'lecturer_info' => ['name' => 'Dosen A'],
                'course_materials' => [
                    ['title' => 'Pertemuan 3', 'join_link' => ['href' => '?mod=sipandai&act=discussion&id=445&courses_id=8810']],
                    ['title' => 'Pertemuan 4', 'join_link' => ['href' => '?mod=sipandai&amp;act=discussion&amp;id=446&amp;courses_id=8810']],
                    ['title' => 'Tanpa link'],
                ],
            ]);
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS)->andReturn($this->meeting());
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'meeting_detail', ['meeting_id' => '446', 'courses_id' => '8810'])->andReturn(['found' => false, 'materials' => [], 'rps_info' => []]);
        }));
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('downloadCampusFile')->andReturnUsing(fn (string $token, string $url): array => [
                'filename' => basename($url, '.pdf').'.txt',
                'content_type' => 'text/plain',
                'contents' => 'Isi '.basename($url),
            ]);
        }));
        $this->instance(PortalBroadcastService::class, Mockery::mock(PortalBroadcastService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dataInvalidated')->once()->withArgs(fn (string $sessionId, string $token, array $cacheKeys, array $syncKeys, string $message): bool => $message === '1 pertemuan masuk ke notebook "Basis Data". Berkas materinya dibaca di background.'
                && in_array('notebook-show', $syncKeys, true));
        }));

        app()->call([new SyncCourseNotebookJob('laravel-session', self::TOKEN, $notebook->id), 'handle']);

        $notebook->refresh();
        $this->assertSame('UC01024011', $notebook->course_code);
        $this->assertSame('Dosen A', $notebook->lecturer_name);
        $this->assertSame(['modul', 'latihan'], PortalArchivedFile::query()->forOwner($this->ownerHash)->orderBy('id')->pluck('kind')->all());
        $sources = $notebook->sources()->orderBy('id')->get();
        $this->assertSame(['meeting', 'archived_file', 'archived_file'], $sources->pluck('kind')->all());
        $this->assertSame(['445', '445', '445'], $sources->pluck('meeting_id')->all());
        $this->assertSame(['ready', 'ready', 'ready'], $sources->pluck('status')->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function classList(): array
    {
        return ['classes' => [
            ['course_info' => ['code' => 'UC01024011', 'name' => 'Basis Data'], 'lecturer_info' => ['name' => 'Dosen A'], 'join_link' => ['params' => self::CLASS_PARAMS]],
            ['course_info' => ['code' => 'TI202', 'name' => 'Jaringan Komputer'], 'lecturer_info' => ['name' => 'Dosen B'], 'join_link' => ['params' => ['id' => '2266', 'dosen_id' => '5241', 'course_id' => '8811']]],
            ['course_info' => ['name' => 'Tanpa link'], 'join_link' => []],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function meeting(): array
    {
        return [
            'found' => true,
            'meeting_info' => ['breadcrumb' => 'Kelas Basis Data > Pertemuan 3'],
            'lecturer_info' => ['name' => 'Dosen A'],
            'schedule_info' => ['date' => 'Kamis, 09 April 2026', 'start_time' => '08:00'],
            'materials' => [
                'main_topic' => 'Pokok Bahasan : Normalisasi Data',
                'main_description' => "1. Anomali data\n2. Bentuk normal 1NF-3NF",
                'main_material_iframe' => '../files/modul/abc-3-mod.pdf',
                'assignment_topic' => 'Pertemuan 3',
                'assignment_instructions' => 'Kamis, 16 April 2026',
                'assignment_material_iframe' => '../files/latihan/abc-3-lat.pdf',
            ],
            'assignment_info' => ['deadline' => 'Kamis, 16 April 2026'],
            'instructions' => ['deadline_text' => '16 April 2026 23:59'],
            'rps_info' => ['file_link' => '', 'file_text' => ''],
        ];
    }

    /**
     * An archived course file whose blob is plain text, so it is read without the model.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function archivedText(array $attributes, string $contents): PortalArchivedFile
    {
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create([
            'course_key' => '8810',
            'course_name' => 'Basis Data',
            'mime' => 'text/plain',
            'filename' => 'materi.txt',
            'sha256' => hash('sha256', $contents),
            ...$attributes,
        ]);
        Storage::disk('local')->put($file->blobPath(), $contents);

        return $file;
    }

    private function readySource(Notebook $notebook, string $title, int $meetingNumber, string $content): NotebookSource
    {
        $source = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'title' => $title, 'meeting_id' => (string) (440 + $meetingNumber), 'meeting_number' => $meetingNumber]);
        NotebookChunk::query()->create(['notebook_source_id' => $source->id, 'notebook_id' => $notebook->id, 'position' => 0, 'page' => null, 'content' => $content]);

        return $source;
    }

    /**
     * A course file's canonical URL on the configured portal host, as
     * PortalUrlGuard::campusFileUrl() builds it from a meeting page.
     */
    private function fileUrl(string $path): string
    {
        return rtrim((string) config('portal.base_url'), '/').'/portal/files/'.$path;
    }

    private function snapshots(): PortalSnapshotStore
    {
        return app(PortalSnapshotStore::class);
    }

    private function getAsStudent(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['campus_session_token' => self::TOKEN])->getJson($uri);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendAsStudent(string $method, string $uri, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => self::TOKEN])
            ->json($method, $uri, $payload, ['X-CSRF-TOKEN' => 'known-csrf-token']);
    }
}
