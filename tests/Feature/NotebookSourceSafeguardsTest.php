<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ArchivePortalFilesJob;
use App\Jobs\IngestNotebookSourceJob;
use App\Jobs\SyncCourseNotebookJob;
use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\NotebookChunk;
use App\Models\NotebookDismissal;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use App\Services\NotebookCourseSync;
use App\Services\NotebookLibrary;
use App\Services\PortalArchivedFileStore;
use App\Services\PortalBroadcastService;
use App\Services\PortalDataOwner;
use App\Services\PortalDatasetRepository;
use App\Services\PortalSessionManager;
use App\Services\PortalSnapshotStore;
use App\Services\SipandaiSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * What keeps notebook sources bounded: files that join a course notebook on
 * their own are read only once it is opened, reading with the model has a
 * daily budget and is shared between notebooks of the same file, removed
 * sources stay removed, one source limit holds on every path, and a reading
 * whose worker died can be started again.
 */
class NotebookSourceSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-notebook-safeguards';

    private const USERNAME = '20990000009';

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
            $mock->shouldReceive('isBackgroundSession')->andReturnFalse();
        }));
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->ownerHash = app(PortalDataOwner::class)->keyForUsername(self::USERNAME);
        NotebookAiConsent::factory()->forOwner($this->ownerHash)->create();
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('notebook-reads:'.$this->ownerHash.':'.now()->format('Y-m-d'));
        Cache::flush();

        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    public function test_course_files_that_join_on_their_own_wait_until_the_notebook_is_opened(): void
    {
        Queue::fake([IngestNotebookSourceJob::class, ArchivePortalFilesJob::class]);
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $this->classList());
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        app(NotebookLibrary::class)->addMeetingNote($notebook, '446', 4, 'Pertemuan 4', 'Catatan pertemuan 4.');
        $this->downloadsReturnText();

        // A background crawl reads a meeting and archives its files.
        $this->archivedText(['meeting_id' => '445', 'meeting_number' => 3, 'source_url' => $this->fileUrl('modul/p3.pdf')], 'Isi modul 3.');
        app(NotebookCourseSync::class)->addOpenedMeeting(self::TOKEN, $this->meeting(), self::MEETING_PARAMS);
        app(PortalArchivedFileStore::class)->store(self::TOKEN, $this->item('modul', $this->fileUrl('modul/p4.pdf'), '446', 4), 'Basis Data');
        app(PortalArchivedFileStore::class)->store(self::TOKEN, $this->item('rps', $this->fileUrl('sap/rps.pdf'), null, null), 'Basis Data');
        $this->getAsStudent('/api/notebooks')->assertOk();

        $files = $notebook->sources()->where('kind', NotebookSource::KIND_ARCHIVED_FILE)->get();
        $this->assertCount(3, $files);
        $this->assertTrue($files->every(fn (NotebookSource $source): bool => $source->status === NotebookSource::STATUS_PENDING && $source->queued_at === null));
        Queue::assertNotPushed(IngestNotebookSourceJob::class);

        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.sources.0.stalled', false);

        Queue::assertPushed(IngestNotebookSourceJob::class, 3);
        $this->assertSame(0, $notebook->sources()->whereNull('queued_at')->where('kind', NotebookSource::KIND_ARCHIVED_FILE)->count());

        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        Queue::assertPushed(IngestNotebookSourceJob::class, 3);
    }

    public function test_course_files_are_read_a_few_at_a_time_while_the_notebook_is_open(): void
    {
        Queue::fake([IngestNotebookSourceJob::class]);
        config(['notebook.limits.queued_readings' => 2]);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $library = app(NotebookLibrary::class);

        foreach (['Satu.', 'Dua.', 'Tiga.'] as $contents) {
            $library->addArchivedFile($notebook, $this->archivedText(['meeting_id' => '445', 'meeting_number' => 3], $contents), byStudent: false);
        }

        $sources = $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->json('data.sources');

        Queue::assertPushed(IngestNotebookSourceJob::class, 2);
        $this->assertSame([false, false, true], array_column($sources, 'waiting'));

        // The page refreshes while they are read: nothing more until a slot frees up.
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        Queue::assertPushed(IngestNotebookSourceJob::class, 2);

        $notebook->sources()->whereNotNull('queued_at')->first()->forceFill(['status' => NotebookSource::STATUS_READY])->save();
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();

        Queue::assertPushed(IngestNotebookSourceJob::class, 3);
        $this->assertSame(0, $notebook->sources()->whereNull('queued_at')->count());
    }

    public function test_files_a_student_adds_in_bulk_are_read_a_few_at_a_time_to_the_end(): void
    {
        Queue::fake([IngestNotebookSourceJob::class]);
        config(['notebook.limits.queued_readings' => 2]);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $files = array_map(fn (int $number): PortalArchivedFile => $this->archivedText(['course_key' => '8810'], "Isi modul {$number}."), range(1, 5));

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => array_map(static fn (PortalArchivedFile $file): int => $file->id, $files)])
            ->assertCreated()
            ->assertJsonPath('message', 'Sumber ditambahkan. 3 berkas menunggu giliran dibaca dan menyusul otomatis.')
            ->assertJsonPath('data.sources.*.waiting', [false, false, true, true, true]);

        Queue::assertPushed(IngestNotebookSourceJob::class, 2);
        $sources = $notebook->sources()->orderBy('id')->get();
        $this->assertTrue($sources->every(static fn (NotebookSource $source): bool => $source->isRequested()));
        $this->assertSame([true, true, false, false, false], $sources->map(static fn (NotebookSource $source): bool => $source->queued_at !== null)->all());

        // A reading that finishes hands its slot to the next pick, whether the page is open or not.
        $library = app(NotebookLibrary::class);
        (new IngestNotebookSourceJob($sources[0]->id))->handle($library);
        $this->assertSame(NotebookSource::STATUS_READY, $sources[0]->fresh()->status, (string) $sources[0]->fresh()->error);
        Queue::assertPushed(IngestNotebookSourceJob::class, 3);
        $this->assertNotNull($sources[2]->fresh()->queued_at);

        // Opening the notebook queues nothing more while both slots are taken.
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        Queue::assertPushed(IngestNotebookSourceJob::class, 3);

        // Removing a queued pick frees its slot.
        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/sources/{$sources[1]->id}")->assertOk();
        Queue::assertPushed(IngestNotebookSourceJob::class, 4);
        $this->assertNotNull($sources[3]->fresh()->queued_at);

        // So does a reading whose worker died.
        (new IngestNotebookSourceJob($sources[2]->id))->failed(new \RuntimeException('Job has timed out.'));
        Queue::assertPushed(IngestNotebookSourceJob::class, 5);
        $this->assertSame(0, $notebook->sources()->whereNull('queued_at')->count());
    }

    public function test_files_the_student_picked_wait_for_a_slot_but_not_for_the_share_of_files_that_joined_on_their_own(): void
    {
        Queue::fake([IngestNotebookSourceJob::class]);
        config([
            'services.gemini.key' => 'test-gemini-key',
            'notebook.limits.daily_source_reads' => 3,
            'notebook.limits.reserved_source_reads' => 1,
            'notebook.limits.queued_readings' => 1,
        ]);
        $this->geminiAnswers("[[HALAMAN 1]]\nIsi modul.");
        $readsKey = 'notebook-reads:'.$this->ownerHash.':'.now()->format('Y-m-d');
        // The share for files that joined on their own is spent; the read kept for the student is not.
        RateLimiter::hit($readsKey, 86400);
        RateLimiter::hit($readsKey, 86400);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $library = app(NotebookLibrary::class);
        $busy = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'kind' => NotebookSource::KIND_UPLOAD, 'status' => NotebookSource::STATUS_PROCESSING, 'queued_at' => now()]);
        $automatic = $library->addArchivedFile($notebook, $this->archivedPdf(1), byStudent: false);

        $picked = $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$this->archivedPdf(2)->id]])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.waiting', true)
            ->assertJsonPath('data.sources.0.error', null)
            ->json('data.sources.0.id');
        Queue::assertNothingPushed();

        // The student's pick takes the free slot first, though the file that joined on its own is older.
        $busy->forceFill(['status' => NotebookSource::STATUS_READY])->save();
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        Queue::assertPushed(IngestNotebookSourceJob::class, 1);
        $this->assertNotNull(NotebookSource::query()->findOrFail($picked)->queued_at);
        $this->assertTrue($automatic->fresh()->isWaiting());

        // Read with the read kept for the student, even by a job queued as automatic.
        (new IngestNotebookSourceJob($picked, automatic: true))->handle($library);
        $this->assertSame(NotebookSource::STATUS_READY, NotebookSource::query()->findOrFail($picked)->status);
        Http::assertSentCount(2);

        // The file that joined on its own waits for tomorrow's share and says so.
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        $this->assertStringStartsWith('Kuota baca AI untuk berkas yang masuk sendiri hari ini sudah terpakai.', (string) $automatic->fresh()->error);
        Queue::assertPushed(IngestNotebookSourceJob::class, 1);
    }

    public function test_a_waiting_course_file_the_student_picks_becomes_their_own_pick(): void
    {
        Queue::fake([IngestNotebookSourceJob::class]);
        config([
            'services.gemini.key' => 'test-gemini-key',
            'notebook.limits.daily_source_reads' => 2,
            'notebook.limits.reserved_source_reads' => 1,
            'notebook.limits.queued_readings' => 1,
        ]);
        $readsKey = 'notebook-reads:'.$this->ownerHash.':'.now()->format('Y-m-d');
        // The share for files that joined on their own is spent.
        RateLimiter::hit($readsKey, 86400);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $file = $this->archivedPdf(1);
        $automatic = app(NotebookLibrary::class)->addArchivedFile($notebook, $file, byStudent: false);
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        $this->assertStringStartsWith('Kuota baca AI untuk berkas yang masuk sendiri hari ini sudah terpakai.', (string) $automatic->fresh()->error);
        $busy = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'kind' => NotebookSource::KIND_UPLOAD, 'status' => NotebookSource::STATUS_PROCESSING, 'queued_at' => now()]);

        // Picked through the API although it is in the notebook already: it waits for a slot, not for tomorrow.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$file->id]])
            ->assertCreated()
            ->assertJsonPath('message', 'Sumber ditambahkan. 1 berkas menunggu giliran dibaca dan menyusul otomatis.')
            ->assertJsonPath('data.sources.0.id', $automatic->id)
            ->assertJsonPath('data.sources.0.waiting', true)
            ->assertJsonPath('data.sources.0.error', null);
        $this->assertTrue($automatic->fresh()->isRequested());
        Queue::assertNothingPushed();

        // The slot that frees up goes to it, read with the student's own reads.
        $busy->forceFill(['status' => NotebookSource::STATUS_READY])->save();
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        Queue::assertPushed(IngestNotebookSourceJob::class, 1);
        $this->assertNotNull($automatic->fresh()->queued_at);
        $this->assertSame(1, $notebook->sources()->where('kind', NotebookSource::KIND_ARCHIVED_FILE)->count());
    }

    public function test_course_files_that_need_the_model_wait_while_the_ai_cannot_be_used(): void
    {
        $readsKey = 'notebook-reads:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $gemini = ['body' => ['error' => ['message' => 'unused']], 'status' => 500];
        Http::fake([self::GEMINI_URL => function () use (&$gemini) {
            return Http::response($gemini['body'], $gemini['status']);
        }]);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $library = app(NotebookLibrary::class);
        $pdf = $library->addArchivedFile($notebook, $this->archivedPdf(1), byStudent: false);
        $text = $library->addArchivedFile($notebook, $this->archivedText(['meeting_id' => '445', 'meeting_number' => 3], 'Catatan dosen.'), byStudent: false);
        $waitingForAi = 'Berkas ini perlu dibaca AI, yang sedang tidak bisa dipakai. Berkas ini dibaca otomatis saat notebook dibuka setelah AI aktif lagi.';

        // No key: the local file is read, the PDF waits instead of failing.
        foreach (range(1, 2) as $open) {
            $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();

            $this->assertSame(NotebookSource::STATUS_READY, $text->fresh()->status);
            $this->assertSame([NotebookSource::STATUS_PENDING, true, $waitingForAi], [$pdf->fresh()->status, $pdf->fresh()->isWaiting(), $pdf->fresh()->error], "Open {$open}");
        }

        Http::assertNothingSent();

        // A key with a retired model: the reading that finds out puts its file back and gives its read back.
        config(['services.gemini.key' => 'test-gemini-key']);
        $gemini = ['body' => ['error' => ['code' => 404, 'message' => 'models/gemini-test-flash is not found for API version v1beta', 'status' => 'NOT_FOUND']], 'status' => 404];
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_status', 'model_misconfigured');

        $this->assertSame([NotebookSource::STATUS_PENDING, true, $waitingForAi], [$pdf->fresh()->status, $pdf->fresh()->isWaiting(), $pdf->fresh()->error]);
        $this->assertSame(0, RateLimiter::attempts($readsKey));
        Http::assertSentCount(1);

        // Remembered as rejected: nothing more is sent meanwhile.
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        Http::assertSentCount(1);
        $this->assertTrue($pdf->fresh()->isWaiting());

        // Fixed: read on the next open, without a tap on every file.
        config(['services.gemini.model' => 'gemini-test-pro']);
        $gemini = ['body' => ['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nIsi modul."]]], 'finishReason' => 'STOP']]], 'status' => 200];
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_status', 'ok');

        $this->assertSame([NotebookSource::STATUS_READY, null], [$pdf->fresh()->status, $pdf->fresh()->error]);
        $this->assertSame(1, RateLimiter::attempts($readsKey));
    }

    public function test_a_notebook_made_from_a_course_and_uploads_wait_for_a_reading_slot_too(): void
    {
        Queue::fake([IngestNotebookSourceJob::class]);
        config(['notebook.limits.queued_readings' => 2]);

        foreach (['Satu.', 'Dua.', 'Tiga.', 'Empat.'] as $contents) {
            $this->archivedText(['course_key' => '8810'], $contents);
        }

        $id = $this->sendAsStudent('POST', '/api/notebooks', ['title' => 'UTS Basis Data', 'course_key' => '8810'])
            ->assertCreated()
            ->assertJsonPath('data.notebook.sources_count', 4)
            ->json('data.notebook.id');

        Queue::assertPushed(IngestNotebookSourceJob::class, 2);
        $notebook = Notebook::query()->findOrFail($id);
        $this->assertSame(2, $notebook->sources()->whereNull('queued_at')->whereNotNull('requested_at')->count());

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('catatan.txt', 'Catatan kuliah.')])
            ->assertCreated()
            ->assertJsonPath('message', 'Sumber ditambahkan. 1 berkas menunggu giliran dibaca dan menyusul otomatis.')
            ->assertJsonPath('data.sources.0.waiting', true);
        Queue::assertPushed(IngestNotebookSourceJob::class, 2);
    }

    public function test_files_that_joined_on_their_own_leave_the_reserved_reads_to_the_student(): void
    {
        config([
            'services.gemini.key' => 'test-gemini-key',
            'notebook.limits.daily_source_reads' => 3,
            'notebook.limits.reserved_source_reads' => 1,
        ]);
        $this->geminiAnswers("[[HALAMAN 1]]\nIsi modul.");
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $library = app(NotebookLibrary::class);
        $pdfs = array_map(fn (int $number): NotebookSource => $library->addArchivedFile($notebook, $this->archivedPdf($number), byStudent: false), [1, 2, 3]);
        $text = $library->addArchivedFile($notebook, $this->archivedText(['meeting_id' => '445', 'meeting_number' => 3], 'Catatan dosen.'), byStudent: false);

        // Two of the three reads are for files that joined on their own; a local file needs none.
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();

        $this->assertSame(['ready', 'ready', 'pending'], array_map(fn (NotebookSource $source): string => $source->fresh()->status, $pdfs));
        // Past the share it waits for the next day and says so, which offers "Baca sekarang".
        $this->assertTrue($pdfs[2]->fresh()->isWaiting());
        $this->assertStringStartsWith('Kuota baca AI untuk berkas yang masuk sendiri hari ini sudah terpakai.', (string) $pdfs[2]->fresh()->error);
        $pdfs[2]->forceFill(['error' => null])->save();
        $this->assertSame(NotebookSource::STATUS_READY, $text->fresh()->status);
        $this->assertNull($text->fresh()->summary);
        Http::assertSentCount(4);

        // A reading queued before the share ran out waits for the next day instead of failing.
        (new IngestNotebookSourceJob($pdfs[2]->id, automatic: true))->handle($library);

        $sources = $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->json('data.sources');
        $waiting = collect($sources)->firstWhere('id', $pdfs[2]->id);
        $this->assertSame(['pending', true, false], [$waiting['status'], $waiting['waiting'], $waiting['stalled']]);
        $this->assertStringStartsWith('Kuota baca AI untuk berkas yang masuk sendiri hari ini sudah terpakai.', $waiting['error']);
        Http::assertSentCount(4);

        // The student may read it now with the read kept for them.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources/{$pdfs[2]->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.source.status', 'ready')
            ->assertJsonPath('data.source.waiting', false);
        Http::assertSentCount(6);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('modul-4.pdf', '%PDF-1.4 empat')])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'failed')
            ->assertJsonPath('data.sources.0.error', 'Kuota baca berkas dengan AI hari ini sudah habis. Ketuk "Baca ulang" besok.');
    }

    public function test_reading_with_the_model_is_limited_per_student_per_day(): void
    {
        config(['services.gemini.key' => 'test-gemini-key', 'notebook.limits.daily_source_reads' => 1]);
        $this->geminiAnswers("[[HALAMAN 1]]\nModul satu.");
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('modul-1.pdf', '%PDF-1.4 satu')])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready');
        // The transcription and its summary are one reading.
        Http::assertSentCount(2);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('modul-2.pdf', '%PDF-1.4 dua')])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'failed')
            ->assertJsonPath('data.sources.0.error', 'Kuota baca berkas dengan AI hari ini sudah habis. Ketuk "Baca ulang" besok.');

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Catatan', 'content' => 'Basis data relasional.'])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready')
            ->assertJsonPath('data.sources.0.summary', null);
        Http::assertSentCount(2);
    }

    public function test_a_course_file_another_notebook_has_read_is_not_sent_to_the_model_again(): void
    {
        config(['services.gemini.key' => 'test-gemini-key']);
        $this->geminiAnswers("[[HALAMAN 1]]\nNormalisasi mengurangi redundansi.");
        $contents = '%PDF-1.4 modul bersama';
        $classmate = 'student_classmate';
        NotebookAiConsent::factory()->forOwner($classmate)->create();
        $theirs = PortalArchivedFile::factory()->forOwner($classmate)->create(['sha256' => hash('sha256', $contents)]);
        $mine = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['sha256' => hash('sha256', $contents)]);
        Storage::disk('local')->put($mine->blobPath(), $contents);

        app(NotebookLibrary::class)->addArchivedFile(Notebook::factory()->forOwner($classmate)->create(), $theirs);
        Http::assertSentCount(2);

        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$mine->id]])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready')
            ->assertJsonPath('data.sources.0.page_count', 1);

        Http::assertSentCount(2);
        $this->assertSame(['Normalisasi mengurangi redundansi.'], NotebookChunk::query()->where('notebook_id', $notebook->id)->pluck('content')->all());
    }

    public function test_a_course_file_read_elsewhere_brings_its_topics_and_questions_without_the_model(): void
    {
        config(['services.gemini.key' => 'test-gemini-key']);
        Http::fake();
        $contents = '%PDF-1.4 modul berpanduan';
        $theirs = PortalArchivedFile::factory()->forOwner('student_classmate')->create(['sha256' => hash('sha256', $contents)]);
        $mine = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['sha256' => hash('sha256', $contents)]);
        Storage::disk('local')->put($mine->blobPath(), $contents);
        $reader = NotebookSource::factory()->withGuide()->create([
            'notebook_id' => Notebook::factory()->forOwner('student_classmate')->create()->id,
            'kind' => NotebookSource::KIND_ARCHIVED_FILE,
            'portal_archived_file_id' => $theirs->id,
            'char_count' => 34,
            'page_count' => 1,
        ]);
        NotebookChunk::query()->create(['notebook_source_id' => $reader->id, 'notebook_id' => $reader->notebook_id, 'position' => 0, 'page' => 1, 'content' => 'Normalisasi mengurangi redundansi.']);

        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$mine->id]])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready')
            ->assertJsonPath('data.sources.0.summary', 'Sumber ini membahas normalisasi basis data.')
            ->assertJsonPath('data.sources.0.topics', ['Normalisasi', 'Redundansi', 'Bentuk normal'])
            ->assertJsonPath('data.sources.0.suggested_questions.1', 'Apa beda 2NF dan 3NF?');

        Http::assertNothingSent();
    }

    public function test_removed_course_sources_are_not_added_back_on_their_own(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $this->classList());
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create(['class_params' => self::CLASS_PARAMS]);
        $rps = $this->archivedText(['meeting_id' => null, 'meeting_number' => null, 'kind' => 'rps', 'source_url' => $this->fileUrl('sap/rps.pdf')], 'Capaian pembelajaran.');
        $modul = $this->archivedText(['meeting_id' => '445', 'meeting_number' => 3, 'source_url' => $this->fileUrl('modul/abc-3-mod.pdf')], 'Normalisasi.');
        app(NotebookCourseSync::class)->addOpenedMeeting(self::TOKEN, $this->meeting(), self::MEETING_PARAMS);
        $this->assertSame(3, $notebook->sources()->count());

        foreach ($notebook->sources()->where('kind', NotebookSource::KIND_ARCHIVED_FILE)->get() as $source) {
            $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/sources/{$source->id}")->assertOk();
        }

        // The notebook list, a re-read of the meeting and a late archive all leave them out.
        $this->getAsStudent('/api/notebooks')->assertOk();
        app(NotebookCourseSync::class)->addOpenedMeeting(self::TOKEN, $this->meeting(), self::MEETING_PARAMS);
        $this->assertNull(app(NotebookCourseSync::class)->attachArchivedFile($modul));
        $this->assertSame(['meeting'], $notebook->sources()->pluck('kind')->all());
        $this->assertSame(2, $notebook->dismissals()->count());

        // Picked from the archive by hand, a file comes back.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$rps->id]])->assertCreated();
        $this->assertTrue($notebook->sources()->where('portal_archived_file_id', $rps->id)->exists());
        $this->assertFalse($notebook->isDismissed(NotebookDismissal::KIND_ARCHIVED_FILE, $rps->source_url));

        // A removed meeting stays out until the student adds it again.
        $note = $notebook->meetingSources()->sole();
        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/sources/{$note->id}")->assertOk();
        $this->assertNull(app(NotebookCourseSync::class)->addOpenedMeeting(self::TOKEN, $this->meeting(), self::MEETING_PARAMS));
        $this->assertSame(0, $notebook->meetingSources()->count());

        $this->snapshots()->put(self::TOKEN, 'meeting_detail', $this->meeting(), params: self::MEETING_PARAMS);
        $this->sendAsStudent('POST', '/api/notebooks/meetings', self::MEETING_PARAMS)->assertCreated();
        $this->assertSame(1, $notebook->meetingSources()->count());
        $this->assertFalse($notebook->isDismissed(NotebookDismissal::KIND_MEETING, '445'));
        $this->assertFalse($notebook->sources()->where('portal_archived_file_id', $modul->id)->exists());
    }

    public function test_a_course_file_archived_again_is_linked_to_its_existing_source(): void
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        app(NotebookLibrary::class)->addMeetingNote($notebook, '445', 3, 'Pertemuan 3', 'Catatan pertemuan 3.');
        $this->downloadsReturnText();
        $store = app(PortalArchivedFileStore::class);
        $item = $this->item('modul', $this->fileUrl('modul/p3.pdf'), '445', 3);

        $first = $store->store(self::TOKEN, $item, 'Basis Data');
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk();
        $source = $notebook->sources()->where('kind', NotebookSource::KIND_ARCHIVED_FILE)->sole();
        $this->assertSame(NotebookSource::STATUS_READY, $source->status);

        $store->deleteOwner($this->ownerHash);
        $this->assertNull($source->fresh()->portal_archived_file_id);

        $second = $store->store(self::TOKEN, $item, 'Basis Data');

        $this->assertNotSame($first->id, $second->id);
        $relinked = $notebook->sources()->where('kind', NotebookSource::KIND_ARCHIVED_FILE)->sole();
        $this->assertSame($source->id, $relinked->id);
        $this->assertSame($second->id, $relinked->portal_archived_file_id);
        $this->assertSame(NotebookSource::STATUS_READY, $relinked->status);
    }

    public function test_one_source_limit_holds_on_every_path(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        config(['notebook.limits.max_course_sources' => 3]);
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $this->classList());
        $this->snapshots()->put(self::TOKEN, 'meeting_detail', $this->meeting(), params: ['meeting_id' => '447', 'courses_id' => '8810']);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $library = app(NotebookLibrary::class);
        $library->addMeetingNote($notebook, '445', 3, 'Pertemuan 3', 'Catatan 3.');
        $library->addMeetingNote($notebook, '446', 4, 'Pertemuan 4', 'Catatan 4.');
        $library->addArchivedFile($notebook, $this->archivedText(['meeting_id' => '445', 'meeting_number' => 3], 'Modul 3.'));
        $late = $this->archivedText(['meeting_id' => '446', 'meeting_number' => 4], 'Modul 4.');

        $this->assertNull(app(NotebookCourseSync::class)->attachArchivedFile($late));
        $this->assertNull(app(NotebookCourseSync::class)->addOpenedMeeting(self::TOKEN, $this->meeting(), ['meeting_id' => '447', 'courses_id' => '8810']));
        $this->sendAsStudent('POST', '/api/notebooks/meetings', ['meeting_id' => '447', 'courses_id' => '8810'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Notebook mata kuliah ini sudah mencapai batas jumlah sumber. Hapus sumber yang tidak perlu dulu.');
        $this->assertSame(3, $notebook->sources()->count());

        // A meeting already in the notebook is still refreshed.
        $this->assertNotNull(app(NotebookCourseSync::class)->addOpenedMeeting(self::TOKEN, $this->meeting(), self::MEETING_PARAMS));
        $this->assertSame(3, $notebook->sources()->count());
        $this->assertSame(3, $notebook->sourceLimit());
        $this->assertSame(30, Notebook::factory()->forOwner($this->ownerHash)->create()->sourceLimit());
    }

    public function test_the_sync_job_stops_at_the_source_limit_and_says_so(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        config(['notebook.limits.max_course_sources' => 2]);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'class_detail', self::CLASS_PARAMS)->andReturn([
                'course_info' => ['name' => 'Basis Data'],
                'course_materials' => array_map(static fn (int $id): array => ['join_link' => ['href' => "?mod=sipandai&act=discussion&id={$id}&courses_id=8810"]], [445, 446, 447]),
            ]);
            $mock->shouldReceive('ensureFresh')->withArgs(fn (string $token, string $dataset): bool => $dataset === 'meeting_detail')->andReturn($this->meeting());
        }));
        $this->downloadsReturnText();
        $this->instance(PortalBroadcastService::class, Mockery::mock(PortalBroadcastService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dataInvalidated')->once()->withArgs(fn (string $sessionId, string $token, array $cacheKeys, array $syncKeys, string $message): bool => str_starts_with($message, '2 pertemuan masuk ke notebook')
                && str_contains($message, 'Batas jumlah sumber notebook tercapai'));
        }));

        app()->call([new SyncCourseNotebookJob('laravel-session', self::TOKEN, $notebook->id), 'handle']);

        $this->assertSame(['445', '446'], $notebook->meetingSources()->orderBy('meeting_id')->pluck('meeting_id')->all());
        $this->assertSame(2, $notebook->sources()->count());
    }

    public function test_an_unchanged_meeting_note_keeps_its_chunks(): void
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $library = app(NotebookLibrary::class);

        $note = $library->addMeetingNote($notebook, '445', 3, 'Pertemuan 3', 'Catatan pertemuan 3.');
        $chunkId = NotebookChunk::query()->where('notebook_source_id', $note->id)->sole()->id;

        $library->addMeetingNote($notebook, '445', 3, 'Pertemuan 3 · Normalisasi', 'Catatan pertemuan 3.');
        $this->assertSame($chunkId, NotebookChunk::query()->where('notebook_source_id', $note->id)->sole()->id);
        $this->assertSame('Pertemuan 3 · Normalisasi', $note->fresh()->title);

        $library->addMeetingNote($notebook, '445', 3, 'Pertemuan 3 · Normalisasi', 'Catatan pertemuan 3, dengan tugas baru.');
        $rewritten = NotebookChunk::query()->where('notebook_source_id', $note->id)->sole();
        $this->assertNotSame($chunkId, $rewritten->id);
        $this->assertSame('Catatan pertemuan 3, dengan tugas baru.', $rewritten->content);
    }

    public function test_chunks_and_archived_sources_are_indexed_for_their_lookups(): void
    {
        // Every meeting-note rewrite and source delete looks chunks up by source.
        $this->assertTrue(Schema::hasIndex('notebook_chunks', ['notebook_source_id', 'position']));
        $this->assertTrue(Schema::hasIndex('notebook_sources', ['portal_archived_file_id']));
        $this->assertTrue(Schema::hasIndex('notebook_sources', ['notebook_id', 'archived_source_url']));
        $this->assertTrue(Schema::hasIndex('notebook_dismissals', ['notebook_id', 'kind', 'reference'], 'unique'));
    }

    public function test_a_reading_whose_worker_died_can_be_started_again(): void
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $file = $this->archivedText(['course_key' => '8810'], 'Isi modul.');
        $source = NotebookSource::factory()->create([
            'notebook_id' => $notebook->id,
            'kind' => NotebookSource::KIND_ARCHIVED_FILE,
            'portal_archived_file_id' => $file->id,
            'archived_source_url' => $file->source_url,
            'mime' => 'text/plain',
            'status' => NotebookSource::STATUS_PROCESSING,
            'queued_at' => now(),
        ]);

        // Still within the job's time: nothing to retry.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources/{$source->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.source.status', 'processing')
            ->assertJsonPath('data.source.stalled', false);

        $this->travel(NotebookSource::STALLED_AFTER_SECONDS + 60)->seconds();
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.sources.0.stalled', true);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources/{$source->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.source.status', 'ready')
            ->assertJsonPath('data.source.stalled', false);
    }

    public function test_a_queued_reading_is_stalled_only_once_the_queue_could_have_run_it(): void
    {
        Queue::fake([IngestNotebookSourceJob::class]);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $source = app(NotebookLibrary::class)->addArchivedFile($notebook, $this->archivedText(['meeting_id' => '445'], 'Isi modul.'));
        Queue::assertPushed(IngestNotebookSourceJob::class, 1);

        // Behind other students' readings on a busy queue: still waiting its turn.
        $this->travel(NotebookSource::STALLED_AFTER_SECONDS + 60)->seconds();
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.sources.0.stalled', false);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources/{$source->id}/retry")->assertOk();
        Queue::assertPushed(IngestNotebookSourceJob::class, 1);

        $this->travel(NotebookSource::QUEUED_STALLED_AFTER_SECONDS)->seconds();
        app(PortalDataOwner::class)->remember(self::TOKEN, self::USERNAME, 3600);
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.sources.0.stalled', true);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources/{$source->id}/retry")->assertOk();
        Queue::assertPushed(IngestNotebookSourceJob::class, 2);
    }

    public function test_a_reading_job_is_unique_only_as_long_as_it_can_wait_and_never_runs_twice_at_once(): void
    {
        $job = new IngestNotebookSourceJob(7);

        $this->assertGreaterThanOrEqual($job->timeout, $job->uniqueFor());
        $this->assertSame(NotebookSource::QUEUED_STALLED_AFTER_SECONDS, $job->uniqueFor());
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
        $this->assertSame('7', $job->middleware()[0]->key);
    }

    public function test_a_reading_job_queued_before_the_automatic_flag_existed_still_runs(): void
    {
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $file = $this->archivedText(['course_key' => '8810'], 'Isi modul.');
        $source = NotebookSource::factory()->create([
            'notebook_id' => $notebook->id,
            'kind' => NotebookSource::KIND_ARCHIVED_FILE,
            'portal_archived_file_id' => $file->id,
            'archived_source_url' => $file->source_url,
            'mime' => 'text/plain',
            'status' => NotebookSource::STATUS_PENDING,
            'queued_at' => now(),
        ]);
        // The payload of a job serialized by the earlier class, which had no `automatic`.
        $values = (new IngestNotebookSourceJob($source->id, automatic: true))->__serialize();
        unset($values["\0".IngestNotebookSourceJob::class."\0automatic"]);
        $job = unserialize('O:'.strlen(IngestNotebookSourceJob::class).':"'.IngestNotebookSourceJob::class.'"'.substr(serialize($values), 1));

        $this->assertInstanceOf(IngestNotebookSourceJob::class, $job);
        $job->handle(app(NotebookLibrary::class));

        $this->assertSame(NotebookSource::STATUS_READY, $source->fresh()->status, (string) $source->fresh()->error);
        // Today's jobs keep the flag through the queue.
        $this->assertStringContainsString('automatic', serialize(new IngestNotebookSourceJob($source->id, automatic: true)));
    }

    public function test_a_meeting_link_of_another_class_makes_no_notebook(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $this->snapshots()->put(self::TOKEN, 'lms_classes', $this->classList());
        $sync = app(NotebookCourseSync::class);

        // Shared by a classmate from a section the student does not take.
        $this->assertFalse($sync->takesCourse(self::TOKEN, '9310'));
        $this->assertNull($sync->addOpenedMeeting(self::TOKEN, $this->meeting(), ['meeting_id' => '41002', 'courses_id' => '9310']));
        $this->assertNull(Notebook::query()->forOwner($this->ownerHash)->course('9310')->first());
        Queue::assertNotPushed(ArchivePortalFilesJob::class);

        // A course of an earlier semester keeps the notebook it has, unless the meeting was removed from it.
        $earlier = Notebook::factory()->forOwner($this->ownerHash)->course('7700')->create();
        $this->assertTrue($sync->takesCourse(self::TOKEN, '7700'));
        $this->assertTrue($sync->takesCourse(self::TOKEN, '8810'));
        NotebookDismissal::factory()->meeting('41003')->create(['notebook_id' => $earlier->id]);

        $this->assertNotNull($sync->addOpenedMeeting(self::TOKEN, $this->meeting(), ['meeting_id' => '41001', 'courses_id' => '7700']));
        $this->assertNull($sync->addOpenedMeeting(self::TOKEN, $this->meeting(), ['meeting_id' => '41003', 'courses_id' => '7700']));
        $this->assertSame(['41001'], $earlier->meetingSources()->pluck('meeting_id')->all());

        $this->assertNotNull($sync->addOpenedMeeting(self::TOKEN, $this->meeting(), self::MEETING_PARAMS));
        $this->assertNotNull(Notebook::query()->forOwner($this->ownerHash)->course('8810')->first());
    }

    public function test_the_sync_job_downloads_again_a_course_file_whose_copy_is_missing(): void
    {
        Queue::fake([ArchivePortalFilesJob::class]);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->course()->create();
        $lost = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create([
            'course_key' => '8810',
            'kind' => 'modul',
            'meeting_id' => '445',
            'meeting_number' => 3,
            'mime' => 'text/plain',
            'filename' => 'abc-3-mod.txt',
            'source_url' => $this->fileUrl('modul/abc-3-mod.pdf'),
            'sha256' => hash('sha256', 'Blob yang hilang.'),
        ]);
        $this->instance(PortalDatasetRepository::class, Mockery::mock(PortalDatasetRepository::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'class_detail', self::CLASS_PARAMS)->andReturn([
                'course_info' => ['name' => 'Basis Data'],
                'course_materials' => [['join_link' => ['href' => '?mod=sipandai&act=discussion&id=445&courses_id=8810']]],
            ]);
            $mock->shouldReceive('ensureFresh')->with(self::TOKEN, 'meeting_detail', self::MEETING_PARAMS)->andReturn($this->meeting());
        }));
        $this->downloadsReturnText();
        $this->instance(PortalBroadcastService::class, Mockery::mock(PortalBroadcastService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dataInvalidated')->once();
        }));

        app()->call([new SyncCourseNotebookJob('laravel-session', self::TOKEN, $notebook->id), 'handle']);

        $lost->refresh();
        $this->assertTrue(Storage::disk('local')->exists($lost->blobPath()));
        $source = $notebook->sources()->where('kind', NotebookSource::KIND_ARCHIVED_FILE)->sole();
        $this->assertSame($lost->id, $source->portal_archived_file_id);
        $this->assertSame(NotebookSource::STATUS_READY, $source->status);
        $this->assertSame('Isi abc-3-mod.pdf', NotebookChunk::query()->where('notebook_source_id', $source->id)->value('content'));
    }

    public function test_a_job_that_dies_leaves_its_source_failed_with_a_way_out(): void
    {
        $processing = NotebookSource::factory()->create(['status' => NotebookSource::STATUS_PROCESSING, 'kind' => NotebookSource::KIND_UPLOAD]);
        $ready = NotebookSource::factory()->create();

        (new IngestNotebookSourceJob($processing->id))->failed(new \RuntimeException('Job has been attempted too many times.'));
        (new IngestNotebookSourceJob($ready->id))->failed(new \RuntimeException('Job has been attempted too many times.'));

        $this->assertSame(NotebookSource::STATUS_FAILED, $processing->fresh()->status);
        $this->assertSame('Sumber ini berhenti dibaca di tengah jalan. Ketuk "Baca ulang" untuk mencoba lagi.', $processing->fresh()->error);
        $this->assertSame(NotebookSource::STATUS_READY, $ready->fresh()->status);
    }

    public function test_adding_more_archive_files_than_there_is_room_for_is_refused(): void
    {
        config(['notebook.limits.max_sources' => 3]);
        $notebook = Notebook::factory()->forOwner($this->ownerHash)->create();
        $present = $this->archivedText(['course_key' => '8810'], 'Satu.');
        app(NotebookLibrary::class)->addArchivedFile($notebook, $present);
        NotebookSource::factory()->create(['notebook_id' => $notebook->id]);
        $first = $this->archivedText(['course_key' => '8810'], 'Dua.');
        $second = $this->archivedText(['course_key' => '8810'], 'Tiga.');

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$first->id, $second->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Notebook ini hanya bisa menambah 1 sumber lagi. Pilih paling banyak 1 berkas, atau hapus sumber yang tidak perlu dulu.');
        $this->assertSame(2, $notebook->sources()->count());

        // A file already in the notebook takes no room.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$present->id, $first->id]])
            ->assertCreated()
            ->assertJsonCount(2, 'data.sources');
        $this->assertSame(3, $notebook->sources()->count());
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.source_limit', 3);
    }

    public function test_a_notebook_made_from_a_course_says_how_many_files_it_took(): void
    {
        config(['notebook.limits.max_sources' => 2]);

        foreach (['Satu.', 'Dua.', 'Tiga.'] as $contents) {
            $this->archivedText(['course_key' => '8810'], $contents);
        }

        $this->sendAsStudent('POST', '/api/notebooks', ['title' => 'UTS Basis Data', 'course_key' => '8810'])
            ->assertCreated()
            ->assertJsonPath('message', 'Notebook dibuat dengan 2 dari 3 berkas materi (batas 2 sumber per notebook).')
            ->assertJsonPath('data.notebook.sources_count', 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function classList(): array
    {
        return ['classes' => [
            ['course_info' => ['code' => 'UC01024011', 'name' => 'Basis Data'], 'lecturer_info' => ['name' => 'Dosen A'], 'join_link' => ['params' => self::CLASS_PARAMS]],
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
            'materials' => [
                'main_topic' => 'Pokok Bahasan : Normalisasi Data',
                'main_description' => 'Anomali data',
                'main_material_iframe' => '../files/modul/abc-3-mod.pdf',
                'assignment_topic' => 'Pertemuan 3',
            ],
            'rps_info' => ['file_link' => '', 'file_text' => ''],
        ];
    }

    /**
     * @return array{kind: string, source_url: string, title: string|null, course_key: string, meeting_id: string|null, meeting_number: int|null}
     */
    private function item(string $kind, string $url, ?string $meetingId, ?int $number): array
    {
        return ['kind' => $kind, 'source_url' => $url, 'title' => null, 'course_key' => '8810', 'meeting_id' => $meetingId, 'meeting_number' => $number];
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
            'kind' => 'modul',
            'mime' => 'text/plain',
            'filename' => 'materi.txt',
            'sha256' => hash('sha256', $contents),
            ...$attributes,
        ]);
        Storage::disk('local')->put($file->blobPath(), $contents);

        return $file;
    }

    /**
     * An archived course PDF, which only the model can read.
     */
    private function archivedPdf(int $number): PortalArchivedFile
    {
        $contents = '%PDF-1.4 modul '.$number;
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create([
            'course_key' => '8810',
            'course_name' => 'Basis Data',
            'kind' => 'modul',
            'meeting_id' => '445',
            'meeting_number' => 3,
            'mime' => 'application/pdf',
            'filename' => "modul-{$number}.pdf",
            'source_url' => $this->fileUrl("modul/modul-{$number}.pdf"),
            'sha256' => hash('sha256', $contents),
        ]);
        Storage::disk('local')->put($file->blobPath(), $contents);

        return $file;
    }

    private function downloadsReturnText(): void
    {
        $this->instance(SipandaiSubmissionService::class, Mockery::mock(SipandaiSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('downloadCampusFile')->andReturnUsing(fn (string $token, string $url): array => [
                'filename' => basename($url, '.pdf').'.txt',
                'content_type' => 'text/plain',
                'contents' => 'Isi '.basename($url),
            ]);
        }));
    }

    private function geminiAnswers(string $text): void
    {
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
        ])]);
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
