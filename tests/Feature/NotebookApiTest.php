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

        // The earlier [1] numbered other excerpts than this request's, so it is not sent back.
        Http::assertSent(fn (Request $request): bool => ($request['contents'][0]['role'] ?? null) === 'user'
            && ($request['contents'][1]['role'] ?? null) === 'model'
            && ($request['contents'][1]['parts'][0]['text'] ?? null) === 'Kunci primer unik.'
            && count($request['contents']) === 3);
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
            ->assertJsonPath('message', NotebookAiException::notConfigured()->getMessage())
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
            ->assertJsonPath('message', NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY)->getMessage())
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
                ->assertSee('kunci API Gemini di server WeCIC ditolak')
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
