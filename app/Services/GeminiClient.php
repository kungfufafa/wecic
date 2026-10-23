<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotebookAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal client for the Gemini `generateContent` endpoint
 * (`services.gemini`). One retry on overload, then the fallback model, all
 * within one wall-clock budget: a web request must answer (even with an
 * error) before the web server's own timeout, so a slow model is given up
 * in time instead of becoming a bare 504.
 *
 * An answer cut at its output limit (which a thinking model's reasoning
 * counts against) is asked for once more with a larger limit, within the
 * same budget; one still cut comes back marked `truncated`. A key or model
 * id the API rejects is remembered for a while (per configuration), so the
 * notebook can say so instead of calling the model again.
 *
 * @phpstan-type GeminiPart array{text?: string, inlineData?: array{mimeType: string, data: string}}
 * @phpstan-type GeminiContent array{role: 'user'|'model', parts: list<GeminiPart>}
 * @phpstan-type GeminiResult array{text: string, finish_reason: string|null, model: string, truncated: bool, thoughts_tokens: int|null, output_tokens: int|null}
 */
class GeminiClient
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /**
     * `error.details[*].reason` of a 400 that is about the key, not the
     * request: Gemini answers a wrong or expired key with 400 INVALID_ARGUMENT.
     */
    private const KEY_ERROR_REASONS = ['API_KEY_INVALID', 'API_KEY_EXPIRED'];

    /**
     * The message of a 400 about the model settings rather than the request:
     * a malformed or unknown model id ("GenerateContentRequest.model:
     * unexpected model name format", "models/x is not found for API
     * version v1beta"), or a thinking budget the model does not take
     * ("Budget 0 is invalid. This model only works in thinking mode.").
     */
    private const MODEL_ERROR_MESSAGE = '/model name|\.model\b|not found for api version|\bmodels?\b[^\n]{0,120}?\b(not found|not supported for generatecontent|does not exist|is not available|no longer available|deprecated|retired|discontinued)\b|thinking/i';

    /**
     * Finish reasons for an answer the model refused to give.
     */
    private const BLOCKED_FINISH_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'];

    /**
     * A retry or a fallback model is only worth starting with this much of
     * the budget left.
     */
    private const MIN_RETRY_SECONDS = 8;

    /**
     * The largest output limit the Gemini 2.5 models take.
     */
    private const MAX_OUTPUT_TOKENS = 65536;

    /**
     * How long a rejected key or model id is remembered: the notebook says
     * so meanwhile, and the next request after it tries the model again.
     * A changed key or model setting is tried at once.
     */
    private const MISCONFIGURATION_TTL_SECONDS = 600;

    public function isConfigured(): bool
    {
        return is_string(config('services.gemini.key')) && trim((string) config('services.gemini.key')) !== '';
    }

    /**
     * Whether a request may be sent: a key is set and neither it nor the
     * model id was rejected lately.
     */
    public function isUsable(): bool
    {
        return $this->isConfigured() && $this->misconfiguration() === null;
    }

    /**
     * What the API rejected lately with the current settings: the key
     * (`NotebookAiException::CAUSE_KEY`) or the model settings
     * (`NotebookAiException::CAUSE_MODEL`), or null.
     */
    public function misconfiguration(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $cause = Cache::get($this->misconfigurationKey());
        } catch (\Throwable) {
            return null;
        }

        return in_array($cause, [NotebookAiException::CAUSE_KEY, NotebookAiException::CAUSE_MODEL], true) ? $cause : null;
    }

    /**
     * @param  list<GeminiContent>  $contents
     * @param  array<string, mixed>  $generationConfig
     * @param  int|null  $budgetSeconds  Wall-clock budget for every attempt together; `services.gemini.request_budget` by default.
     * @param  int|null  $attemptTimeoutSeconds  Longest one attempt may take; `services.gemini.timeout` by default. A queued
     *                                           reading passes its whole budget, so one long generation can finish.
     * @return GeminiResult
     */
    public function generate(array $contents, ?string $systemInstruction = null, array $generationConfig = [], ?int $budgetSeconds = null, ?int $attemptTimeoutSeconds = null): array
    {
        if (! $this->isConfigured()) {
            throw NotebookAiException::notConfigured();
        }

        $remembered = $this->misconfiguration();

        if ($remembered !== null) {
            throw NotebookAiException::misconfigured($remembered);
        }

        $generationConfig = $this->withThinkingBudget($generationConfig);
        $payload = array_filter([
            'systemInstruction' => $systemInstruction !== null ? ['parts' => [['text' => $systemInstruction]]] : null,
            'contents' => $contents,
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
        ], static fn (mixed $value): bool => $value !== null);

        $models = array_values(array_unique(array_filter([
            (string) config('services.gemini.model'),
            (string) config('services.gemini.fallback_model'),
        ])));
        $deadline = microtime(true) + max(1, $budgetSeconds ?? (int) config('services.gemini.request_budget', 50));
        $attemptTimeout = max(10, $attemptTimeoutSeconds ?? (int) config('services.gemini.timeout', 60));
        $attempted = 0;
        $causes = [];
        $timedOut = false;

        foreach ($models as $index => $model) {
            $attempted++;
            ['response' => $response, 'timed_out' => $timedOut] = $this->send($model, $payload, $deadline, $attemptTimeout);

            if ($response !== null && $response->successful()) {
                $result = $this->parse($response, $model);

                if ($result['truncated']) {
                    $result = $this->askForMore($model, $payload, $result, $deadline, $attemptTimeout);
                }

                return $this->complete($result);
            }

            $status = $response?->status();
            Log::warning('Gemini request failed', [
                'model' => $model,
                'status' => $status,
                'timed_out' => $timedOut,
                'error' => $response?->json('error.message'),
            ]);

            $cause = $response !== null ? $this->configurationProblem($response) : null;

            if ($cause !== null) {
                $causes[] = $cause;

                // A wrong key fails for every model; an unknown model id may not.
                if ($cause === NotebookAiException::CAUSE_KEY) {
                    break;
                }
            } elseif ($status === 400) {
                // A bad request will not get better with another model.
                throw NotebookAiException::rejected();
            }

            if ($index === count($models) - 1 || $this->remaining($deadline) < self::MIN_RETRY_SECONDS) {
                break;
            }
        }

        if ($causes !== [] && count($causes) === $attempted) {
            $cause = in_array(NotebookAiException::CAUSE_KEY, $causes, true) ? NotebookAiException::CAUSE_KEY : NotebookAiException::CAUSE_MODEL;
            $this->rememberMisconfiguration($cause);
            Log::error($cause === NotebookAiException::CAUSE_KEY
                ? 'Gemini rejected the API key: check GEMINI_API_KEY'
                : 'Gemini rejected the model settings: check GEMINI_MODEL, GEMINI_FALLBACK_MODEL and NOTEBOOK_THINKING_BUDGET', ['models' => $models]);

            throw NotebookAiException::misconfigured($cause);
        }

        throw $timedOut ? NotebookAiException::timedOut() : NotebookAiException::unavailable();
    }

    /**
     * One attempt, and a second one on overload while the budget allows.
     * `timed_out` tells whether the last attempt was cut by its timeout.
     *
     * @param  array<string, mixed>  $payload
     * @return array{response: Response|null, timed_out: bool}
     */
    private function send(string $model, array $payload, float $deadline, int $attemptTimeout): array
    {
        $url = rtrim((string) config('services.gemini.base_url'), '/').'/models/'.rawurlencode($model).':generateContent';
        $response = null;
        $timedOut = false;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $timeout = min($attemptTimeout, max(1, (int) ceil($this->remaining($deadline))));
            $timedOut = false;

            try {
                $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post($url, $payload);
            } catch (ConnectionException $exception) {
                $timedOut = $this->isTimeout($exception);
                Log::warning('Gemini is unreachable', ['model' => $model, 'timeout' => $timeout, 'error' => $exception->getMessage()]);
                $response = null;
            }

            if ($response !== null && ! in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
                return ['response' => $response, 'timed_out' => false];
            }

            $delay = max(0, (int) config('services.gemini.retry_delay_ms', 1200)) / 1000;

            if ($attempt === 2 || $this->remaining($deadline) - $delay < self::MIN_RETRY_SECONDS) {
                break;
            }

            usleep((int) ($delay * 1_000_000));
        }

        return ['response' => $response, 'timed_out' => $timedOut];
    }

    /**
     * The answer stopped at its output limit: ask the same model once more
     * with room for what it spent thinking and twice the answer, while the
     * budget allows. Without a larger answer, the cut one stands: a retry
     * that failed, thought through the larger limit without writing, or
     * was cut again with less text than the first does not replace it.
     *
     * @param  array<string, mixed>  $payload
     * @param  GeminiResult  $cut
     * @return GeminiResult
     */
    private function askForMore(string $model, array $payload, array $cut, float $deadline, int $attemptTimeout): array
    {
        $limit = $payload['generationConfig']['maxOutputTokens'] ?? null;
        $larger = is_int($limit) ? min(self::MAX_OUTPUT_TOKENS, 2 * $limit + (int) $cut['thoughts_tokens']) : null;
        $retry = $larger !== null && $larger > $limit && $this->remaining($deadline) >= self::MIN_RETRY_SECONDS;

        Log::info('Gemini answer stopped at its output limit', [
            'model' => $model,
            'max_output_tokens' => $limit,
            'thoughts_tokens' => $cut['thoughts_tokens'],
            'output_tokens' => $cut['output_tokens'],
            'retry_max_output_tokens' => $retry ? $larger : null,
        ]);

        if (! $retry) {
            return $cut;
        }

        $payload['generationConfig']['maxOutputTokens'] = $larger;
        ['response' => $response] = $this->send($model, $payload, $deadline, $attemptTimeout);

        if ($response === null || ! $response->successful()) {
            Log::warning('Gemini retry with a larger output limit failed', ['model' => $model, 'status' => $response?->status()]);

            return $cut;
        }

        try {
            $more = $this->parse($response, $model);
        } catch (NotebookAiException $exception) {
            if ($exception->is(NotebookAiException::REASON_BLOCKED)) {
                throw $exception;
            }

            return $cut;
        }

        if ($more['truncated'] && mb_strlen($more['text']) < mb_strlen($cut['text'])) {
            Log::info('Gemini retry with a larger output limit gave less text', [
                'model' => $model,
                'thoughts_tokens' => $more['thoughts_tokens'],
                'output_tokens' => $more['output_tokens'],
            ]);

            return $cut;
        }

        return $more;
    }

    /**
     * An answer cut before any text is an error, not an empty answer.
     *
     * @param  GeminiResult  $result
     * @return GeminiResult
     */
    private function complete(array $result): array
    {
        if ($result['text'] === '') {
            throw NotebookAiException::outputExhausted();
        }

        return $result;
    }

    /**
     * `notebook.thinking_budget`, when set, as `thinkingConfig`; a positive
     * budget is added to the output limit, which thinking counts against,
     * so the answer keeps the room it was given.
     *
     * @param  array<string, mixed>  $generationConfig
     * @return array<string, mixed>
     */
    private function withThinkingBudget(array $generationConfig): array
    {
        $budget = config('notebook.thinking_budget');

        if (! is_numeric($budget) || isset($generationConfig['thinkingConfig'])) {
            return $generationConfig;
        }

        $budget = (int) $budget;
        $generationConfig['thinkingConfig'] = ['thinkingBudget' => $budget];

        if ($budget > 0 && is_int($generationConfig['maxOutputTokens'] ?? null)) {
            $generationConfig['maxOutputTokens'] = min(self::MAX_OUTPUT_TOKENS, $generationConfig['maxOutputTokens'] + $budget);
        }

        return $generationConfig;
    }

    /**
     * What a failed response says is wrong with the settings: the key or
     * the project (a 401/403, a 400 about the key such as `API_KEY_INVALID`,
     * or `FAILED_PRECONDITION`, e.g. a region without the free tier), the
     * model settings (a 404, or a 400 about the model id or the thinking
     * budget), or nothing (null).
     *
     * @return NotebookAiException::CAUSE_*|null
     */
    private function configurationProblem(Response $response): ?string
    {
        $status = $response->status();

        if ($status === 404) {
            return NotebookAiException::CAUSE_MODEL;
        }

        if ($status === 401 || $status === 403) {
            return NotebookAiException::CAUSE_KEY;
        }

        $error = $status === 400 ? $response->json('error') : null;

        if (! is_array($error)) {
            return null;
        }

        $details = is_array($error['details'] ?? null) ? array_filter($error['details'], 'is_array') : [];
        $reasons = array_map(static fn (array $detail): mixed => $detail['reason'] ?? null, $details);
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';

        if (array_intersect($reasons, self::KEY_ERROR_REASONS) !== []
            || ($error['status'] ?? null) === 'FAILED_PRECONDITION'
            || str_contains(strtolower($message), 'api key')) {
            return NotebookAiException::CAUSE_KEY;
        }

        $fields = [];

        foreach ($details as $detail) {
            foreach (is_array($detail['fieldViolations'] ?? null) ? $detail['fieldViolations'] : [] as $violation) {
                $fields[] = is_array($violation) && is_string($violation['field'] ?? null) ? $violation['field'] : '';
            }
        }

        $aboutModel = preg_match(self::MODEL_ERROR_MESSAGE, $message) === 1
            || array_filter($fields, static fn (string $field): bool => preg_match('/(^|\.)model$|thinking/i', $field) === 1) !== [];

        return $aboutModel ? NotebookAiException::CAUSE_MODEL : null;
    }

    /**
     * @param  NotebookAiException::CAUSE_*  $cause
     */
    private function rememberMisconfiguration(string $cause): void
    {
        try {
            Cache::put($this->misconfigurationKey(), $cause, self::MISCONFIGURATION_TTL_SECONDS);
        } catch (\Throwable $throwable) {
            Log::warning('Gemini misconfiguration could not be remembered', ['error' => $throwable->getMessage()]);
        }
    }

    /**
     * One key per configuration: fixing the key, a model id or the thinking
     * budget forgets the rejection at once.
     */
    private function misconfigurationKey(): string
    {
        return 'gemini:misconfigured:'.sha1((string) json_encode([
            (string) config('services.gemini.key'),
            (string) config('services.gemini.model'),
            (string) config('services.gemini.fallback_model'),
            (string) config('services.gemini.base_url'),
            config('notebook.thinking_budget'),
        ]));
    }

    /**
     * The model did not answer within the attempt's timeout (cURL 28
     * "Operation timed out after N milliseconds with M bytes received"). A
     * connection or name lookup that timed out ("Connection timed out
     * after", "Resolving timed out after", or "Failed to connect … :
     * Operation timed out" with the system's own wording) means the model
     * could not be reached at all, which is not the request's fault.
     */
    private function isTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'operation timed out after') && ! str_contains($message, 'failed to connect');
    }

    private function remaining(float $deadline): float
    {
        return $deadline - microtime(true);
    }

    /**
     * The answer's text (thoughts left out), why it stopped, and what it
     * spent (`usageMetadata`). An answer cut at the output limit
     * (`MAX_TOKENS`) may have no text yet; any other empty answer fails.
     *
     * @return GeminiResult
     */
    private function parse(Response $response, string $model): array
    {
        if ($response->json('promptFeedback.blockReason') !== null) {
            throw NotebookAiException::blocked();
        }

        $candidate = $response->json('candidates.0');
        $finishReason = is_array($candidate) && is_string($candidate['finishReason'] ?? null) ? $candidate['finishReason'] : null;
        $parts = is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [];
        $text = trim(implode('', array_map(
            static fn (mixed $part): string => is_array($part) && is_string($part['text'] ?? null) && ! ($part['thought'] ?? false) ? $part['text'] : '',
            $parts
        )));
        $usage = $response->json('usageMetadata');
        $truncated = $finishReason === 'MAX_TOKENS';

        if ($text === '' && ! $truncated) {
            throw in_array($finishReason, self::BLOCKED_FINISH_REASONS, true) ? NotebookAiException::blocked() : NotebookAiException::emptyAnswer();
        }

        return [
            'text' => $text,
            'finish_reason' => $finishReason,
            'model' => $model,
            'truncated' => $truncated,
            'thoughts_tokens' => is_array($usage) && is_int($usage['thoughtsTokenCount'] ?? null) ? $usage['thoughtsTokenCount'] : null,
            'output_tokens' => is_array($usage) && is_int($usage['candidatesTokenCount'] ?? null) ? $usage['candidatesTokenCount'] : null,
        ];
    }
}
