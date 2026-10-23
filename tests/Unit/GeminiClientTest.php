<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\NotebookAiException;
use App\Services\GeminiClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-test-flash',
            'services.gemini.fallback_model' => 'gemini-test-lite',
            'services.gemini.timeout' => 60,
            'services.gemini.request_budget' => 50,
            'services.gemini.retry_delay_ms' => 0,
            'notebook.thinking_budget' => null,
        ]);
    }

    public function test_a_caller_may_give_one_attempt_its_whole_budget(): void
    {
        $timeouts = [];
        Http::fake([self::GEMINI_URL => function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Isi.']]], 'finishReason' => 'STOP']]]);
        }]);

        app(GeminiClient::class)->generate($this->contents(), null, [], 240, 240);
        app(GeminiClient::class)->generate($this->contents(), null, [], 240);
        app(GeminiClient::class)->generate($this->contents());

        $this->assertSame([240, 60, 50], $timeouts);
    }

    public function test_an_unknown_model_id_is_reported_as_a_configuration_problem(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['error' => ['message' => 'models/gemini-test-flash is not found']], 404)]);

        $exception = $this->failure();

        $this->assertSame(NotebookAiException::misconfigured(NotebookAiException::CAUSE_MODEL)->getMessage(), $exception->getMessage());
        $this->assertSame(503, $exception->getCode());
        $this->assertTrue($exception->concernsConfiguration());
        // The fallback model is tried once; a 404 is not retried.
        Http::assertSentCount(2);
    }

    public function test_a_retired_primary_model_falls_back_to_the_other_model(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-flash:generateContent' => Http::response(['error' => ['message' => 'not found']], 404),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-lite:generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Jawaban.']]], 'finishReason' => 'STOP']]]),
        ]);

        $result = app(GeminiClient::class)->generate($this->contents());

        $this->assertSame('Jawaban.', $result['text']);
        $this->assertSame('gemini-test-lite', $result['model']);
    }

    public function test_a_wrong_key_is_not_tried_on_another_model(): void
    {
        Log::spy();
        // What Gemini answers for a wrong or expired key: a 400, not a 401.
        Http::fake([self::GEMINI_URL => Http::response(['error' => [
            'code' => 400,
            'message' => 'API key not valid. Please pass a valid API key.',
            'status' => 'INVALID_ARGUMENT',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com'],
                ['@type' => 'type.googleapis.com/google.rpc.LocalizedMessage', 'locale' => 'en-US', 'message' => 'API key not valid. Please pass a valid API key.'],
            ],
        ]], 400)]);

        $exception = $this->failure();

        $this->assertSame(NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY)->getMessage(), $exception->getMessage());
        $this->assertSame(503, $exception->getCode());
        $this->assertFalse($exception->is(NotebookAiException::REASON_REJECTED));
        $this->assertSame(NotebookAiException::CAUSE_KEY, app(GeminiClient::class)->misconfiguration());
        Http::assertSentCount(1);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_revoked_key_or_a_project_without_access_is_a_configuration_problem(): void
    {
        Http::fake([self::GEMINI_URL => Http::sequence()
            ->push(['error' => ['code' => 403, 'message' => 'Permission denied', 'status' => 'PERMISSION_DENIED']], 403)
            ->push(['error' => ['code' => 400, 'message' => 'User location is not supported for the API use.', 'status' => 'FAILED_PRECONDITION']], 400)
            ->push(['error' => ['code' => 400, 'message' => 'API key expired. Please renew the API key.', 'status' => 'INVALID_ARGUMENT']], 400)]);

        foreach (range(1, 3) as $attempt) {
            $this->assertSame(NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY)->getMessage(), $this->failure()->getMessage(), "Attempt {$attempt}");
            // Each answer on its own, not the one remembered from the attempt before.
            Cache::flush();
        }

        Http::assertSentCount(3);
    }

    public function test_a_model_that_is_only_busy_is_still_reported_as_busy(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-flash:generateContent' => Http::response(['error' => ['message' => 'not found']], 404),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-lite:generateContent' => Http::response(['error' => ['message' => 'overloaded']], 503),
        ]);

        $this->assertSame(NotebookAiException::unavailable()->getMessage(), $this->failure()->getMessage());
    }

    public function test_a_bad_request_is_rejected_without_another_model(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['error' => ['code' => 400, 'message' => 'Request payload size exceeds the limit: 20971520 bytes.', 'status' => 'INVALID_ARGUMENT']], 400)]);

        $exception = $this->failure();

        $this->assertTrue($exception->is(NotebookAiException::REASON_REJECTED));
        $this->assertSame(422, $exception->getCode());
        Http::assertSentCount(1);
    }

    public function test_a_request_cut_by_its_timeout_is_reported_as_a_timeout(): void
    {
        config(['services.gemini.fallback_model' => null]);
        Http::fake([self::GEMINI_URL => Factory::failedConnection('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received')]);

        $exception = $this->failure();

        $this->assertTrue($exception->is(NotebookAiException::REASON_TIMED_OUT));
        $this->assertSame(NotebookAiException::timedOut()->getMessage(), $exception->getMessage());
    }

    public function test_an_unreachable_model_is_reported_as_busy(): void
    {
        config(['services.gemini.fallback_model' => null]);
        Http::fake([self::GEMINI_URL => Factory::failedConnection()]);

        $exception = $this->failure();

        $this->assertFalse($exception->is(NotebookAiException::REASON_TIMED_OUT));
        $this->assertSame(NotebookAiException::unavailable()->getMessage(), $exception->getMessage());
    }

    public function test_a_connection_that_timed_out_is_not_blamed_on_the_request(): void
    {
        config(['services.gemini.fallback_model' => null]);

        foreach ([
            'cURL error 28: Connection timed out after 10001 milliseconds',
            'cURL error 28: Resolving timed out after 5000 milliseconds',
            // A connect timeout in the system's own ETIMEDOUT wording (macOS).
            'cURL error 7: Failed to connect to generativelanguage.googleapis.com port 443 after 75006 ms: Operation timed out',
        ] as $error) {
            Http::fake([self::GEMINI_URL => Factory::failedConnection($error)]);

            $exception = $this->failure();

            $this->assertFalse($exception->is(NotebookAiException::REASON_TIMED_OUT), $error);
            $this->assertSame(NotebookAiException::unavailable()->getMessage(), $exception->getMessage(), $error);
        }
    }

    public function test_an_answer_withheld_for_recitation_is_reported_as_blocked(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => []], 'finishReason' => 'RECITATION']]])]);

        $this->assertSame(NotebookAiException::blocked()->getMessage(), $this->failure()->getMessage());
    }

    public function test_a_rejected_model_is_remembered_until_the_settings_change_or_a_while_passes(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['error' => ['message' => 'models/gemini-test-flash is not found for API version v1beta']], 404)]);
        $client = app(GeminiClient::class);

        $this->failure();
        Http::assertSentCount(2);
        $this->assertSame(NotebookAiException::CAUSE_MODEL, $client->misconfiguration());
        $this->assertFalse($client->isUsable());

        // Said again without calling the model.
        $this->assertSame(NotebookAiException::misconfigured(NotebookAiException::CAUSE_MODEL)->getMessage(), $this->failure()->getMessage());
        Http::assertSentCount(2);

        // A changed model setting is tried at once.
        config(['services.gemini.fallback_model' => null, 'services.gemini.model' => 'gemini-test-pro']);
        $this->assertNull($client->misconfiguration());
        $this->failure();
        Http::assertSentCount(3);
        $this->failure();
        Http::assertSentCount(3);

        // The same settings are tried again after a while.
        $this->travel(11)->minutes();
        $this->assertTrue($client->isUsable());
        $this->failure();
        Http::assertSentCount(4);
    }

    public function test_a_bad_request_about_the_model_settings_is_a_configuration_problem(): void
    {
        $error = [];
        Http::fake([self::GEMINI_URL => function () use (&$error) {
            return Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', ...$error]], 400);
        }]);

        foreach ([
            ['message' => '* GenerateContentRequest.model: unexpected model name format'],
            ['message' => 'Model gemini-2.0-flash-exp is no longer available. Please switch to a newer model.'],
            // NOTEBOOK_THINKING_BUDGET=0 for a model that always thinks.
            ['message' => 'Budget 0 is invalid. This model only works in thinking mode.'],
            ['message' => 'Request contains an invalid argument.', 'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'model', 'description' => 'unexpected model name format']]],
            ]],
        ] as $error) {
            Cache::flush();

            $exception = $this->failure();

            $this->assertTrue($exception->is(NotebookAiException::REASON_MISCONFIGURED), $error['message']);
            $this->assertSame(NotebookAiException::misconfigured(NotebookAiException::CAUSE_MODEL)->getMessage(), $exception->getMessage(), $error['message']);
            $this->assertSame(NotebookAiException::CAUSE_MODEL, app(GeminiClient::class)->misconfiguration(), $error['message']);
        }

        // A request that is merely too large is the request's own problem.
        Cache::flush();
        $error = ['message' => 'The input token count (1200000) exceeds the maximum number of tokens allowed (1048576).'];
        $this->assertTrue($this->failure()->is(NotebookAiException::REASON_REJECTED));
        $this->assertNull(app(GeminiClient::class)->misconfiguration());
    }

    public function test_an_answer_cut_at_its_output_limit_is_asked_for_once_more_with_room_for_thinking(): void
    {
        $limits = [];
        Http::fake([self::GEMINI_URL => function (Request $request) use (&$limits) {
            $limits[] = $request['generationConfig']['maxOutputTokens'] ?? null;

            return count($limits) === 1
                ? Http::response($this->answer('Ringkasan seba', 'MAX_TOKENS', thoughts: 1800))
                : Http::response($this->answer('Ringkasan selengkapnya.', 'STOP', thoughts: 1700));
        }]);

        $result = app(GeminiClient::class)->generate($this->contents(), null, ['maxOutputTokens' => 2048]);

        $this->assertSame([2048, 2 * 2048 + 1800], $limits);
        $this->assertSame('Ringkasan selengkapnya.', $result['text']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(1700, $result['thoughts_tokens']);
    }

    public function test_an_answer_still_cut_after_the_larger_limit_is_marked_truncated(): void
    {
        Http::fake([self::GEMINI_URL => Http::sequence()
            ->push($this->answer('Bagian satu', 'MAX_TOKENS', thoughts: 500))
            ->push($this->answer('Bagian satu dan dua', 'MAX_TOKENS', thoughts: 400))]);

        $result = app(GeminiClient::class)->generate($this->contents(), null, ['maxOutputTokens' => 1024]);

        $this->assertSame('Bagian satu dan dua', $result['text']);
        $this->assertTrue($result['truncated']);
        $this->assertSame('MAX_TOKENS', $result['finish_reason']);
        Http::assertSentCount(2);
    }

    public function test_a_retry_that_only_thought_or_wrote_less_keeps_the_cut_answer(): void
    {
        $thoughtOnly = [
            'candidates' => [['content' => ['parts' => [['text' => 'Menimbang ulang…', 'thought' => true]]], 'finishReason' => 'MAX_TOKENS']],
            'usageMetadata' => ['thoughtsTokenCount' => 18000, 'candidatesTokenCount' => 0],
        ];
        Http::fake([self::GEMINI_URL => Http::sequence()
            ->push($this->answer('Normalisasi punya beberapa tahap [1]. Tahap pertama', 'MAX_TOKENS', thoughts: 3000))
            ->push($thoughtOnly)
            ->push($this->answer('Normalisasi punya beberapa tahap [1]. Tahap pertama', 'MAX_TOKENS', thoughts: 3000))
            ->push($this->answer('Normalisasi', 'MAX_TOKENS', thoughts: 18000))]);

        // The larger limit went to thinking alone: the first answer stands, marked cut.
        $result = app(GeminiClient::class)->generate($this->contents(), null, ['maxOutputTokens' => 8192]);

        $this->assertSame('Normalisasi punya beberapa tahap [1]. Tahap pertama', $result['text']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(3000, $result['thoughts_tokens']);

        // Cut again with less text than the first: the first is kept too.
        $result = app(GeminiClient::class)->generate($this->contents(), null, ['maxOutputTokens' => 8192]);

        $this->assertSame('Normalisasi punya beberapa tahap [1]. Tahap pertama', $result['text']);
        $this->assertTrue($result['truncated']);
        Http::assertSentCount(4);
    }

    public function test_an_answer_cut_before_any_text_is_an_error_not_an_empty_answer(): void
    {
        // Only thoughts came back: the model thought through its whole limit, twice.
        Http::fake([self::GEMINI_URL => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Menimbang…', 'thought' => true]]], 'finishReason' => 'MAX_TOKENS']],
            'usageMetadata' => ['thoughtsTokenCount' => 2040, 'candidatesTokenCount' => 0],
        ])]);

        try {
            app(GeminiClient::class)->generate($this->contents(), null, ['maxOutputTokens' => 2048]);
            $this->fail('The request was expected to fail.');
        } catch (NotebookAiException $exception) {
        }

        $this->assertTrue($exception->is(NotebookAiException::REASON_TOO_LONG));
        $this->assertTrue($exception->modelAnswered());
        $this->assertSame(NotebookAiException::outputExhausted()->getMessage(), $exception->getMessage());
        Http::assertSentCount(2);
    }

    public function test_a_cut_answer_is_not_asked_for_again_past_the_budget_or_the_largest_limit(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->answer('Terpotong', 'MAX_TOKENS'))]);

        // Less than a retry's worth of the budget left.
        $this->assertTrue(app(GeminiClient::class)->generate($this->contents(), null, ['maxOutputTokens' => 1024], 5)['truncated']);
        Http::assertSentCount(1);

        // Already at the largest limit.
        $this->assertTrue(app(GeminiClient::class)->generate($this->contents(), null, ['maxOutputTokens' => 65536])['truncated']);
        Http::assertSentCount(2);
    }

    public function test_a_thinking_budget_is_sent_only_when_configured_and_widens_the_output_limit(): void
    {
        $configs = [];
        Http::fake([self::GEMINI_URL => function (Request $request) use (&$configs) {
            $configs[] = $request['generationConfig'] ?? null;

            return Http::response($this->answer('Isi.', 'STOP'));
        }]);
        $client = app(GeminiClient::class);

        $client->generate($this->contents(), null, ['maxOutputTokens' => 2048]);
        config(['notebook.thinking_budget' => 1024]);
        $client->generate($this->contents(), null, ['maxOutputTokens' => 2048]);
        config(['notebook.thinking_budget' => 0]);
        $client->generate($this->contents(), null, ['maxOutputTokens' => 2048]);
        config(['notebook.thinking_budget' => -1]);
        $client->generate($this->contents());

        $this->assertSame([
            ['maxOutputTokens' => 2048],
            ['maxOutputTokens' => 2048 + 1024, 'thinkingConfig' => ['thinkingBudget' => 1024]],
            ['maxOutputTokens' => 2048, 'thinkingConfig' => ['thinkingBudget' => 0]],
            ['thinkingConfig' => ['thinkingBudget' => -1]],
        ], $configs);
    }

    private function failure(): NotebookAiException
    {
        try {
            app(GeminiClient::class)->generate($this->contents());
        } catch (NotebookAiException $exception) {
            return $exception;
        }

        $this->fail('The request was expected to fail.');
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $text, string $finishReason, int $thoughts = 0): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => $finishReason]],
            'usageMetadata' => ['thoughtsTokenCount' => $thoughts, 'candidatesTokenCount' => 10],
        ];
    }

    /**
     * @return list<array{role: 'user', parts: list<array{text: string}>}>
     */
    private function contents(): array
    {
        return [['role' => 'user', 'parts' => [['text' => 'Halo']]]];
    }
}
