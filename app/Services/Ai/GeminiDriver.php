<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\NotebookAiException;
use App\Support\Ai\AiCapabilities;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Illuminate\Http\Client\Response;

/**
 * Google Gemini over the `generateContent` endpoint (`services.gemini`).
 *
 * `notebook.thinking_budget`, when set, is sent as `thinkingConfig`; a
 * positive budget is added to the output limit, which thinking counts
 * against, so the answer keeps the room it was given. A JSON schema is sent
 * as `responseSchema` in Gemini's uppercase dialect (geminiSchema()).
 *
 * @phpstan-type GeminiPart array{text?: string, inlineData?: array{mimeType: string, data: string}}
 * @phpstan-type GeminiContent array{role: 'user'|'model', parts: list<GeminiPart>}
 */
class GeminiDriver extends HttpAiClient
{
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
     * The largest output limit the Gemini 2.5 models take.
     */
    private const MAX_OUTPUT_TOKENS = 65536;

    /**
     * Inline data is limited by the request size (20 MB).
     */
    private const MAX_INLINE_BYTES = 19 * 1024 * 1024;

    /**
     * JSON Schema keywords Gemini's `responseSchema` does not take.
     */
    private const UNSUPPORTED_SCHEMA_KEYWORDS = ['additionalProperties'];

    public function driver(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Google Gemini';
    }

    protected function ownHost(): string
    {
        return 'generativelanguage.googleapis.com';
    }

    public function capabilities(): AiCapabilities
    {
        return new AiCapabilities(
            pdfInput: true,
            imageInput: true,
            structuredOutput: AiCapabilities::STRUCTURED_JSON_SCHEMA,
            maxOutputTokens: self::MAX_OUTPUT_TOKENS,
            maxInlineBytes: self::MAX_INLINE_BYTES,
        );
    }

    /**
     * Lowercase JSON Schema in Gemini's dialect: types in capitals
     * (`OBJECT`, `STRING`…), and keywords it does not take left out.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function geminiSchema(array $schema): array
    {
        $mapped = [];

        foreach ($schema as $keyword => $value) {
            if (in_array($keyword, self::UNSUPPORTED_SCHEMA_KEYWORDS, true)) {
                continue;
            }

            $mapped[$keyword] = match (true) {
                $keyword === 'type' && is_string($value) => strtoupper($value),
                $keyword === 'items' && is_array($value) => self::geminiSchema($value),
                $keyword === 'properties' && is_array($value) => array_map(
                    static fn (mixed $property): mixed => is_array($property) ? self::geminiSchema($property) : $property,
                    $value
                ),
                default => $value,
            };
        }

        return $mapped;
    }

    protected function settingsKey(): string
    {
        return 'services.gemini';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(AiRequest $request): array
    {
        $generationConfig = $this->withThinkingBudget(array_filter([
            'temperature' => $request->temperature,
            'maxOutputTokens' => $request->maxOutputTokens,
            'responseMimeType' => $request->jsonSchema !== null ? 'application/json' : null,
            'responseSchema' => $request->jsonSchema !== null ? self::geminiSchema($request->jsonSchema) : null,
        ], static fn (mixed $value): bool => $value !== null));

        return array_filter([
            'systemInstruction' => $request->system !== null ? ['parts' => [['text' => $request->system]]] : null,
            'contents' => array_map($this->content(...), $request->messages),
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function endpoint(string $model): string
    {
        return rtrim((string) $this->setting('base_url'), '/').'/models/'.rawurlencode($model).':generateContent';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->key()];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function outputLimit(array $payload): ?int
    {
        $limit = $payload['generationConfig']['maxOutputTokens'] ?? null;

        return is_int($limit) ? $limit : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withOutputLimit(array $payload, int $limit): array
    {
        $payload['generationConfig']['maxOutputTokens'] = $limit;

        return $payload;
    }

    protected function reasoningSetting(): mixed
    {
        return config('notebook.thinking_budget');
    }

    /**
     * @return list<string>
     */
    public function settingNames(string $cause): array
    {
        return $cause === NotebookAiException::CAUSE_KEY
            ? ['GEMINI_API_KEY']
            : ['GEMINI_MODEL', 'GEMINI_FALLBACK_MODEL', 'NOTEBOOK_THINKING_BUDGET'];
    }

    public function freeTier(): bool
    {
        return (bool) $this->setting('free_tier', true);
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
    protected function configurationProblem(Response $response): ?string
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
     * Gemini keeps the text written before it stopped for recitation or
     * safety, as it always has: the answer is short of the withheld part
     * only, not cut by a refusal.
     */
    protected function discardsBlockedText(): bool
    {
        return false;
    }

    /**
     * The answer's text (thoughts left out), why it stopped, and what it
     * spent (`usageMetadata`). An answer cut at the output limit
     * (`MAX_TOKENS`) may have no text yet; any other empty answer fails.
     */
    protected function parse(Response $response, string $model): AiResult
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
        $blocked = in_array($finishReason, self::BLOCKED_FINISH_REASONS, true);

        if ($text === '' && ! $truncated) {
            throw $blocked ? NotebookAiException::blocked() : NotebookAiException::emptyAnswer();
        }

        return new AiResult(
            text: $text,
            finishReason: $finishReason,
            model: $model,
            truncated: $truncated,
            thoughtsTokens: is_array($usage) && is_int($usage['thoughtsTokenCount'] ?? null) ? $usage['thoughtsTokenCount'] : null,
            outputTokens: is_array($usage) && is_int($usage['candidatesTokenCount'] ?? null) ? $usage['candidatesTokenCount'] : null,
            inputTokens: is_array($usage) && is_int($usage['promptTokenCount'] ?? null) ? $usage['promptTokenCount'] : null,
            stop: match (true) {
                $truncated => AiResult::STOP_LENGTH,
                $blocked => AiResult::STOP_BLOCKED,
                default => AiResult::STOP_END,
            },
        );
    }

    /**
     * A message in Gemini's shape: the assistant speaks as `model`, a file
     * goes inline.
     *
     * @param  array{role: 'user'|'assistant', parts: list<array<string, string>>}  $message
     * @return GeminiContent
     */
    private function content(array $message): array
    {
        return [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => array_map(static fn (array $part): array => ($part['type'] ?? 'text') === 'file'
                ? ['inlineData' => ['mimeType' => $part['mime'], 'data' => $part['base64']]]
                : ['text' => $part['text']], $message['parts']),
        ];
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

        if (! is_numeric($budget)) {
            return $generationConfig;
        }

        $budget = (int) $budget;
        $generationConfig['thinkingConfig'] = ['thinkingBudget' => $budget];

        if ($budget > 0 && is_int($generationConfig['maxOutputTokens'] ?? null)) {
            $generationConfig['maxOutputTokens'] = min(self::MAX_OUTPUT_TOKENS, $generationConfig['maxOutputTokens'] + $budget);
        }

        return $generationConfig;
    }
}
