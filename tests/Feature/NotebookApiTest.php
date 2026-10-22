<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\NotebookChunk;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use App\Services\NotebookAssistant;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use ZipArchive;

class NotebookApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-notebook';

    private const USERNAME = '20990000007';

    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/*';

    private string $ownerHash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-test-flash',
            'services.gemini.fallback_model' => null,
            'services.gemini.retry_delay_ms' => 0,
        ]);
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

    public function test_a_notebook_for_a_course_starts_with_its_archived_files(): void
    {
        $this->geminiAnswers("[[HALAMAN 1]]\nNormalisasi mengurangi redundansi.");
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['course_key' => '8810', 'course_name' => 'Basis Data', 'meeting_number' => 3, 'title' => 'Normalisasi']);
        Storage::disk('local')->put($file->blobPath(), '%PDF-1.4 normalisasi');
        PortalArchivedFile::factory()->forOwner($this->ownerHash)->create(['course_key' => '8811']);

        $response = $this->sendAsStudent('POST', '/api/notebooks', ['title' => 'UTS Basis Data', 'course_key' => '8810'])
            ->assertCreated()
            ->assertJsonPath('data.notebook.course_name', 'Basis Data')
            ->assertJsonPath('data.notebook.sources_count', 1);

        $source = NotebookSource::query()->sole();
        $this->assertSame($response->json('data.notebook.id'), $source->notebook_id);
        $this->assertSame('Pertemuan 3 · Normalisasi', $source->title);
        $this->assertSame(NotebookSource::STATUS_READY, $source->status);
        $this->assertSame(1, $source->page_count);
        $this->assertSame('Normalisasi mengurangi redundansi.', NotebookChunk::query()->sole()->content);
    }

    public function test_notebooks_of_other_students_are_not_found(): void
    {
        $foreign = Notebook::factory()->forOwner('student_someone_else')->create();

        $this->getAsStudent('/api/notebooks/'.$foreign->id)->assertNotFound();
        $this->sendAsStudent('DELETE', '/api/notebooks/'.$foreign->id)->assertNotFound();
        $this->assertTrue($foreign->exists());
        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonCount(0, 'data.notebooks');
    }

    public function test_pasted_text_becomes_a_ready_source_split_into_chunks(): void
    {
        config(['services.gemini.key' => null]);
        config(['notebook.limits.chunk_chars' => 400]);
        $notebook = $this->notebook();
        $paragraphs = implode("\n\n", array_map(static fn (int $index): string => "Paragraf {$index}: ".str_repeat('basis data relasional ', 10), range(1, 4)));

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Catatan minggu 3', 'content' => $paragraphs])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready')
            ->assertJsonPath('data.sources.0.summary', null);

        $this->assertGreaterThan(1, NotebookChunk::query()->count());
        $this->assertTrue(NotebookChunk::query()->get()->every(fn (NotebookChunk $chunk): bool => mb_strlen($chunk->content) <= 400));
    }

    public function test_word_and_powerpoint_uploads_are_read_without_the_model(): void
    {
        config(['services.gemini.key' => null]);
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => $this->docx(['Bab 1 Pendahuluan', 'Basis data adalah kumpulan data.'])])->assertCreated();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => $this->pptx([1 => ['Normalisasi', '1NF'], 2 => ['Denormalisasi']])])->assertCreated();

        $docx = NotebookSource::query()->where('title', 'bab-1')->sole();
        $pptx = NotebookSource::query()->where('title', 'slide-normalisasi')->sole();
        $this->assertSame(NotebookSource::STATUS_READY, $docx->status);
        $this->assertSame("Bab 1 Pendahuluan\nBasis data adalah kumpulan data.", $docx->chunks()->value('content'));
        $this->assertSame([[1, "Normalisasi\n1NF"], [2, 'Denormalisasi']], $pptx->chunks()->orderBy('position')->get()->map(fn (NotebookChunk $chunk): array => [$chunk->page, $chunk->content])->all());
        $this->assertSame(2, $pptx->page_count);
    }

    public function test_a_pdf_needs_the_model_and_fails_with_a_clear_message_without_it(): void
    {
        config(['services.gemini.key' => null]);
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('modul.pdf', '%PDF-1.4 isi')])
            ->assertCreated();

        $source = NotebookSource::query()->sole();
        $this->assertSame(NotebookSource::STATUS_FAILED, $source->status);
        $this->assertStringContainsString('GEMINI_API_KEY', (string) $source->error);
    }

    public function test_a_pdf_is_transcribed_once_with_page_numbers(): void
    {
        $this->geminiAnswers("[[HALAMAN 1]]\nPendahuluan basis data.\n[[HALAMAN 2]]\nNormalisasi 1NF.");
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('modul.pdf', '%PDF-1.4 isi')])->assertCreated();

        $this->assertSame([[1, 'Pendahuluan basis data.'], [2, 'Normalisasi 1NF.']], NotebookChunk::query()->orderBy('position')->get()->map(fn (NotebookChunk $chunk): array => [$chunk->page, $chunk->content])->all());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-goog-api-key', 'test-gemini-key')
            && ($request['contents'][0]['parts'][0]['inlineData']['mimeType'] ?? null) === 'application/pdf');
    }

    public function test_an_answer_cites_the_excerpts_it_used_and_drops_made_up_numbers(): void
    {
        $notebook = $this->notebookWithChunks([
            ['page' => 1, 'content' => 'Normalisasi mengurangi redundansi data.'],
            ['page' => 2, 'content' => 'Bentuk normal ketiga menghapus ketergantungan transitif.'],
        ]);
        $this->geminiAnswers('3NF menghapus ketergantungan transitif [2, 9]. Tujuannya mengurangi redundansi [1].');

        $response = $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu 3NF?'])->assertCreated();

        $response->assertJsonPath('data.question.content', 'Apa itu 3NF?')
            ->assertJsonPath('data.answer.content', '3NF menghapus ketergantungan transitif [2]. Tujuannya mengurangi redundansi [1].')
            ->assertJsonPath('data.answer.citations.0.n', 1)
            ->assertJsonPath('data.answer.citations.0.page', 1)
            ->assertJsonPath('data.answer.citations.1.excerpt', 'Bentuk normal ketiga menghapus ketergantungan transitif.')
            ->assertJsonCount(2, 'data.answer.citations');
        Http::assertSent(function (Request $request): bool {
            $prompt = $request['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($prompt, '[1] (Sumber: Modul, hlm. 1)') && str_ends_with($prompt, 'Pertanyaan: Apa itu 3NF?');
        });
        $this->assertSame(2, NotebookMessage::query()->count());
    }

    public function test_previous_turns_are_sent_as_conversation_history(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Kunci primer unik.']]);
        NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'role' => 'user', 'content' => 'Apa itu kunci primer?']);
        NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'role' => 'assistant', 'content' => 'Kunci primer unik [1].']);
        $this->geminiAnswers('Ya [1].');

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Boleh kosong?'])->assertCreated();

        Http::assertSent(fn (Request $request): bool => ($request['contents'][0]['role'] ?? null) === 'user'
            && ($request['contents'][1]['role'] ?? null) === 'model'
            && count($request['contents']) === 3);
    }

    public function test_a_quiz_is_generated_as_structured_data_and_saved_to_notes(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        $this->geminiAnswers(json_encode([[
            'question' => 'Fungsi JOIN?',
            'options' => ['Menghapus', 'Menggabungkan tabel', 'Mengurutkan', 'Membuat indeks'],
            'answer_index' => 1,
            'explanation' => 'JOIN menggabungkan tabel.',
            'citations' => [1],
        ]]));

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz'])
            ->assertCreated()
            ->assertJsonPath('message', 'Kuis dibuat.')
            ->assertJsonPath('data.message.kind', 'quiz')
            ->assertJsonPath('data.message.pinned', true)
            ->assertJsonPath('data.message.data.0.answer_index', 1)
            ->assertJsonPath('data.message.citations.0.page', 4);
        Http::assertSent(fn (Request $request): bool => ($request['generationConfig']['responseMimeType'] ?? null) === 'application/json');
    }

    public function test_questions_need_the_model_and_a_ready_source(): void
    {
        $empty = $this->notebook();
        $this->sendAsStudent('POST', "/api/notebooks/{$empty->id}/messages", ['question' => 'Apa isi materi?'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tambahkan minimal satu sumber yang sudah selesai dibaca dulu.');

        config(['services.gemini.key' => null]);
        $ready = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        $this->sendAsStudent('POST', "/api/notebooks/{$ready->id}/messages", ['question' => 'Apa isi materi?'])->assertStatus(503);
    }

    public function test_the_daily_budget_is_enforced_per_student(): void
    {
        config(['notebook.limits.daily_requests' => 1]);
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        $this->geminiAnswers('Jawaban [1].');

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Pertama?'])->assertCreated();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Kedua?'])->assertStatus(429);

        RateLimiter::clear('notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d'));
    }

    public function test_an_overloaded_model_is_reported_without_storing_the_question(): void
    {
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        Http::fake([self::GEMINI_URL => Http::response(['error' => ['message' => 'overloaded']], 503)]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Layanan AI sedang sibuk atau tidak bisa dihubungi. Coba lagi beberapa saat.');
        $this->assertSame(0, NotebookMessage::query()->count());
    }

    public function test_a_slow_or_overloaded_model_is_given_up_within_the_request_budget(): void
    {
        config(['notebook.limits.daily_requests' => 100, 'services.gemini.fallback_model' => 'gemini-test-lite', 'services.gemini.request_budget' => 1]);
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        Http::fake([self::GEMINI_URL => Http::response(['error' => ['message' => 'high demand']], 503)]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Layanan AI sedang sibuk atau tidak bisa dihubungi. Coba lagi beberapa saat.');

        // No retry and no fallback model once the budget is spent.
        Http::assertSentCount(1);

        config(['services.gemini.request_budget' => 50]);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])->assertStatus(503);
        Http::assertSentCount(1 + 4);
        $this->assertSame(0, NotebookMessage::query()->count());
    }

    public function test_large_notebooks_send_the_excerpts_that_match_the_question(): void
    {
        config(['notebook.limits.context_chars' => 2000]);
        $filler = str_repeat('pembahasan umum tentang materi kuliah ', 30);
        $notebook = $this->notebookWithChunks([
            ['page' => 1, 'content' => $filler],
            ['page' => 2, 'content' => 'Trigger dijalankan otomatis saat INSERT atau UPDATE.'],
            ['page' => 3, 'content' => $filler.'lagi'],
        ]);

        $excerpts = app(NotebookAssistant::class)->excerpts($notebook, 'Kapan trigger dijalankan?');

        $this->assertContains(2, array_map(static fn (array $excerpt): ?int => $excerpt['chunk']->page, $excerpts));
        $this->assertLessThanOrEqual(2000, array_sum(array_map(static fn (array $excerpt): int => mb_strlen($excerpt['chunk']->content), $excerpts)));
    }

    public function test_notes_can_be_pinned_and_messages_deleted(): void
    {
        $notebook = $this->notebook();
        $message = NotebookMessage::factory()->create(['notebook_id' => $notebook->id]);

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$message->id}", ['pinned' => true])
            ->assertOk()
            ->assertJsonPath('data.message.pinned', true);
        $this->sendAsStudent('DELETE', "/api/notebooks/{$notebook->id}/messages/{$message->id}")->assertOk();
        $this->assertNull($message->fresh());
    }

    public function test_deleting_a_notebook_removes_its_uploads(): void
    {
        config(['services.gemini.key' => null]);
        $notebook = $this->notebook();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('catatan.txt', 'Catatan kuliah')])->assertCreated();
        $path = NotebookSource::query()->value('disk_path');
        Storage::disk('local')->assertExists($path);

        $this->sendAsStudent('DELETE', '/api/notebooks/'.$notebook->id)->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, NotebookChunk::query()->count());
    }

    public function test_unsupported_uploads_are_rejected(): void
    {
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->create('program.exe', 10)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Format berkas harus PDF, DOCX, PPTX, TXT, MD, PNG, atau JPG.');
    }

    public function test_notebook_pages_render(): void
    {
        $notebook = $this->notebook();

        $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook')->assertOk()->assertSee('Notebook baru');
        $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook/'.$notebook->id)->assertOk()->assertSee('Studio');
    }

    private function notebook(): Notebook
    {
        return Notebook::factory()->forOwner($this->ownerHash)->create();
    }

    /**
     * @param  list<array{page: int|null, content: string}>  $chunks
     */
    private function notebookWithChunks(array $chunks): Notebook
    {
        $notebook = $this->notebook();
        $source = NotebookSource::factory()->create(['notebook_id' => $notebook->id, 'title' => 'Modul']);

        foreach ($chunks as $position => $chunk) {
            NotebookChunk::query()->create(['notebook_source_id' => $source->id, 'notebook_id' => $notebook->id, 'position' => $position, ...$chunk]);
        }

        return $notebook;
    }

    private function geminiAnswers(string $text): void
    {
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
        ])]);
    }

    /**
     * @param  list<string>  $paragraphs
     */
    private function docx(array $paragraphs): UploadedFile
    {
        $body = implode('', array_map(static fn (string $text): string => '<w:p><w:r><w:t>'.htmlspecialchars($text).'</w:t></w:r></w:p>', $paragraphs));

        return $this->zipUpload('bab-1.docx', ['word/document.xml' => '<w:document><w:body>'.$body.'</w:body></w:document>']);
    }

    /**
     * @param  array<int, list<string>>  $slides
     */
    private function pptx(array $slides): UploadedFile
    {
        $entries = [];

        foreach ($slides as $number => $lines) {
            $entries["ppt/slides/slide{$number}.xml"] = '<p:sld>'.implode('', array_map(static fn (string $line): string => '<a:p><a:r><a:t>'.htmlspecialchars($line).'</a:t></a:r></a:p>', $lines)).'</p:sld>';
        }

        return $this->zipUpload('slide-normalisasi.pptx', $entries);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zipUpload(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'notebook-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
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
