<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Exceptions\NotebookAiException;
use App\Services\Ai\AnthropicDriver;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicDriverTest extends TestCase
{
    private const MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-opus-5-5',
            'services.anthropic.fallback_model' => 'claude-sonnet-5-5',
            'services.anthropic.base_url' => 'https://api.anthropic.com',
            'services.anthropic.version' => '2026-10-21',
            'services.anthropic.effort' => null,
            'services.anthropic.timeout' => 60,
            'services.anthropic.retry_delay_ms' => 0,
            'services.anthropic.max_output_tokens' => 128000,
            // Exact limits in the payload tests; the headroom has its own test.
            'services.anthropic.thinking_headroom' => 0,
            'notebook.ai.request_budget' => 50,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_the_defaults_name_claude_opus_5_5_and_api_version_2026_10_21(): void
    {
        $this->refreshApplication();

        $this->assertSame('claude-opus-5-5', config('services.anthropic.model'));
        $this->assertSame('2026-10-21', config('services.anthropic.version'));
        $this->assertSame('https://api.anthropic.com', config('services.anthropic.base_url'));
        $this->assertNull(config('services.anthropic.effort'));
    }

    public function test_a_request_becomes_a_messages_api_request(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->answer('Jawaban.'))]);

        app(AnthropicDriver::class)->generate(new AiRequest(
            messages: [
                AiRequest::assistant('Sapaan yang tidak punya pertanyaan.'),
                AiRequest::user([AiRequest::text('Apa itu 3NF?')]),
                AiRequest::assistant('Bentuk normal ketiga [1].'),
                AiRequest::user([
                    AiRequest::file('application/pdf', 'UERG'),
                    AiRequest::file('image/png', 'iVBO'),
                    AiRequest::text('Salin dokumen ini.'),
                ]),
            ],
            system: 'Kamu asisten belajar.',
            maxOutputTokens: 4096,
            temperature: 0.3,
        ));

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(self::MESSAGES_URL, $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', 'test-anthropic-key'));
            $this->assertTrue($request->hasHeader('anthropic-version', '2026-10-21'));
            $this->assertTrue($request->hasHeader('content-type', 'application/json'));
            $this->assertSame([
                'model' => 'claude-opus-5-5',
                'max_tokens' => 4096,
                'system' => 'Kamu asisten belajar.',
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Apa itu 3NF?']]],
                    ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Bentuk normal ketiga [1].']]],
                    ['role' => 'user', 'content' => [
                        ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'UERG']],
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'iVBO']],
                        ['type' => 'text', 'text' => 'Salin dokumen ini.'],
                    ]],
                ],
            ], $request->data());

            foreach (['temperature', 'top_p', 'top_k', 'thinking', 'output_config'] as $refused) {
                $this->assertArrayNotHasKey($refused, $request->data());
            }

            return true;
        });
    }

    public function test_a_schema_goes_as_output_config_format_and_effort_only_when_configured(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->answer('{"items":[]}'))]);
        $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['items'], 'additionalProperties' => false];

        app(AnthropicDriver::class)->generate(new AiRequest(messages: [AiRequest::user([AiRequest::text('Buat kuis.')])], jsonSchema: $schema));
        config(['services.anthropic.effort' => 'low']);
        app(AnthropicDriver::class)->generate(new AiRequest(messages: [AiRequest::user([AiRequest::text('Halo')])]));

        $sent = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();

        $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema]], $sent[0]['output_config']);
        // No limit asked for: the API still needs one.
        $this->assertSame(8192, $sent[0]['max_tokens']);
        $this->assertSame(['effort' => 'low'], $sent[1]['output_config']);
    }

    public function test_a_conversation_never_ends_on_an_assistant_turn_and_turns_of_one_role_are_joined(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->answer('Ya.'))]);

        app(AnthropicDriver::class)->generate(new AiRequest(messages: [
            AiRequest::user([AiRequest::text('Satu.')]),
            AiRequest::user([AiRequest::text('Dua.'), AiRequest::text('  ')]),
            AiRequest::assistant('Awalan jawaban'),
        ]));

        Http::assertSent(fn (Request $request): bool => $request['messages'] === [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Satu.'], ['type' => 'text', 'text' => 'Dua.']]],
        ]);
    }

    public function test_thinking_blocks_are_left_out_and_usage_is_read(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Menimbang jawabannya.', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'Bagian satu. '],
                ['type' => 'text', 'text' => 'Bagian dua.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
        ])]);

        $result = app(AnthropicDriver::class)->generate($this->request());

        $this->assertSame('Bagian satu. Bagian dua.', $result->text);
        $this->assertSame([AiResult::STOP_END, 'end_turn', false, 120, 45, 'claude-opus-5-5'], [$result->stop, $result->finishReason, $result->truncated, $result->inputTokens, $result->outputTokens, $result->model]);
    }

    public function test_a_refusal_is_blocked(): void
    {
        Http::fake([self::MESSAGES_URL => Http::sequence()
            ->push(['content' => [], 'stop_reason' => 'refusal', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]])
            ->push($this->answer('Bab 1 sampai sini', 'refusal'))]);

        $exception = $this->failure();
        $partial = $this->failure();

        $this->assertTrue($exception->is(NotebookAiException::REASON_BLOCKED));
        $this->assertSame(NotebookAiException::blocked()->getMessage(), $exception->getMessage());
        // An answer declined halfway does not pass for a whole one.
        $this->assertTrue($partial->is(NotebookAiException::REASON_BLOCKED));
        Http::assertSentCount(2);
    }

    public function test_a_transcription_keeps_the_text_written_before_a_refusal(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->answer('Bab 1 sampai sini', 'refusal'))]);

        $partial = app(AnthropicDriver::class)->generate(new AiRequest(
            messages: [AiRequest::user([AiRequest::text('Salin dokumen ini.')])],
            keepBlockedText: true,
        ));

        $this->assertSame([AiResult::STOP_BLOCKED, 'Bab 1 sampai sini', false], [$partial->stop, $partial->text, $partial->truncated]);
    }

    public function test_every_output_limit_gets_room_for_thinking_within_the_models_ceiling(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->answer('Ringkasan.'))]);
        config(['services.anthropic.thinking_headroom' => null]);

        app(AnthropicDriver::class)->generate($this->request(maxOutputTokens: 2048));
        config(['services.anthropic.max_output_tokens' => 64000]);
        app(AnthropicDriver::class)->generate($this->request(maxOutputTokens: 65536));

        $limits = Http::recorded()->map(fn (array $pair): int => $pair[0]['max_tokens'])->all();

        $this->assertSame([2048 + 8192, 64000], $limits);
        $this->assertSame(64000, app(AnthropicDriver::class)->capabilities()->maxOutputTokens);
    }

    public function test_a_max_tokens_over_the_models_ceiling_is_a_model_problem(): void
    {
        config(['services.anthropic.fallback_model' => null]);
        Http::fake([self::MESSAGES_URL => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens: 73728 > 64000, which is the maximum allowed number of output tokens for claude-haiku-4-5']], 400)]);

        $this->assertSame(NotebookAiException::CAUSE_MODEL, $this->failureCause());
        $this->assertContains('ANTHROPIC_MAX_OUTPUT_TOKENS', app(AnthropicDriver::class)->settingNames(NotebookAiException::CAUSE_MODEL));
    }

    public function test_an_image_over_the_apis_limit_is_refused_before_anything_is_sent(): void
    {
        Http::fake();
        $capabilities = app(AnthropicDriver::class)->capabilities();

        try {
            app(AnthropicDriver::class)->generate(new AiRequest(messages: [AiRequest::user([
                AiRequest::file('image/jpeg', base64_encode(str_repeat('x', $capabilities->maxBytesFor('image/jpeg') + 1))),
            ])]));
            $this->fail('The request was expected to fail.');
        } catch (NotebookAiException $exception) {
            $this->assertTrue($exception->is(NotebookAiException::REASON_REJECTED));
        }

        // 5 MB of base64; PDFs keep the request's own limit.
        $this->assertSame(intdiv(5 * 1024 * 1024 * 3, 4), $capabilities->maxBytesFor('image/png'));
        $this->assertSame(19 * 1024 * 1024, $capabilities->maxBytesFor('application/pdf'));
        Http::assertNothingSent();
    }

    public function test_an_answer_cut_at_max_tokens_is_asked_for_once_more_and_the_cut_answer_kept_when_the_retry_writes_less(): void
    {
        $limits = [];
        $answers = [$this->answer('Jawaban panjang yang terpotong', 'max_tokens'), $this->answer('Lebih pendek', 'max_tokens')];
        Http::fake([self::MESSAGES_URL => function (Request $request) use (&$limits, &$answers) {
            $limits[] = $request['max_tokens'];

            return Http::response(array_shift($answers));
        }]);

        $result = app(AnthropicDriver::class)->generate($this->request(maxOutputTokens: 1000));

        $this->assertSame([1000, 2000], $limits);
        $this->assertSame('Jawaban panjang yang terpotong', $result->text);
        $this->assertSame([true, AiResult::STOP_LENGTH], [$result->truncated, $result->stop]);
    }

    public function test_a_cut_answer_completed_by_the_larger_limit_replaces_it(): void
    {
        Http::fake([self::MESSAGES_URL => Http::sequence()
            ->push($this->answer('Awal', 'max_tokens'))
            ->push($this->answer('Awal dan akhir.'))]);

        $result = app(AnthropicDriver::class)->generate($this->request(maxOutputTokens: 1000));

        $this->assertSame(['Awal dan akhir.', false], [$result->text, $result->truncated]);
    }

    public function test_a_wrong_key_is_a_key_problem_remembered_and_not_tried_on_the_fallback(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)]);

        $exception = $this->failure();

        $this->assertSame(NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY, 'Anthropic Claude')->getMessage(), $exception->getMessage());
        $this->assertSame(NotebookAiException::CAUSE_KEY, app(AnthropicDriver::class)->misconfiguration());
        $this->assertFalse(app(AnthropicDriver::class)->isUsable());

        // Remembered: the next request does not reach the API.
        $this->failure();
        Http::assertSentCount(1);

        config(['services.anthropic.key' => 'another-test-key']);
        $this->assertTrue(app(AnthropicDriver::class)->isUsable());
    }

    public function test_a_permission_error_is_a_key_problem_too(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response(['type' => 'error', 'error' => ['type' => 'permission_error', 'message' => 'not allowed']], 403)]);

        $this->assertSame(NotebookAiException::CAUSE_KEY, $this->failureCause());
    }

    public function test_an_unknown_model_falls_back_to_the_other_model(): void
    {
        Http::fake([self::MESSAGES_URL => function (Request $request) {
            return $request['model'] === 'claude-opus-5-5'
                ? Http::response(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: claude-opus-5-5']], 404)
                : Http::response($this->answer('Dari model cadangan.'));
        }]);

        $result = app(AnthropicDriver::class)->generate($this->request());

        $this->assertSame(['Dari model cadangan.', 'claude-sonnet-5-5'], [$result->text, $result->model]);
        $this->assertNull(app(AnthropicDriver::class)->misconfiguration());
    }

    public function test_models_that_are_all_unknown_are_a_model_problem_remembered_for_the_settings(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: claude-x']], 404)]);

        $this->assertSame(NotebookAiException::CAUSE_MODEL, $this->failureCause());
        Http::assertSentCount(2);
        $this->assertSame(NotebookAiException::CAUSE_MODEL, app(AnthropicDriver::class)->misconfiguration());

        config(['services.anthropic.model' => 'claude-haiku-5-5']);
        $this->assertNull(app(AnthropicDriver::class)->misconfiguration());
    }

    public function test_a_bad_request_about_the_model_or_effort_is_a_model_problem_and_any_other_is_rejected(): void
    {
        config(['services.anthropic.fallback_model' => null]);
        Http::fake([self::MESSAGES_URL => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'output_config.effort: "max" is not supported by this model']], 400)
            ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'messages: roles must alternate between "user" and "assistant"']], 400)]);

        $this->assertSame(NotebookAiException::CAUSE_MODEL, $this->failureCause());
        Cache::flush();
        $rejected = $this->failure();

        $this->assertTrue($rejected->is(NotebookAiException::REASON_REJECTED));
        Http::assertSentCount(2);
    }

    public function test_an_overloaded_api_is_tried_once_more_within_the_budget(): void
    {
        config(['services.anthropic.fallback_model' => null]);
        Http::fake([self::MESSAGES_URL => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
            ->push($this->answer('Setelah ramai.'))]);

        $this->assertSame('Setelah ramai.', app(AnthropicDriver::class)->generate($this->request())->text);
        Http::assertSentCount(2);
    }

    public function test_an_api_still_overloaded_is_busy_not_misconfigured(): void
    {
        config(['services.anthropic.fallback_model' => null]);
        Http::fake([self::MESSAGES_URL => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)]);

        $exception = $this->failure();

        $this->assertSame(NotebookAiException::unavailable()->getMessage(), $exception->getMessage());
        $this->assertFalse($exception->concernsConfiguration());
        Http::assertSentCount(2);
    }

    public function test_it_reads_pdfs_and_images_and_follows_a_schema(): void
    {
        $capabilities = app(AnthropicDriver::class)->capabilities();

        $this->assertTrue($capabilities->pdfInput);
        $this->assertTrue($capabilities->imageInput);
        $this->assertSame('json_schema', $capabilities->structuredOutput);
        $this->assertSame(['anthropic', 'Anthropic Claude'], [app(AnthropicDriver::class)->driver(), app(AnthropicDriver::class)->label()]);
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['services.anthropic.key' => '']);
        Http::fake();

        $this->assertFalse(app(AnthropicDriver::class)->isConfigured());
        $this->assertTrue($this->failure()->is(NotebookAiException::REASON_NOT_CONFIGURED));
        Http::assertNothingSent();
    }

    private function failureCause(): string
    {
        $exception = $this->failure();

        $this->assertTrue($exception->is(NotebookAiException::REASON_MISCONFIGURED));

        return $exception->getMessage() === NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY, 'Anthropic Claude')->getMessage()
            ? NotebookAiException::CAUSE_KEY
            : NotebookAiException::CAUSE_MODEL;
    }

    private function failure(): NotebookAiException
    {
        try {
            app(AnthropicDriver::class)->generate($this->request());
        } catch (NotebookAiException $exception) {
            return $exception;
        }

        $this->fail('The request was expected to fail.');
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $text, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    private function request(?int $maxOutputTokens = null): AiRequest
    {
        return new AiRequest(messages: [AiRequest::user([AiRequest::text('Halo')])], maxOutputTokens: $maxOutputTokens);
    }
}
