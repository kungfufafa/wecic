<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Exceptions\NotebookAiException;
use App\Services\Ai\OpenAiCompatibleDriver;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiCompatibleDriverTest extends TestCase
{
    private const LOCAL_URL = 'http://llm.test/v1/chat/completions';

    private const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';

    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'required' => ['items'],
        'additionalProperties' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai_compatible.key' => null,
            'services.openai_compatible.base_url' => 'http://llm.test/v1',
            'services.openai_compatible.model' => 'llama-test',
            'services.openai_compatible.fallback_model' => null,
            'services.openai_compatible.label' => 'Server AI Kampus',
            'services.openai_compatible.image_input' => false,
            'services.openai_compatible.pdf_input' => false,
            'services.openai_compatible.structured_output' => 'json_schema',
            'services.openai_compatible.token_param' => 'max_tokens',
            'services.openai_compatible.max_output_tokens' => 4096,
            'services.openai_compatible.send_temperature' => true,
            'services.openai_compatible.timeout' => 60,
            'services.openai_compatible.retry_delay_ms' => 0,
            'notebook.ai.request_budget' => 50,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_a_local_server_needs_no_key_and_gets_no_authorization_header(): void
    {
        Http::fake([self::LOCAL_URL => Http::response($this->answer('Jawaban.'))]);

        $result = app(OpenAiCompatibleDriver::class)->generate(new AiRequest(
            messages: [
                AiRequest::user([AiRequest::text('Apa itu 3NF?')]),
                AiRequest::assistant('Bentuk normal ketiga.'),
                AiRequest::user([AiRequest::text('Contohnya?')]),
            ],
            system: 'Kamu asisten belajar.',
            maxOutputTokens: 2048,
            temperature: 0.3,
        ));

        $this->assertSame('Jawaban.', $result->text);
        Http::assertSent(function (Request $request): bool {
            $this->assertFalse($request->hasHeader('Authorization'));
            $this->assertSame([
                'model' => 'llama-test',
                'messages' => [
                    ['role' => 'system', 'content' => 'Kamu asisten belajar.'],
                    ['role' => 'user', 'content' => 'Apa itu 3NF?'],
                    ['role' => 'assistant', 'content' => 'Bentuk normal ketiga.'],
                    ['role' => 'user', 'content' => 'Contohnya?'],
                ],
                'max_tokens' => 2048,
                'temperature' => 0.3,
            ], $request->data());

            return true;
        });
    }

    public function test_a_key_is_sent_as_a_bearer_token_and_a_remote_server_needs_one(): void
    {
        config(['services.openai_compatible.base_url' => 'https://api.openai.com/v1', 'services.openai_compatible.token_param' => 'max_completion_tokens']);

        $this->assertFalse(app(OpenAiCompatibleDriver::class)->isConfigured());

        config(['services.openai_compatible.key' => 'test-openai-key']);
        Http::fake([self::OPENAI_URL => Http::response($this->answer('Halo.'))]);

        app(OpenAiCompatibleDriver::class)->generate($this->request(maxOutputTokens: 1000));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-openai-key')
            && ($request['max_completion_tokens'] ?? null) === 1000
            && ! isset($request['max_tokens']));
    }

    public function test_without_a_model_it_is_not_configured(): void
    {
        config(['services.openai_compatible.model' => null]);

        $this->assertFalse(app(OpenAiCompatibleDriver::class)->isConfigured());
    }

    /**
     * @return array<string, array{string, array<string, mixed>|null, bool}>
     */
    public static function structuredOutputModes(): array
    {
        return [
            'json_schema' => ['json_schema', ['type' => 'json_schema', 'json_schema' => ['name' => 'notebook_output', 'strict' => true, 'schema' => self::SCHEMA]], false],
            'json_object' => ['json_object', ['type' => 'json_object'], true],
            'none' => ['none', null, true],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $responseFormat
     */
    #[DataProvider('structuredOutputModes')]
    public function test_a_schema_is_sent_the_way_the_server_takes_it(string $mode, ?array $responseFormat, bool $schemaInPrompt): void
    {
        config(['services.openai_compatible.structured_output' => $mode]);
        Http::fake([self::LOCAL_URL => Http::response($this->answer('{"items":[]}'))]);

        app(OpenAiCompatibleDriver::class)->generate(new AiRequest(messages: [AiRequest::user([AiRequest::text('Buat kuis.')])], system: 'Sistem.', jsonSchema: self::SCHEMA));

        Http::assertSent(function (Request $request) use ($responseFormat, $schemaInPrompt): bool {
            $this->assertSame($responseFormat, $request['response_format'] ?? null);
            $this->assertSame($schemaInPrompt, str_contains($request['messages'][0]['content'], '"additionalProperties":false'));
            $this->assertStringStartsWith('Sistem.', $request['messages'][0]['content']);

            return true;
        });
    }

    public function test_the_output_limit_is_capped_at_the_models_largest_and_temperature_can_be_left_out(): void
    {
        config(['services.openai_compatible.send_temperature' => false]);
        Http::fake([self::LOCAL_URL => Http::response($this->answer('Ok.'))]);

        app(OpenAiCompatibleDriver::class)->generate(new AiRequest(messages: [AiRequest::user([AiRequest::text('Halo')])], maxOutputTokens: 65536, temperature: 0));

        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === 4096 && ! array_key_exists('temperature', $request->data()));
    }

    public function test_length_means_truncated_and_is_asked_for_once_more_up_to_the_largest_limit(): void
    {
        $limits = [];
        Http::fake([self::LOCAL_URL => function (Request $request) use (&$limits) {
            $limits[] = $request['max_tokens'];

            return Http::response($this->answer('Terpotong', 'length', reasoningTokens: 7));
        }]);

        $result = app(OpenAiCompatibleDriver::class)->generate($this->request(maxOutputTokens: 3000));

        $this->assertSame([3000, 4096], $limits);
        $this->assertSame([true, AiResult::STOP_LENGTH, 'length', 7], [$result->truncated, $result->stop, $result->finishReason, $result->thoughtsTokens]);
    }

    public function test_a_refusal_or_content_filter_is_blocked(): void
    {
        Http::fake([self::LOCAL_URL => Http::sequence()
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'Maaf, tidak bisa.'], 'finish_reason' => 'stop']]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => 'content_filter']]])
            ->push($this->answer('Sebagian', 'content_filter'))
            ->push($this->answer('Sebagian', 'content_filter'))]);

        $this->assertTrue($this->failure()->is(NotebookAiException::REASON_BLOCKED));
        $this->assertTrue($this->failure()->is(NotebookAiException::REASON_BLOCKED));
        // Text cut by the filter is no whole answer, unless a transcription keeps it.
        $this->assertTrue($this->failure()->is(NotebookAiException::REASON_BLOCKED));
        $kept = app(OpenAiCompatibleDriver::class)->generate(new AiRequest(messages: [AiRequest::user([AiRequest::text('Salin.')])], keepBlockedText: true));
        $this->assertSame([AiResult::STOP_BLOCKED, 'Sebagian'], [$kept->stop, $kept->text]);
    }

    public function test_an_image_or_pdf_the_model_cannot_read_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        foreach (['image/png', 'application/pdf'] as $mime) {
            try {
                app(OpenAiCompatibleDriver::class)->generate(new AiRequest(messages: [AiRequest::user([AiRequest::file($mime, 'QUJD'), AiRequest::text('Salin.')])]));
                $this->fail('The request was expected to fail.');
            } catch (NotebookAiException $exception) {
                $this->assertTrue($exception->is(NotebookAiException::REASON_UNSUPPORTED_INPUT));
                $this->assertSame(422, $exception->getCode());
                $this->assertSame(NotebookAiException::UNSUPPORTED_INPUT_MESSAGE, $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_images_and_pdfs_go_as_content_parts_when_the_model_reads_them(): void
    {
        config(['services.openai_compatible.image_input' => true, 'services.openai_compatible.pdf_input' => true]);
        Http::fake([self::LOCAL_URL => Http::response($this->answer('Isi.'))]);

        app(OpenAiCompatibleDriver::class)->generate(new AiRequest(messages: [AiRequest::user([
            AiRequest::file('image/png', 'iVBO'),
            AiRequest::file('application/pdf', 'UERG'),
            AiRequest::text('Salin.'),
        ])]));

        Http::assertSent(fn (Request $request): bool => $request['messages'] === [['role' => 'user', 'content' => [
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBO']],
            ['type' => 'file', 'file' => ['filename' => 'dokumen.pdf', 'file_data' => 'data:application/pdf;base64,UERG']],
            ['type' => 'text', 'text' => 'Salin.'],
        ]]]);
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string}>
     */
    public static function configurationErrors(): array
    {
        return [
            'unauthorized' => [401, ['error' => ['message' => 'Incorrect API key provided', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], NotebookAiException::CAUSE_KEY],
            'forbidden' => [403, ['error' => ['message' => 'Project does not have access']], NotebookAiException::CAUSE_KEY],
            'not found' => [404, ['error' => ['message' => 'The model `llama-test` does not exist', 'code' => 'model_not_found']], NotebookAiException::CAUSE_MODEL],
            'model_not_found 400' => [400, ['error' => ['message' => 'unknown', 'code' => 'model_not_found']], NotebookAiException::CAUSE_MODEL],
            'model in a 400' => [400, ['error' => 'model "llama-test" not found, try pulling it first'], NotebookAiException::CAUSE_MODEL],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('configurationErrors')]
    public function test_errors_about_the_settings_say_which_setting(int $status, array $body, string $cause): void
    {
        Http::fake([self::LOCAL_URL => Http::response($body, $status)]);

        $exception = $this->failure();

        $this->assertSame(NotebookAiException::misconfigured($cause, 'Server AI Kampus')->getMessage(), $exception->getMessage());
        $this->assertSame($cause, app(OpenAiCompatibleDriver::class)->misconfiguration());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function requestErrors(): array
    {
        return [
            'context length' => [['error' => ['message' => "This model's maximum context length is 32768 tokens. However, you requested 40000 tokens.", 'type' => 'invalid_request_error', 'code' => 'context_length_exceeded']]],
            'context length without a code (vLLM)' => [['error' => ['message' => "This model's maximum context length is 32768 tokens. However, you requested 40000 tokens (23616 in the messages, 16384 in the completion).", 'type' => 'BadRequestError']]],
            'temperature' => [['error' => ['message' => "Unsupported value: 'temperature' does not support 0.3 with this model. Only the default (1) value is supported.", 'code' => 'unsupported_value']]],
            'max_tokens' => [['error' => ['message' => "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead."]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('requestErrors')]
    public function test_a_400_about_the_request_fails_it_alone_even_when_it_names_the_model(array $body): void
    {
        config(['services.openai_compatible.fallback_model' => 'llama-test-small']);
        Http::fake([self::LOCAL_URL => Http::response($body, 400)]);

        $exception = $this->failure();

        $this->assertTrue($exception->is(NotebookAiException::REASON_REJECTED));
        $this->assertNull(app(OpenAiCompatibleDriver::class)->misconfiguration());
        $this->assertTrue(app(OpenAiCompatibleDriver::class)->isUsable());
        Http::assertSentCount(1);
    }

    public function test_an_unknown_model_falls_back_to_the_other_model(): void
    {
        config(['services.openai_compatible.fallback_model' => 'llama-test-small']);
        Http::fake([self::LOCAL_URL => fn (Request $request) => $request['model'] === 'llama-test'
            ? Http::response(['error' => ['message' => 'not found', 'code' => 'model_not_found']], 404)
            : Http::response($this->answer('Dari cadangan.'))]);

        $this->assertSame('llama-test-small', app(OpenAiCompatibleDriver::class)->generate($this->request())->model);
        Http::assertSentCount(2);
    }

    public function test_a_wrong_key_is_not_tried_on_the_other_model(): void
    {
        config(['services.openai_compatible.fallback_model' => 'llama-test-small']);
        Http::fake([self::LOCAL_URL => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $this->failure();

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function busyStatuses(): array
    {
        return ['rate limited' => [429], 'server error' => [500], 'unavailable' => [503]];
    }

    #[DataProvider('busyStatuses')]
    public function test_a_busy_server_is_tried_once_more_then_reported_busy(int $status): void
    {
        Http::fake([self::LOCAL_URL => Http::response(['error' => ['message' => 'busy']], $status)]);

        $exception = $this->failure();

        $this->assertSame(NotebookAiException::unavailable()->getMessage(), $exception->getMessage());
        $this->assertNull(app(OpenAiCompatibleDriver::class)->misconfiguration());
        Http::assertSentCount(2);
    }

    public function test_capabilities_and_label_come_from_the_settings(): void
    {
        $driver = app(OpenAiCompatibleDriver::class);

        $this->assertSame(['openai', 'Server AI Kampus'], [$driver->driver(), $driver->label()]);
        $this->assertFalse($driver->capabilities()->pdfInput);
        $this->assertFalse($driver->capabilities()->imageInput);
        $this->assertSame(4096, $driver->capabilities()->maxOutputTokens);

        config(['services.openai_compatible.structured_output' => 'nonsense', 'services.openai_compatible.label' => '']);
        $this->assertSame('json_schema', app(OpenAiCompatibleDriver::class)->capabilities()->structuredOutput);
        $this->assertSame('OpenAI', app(OpenAiCompatibleDriver::class)->label());
    }

    private function failure(): NotebookAiException
    {
        try {
            app(OpenAiCompatibleDriver::class)->generate($this->request());
        } catch (NotebookAiException $exception) {
            return $exception;
        }

        $this->fail('The request was expected to fail.');
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $text, string $finishReason = 'stop', int $reasoningTokens = 0): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => $finishReason]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4, 'completion_tokens_details' => ['reasoning_tokens' => $reasoningTokens]],
        ];
    }

    private function request(?int $maxOutputTokens = null): AiRequest
    {
        return new AiRequest(messages: [AiRequest::user([AiRequest::text('Halo')])], maxOutputTokens: $maxOutputTokens);
    }
}
