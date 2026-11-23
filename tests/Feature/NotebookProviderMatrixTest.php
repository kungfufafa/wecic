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
use App\Services\Ai\NotebookAiClient;
use App\Services\Ai\NotebookAiProviders;
use App\Services\NotebookAssistant;
use App\Services\PortalDataOwner;
use App\Services\PortalSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * The notebook's questions, studio outputs, AI status and file reading on
 * every provider (`notebook.ai.provider`), each faked in its own API shape.
 */
class NotebookProviderMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-provider-matrix';

    private const USERNAME = '20990000008';

    private const URLS = [
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models/*',
        'anthropic' => 'https://api.anthropic.com/v1/messages',
        'openai' => 'http://llm.test/v1/chat/completions',
    ];

    private const UNSUPPORTED = 'Model AI yang dipakai server tidak bisa membaca PDF hasil pindaian atau gambar; tempel teksnya atau unggah DOCX/PPTX.';

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
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-opus-5-5',
            'services.anthropic.fallback_model' => null,
            'services.anthropic.base_url' => 'https://api.anthropic.com',
            'services.anthropic.version' => '2026-10-21',
            'services.anthropic.retry_delay_ms' => 0,
            'services.openai_compatible.key' => null,
            'services.openai_compatible.base_url' => 'http://llm.test/v1',
            'services.openai_compatible.model' => 'llama-test',
            'services.openai_compatible.fallback_model' => null,
            'services.openai_compatible.structured_output' => 'json_schema',
            'services.openai_compatible.token_param' => 'max_tokens',
            'services.openai_compatible.max_output_tokens' => 16384,
            'services.openai_compatible.image_input' => false,
            'services.openai_compatible.pdf_input' => false,
            'services.openai_compatible.retry_delay_ms' => 0,
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

    /**
     * @return array<string, array{string}>
     */
    public static function providers(): array
    {
        return ['gemini' => ['gemini'], 'anthropic' => ['anthropic'], 'openai' => ['openai']];
    }

    #[DataProvider('providers')]
    public function test_an_answer_keeps_the_citations_it_may_use_and_drops_made_up_ones(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider]);
        $notebook = $this->notebookWithChunks([
            ['page' => 1, 'content' => 'Normalisasi mengurangi redundansi data.'],
            ['page' => 2, 'content' => 'Bentuk normal ketiga menghapus ketergantungan transitif.'],
        ]);
        Http::fake([self::URLS[$provider] => Http::response($this->answer($provider, '3NF menghapus ketergantungan transitif [2, 9]. Tujuannya mengurangi redundansi [1].'))]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu 3NF?'])
            ->assertCreated()
            ->assertJsonPath('data.answer.content', '3NF menghapus ketergantungan transitif [2]. Tujuannya mengurangi redundansi [1].')
            ->assertJsonPath('data.answer.citations.0.page', 1)
            ->assertJsonPath('data.answer.citations.1.excerpt', 'Bentuk normal ketiga menghapus ketergantungan transitif.')
            ->assertJsonCount(2, 'data.answer.citations');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($this->lastUserText($provider, $request), '[1] (Sumber: Modul, hlm. 1)')
            && str_ends_with($this->lastUserText($provider, $request), 'Pertanyaan: Apa itu 3NF?'));
    }

    #[DataProvider('providers')]
    public function test_tutor_mode_adds_its_instruction_to_the_question_and_sends_the_system_instruction_unchanged(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider]);
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Normalisasi mengurangi redundansi data.']]);
        Http::fake([self::URLS[$provider] => Http::response($this->answer($provider, 'Apa yang sudah kamu ketahui tentang redundansi? [1]'))]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Bantu aku paham normalisasi.', 'mode' => 'tutor'])
            ->assertCreated()
            ->assertJsonPath('data.answer.context.mode', 'tutor');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($this->lastUserText($provider, $request), "Pertanyaan: Bantu aku paham normalisasi.\n\n---\nMode tutor: ".$this->assistantConstant('TUTOR_INSTRUCTION'))
            && $this->sentSystem($provider, $request) === $this->systemInstruction());
        $this->assertSame('tutor', NotebookMessage::query()->where('role', 'assistant')->sole()->context['mode']);
        $this->assertStringContainsString('Untuk soal tugas atau ujian yang dinilai, bantu memahami: jelaskan konsep, langkah, dan contoh lain, bukan jawaban siap salin.', $this->systemInstruction());
        $this->assertStringContainsString('jangan menyelesaikan tugas/soal milik mahasiswa', $this->assistantConstant('TUTOR_INSTRUCTION'));
    }

    #[DataProvider('providers')]
    public function test_a_question_without_a_mode_is_sent_as_before_and_recorded_as_a_direct_answer(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider, 'services.anthropic.thinking_headroom' => 0]);
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Normalisasi mengurangi redundansi data.']]);
        Http::fake([self::URLS[$provider] => Http::response($this->answer($provider, 'Normalisasi mengurangi redundansi [1].'))]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?'])
            ->assertCreated()
            ->assertJsonPath('data.answer.context.mode', 'answer');
        Http::assertSent(fn (Request $request): bool => str_ends_with($this->lastUserText($provider, $request), 'Pertanyaan: Apa itu normalisasi?')
            && ! str_contains($this->lastUserText($provider, $request), 'Mode ')
            && $this->sentSystem($provider, $request) === $this->systemInstruction()
            && $this->sentOutputLimit($provider, $request) === 8192);
    }

    #[DataProvider('providers')]
    public function test_ringkas_asks_for_at_most_five_points_by_prompt_with_the_usual_output_limit(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider, 'services.anthropic.thinking_headroom' => 0]);
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Normalisasi mengurangi redundansi data.']]);
        Http::fake([self::URLS[$provider] => Http::response($this->answer($provider, '- Mengurangi redundansi [1].'))]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa itu normalisasi?', 'mode' => 'ringkas'])
            ->assertCreated()
            ->assertJsonPath('data.answer.context.mode', 'ringkas');
        $this->assertStringContainsString('paling banyak 5 poin', $this->assistantConstant('RINGKAS_INSTRUCTION'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($this->lastUserText($provider, $request), 'Mode ringkas: '.$this->assistantConstant('RINGKAS_INSTRUCTION'))
            && $this->sentSystem($provider, $request) === $this->systemInstruction()
            && $this->sentOutputLimit($provider, $request) === 8192);
    }

    #[DataProvider('providers')]
    public function test_a_quiz_is_saved_pinned_and_unwrapped_from_its_items(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider]);
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        $question = [
            'question' => 'Fungsi JOIN?',
            'options' => ['Menghapus', 'Menggabungkan tabel', 'Mengurutkan', 'Membuat indeks'],
            'answer_index' => 1,
            'explanation' => 'JOIN menggabungkan tabel.',
            'citations' => [1],
        ];
        Http::fake([self::URLS[$provider] => Http::response($this->answer($provider, (string) json_encode(['items' => [$question]])))]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz'])
            ->assertCreated()
            ->assertJsonPath('data.message.kind', 'quiz')
            ->assertJsonPath('data.message.pinned', true)
            ->assertJsonPath('data.message.data.0.answer_index', 1)
            ->assertJsonPath('data.message.citations.0.page', 4);
        $this->assertSame([$question], json_decode((string) NotebookMessage::query()->where('kind', 'quiz')->value('content'), true));
        Http::assertSent(fn (Request $request): bool => $this->sentSchemaRoot($provider, $request) === ($provider === 'gemini' ? 'OBJECT' : 'object'));
    }

    #[DataProvider('providers')]
    public function test_quiz_options_set_the_count_and_difficulty_in_the_prompt_and_the_item_count_where_the_schema_takes_it(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider, 'services.anthropic.thinking_headroom' => 0]);
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        $question = ['question' => 'Fungsi JOIN?', 'options' => ['Menghapus', 'Menggabungkan tabel', 'Mengurutkan', 'Membuat indeks'], 'answer_index' => 1, 'explanation' => 'JOIN menggabungkan tabel.', 'topic' => 'JOIN', 'citations' => [1]];
        Http::fake([self::URLS[$provider] => Http::response($this->answer($provider, (string) json_encode(['items' => array_fill(0, 5, $question)])))]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz', 'options' => ['count' => 5, 'difficulty' => 'sulit']])
            ->assertCreated()
            ->assertJsonPath('data.message.context.options', ['count' => 5, 'difficulty' => 'sulit'])
            ->assertJsonPath('data.message.data.0.topic', 'JOIN')
            ->assertJsonPath('data.ai_budget.used', 1);
        $this->assertSame(['count' => 5, 'difficulty' => 'sulit'], NotebookMessage::query()->where('kind', 'quiz')->sole()->context['options']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($provider): bool {
            $items = $this->sentSchemaItems($provider, $request);

            return str_contains($this->lastUserText($provider, $request), 'Jumlah: 5. Tingkat kesulitan: sulit.')
                && $this->sentSystem($provider, $request) === $this->systemInstruction()
                && $this->sentOutputLimit($provider, $request) === 16384
                && ($items['type'] ?? null) === ($provider === 'gemini' ? 'ARRAY' : 'array')
                // Claude's structured outputs take no item counts: there the count is in the prompt only.
                && ($items['maxItems'] ?? null) === ($provider === 'anthropic' ? null : 5)
                && ($items['minItems'] ?? null) === ($provider === 'anthropic' ? null : 5);
        });
    }

    #[DataProvider('providers')]
    public function test_a_quiz_without_options_is_asked_for_as_before(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider, 'services.anthropic.thinking_headroom' => 0]);
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        Http::fake([self::URLS[$provider] => Http::response($this->answer($provider, (string) json_encode(['items' => [['question' => 'Q?', 'options' => ['a', 'b'], 'answer_index' => 0, 'explanation' => 'E.', 'citations' => [1]]]])))]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'quiz'])
            ->assertCreated()
            ->assertJsonMissingPath('data.message.context.options');
        Http::assertSent(fn (Request $request): bool => str_ends_with($this->lastUserText($provider, $request), 'Tugas: '.((array) (new ReflectionClassConstant(NotebookAssistant::class, 'STUDIO_PROMPTS'))->getValue())['quiz'])
            && $this->sentOutputLimit($provider, $request) === 16384
            && ! array_key_exists('maxItems', $this->sentSchemaItems($provider, $request)));
    }

    #[DataProvider('providers')]
    public function test_a_rejected_key_is_reported_as_invalid_key(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider]);
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        Http::fake([self::URLS[$provider] => $this->keyError($provider)]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])
            ->assertStatus(503)
            ->assertJsonPath('message', NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY, app(NotebookAiClient::class)->label())->getMessage())
            ->assertJsonPath('data.ai_status', 'invalid_key');
        $this->getAsStudent("/api/notebooks/{$notebook->id}")->assertOk()->assertJsonPath('data.ai_status', 'invalid_key')->assertJsonPath('data.ai_available', false);
        Http::assertSentCount(1);
    }

    #[DataProvider('providers')]
    public function test_an_unknown_model_is_reported_as_model_misconfigured(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider]);
        $notebook = $this->notebookWithChunks([['page' => null, 'content' => 'Isi.']]);
        Http::fake([self::URLS[$provider] => $this->modelError($provider)]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Apa isinya?'])
            ->assertStatus(503)
            ->assertJsonPath('data.ai_status', 'model_misconfigured');
        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_status', 'model_misconfigured');

        // Remembered: the next request is answered without calling the model.
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'summary'])
            ->assertStatus(503)
            ->assertJsonPath('data.ai_status', 'model_misconfigured');
        Http::assertSentCount(1);
    }

    #[DataProvider('providers')]
    public function test_a_provider_without_its_settings_is_reported_as_missing_key(string $provider): void
    {
        $this->useProviders([
            'notebook.ai.provider' => $provider,
            'services.gemini.key' => null,
            'services.anthropic.key' => null,
            'services.openai_compatible.model' => null,
        ]);
        Http::fake();

        $this->getAsStudent('/api/notebooks')->assertOk()->assertJsonPath('data.ai_status', 'missing_key')->assertJsonPath('data.ai_available', false);
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_pasted_text_gets_a_source_guide_with_summary_topics_and_understanding_questions(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider]);
        $guide = [
            'summary' => 'Sumber ini membahas normalisasi basis data.',
            'topics' => ['Normalisasi', 'Redundansi', 'Bentuk normal'],
            'questions' => [
                'Mengapa normalisasi mengurangi redundansi?',
                'Kerjakan soal nomor 2 untuk saya.',
                'Tulis esai tentang normalisasi?',
                'Buat makalah tentang 3NF?',
                'Jawab soal nomor 2 di tugas ini?',
                'Berikan jawaban lengkap tugas 3?',
                'Susun laporan praktikum ini?',
                'Apa kunci jawaban kuis 1?',
                'Ringkas bab ini',
                'Apa beda 2NF dan 3NF?',
                'Bagaimana contoh tabel yang belum normal?',
            ],
        ];
        Http::fake([self::URLS[$provider] => Http::response($this->answer($provider, (string) json_encode($guide)))]);
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Catatan', 'content' => 'Normalisasi basis data mengurangi redundansi.'])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.summary', 'Sumber ini membahas normalisasi basis data.')
            ->assertJsonPath('data.sources.0.topics', ['Normalisasi', 'Redundansi', 'Bentuk normal'])
            // Do-my-task questions (suffixed or plain imperatives) and a line that is no question are dropped, whatever the prompt said.
            ->assertJsonPath('data.sources.0.suggested_questions', [
                'Mengapa normalisasi mengurangi redundansi?',
                'Apa beda 2NF dan 3NF?',
                'Bagaimana contoh tabel yang belum normal?',
            ]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $this->sentSchemaRoot($provider, $request) === ($provider === 'gemini' ? 'OBJECT' : 'object')
            && str_contains($this->lastUserText($provider, $request), 'pertanyaan pemahaman')
            && str_contains($this->lastUserText($provider, $request), 'jangan memakai kata seperti "kerjakan"'));

        $this->getAsStudent("/api/notebooks/{$notebook->id}")
            ->assertOk()
            ->assertJsonPath('data.suggestions', [
                'Mengapa normalisasi mengurangi redundansi?',
                'Apa beda 2NF dan 3NF?',
                'Bagaimana contoh tabel yang belum normal?',
            ]);
    }

    #[DataProvider('providers')]
    public function test_a_guide_the_provider_refuses_falls_back_to_a_plain_summary(string $provider): void
    {
        $this->useProviders(['notebook.ai.provider' => $provider]);
        Http::fake([self::URLS[$provider] => Http::sequence()
            ->push(['error' => ['message' => 'Invalid schema for response_format.']], 400)
            ->push($this->answer($provider, "Sumber ini membahas normalisasi.\nTopik: Normalisasi, Redundansi, Bentuk normal"))]);
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Catatan', 'content' => 'Normalisasi basis data.'])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.summary', 'Sumber ini membahas normalisasi.')
            ->assertJsonPath('data.sources.0.topics', ['Normalisasi', 'Redundansi', 'Bentuk normal'])
            ->assertJsonPath('data.sources.0.suggested_questions', []);

        Http::assertSentCount(2);
        $this->assertSame(NotebookAssistant::STARTER_QUESTIONS, $this->getAsStudent("/api/notebooks/{$notebook->id}")->json('data.suggestions'));
    }

    public function test_a_model_without_structured_output_keeps_the_plain_summary_with_topics_from_its_topik_line(): void
    {
        $this->useProviders(['notebook.ai.provider' => 'openai', 'services.openai_compatible.structured_output' => 'none']);
        Http::fake([self::URLS['openai'] => Http::response($this->answer('openai', "Sumber ini membahas normalisasi.\nTopik: Normalisasi, Redundansi, Normalisasi"))]);
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'text', 'title' => 'Catatan', 'content' => 'Normalisasi basis data.'])
            ->assertCreated()
            ->assertJsonPath('data.sources.0.summary', 'Sumber ini membahas normalisasi.')
            ->assertJsonPath('data.sources.0.topics', ['Normalisasi', 'Redundansi'])
            ->assertJsonPath('data.sources.0.suggested_questions', []);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => ! isset($request['response_format'])
            && str_contains($this->lastUserText('openai', $request), 'Topik: '));
    }

    public function test_json_wrapped_in_prose_is_read_only_from_a_model_without_native_schemas(): void
    {
        $this->useProviders(['notebook.ai.provider' => 'openai', 'services.openai_compatible.structured_output' => 'json_object']);
        $notebook = $this->notebookWithChunks([['page' => 4, 'content' => 'SQL JOIN menggabungkan tabel.']]);
        $map = ['title' => 'SQL', 'children' => [['title' => 'JOIN', 'children' => []]]];
        Http::fake([self::URLS['openai'] => Http::sequence()
            ->push($this->answer('openai', "Berikut peta pikirannya:\n```json\n".json_encode($map)."\n```"))
            // A list where a mind map belongs is no mind map.
            ->push($this->answer('openai', 'Cabang: ["JOIN", "WHERE"]'))]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'mind_map'])
            ->assertCreated()
            ->assertJsonPath('data.message.data.title', 'SQL');

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'mind_map'])
            ->assertStatus(502)
            ->assertJsonPath('message', NotebookAiException::emptyAnswer()->getMessage());
        $this->assertSame(1, NotebookMessage::query()->count());
    }

    public function test_an_answer_claude_declines_halfway_is_not_saved_as_a_whole_answer(): void
    {
        $this->useProviders(['notebook.ai.provider' => 'anthropic']);
        $notebook = $this->notebookWithChunks([['page' => 1, 'content' => 'Isi modul farmakologi.']]);
        Http::fake([self::URLS['anthropic'] => Http::response([
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Setengah jawaban sebelum']],
            'stop_reason' => 'refusal',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 5],
        ])]);

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/messages", ['question' => 'Jelaskan dosisnya.'])
            ->assertStatus(422)
            ->assertJsonPath('message', NotebookAiException::blocked()->getMessage());
        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/studio", ['kind' => 'summary'])
            ->assertStatus(422);

        $this->assertSame(0, NotebookMessage::query()->count());
    }

    public function test_an_image_over_claudes_limit_is_refused_with_its_size_before_anything_is_sent(): void
    {
        $this->useProviders(['notebook.ai.provider' => 'anthropic']);
        Http::fake();
        $notebook = $this->notebook();
        $message = 'Gambar ini terlalu besar untuk dibaca model AI (maks. 3,75 MB). Perkecil atau kompres gambarnya, lalu unggah lagi.';

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => UploadedFile::fake()->createWithContent('catatan.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('x', 6 * 1024 * 1024))])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', $message);

        $this->assertSame(0, NotebookSource::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_scanned_pdf_or_an_image_is_refused_when_the_reading_model_cannot_read_it(): void
    {
        $this->useProviders(['notebook.ai.provider' => 'openai']);
        Http::fake();
        $notebook = $this->notebook();

        foreach ([$this->fixtureUpload('scanned.pdf', 'pindaian.pdf'), UploadedFile::fake()->image('papan-tulis.png')] as $file) {
            $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => $file])
                ->assertStatus(422)
                ->assertJsonPath('message', self::UNSUPPORTED)
                ->assertJsonPath('errors.file.0', self::UNSUPPORTED);
        }

        $this->assertSame(0, NotebookSource::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_born_digital_pdf_is_read_locally_when_the_reading_model_cannot_read_pdfs(): void
    {
        $this->useProviders(['notebook.ai.provider' => 'openai']);
        Http::fake([self::URLS['openai'] => Http::response($this->answer('openai', 'Ringkasan normalisasi.'))]);
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => $this->fixtureUpload('born-digital.pdf', 'modul.pdf')])
            ->assertCreated();

        $source = NotebookSource::query()->sole();
        $this->assertSame(NotebookSource::STATUS_READY, $source->status);
        $this->assertSame(2, $source->page_count);
        $this->assertSame([1, 2], NotebookChunk::query()->orderBy('position')->pluck('page')->all());
        // Only the source summary reached the model; the PDF itself never did.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => is_string($request['messages'][0]['content'] ?? null));
    }

    public function test_a_scanned_course_file_fails_with_the_same_message_when_the_reading_model_cannot_read_it(): void
    {
        $this->useProviders(['notebook.ai.provider' => 'openai']);
        Http::fake();
        $notebook = $this->notebook();
        $file = PortalArchivedFile::factory()->forOwner($this->ownerHash)->create();
        Storage::disk('local')->put($file->blobPath(), $this->fixture('scanned.pdf'));

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'archived_file', 'archived_file_ids' => [$file->id]])
            ->assertCreated();

        $source = NotebookSource::query()->sole();
        $this->assertSame(NotebookSource::STATUS_FAILED, $source->status);
        $this->assertSame(self::UNSUPPORTED, $source->error);
        Http::assertNothingSent();
    }

    public function test_an_ingest_provider_reads_scanned_pdfs_with_gemini_while_another_provider_answers(): void
    {
        $this->useProviders(['notebook.ai.provider' => 'openai', 'notebook.ai.ingest_provider' => 'gemini']);
        Http::fake([
            self::URLS['gemini'] => Http::response($this->answer('gemini', "[[HALAMAN 1]]\nHasil pindaian modul.")),
            self::URLS['openai'] => Http::response($this->answer('openai', 'Ringkasan.')),
        ]);
        $notebook = $this->notebook();

        $this->sendAsStudent('POST', "/api/notebooks/{$notebook->id}/sources", ['type' => 'upload', 'file' => $this->fixtureUpload('scanned.pdf', 'pindaian.pdf')])
            ->assertCreated();

        $this->assertSame(NotebookSource::STATUS_READY, NotebookSource::query()->sole()->status);
        $this->assertSame('Hasil pindaian modul.', NotebookChunk::query()->sole()->content);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/')
            && ($request['contents'][0]['parts'][0]['inlineData']['mimeType'] ?? null) === 'application/pdf');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::URLS['openai']);
    }

    /**
     * A successful answer in the provider's own shape.
     *
     * @return array<string, mixed>
     */
    private function answer(string $provider, string $text): array
    {
        return match ($provider) {
            'gemini' => ['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']]],
            'anthropic' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 20, 'output_tokens' => 10]],
            default => ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
        };
    }

    private function keyError(string $provider): \GuzzleHttp\Promise\PromiseInterface
    {
        return match ($provider) {
            'gemini' => Http::response(['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400),
            'anthropic' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
            default => Http::response(['error' => ['message' => 'Incorrect API key provided.', 'code' => 'invalid_api_key']], 401),
        };
    }

    private function modelError(string $provider): \GuzzleHttp\Promise\PromiseInterface
    {
        return match ($provider) {
            'gemini' => Http::response(['error' => ['code' => 404, 'message' => 'models/gemini-test-flash is not found for API version v1beta.', 'status' => 'NOT_FOUND']], 404),
            'anthropic' => Http::response(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: claude-opus-5-5']], 404),
            default => Http::response(['error' => ['message' => 'The model `llama-test` does not exist.', 'code' => 'model_not_found']], 404),
        };
    }

    /**
     * The text of the last user turn as the provider received it.
     */
    private function lastUserText(string $provider, Request $request): string
    {
        $text = match ($provider) {
            'gemini' => collect($request['contents'])->last()['parts'][0]['text'] ?? '',
            'anthropic' => collect($request['messages'])->last()['content'][0]['text'] ?? '',
            default => collect($request['messages'])->last()['content'] ?? '',
        };

        return is_string($text) ? $text : '';
    }

    /**
     * The system instruction as the provider received it: Gemini's
     * systemInstruction, Anthropic's top-level system, or the OpenAI
     * system message.
     */
    private function sentSystem(string $provider, Request $request): ?string
    {
        $system = match ($provider) {
            'gemini' => $request['systemInstruction']['parts'][0]['text'] ?? null,
            'anthropic' => $request['system'] ?? null,
            default => collect($request['messages'])->firstWhere('role', 'system')['content'] ?? null,
        };

        return is_string($system) ? $system : null;
    }

    private function sentOutputLimit(string $provider, Request $request): ?int
    {
        $limit = match ($provider) {
            'gemini' => $request['generationConfig']['maxOutputTokens'] ?? null,
            default => $request['max_tokens'] ?? null,
        };

        return is_int($limit) ? $limit : null;
    }

    private function systemInstruction(): string
    {
        return $this->assistantConstant('SYSTEM_INSTRUCTION');
    }

    private function assistantConstant(string $name): string
    {
        return (string) (new ReflectionClassConstant(NotebookAssistant::class, $name))->getValue();
    }

    /**
     * The type of the root of the JSON schema the provider was sent.
     */
    private function sentSchemaRoot(string $provider, Request $request): mixed
    {
        return match ($provider) {
            'gemini' => $request['generationConfig']['responseSchema']['type'] ?? null,
            'anthropic' => $request['output_config']['format']['schema']['type'] ?? null,
            default => $request['response_format']['json_schema']['schema']['type'] ?? null,
        };
    }

    /**
     * The `items` array of the list schema the provider was sent.
     *
     * @return array<string, mixed>
     */
    private function sentSchemaItems(string $provider, Request $request): array
    {
        $items = match ($provider) {
            'gemini' => $request['generationConfig']['responseSchema']['properties']['items'] ?? null,
            'anthropic' => $request['output_config']['format']['schema']['properties']['items'] ?? null,
            default => $request['response_format']['json_schema']['schema']['properties']['items'] ?? null,
        };

        return is_array($items) ? $items : [];
    }

    /**
     * Switch the provider(s), and keep the student's consent for them: a
     * consent given for another provider no longer holds.
     *
     * @param  array<string, mixed>  $settings
     */
    private function useProviders(array $settings): void
    {
        config($settings);
        NotebookAiConsent::query()->update(['provider' => app(NotebookAiProviders::class)->consentKey()]);
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

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/notebook/'.$name));
    }

    private function fixtureUpload(string $fixture, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'notebook-matrix-');
        file_put_contents($path, $this->fixture($fixture));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function getAsStudent(string $uri): TestResponse
    {
        return $this->withSession(['campus_session_token' => self::TOKEN])->getJson($uri);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendAsStudent(string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->withSession(['_token' => 'known-csrf-token', 'campus_session_token' => self::TOKEN])
            ->json($method, $uri, $payload, ['X-CSRF-TOKEN' => 'known-csrf-token']);
    }
}
