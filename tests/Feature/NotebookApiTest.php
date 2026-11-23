<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\NotebookAiException;
use App\Models\Notebook;
use App\Models\NotebookAiConsent;
use App\Models\NotebookChunk;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use App\Models\PortalArchivedFile;
use App\Services\NotebookAssistant;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'notebook.thinking_budget' => null,
        ]);
        $this->instance(PortalSessionManager::class, Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('validateSession')->andReturnTrue();
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
        $this->assertStringContainsString('kunci API Google Gemini belum diisi', (string) $source->error);
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

        // The earlier [1] numbered other excerpts than this request's, so it is not sent back.
        Http::assertSent(fn (Request $request): bool => ($request['contents'][0]['role'] ?? null) === 'user'
            && ($request['contents'][1]['role'] ?? null) === 'model'
            && ($request['contents'][1]['parts'][0]['text'] ?? null) === 'Kunci primer unik.'
            && count($request['contents']) === 3);
    }

    public function test_an_unknown_answer_mode_is_refused_in_indonesian_before_the_model_is_called(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Kunci primer unik.']]);
        Http::fake();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu kunci primer?', 'mode' => 'ujian'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mode jawaban tidak dikenal. Pilih jawab langsung, mode tutor, atau ringkas.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu kunci primer?', 'mode' => ['tutor']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mode jawaban tidak valid.');
        Http::assertNothingSent();
        $this->assertSame(0, NotebookMessage::query()->count());
    }

    public function test_tutor_mode_sends_the_configured_length_of_history_and_other_modes_the_usual_one(): void
    {
        config(['notebook.limits.history_messages' => 2, 'notebook.limits.tutor_history_messages' => 4]);
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Kunci primer unik.']]);

        foreach (range(1, 3) as $turn) {
            NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'role' => 'user', 'content' => "Pertanyaan {$turn}"]);
            NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'role' => 'assistant', 'content' => "Jawaban {$turn}"]);
        }

        $this->geminiAnswers('Coba jelaskan dulu dengan kata-katamu [1].');

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Boleh kosong?', 'mode' => 'tutor'])->assertCreated();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Boleh ganda?', 'mode' => 'ringkas'])->assertCreated();

        $sent = Http::recorded()->map(fn (array $pair): array => $pair[0]['contents'])->values();
        $this->assertCount(5, $sent[0]);
        $this->assertSame('Pertanyaan 2', $sent[0][0]['parts'][0]['text']);
        $this->assertCount(3, $sent[1]);
    }

    public function test_bracketed_numbers_in_code_are_not_citations(): void
    {
        $notebook = $this->notebookWithChunks([
            ['page' => 1, 'content' => 'Array diakses lewat indeks.'],
            ['page' => 2, 'content' => 'Indeks array dimulai dari nol.'],
            ['page' => 3, 'content' => 'Matriks adalah array dua dimensi.'],
        ]);
        $answer = "Gunakan arr[0] untuk elemen pertama, lalu arr[1] dan matrix[2][5] [1][3].\nContoh: `x = data[2]` [2, 9].\n```\nprint(nilai[3])\n[1]\n```";
        $this->geminiAnswers($answer);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Bagaimana mengakses elemen array?'])
            ->assertCreated()
            ->assertJsonPath('data.answer.content', str_replace('[2, 9]', '[2]', $answer))
            ->assertJsonPath('data.answer.citations.*.n', [1, 2, 3]);
    }

    public function test_a_citation_glued_to_a_word_is_left_as_written_and_the_model_is_told_to_space_it(): void
    {
        $notebook = $this->notebookWithChunks([
            ['page' => 1, 'content' => 'Normalisasi mengurangi redundansi data.'],
            ['page' => 2, 'content' => 'Bentuk normal ketiga menghapus ketergantungan transitif.'],
        ]);
        $this->geminiAnswers('Normalisasi mengurangi redundansi[1]. 3NF menghapus ketergantungan transitif.[2]');

        // Glued to a word it reads like arr[1], so it is not a citation; after a full stop it is.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])
            ->assertCreated()
            ->assertJsonPath('data.answer.content', 'Normalisasi mengurangi redundansi[1]. 3NF menghapus ketergantungan transitif.[2]')
            ->assertJsonPath('data.answer.citations.*.n', [2]);
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['systemInstruction']['parts'][0]['text'] ?? ''), 'Tulis rujukan dengan satu spasi sebelum kurung siku'));

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'summary'])->assertCreated();
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['contents'][0]['parts'][0]['text'] ?? ''), 'Beri rujukan [n] dengan spasi sebelum kurung siku.'));
    }

    public function test_a_failed_model_call_does_not_use_the_daily_budget(): void
    {
        config(['notebook.limits.daily_requests' => 1]);
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        Http::fake([self::GEMINI_URL => Http::sequence()
            ->push(['error' => ['message' => 'overloaded']], 503)
            ->push(['error' => ['message' => 'overloaded']], 503)
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Jawaban [1].']]], 'finishReason' => 'STOP']]])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Pertama?'])->assertStatus(503);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Pertama?'])->assertCreated();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Kedua?'])->assertStatus(429);

        RateLimiter::clear('notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d'));
    }

    public function test_an_answer_cut_at_the_output_limit_says_so(): void
    {
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Normalisasi mengurangi redundansi.']]);
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Normalisasi punya beberapa tahap [1]. Tahap pertama']]], 'finishReason' => 'MAX_TOKENS']],
            'usageMetadata' => ['thoughtsTokenCount' => 3000, 'candidatesTokenCount' => 5192],
        ])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Jelaskan normalisasi.'])
            ->assertCreated()
            ->assertJsonPath('data.answer.content', "Normalisasi punya beberapa tahap [1]. Tahap pertama\n\n*Jawaban terpotong karena terlalu panjang. Minta lanjutannya, atau persempit pertanyaanmu.*")
            ->assertJsonPath('data.answer.context.truncated', true);

        // Asked once more with room for the thinking and twice the answer, then kept as cut.
        $this->assertSame([8192, 2 * 8192 + 3000], Http::recorded()->map(fn (array $pair): mixed => $pair[0]['generationConfig']['maxOutputTokens'] ?? null)->all());
        $this->assertSame(1, RateLimiter::attempts($budgetKey));

        RateLimiter::clear($budgetKey);
    }

    public function test_an_answer_cut_at_the_output_limit_is_completed_with_a_larger_limit(): void
    {
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Normalisasi mengurangi redundansi.']]);
        Http::fake([self::GEMINI_URL => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Normalisasi punya']]], 'finishReason' => 'MAX_TOKENS']], 'usageMetadata' => ['thoughtsTokenCount' => 8000]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Normalisasi punya tiga tahap [1].']]], 'finishReason' => 'STOP']]])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Jelaskan normalisasi.'])
            ->assertCreated()
            ->assertJsonPath('data.answer.content', 'Normalisasi punya tiga tahap [1].')
            ->assertJsonPath('data.answer.context.truncated', false);

        RateLimiter::clear('notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d'));
    }

    public function test_an_answer_whose_larger_retry_only_thought_keeps_the_cut_answer(): void
    {
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Normalisasi mengurangi redundansi.']]);
        Http::fake([self::GEMINI_URL => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Normalisasi punya beberapa tahap [1]. Tahap pertama']]], 'finishReason' => 'MAX_TOKENS']], 'usageMetadata' => ['thoughtsTokenCount' => 3000]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Menimbang ulang…', 'thought' => true]]], 'finishReason' => 'MAX_TOKENS']], 'usageMetadata' => ['thoughtsTokenCount' => 19384]])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Jelaskan normalisasi.'])
            ->assertCreated()
            ->assertJsonPath('data.answer.content', "Normalisasi punya beberapa tahap [1]. Tahap pertama\n\n*Jawaban terpotong karena terlalu panjang. Minta lanjutannya, atau persempit pertanyaanmu.*")
            ->assertJsonPath('data.answer.context.truncated', true);
        Http::assertSentCount(2);
        $this->assertSame(1, RateLimiter::attempts($budgetKey));

        RateLimiter::clear($budgetKey);
    }

    public function test_a_studio_output_cut_before_any_text_is_an_error_not_an_empty_note(): void
    {
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Normalisasi mengurangi redundansi.']]);
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Menyusun ringkasan…', 'thought' => true]]], 'finishReason' => 'MAX_TOKENS']],
            'usageMetadata' => ['thoughtsTokenCount' => 16384],
        ])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'summary'])
            ->assertStatus(502)
            ->assertJsonPath('message', NotebookAiException::outputExhausted()->getMessage());

        $this->assertSame(0, NotebookMessage::query()->count());
        Http::assertSentCount(2);
        // The model spent its whole output twice: that is charged like any answer.
        $this->assertSame(1, RateLimiter::attempts($budgetKey));

        RateLimiter::clear($budgetKey);
    }

    public function test_a_source_summary_cut_at_its_limit_says_so_and_one_without_text_is_left_out(): void
    {
        $responses = [];
        Http::fake([self::GEMINI_URL => function () use (&$responses) {
            return Http::response(array_shift($responses));
        }]);
        $notebook = $this->notebook();

        $responses = array_fill(0, 2, ['candidates' => [['content' => ['parts' => [['text' => 'Sumber ini membahas normalisasi dan']]], 'finishReason' => 'MAX_TOKENS']]]);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Catatan 1', 'content' => 'Normalisasi basis data.'])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready')
            ->assertJsonPath('data.sources.0.summary', "Sumber ini membahas normalisasi dan\n(Ringkasan terpotong karena terlalu panjang.)");

        $responses = array_fill(0, 2, ['candidates' => [['content' => ['parts' => [['text' => 'Memikirkan…', 'thought' => true]]], 'finishReason' => 'MAX_TOKENS']]]);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Catatan 2', 'content' => 'Denormalisasi basis data.'])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.status', 'ready')
            ->assertJsonPath('data.sources.0.summary', null);

        Http::assertSentCount(4);
        RateLimiter::clear('notebook-reads:'.$this->ownerHash.':'.now()->format('Y-m-d'));
    }

    public function test_the_ai_status_says_why_the_ai_cannot_answer(): void
    {
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);

        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_status', 'ok')->assertJsonPath('data.ai_available', true);
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_status', 'ok');

        config(['services.gemini.key' => null]);
        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_status', 'missing_key')->assertJsonPath('data.ai_available', false);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])
            ->assertStatus(503)
            ->assertJsonPath('message', NotebookAiException::notConfigured('Google Gemini')->getMessage())
            ->assertJsonPath('data.ai_status', 'missing_key');

        config(['services.gemini.key' => 'test-gemini-key', 'notebook.enabled' => false]);
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_status', 'disabled')->assertJsonPath('data.ai_available', false);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'summary'])
            ->assertStatus(503)
            ->assertJsonPath('message', NotebookAiException::disabled()->getMessage())
            ->assertJsonPath('data.ai_status', 'disabled');

        Http::assertNothingSent();
    }

    public function test_a_retired_model_is_reported_as_such_until_it_is_fixed(): void
    {
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        Http::fake([self::GEMINI_URL => Http::response(['error' => ['code' => 404, 'message' => 'models/gemini-test-flash is not found for API version v1beta, or is not supported for generateContent.', 'status' => 'NOT_FOUND']], 404)]);

        // Not "busy" and not a missing key: the model setting is wrong.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])
            ->assertStatus(503)
            ->assertJsonPath('message', NotebookAiException::misconfigured(NotebookAiException::CAUSE_MODEL)->getMessage())
            ->assertJsonPath('data.ai_status', 'model_misconfigured');
        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_status', 'model_misconfigured')->assertJsonPath('data.ai_available', false);
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_status', 'model_misconfigured');

        // Remembered: the next question is answered at once, without calling the model again.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'summary'])
            ->assertStatus(503)
            ->assertJsonPath('data.ai_status', 'model_misconfigured');
        Http::assertSentCount(1);
        $this->assertSame(0, NotebookMessage::query()->count());
        $this->assertSame(0, RateLimiter::attempts($budgetKey));

        // A fixed model setting is used right away.
        config(['services.gemini.model' => 'gemini-test-pro']);
        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_status', 'ok');
    }

    public function test_a_rejected_key_is_reported_as_such(): void
    {
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        Http::fake([self::GEMINI_URL => Http::response(['error' => [
            'code' => 400,
            'message' => 'API key not valid. Please pass a valid API key.',
            'status' => 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']],
        ]], 400)]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])
            ->assertStatus(503)
            ->assertJsonPath('message', NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY, 'Google Gemini')->getMessage())
            ->assertJsonPath('data.ai_status', 'invalid_key');
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_status', 'invalid_key');

        // A new key is tried right away.
        config(['services.gemini.key' => 'another-test-key']);
        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_status', 'ok');
    }

    public function test_a_busy_model_comes_without_an_ai_status(): void
    {
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        Http::fake([self::GEMINI_URL => Http::response(['error' => ['message' => 'overloaded']], 503)]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])
            ->assertStatus(503)
            ->assertJsonMissingPath('data.ai_status');
        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_status', 'ok');
    }

    public function test_a_quiz_cut_at_the_output_limit_asks_for_fewer_sources_and_is_charged(): void
    {
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => '[{"question": "Fungsi JOIN?", "options": ["Meng']]], 'finishReason' => 'MAX_TOKENS']]])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Hasilnya terlalu panjang untuk dibuat sekaligus. Centang lebih sedikit sumber, lalu coba lagi.');

        // The full output was produced (and paid for), so repeating it is not free.
        $this->assertSame(1, RateLimiter::attempts($budgetKey));
        $this->assertSame(0, NotebookMessage::query()->count());

        RateLimiter::clear($budgetKey);
    }

    public function test_answers_the_model_withheld_or_garbled_are_charged(): void
    {
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        Http::fake([self::GEMINI_URL => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => []], 'finishReason' => 'SAFETY']]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'bukan JSON']]], 'finishReason' => 'STOP']]])
            ->push(['error' => ['message' => 'overloaded']], 503)
            ->push(['error' => ['message' => 'overloaded']], 503)]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu JOIN?'])
            ->assertStatus(422)
            ->assertJsonPath('message', NotebookAiException::blocked()->getMessage());
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'flashcards'])
            ->assertStatus(502)
            ->assertJsonPath('message', NotebookAiException::emptyAnswer()->getMessage());
        $this->assertSame(2, RateLimiter::attempts($budgetKey));

        // A model that never answered costs nothing.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu JOIN?'])->assertStatus(503);
        $this->assertSame(2, RateLimiter::attempts($budgetKey));

        RateLimiter::clear($budgetKey);
    }

    public function test_saved_notes_older_than_the_latest_messages_are_still_listed(): void
    {
        $notebook = $this->notebook();
        $quiz = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'kind' => 'quiz', 'content' => '[]', 'pinned' => false]);
        $pinned = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'pinned' => true]);
        $oldChat = NotebookMessage::factory()->create(['notebook_id' => $notebook->id]);
        NotebookMessage::factory()->count(200)->create(['notebook_id' => $notebook->id]);

        $data = $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->json('data');
        $messages = $data['messages'];

        $this->assertCount(202, $messages);
        $this->assertSame([$quiz->id, $pinned->id], array_slice(array_column($messages, 'id'), 0, 2));
        $this->assertNotContains($oldChat->id, array_column($messages, 'id'));
        // The chat starts after them: the old saved answer is only a note, not a reply without its question.
        $this->assertSame($messages[2]['id'], $data['chat_from_id']);
        $this->assertGreaterThan($pinned->id, $data['chat_from_id']);
    }

    public function test_a_quiz_is_generated_as_structured_data_and_saved_to_notes(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        // The schema's root is an object: the list comes wrapped in `items` and is saved bare.
        $this->geminiAnswers(json_encode(['items' => [[
            'question' => 'Fungsi JOIN?',
            'options' => ['Menghapus', 'Menggabungkan tabel', 'Mengurutkan', 'Membuat indeks'],
            'answer_index' => 1,
            'explanation' => 'JOIN menggabungkan tabel.',
            'citations' => [1],
        ]]]));

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz'])
            ->assertCreated()
            ->assertJsonPath('message', 'Kuis dibuat.')
            ->assertJsonPath('data.message.kind', 'quiz')
            ->assertJsonPath('data.message.pinned', true)
            ->assertJsonPath('data.message.data.0.answer_index', 1)
            ->assertJsonPath('data.message.citations.0.page', 4);
        Http::assertSent(fn (Request $request): bool => ($request['generationConfig']['responseMimeType'] ?? null) === 'application/json'
            && ($request['generationConfig']['responseSchema']['type'] ?? null) === 'OBJECT'
            && ($request['generationConfig']['responseSchema']['properties']['items']['type'] ?? null) === 'ARRAY');
        $this->assertSame(1, json_decode((string) NotebookMessage::query()->where('kind', 'quiz')->value('content'), true)[0]['answer_index']);
    }

    public function test_a_mind_map_cut_before_its_last_brace_is_too_long_not_a_list_of_its_branches(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        $cut = '{"title":"T","children":[{"title":"A","children":[{"title":"a","children":[]}]}]';
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => $cut]]], 'finishReason' => 'MAX_TOKENS']]])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'mind_map'])
            ->assertStatus(502)
            ->assertJsonPath('message', NotebookAiException::tooLong()->getMessage());

        $this->assertSame(0, NotebookMessage::query()->count());
    }

    public function test_a_list_output_is_unwrapped_from_items_and_a_wrapper_without_a_list_is_no_answer(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        $card = ['front' => 'JOIN', 'back' => 'Menggabungkan tabel.', 'citations' => [1]];
        Http::fake([self::GEMINI_URL => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['cards' => [$card]])]]], 'finishReason' => 'STOP']]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([$card])]]], 'finishReason' => 'STOP']]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => [$card]])]]], 'finishReason' => 'STOP']]])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'flashcards'])
            ->assertStatus(502)
            ->assertJsonPath('message', NotebookAiException::emptyAnswer()->getMessage());

        // A bare list is kept as it is; a wrapped one is stored bare.
        foreach (range(1, 2) as $attempt) {
            $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'flashcards'])
                ->assertCreated()
                ->assertJsonPath('data.message.data.0.front', 'JOIN')
                ->assertJsonPath('data.message.citations.0.page', 4);
        }

        $this->assertSame(
            [[$card], [$card]],
            NotebookMessage::query()->where('kind', 'flashcards')->orderBy('id')->pluck('content')->map(fn (string $content): mixed => json_decode($content, true))->all()
        );
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

    public function test_requests_sent_at_the_same_time_cannot_pass_the_daily_budget_together(): void
    {
        config(['notebook.limits.daily_requests' => 1]);
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        $parallel = null;
        $sent = false;
        Http::fake([self::GEMINI_URL => function () use (&$parallel, &$sent, $notebook) {
            // A second question from another tab while the model works on the first.
            if (! $sent) {
                $sent = true;

                try {
                    app(NotebookAssistant::class)->ask($notebook->fresh(), 'Kedua?');
                    $parallel = 'answered';
                } catch (NotebookAiException $exception) {
                    $parallel = $exception;
                }
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Jawaban [1].']]], 'finishReason' => 'STOP']]]);
        }]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Pertama?'])->assertCreated();

        $this->assertInstanceOf(NotebookAiException::class, $parallel);
        $this->assertSame(NotebookAiException::dailyLimitReached()->getMessage(), $parallel->getMessage());
        $this->assertSame(429, $parallel->getCode());
        Http::assertSentCount(1);
        $this->assertSame(1, RateLimiter::attempts($budgetKey));

        RateLimiter::clear($budgetKey);
    }

    public function test_a_request_charged_after_the_budget_check_is_refused_before_the_model_is_called(): void
    {
        config(['notebook.limits.daily_requests' => 1]);
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        $this->geminiAnswers('Jawaban [1].');
        $charged = false;
        // Another request spends the last one while this one gathers its excerpts.
        DB::listen(function (QueryExecuted $query) use (&$charged, $budgetKey): void {
            if (! $charged && str_contains($query->sql, 'notebook_chunks')) {
                $charged = true;
                RateLimiter::hit($budgetKey, 86400);
            }
        });

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Pertama?'])
            ->assertStatus(429)
            ->assertJsonPath('message', NotebookAiException::dailyLimitReached()->getMessage());

        $this->assertTrue($charged);
        Http::assertNothingSent();
        // The refused request gave its hit back: only the other one is spent.
        $this->assertSame(1, RateLimiter::attempts($budgetKey));
        $this->assertSame(0, NotebookMessage::query()->count());

        RateLimiter::clear($budgetKey);
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

    public function test_studio_options_out_of_range_or_unknown_are_refused_in_indonesian_before_the_model_is_called(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Kunci primer unik.']]);
        Http::fake();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz', 'options' => ['count' => 16]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah paling banyak 15 untuk kuis.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'flashcards', 'options' => ['count' => 21]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah paling banyak 20 untuk flashcard.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz', 'options' => ['count' => 4]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah paling sedikit 5.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz', 'options' => ['difficulty' => 'ujian']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tingkat kesulitan tidak dikenal. Pilih campuran, dasar, atau sulit.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'summary', 'options' => ['count' => 5]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah dan tingkat kesulitan hanya untuk kuis dan flashcard.');
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz', 'options' => ['timer' => 30]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilihan latihan tidak valid.');
        Http::assertNothingSent();
        $this->assertSame(0, NotebookMessage::query()->count());
    }

    public function test_flashcard_options_are_sent_and_kept(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Kunci primer unik.']]);
        $this->geminiAnswers((string) json_encode(['items' => array_fill(0, 20, ['front' => 'Kunci primer?', 'back' => 'Unik.', 'citations' => [1]])]));

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'flashcards', 'options' => ['count' => 20, 'difficulty' => 'dasar']])
            ->assertCreated()
            ->assertJsonPath('data.message.context.options', ['count' => 20, 'difficulty' => 'dasar']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) collect($request['contents'])->last()['parts'][0]['text'], 'Jumlah: 20. Tingkat kesulitan: dasar.')
            && $request['generationConfig']['maxOutputTokens'] === 16384
            && $request['generationConfig']['responseSchema']['properties']['items']['maxItems'] === 20);
    }

    public function test_a_quiz_attempt_is_kept_with_the_score_counted_by_the_server_without_the_model_or_the_budget(): void
    {
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebook();
        $quiz = $this->quiz($notebook, 4);
        Http::fake();

        // The client claims a perfect score; the server counts the choices against answer_index instead.
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['choices' => [1, 0, null, 3], 'flagged' => [3], 'score' => 4, 'total' => 4]])
            ->assertOk()
            ->assertJsonPath('message', 'Hasil latihan disimpan.')
            ->assertJsonPath('data.message.context.practice.score', 1)
            ->assertJsonPath('data.message.context.practice.total', 3)
            ->assertJsonPath('data.message.context.practice.missed', [1, 2])
            ->assertJsonPath('data.message.context.practice.flagged', [3])
            ->assertJsonPath('data.message.pinned', true);

        $practice = $quiz->fresh()->context['practice'];
        $this->assertSame([1, 3], [$practice['score'], $practice['total']]);
        $this->assertSame([1, 0, null, 3], $practice['choices']);
        // With choices, a client score, total or missed list is ignored rather than checked.
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['choices' => [1, 0, 2, 3], 'score' => 5, 'missed' => [99]]])
            ->assertOk()
            ->assertJsonPath('data.message.context.practice.score', 1)
            ->assertJsonPath('data.message.context.practice.total', 4)
            ->assertJsonPath('data.message.context.practice.missed', [1, 2, 3]);
        // The context the output was made with is kept beside the attempt.
        $this->assertSame([], $quiz->fresh()->context['source_ids']);
        Http::assertNothingSent();
        $this->assertSame(0, RateLimiter::attempts($budgetKey));
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_budget', ['limit' => 60, 'used' => 0, 'remaining' => 60]);
    }

    public function test_a_flashcard_attempt_is_kept_as_sent(): void
    {
        $notebook = $this->notebook();
        $cards = NotebookMessage::factory()->create([
            'notebook_id' => $notebook->id,
            'role' => 'assistant',
            'kind' => 'flashcards',
            'content' => (string) json_encode(array_fill(0, 5, ['front' => 'Depan', 'back' => 'Belakang', 'citations' => []])),
            'pinned' => true,
        ]);

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$cards->id}", ['practice' => ['score' => 3, 'total' => 5, 'missed' => [4, 0]]])
            ->assertOk()
            ->assertJsonPath('data.message.context.practice.score', 3)
            ->assertJsonPath('data.message.context.practice.missed', [0, 4]);
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$cards->id}", ['practice' => ['choices' => [0, 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilihan jawaban hanya untuk kuis.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$cards->id}", ['practice' => ['score' => 6, 'total' => 6]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah soal melebihi isi latihan ini.');
    }

    public function test_practice_is_refused_on_a_chat_message_or_with_invalid_items(): void
    {
        $notebook = $this->notebook();
        $chat = NotebookMessage::factory()->create(['notebook_id' => $notebook->id, 'role' => 'assistant']);
        $quiz = $this->quiz($notebook, 3);

        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$chat->id}", ['practice' => ['score' => 1, 'total' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hasil latihan hanya bisa disimpan untuk kuis atau flashcard.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['score' => 1, 'total' => 3, 'missed' => [1, 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor soal tidak boleh berulang.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['score' => 1, 'total' => 3, 'missed' => ['satu']]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor soal harus berupa angka.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['score' => 1, 'total' => 3, 'missed' => range(0, 30)]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Daftar soal yang salah terlalu panjang.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['score' => 1, 'total' => 3, 'missed' => [7]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor soal tidak ada di latihan ini.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['score' => 3, 'total' => 2]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Skor tidak boleh melebihi jumlah soal.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['missed' => [0]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kirim skor latihan atau pilihan jawabannya.');
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih simpan atau lepas dari catatan.');
        // An empty attempt alongside a pin is refused, not kept as 0/0.
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['pinned' => true, 'practice' => []])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hasil latihan tidak boleh kosong.');
        // A quiz score is never taken from the client: without its choices the attempt is refused.
        $this->sendAsStudent('PATCH', "/api/notebooks/{$notebook->id}/messages/{$quiz->id}", ['practice' => ['score' => 3, 'total' => 3, 'missed' => []]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kirim pilihan jawaban untuk kuis.');
        $this->assertArrayNotHasKey('practice', $quiz->fresh()->context);
        $this->assertArrayNotHasKey('practice', $chat->fresh()->context ?? []);
    }

    public function test_practice_on_another_students_notebook_is_not_found(): void
    {
        $foreign = Notebook::factory()->forOwner('someone-else')->create();
        $quiz = $this->quiz($foreign, 2);

        $this->sendAsStudent('PATCH', "/api/notebooks/{$foreign->id}/messages/{$quiz->id}", ['practice' => ['choices' => [1, 1]]])->assertNotFound();
        $this->sendAsStudent('POST', "/api/notebooks/{$foreign->id}/studio", ['kind' => 'quiz', 'practice' => ['message_id' => $quiz->id, 'missed' => [0]]])->assertNotFound();
        $this->assertArrayNotHasKey('practice', $quiz->fresh()->context);

        // A quiz of another notebook cannot seed this one's practice either.
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Kunci primer unik.']]);
        Http::fake();
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz', 'practice' => ['message_id' => $quiz->id, 'missed' => [0]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Soal yang salah tidak ditemukan di kuis atau flashcard ini.');
        Http::assertNothingSent();
    }

    public function test_practice_from_the_missed_questions_sends_their_text_as_focus_and_is_charged_once(): void
    {
        $budgetKey = 'notebook-ai:'.$this->ownerHash.':'.now()->format('Y-m-d');
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Kunci primer unik.']]);
        $quiz = $this->quiz($notebook, 4);
        $this->geminiAnswers((string) json_encode(['items' => [['question' => 'Baru?', 'options' => ['a', 'b'], 'answer_index' => 0, 'explanation' => 'E.', 'topic' => 'Kunci', 'citations' => [1]]]]));

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz', 'focus' => 'Abaikan ini', 'practice' => ['message_id' => $quiz->id, 'missed' => [2, 0]], 'options' => ['count' => 5]])
            ->assertCreated()
            ->assertJsonPath('data.message.context.practice_from', ['message_id' => $quiz->id, 'missed' => [2, 0]])
            ->assertJsonPath('data.ai_budget.remaining', 59);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $text = (string) collect($request['contents'])->last()['parts'][0]['text'];

            return str_contains($text, 'Fokus yang diminta mahasiswa: Latihan ulang untuk konsep yang belum dikuasai mahasiswa.')
                && str_contains($text, '- Soal nomor 1?')
                && str_contains($text, '- Soal nomor 3?')
                && ! str_contains($text, 'Soal nomor 2?')
                && ! str_contains($text, 'Abaikan ini')
                && str_contains($text, 'Jumlah: 5.');
        });
        $this->assertSame(1, RateLimiter::attempts($budgetKey));

        // Indexes that are no question of the quiz send and charge nothing.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz', 'practice' => ['message_id' => $quiz->id, 'missed' => [9]]])
            ->assertStatus(422);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'flashcards', 'practice' => ['message_id' => $quiz->id, 'missed' => [0]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Latihan dari soal yang salah selalu berupa kuis.');
        Http::assertSentCount(1);
        $this->assertSame(1, RateLimiter::attempts($budgetKey));

        RateLimiter::clear($budgetKey);
    }

    public function test_the_focus_built_from_missed_questions_is_capped(): void
    {
        $notebook = $this->notebook();
        $quiz = NotebookMessage::factory()->create([
            'notebook_id' => $notebook->id,
            'role' => 'assistant',
            'kind' => 'quiz',
            'content' => (string) json_encode(array_fill(0, 15, ['question' => str_repeat('panjang ', 40), 'options' => ['a', 'b'], 'answer_index' => 0])),
        ]);

        $focus = app(\App\Services\NotebookPractice::class)->missedFocus($quiz, range(0, 14));

        $this->assertSame(1500, mb_strlen((string) $focus));
        $this->assertStringEndsWith('…', (string) $focus);
        $this->assertNull(app(\App\Services\NotebookPractice::class)->missedFocus($quiz, [20]));
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
        $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook/'.$notebook->id)
            ->assertOk()
            ->assertSee('Studio')
            // The conversation is redrawn whole, so only the new answer is announced.
            ->assertDontSee('id="notebook-messages" aria-live', false)
            ->assertSee('app.announce(`Jawaban: ', false)
            // Each source checkbox is named after its source.
            ->assertSee('aria-label="Pakai ${app.escapeHtml(source.title)} sebagai konteks"', false)
            ->assertDontSee('aria-label="Pakai sebagai konteks"', false)
            // No ticked source: asking is blocked instead of sending an empty selection.
            ->assertSee('Centang minimal satu sumber untuk bertanya')
            // Quiz feedback in words, and studio outputs that can only be deleted.
            ->assertSee('Pilihanmu, salah')
            ->assertSee("message.pinned || message.kind !== 'chat'", false)
            // A lost reading can be started again, and polling stops.
            ->assertSee('Terhenti')
            ->assertSee('POLL_LIMIT_MS', false)
            // A stalled source that cannot be read again says how to get it back.
            ->assertSee('Hapus sumber ini, lalu tambahkan lagi.')
            // A file waiting for tomorrow's reading budget may be read now.
            ->assertSee('Baca sekarang')
            // Saved answers older than the conversation stay in Catatan only.
            ->assertSee('message.id >= state.chatFromId', false)
            // Only a bare proxy 502/504 is "busy"; MyCIC's own 502 message ("Hasilnya terlalu panjang…") is shown.
            ->assertSee('(error?.status === 504 || error?.status === 502) && !error?.payload?.message', false)
            // A source waiting for a reading slot says so.
            ->assertSee('Menunggu giliran dibaca.');
    }

    public function test_the_notebook_page_offers_the_answer_modes_remembered_per_notebook(): void
    {
        $notebook = $this->notebook();

        $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook/'.$notebook->id)
            ->assertOk()
            ->assertSeeInOrder(['role="group" aria-label="Cara menjawab"', 'Jawab langsung', 'Mode tutor', 'Ringkas', 'id="notebook-question"'], false)
            ->assertSee('data-ask-mode="answer" aria-pressed="true"', false)
            ->assertSee('data-ask-mode="tutor" aria-pressed="false"', false)
            ->assertSee('min-h-11 flex-1', false)
            // Remembered per notebook, and a browser that refuses storage still works.
            ->assertSee('`mycic-notebook-ask-mode:${notebookId}`', false)
            ->assertSee('window.localStorage.setItem(ASK_MODE_KEY, state.askMode)', false)
            ->assertSee('mode: state.askMode', false)
            ->assertSee('askModeBadge(message)', false)
            ->assertSee("state.askMode === 'tutor' ? TUTOR_SUGGESTIONS : SUGGESTIONS", false)
            ->assertSee('Tuntun aku memahami konsep utama materi ini langkah demi langkah.')
            ->assertSee('tanpa mengerjakan tugasmu');
    }

    public function test_the_notebook_page_offers_quiz_and_flashcard_practice(): void
    {
        $notebook = $this->notebook();

        $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook/'.$notebook->id)
            ->assertOk()
            // Progress is kept across redraws of Catatan.
            ->assertSee('openNoteIds: new Set(),', false)
            ->assertSee("notesHost.addEventListener('toggle'", false)
            ->assertSee('practice: {},', false)
            ->assertSee('state.practice[messageId] ??=', false)
            // Answers, verdicts in words, citations on explanations, and a way out for a wrong item.
            ->assertSee('Pilihanmu, salah')
            ->assertSee('itemCitations(question)', false)
            ->assertSee('Soal ini salah?')
            ->assertSee('Ditandai salah, tidak dihitung dalam skor.')
            // After the last answer: score, local retry, review, and a charged practice set with the budget shown first.
            ->assertSeeInOrder(['Skor ${result.score}/${result.total}', 'budgetLine()', 'Ulangi', 'Tinjau yang salah', 'practiceFromButton(message.id, result.missed)'], false)
            ->assertSee('Sisa kuota AI hari ini: ${state.aiBudget.remaining} dari ${state.aiBudget.limit}. Membuat latihan memakai 1.', false)
            ->assertSee('practice: { message_id: Number(practiceFrom.dataset.practiceFrom), missed }', false)
            // One card at a time, with the grid as the other view.
            ->assertSee('Satu kartu')
            ->assertSee('Semua kartu')
            ->assertSee('Sudah hafal')
            ->assertSee('Acak')
            // Count and difficulty before a quiz or flashcards.
            ->assertSee("const STUDIO_OPTION_KINDS = ['assignment_plan', 'quiz', 'flashcards'];", false)
            ->assertSee('const STUDIO_DIFFICULTIES = {"campuran":"Campuran","dasar":"Dasar","sulit":"Sulit"};', false)
            ->assertSee('max: 15', false)
            ->assertSee('max: 20', false)
            ->assertSee('Tingkat kesulitan');
    }

    public function test_notebook_pages_show_the_banner_for_each_reason_the_ai_cannot_answer(): void
    {
        $notebook = $this->notebook();
        $pages = [
            $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook')->assertOk(),
            $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook/'.$notebook->id)->assertOk(),
        ];

        foreach ($pages as $page) {
            $page->assertSee('data-ai-status-banner="disabled"', false)
                ->assertSee('Fitur AI Notebook dimatikan di server WeCIC.')
                ->assertSee('data-ai-status-banner="missing_key"', false)
                ->assertSee('(GEMINI_API_KEY kosong)')
                ->assertSee('data-ai-status-banner="invalid_key"', false)
                ->assertSee('kunci API Google Gemini di server WeCIC ditolak')
                ->assertSee('data-ai-status-banner="model_misconfigured"', false)
                ->assertSee('pengaturan model AI di server WeCIC ditolak (model tidak dikenali, sudah tidak tersedia, atau pengaturannya tidak cocok)')
                // Not only the model id: a thinking budget the model does not take is rejected the same way.
                ->assertSee('misalnya GEMINI_MODEL atau NOTEBOOK_THINKING_BUDGET')
                ->assertDontSee('perlu memperbarui GEMINI_MODEL')
                // The banner follows ai_status, not just ai_available.
                ->assertSee("data?.ai_status || (data?.ai_available === false ? 'missing_key' : 'ok')", false)
                ->assertSee('banner.dataset.aiStatusBanner !==', false)
                ->assertDontSee('id="notebook-ai-off"', false);
        }

        // A question refused for the server's AI settings swaps the banner too.
        $pages[1]->assertSee('noteAiStatus(error);', false)
            ->assertSee('error?.payload?.data?.ai_status', false)
            // A file waiting for the AI to work again offers no "Baca sekarang" that would only fail.
            ->assertSee('source.waiting && Boolean(source.error) && state.aiAvailable', false);
    }

    public function test_answers_and_studio_outputs_record_the_provider_model_and_tokens_spent(): void
    {
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Normalisasi mengurangi redundansi data.']]);
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Normalisasi mengurangi redundansi [1].']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 640, 'candidatesTokenCount' => 42, 'thoughtsTokenCount' => 128],
        ])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])
            ->assertCreated()
            ->assertJsonPath('data.answer.context.provider', 'gemini')
            ->assertJsonPath('data.answer.context.model', 'gemini-test-flash')
            ->assertJsonPath('data.answer.context.usage', ['input_tokens' => 640, 'output_tokens' => 42, 'thoughts_tokens' => 128])
            ->assertJsonPath('data.answer.context.truncated', false);
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'summary'])
            ->assertCreated()
            ->assertJsonPath('data.message.context.provider', 'gemini')
            ->assertJsonPath('data.message.context.model', 'gemini-test-flash')
            ->assertJsonPath('data.message.context.usage.input_tokens', 640);

        $stored = NotebookMessage::query()->where('kind', 'summary')->sole()->context;
        $this->assertSame(['input_tokens' => 640, 'output_tokens' => 42, 'thoughts_tokens' => 128], $stored['usage']);
        $this->assertSame(1, $stored['excerpts']);
    }

    public function test_the_notebook_payloads_name_the_ai_provider(): void
    {
        $notebook = $this->notebook();
        $gemini = ['driver' => 'gemini', 'label' => 'Google Gemini', 'free_tier' => true, 'consent_key' => 'gemini:free', 'processors' => [['label' => 'Google Gemini', 'free_tier' => true]]];

        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_provider', $gemini);
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_provider', $gemini);

        // A paid Gemini key drops the free-tier warning.
        config(['services.gemini.free_tier' => false]);
        $this->getAsStudent("/api/notebooks/{$notebook->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_provider.free_tier', false)
            ->assertJsonPath('data.ai_consent.free_tier', false);

        config(['notebook.ai.provider' => 'anthropic']);
        $this->getAsStudent("/api/notebooks/{$notebook->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_provider.driver', 'anthropic')
            ->assertJsonPath('data.ai_provider.label', 'Anthropic Claude')
            ->assertJsonPath('data.ai_provider.consent_key', 'anthropic')
            ->assertJsonPath('data.ai_consent.provider', 'Anthropic Claude');
    }

    public function test_notebook_pages_name_the_settings_of_the_provider_the_server_uses(): void
    {
        config(['notebook.ai.provider' => 'anthropic']);
        $notebook = $this->notebook();
        $pages = [
            $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook')->assertOk(),
            $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook/'.$notebook->id)->assertOk(),
        ];

        foreach ($pages as $page) {
            $page->assertSee('(ANTHROPIC_API_KEY kosong)')
                ->assertSee('kunci API Anthropic Claude di server WeCIC ditolak')
                ->assertSee('misalnya ANTHROPIC_MODEL atau ANTHROPIC_EFFORT')
                ->assertDontSee('GEMINI_API_KEY')
                ->assertDontSee('GEMINI_MODEL')
                ->assertDontSee('Gemini');
        }

        $pages[1]->assertSee('memakai Anthropic Claude')
            ->assertSee('AI aktif: pertanyaan dan isi sumber dikirim ke Anthropic Claude.')
            ->assertDontSee('tingkat gratis')
            ->assertSee('aiProviderLabel: "Anthropic Claude"', false)
            ->assertSee('aiConsentKey: "anthropic"', false);
    }

    public function test_the_consent_step_on_the_notebook_page_tells_about_the_gemini_free_tier(): void
    {
        $page = $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook/'.$this->notebook()->id)->assertOk();

        $page->assertSee('memakai Google Gemini')
            ->assertSee('WeCIC memakai layanan Gemini tingkat gratis, sehingga Google dapat menyimpan dan memakai data itu untuk meningkatkan produknya.');

        config(['services.gemini.free_tier' => false]);
        $this->withSession(['campus_session_token' => self::TOKEN])->get('/notebook/'.$this->notebook()->id)
            ->assertOk()
            ->assertSee('memakai Google Gemini')
            ->assertDontSee('tingkat gratis');
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

    /**
     * A saved quiz of `$count` questions whose answer is always option 1,
     * with the context it was made with.
     */
    private function quiz(Notebook $notebook, int $count): NotebookMessage
    {
        return NotebookMessage::factory()->create([
            'notebook_id' => $notebook->id,
            'role' => 'assistant',
            'kind' => 'quiz',
            'content' => (string) json_encode(array_map(static fn (int $number): array => [
                'question' => "Soal nomor {$number}?",
                'options' => ['A', 'B', 'C', 'D'],
                'answer_index' => 1,
                'explanation' => "Penjelasan {$number}.",
                'citations' => [1],
            ], range(1, $count))),
            'context' => ['source_ids' => [], 'sources' => [], 'total_sources' => 1, 'excerpts' => 1, 'chars' => 10, 'partial' => false, 'focus' => null],
            'pinned' => true,
        ]);
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
