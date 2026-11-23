<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\NotebookAiException;
use App\Support\Ai\AiCapabilities;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Illuminate\Http\Client\Response;

/**
 * Anthropic Claude over the Messages API (`POST /v1/messages`,
 * `services.anthropic`), with plain HTTP like the other drivers.
 *
 * The request carries the system prompt at the top level and always a
 * `max_tokens`. PDFs go as `document` blocks and images as `image` blocks,
 * always inside user messages. A JSON schema goes as
 * `output_config.format`; `output_config.effort` only when
 * `services.anthropic.effort` is set. On API version 2026-10-21 sampling
 * settings (temperature, top_p, top_k) are refused, and Claude Opus 5.5
 * refuses any `thinking` setting but adaptive, so neither is sent; nor is
 * the assistant turn ever prefilled.
 *
 * @phpstan-type AnthropicBlock array<string, mixed>
 * @phpstan-type AnthropicMessage array{role: 'user'|'assistant', content: list<AnthropicBlock>}
 */
class AnthropicDriver extends HttpAiClient
{
    /**
     * The largest output limit current Claude models take (Claude Opus,
     * Sonnet and Haiku 5.5); `services.anthropic.max_output_tokens` lowers
     * it for an older model with a smaller ceiling (64000 on Haiku 4.5).
     */
    private const MAX_OUTPUT_TOKENS = 128000;

    /**
     * Tokens added to every output limit for the model's thinking, when
     * `services.anthropic.thinking_headroom` is unset: current models think
     * adaptively (Claude Opus 5.5 always does) and their thinking counts
     * against `max_tokens`, so a limit sized for the answer alone would cut
     * it. The limit is a ceiling, not a charge.
     */
    private const DEFAULT_THINKING_HEADROOM = 8192;

    /**
     * The largest image the Messages API takes: 5 MB, which the API counts
     * on the base64 text, so 3.75 MB of image.
     */
    private const MAX_IMAGE_BYTES = 3932160;

    /**
     * The output limit sent when the request names none: the API needs one.
     */
    private const DEFAULT_OUTPUT_TOKENS = 8192;

    /**
     * Files go base64-encoded in a request of at most 32 MB.
     */
    private const MAX_INLINE_BYTES = 19 * 1024 * 1024;

    /**
     * The message of a 400 about the model settings rather than the
     * request: an unknown or unavailable model id, or an effort the model
     * does not take.
     */
    private const MODEL_ERROR_MESSAGE = '/^model\b|\bmodel\b[^\n]{0,120}?\b(not found|does not exist|not supported|is not available|no longer available|deprecated|retired|invalid)\b|\beffort\b|\bmax_tokens\b:?\s*\d+\s*>\s*\d+/i';

    public function driver(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Anthropic Claude';
    }

    protected function ownHost(): string
    {
        return 'api.anthropic.com';
    }

    public function capabilities(): AiCapabilities
    {
        return new AiCapabilities(
            pdfInput: true,
            imageInput: true,
            structuredOutput: AiCapabilities::STRUCTURED_JSON_SCHEMA,
            maxOutputTokens: $this->maxOutputTokens(),
            maxInlineBytes: self::MAX_INLINE_BYTES,
            maxImageBytes: self::MAX_IMAGE_BYTES,
        );
    }

    protected function settingsKey(): string
    {
        return 'services.anthropic';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(AiRequest $request): array
    {
        $outputConfig = array_filter([
            'format' => $request->jsonSchema !== null ? ['type' => 'json_schema', 'schema' => $request->jsonSchema] : null,
            'effort' => $this->effort(),
        ], static fn (mixed $value): bool => $value !== null);

        return array_filter([
            'max_tokens' => min($this->maxOutputTokens(), ($request->maxOutputTokens ?? self::DEFAULT_OUTPUT_TOKENS) + $this->thinkingHeadroom()),
            'system' => $request->system,
            'messages' => $this->messages($request->messages),
            'output_config' => $outputConfig !== [] ? $outputConfig : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function forModel(array $payload, string $model): array
    {
        return ['model' => $model, ...$payload];
    }

    protected function endpoint(string $model): string
    {
        return rtrim((string) $this->setting('base_url'), '/').'/v1/messages';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'x-api-key' => $this->key(),
            'anthropic-version' => (string) ($this->setting('version') ?: '2026-10-21'),
            'content-type' => 'application/json',
        ];
    }

    /**
     * Overload (529) is worth a second attempt too.
     *
     * @return list<int>
     */
    protected function retryableStatuses(): array
    {
        return [...parent::retryableStatuses(), 529];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function outputLimit(array $payload): ?int
    {
        return is_int($payload['max_tokens'] ?? null) ? $payload['max_tokens'] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withOutputLimit(array $payload, int $limit): array
    {
        $payload['max_tokens'] = $limit;

        return $payload;
    }

    protected function reasoningSetting(): mixed
    {
        return $this->effort();
    }

    /**
     * @return list<string>
     */
    public function settingNames(string $cause): array
    {
        return $cause === NotebookAiException::CAUSE_KEY
            ? ['ANTHROPIC_API_KEY']
            : ['ANTHROPIC_MODEL', 'ANTHROPIC_FALLBACK_MODEL', 'ANTHROPIC_MAX_OUTPUT_TOKENS', 'ANTHROPIC_EFFORT'];
    }

    public function freeTier(): bool
    {
        return false;
    }

    /**
     * The key or the account behind it (401 `authentication_error`, 402
     * `billing_error`, 403 `permission_error`), the model settings (404
     * `not_found_error`, or a 400 `invalid_request_error` about the model
     * id, the effort, or a `max_tokens` over the model's ceiling), or
     * nothing (null).
     *
     * @return NotebookAiException::CAUSE_*|null
     */
    protected function configurationProblem(Response $response): ?string
    {
        $status = $response->status();

        if (in_array($status, [401, 402, 403], true)) {
            return NotebookAiException::CAUSE_KEY;
        }

        if ($status === 404) {
            return NotebookAiException::CAUSE_MODEL;
        }

        $message = $response->json('error.message');

        if ($status === 400 && is_string($message) && preg_match(self::MODEL_ERROR_MESSAGE, $message) === 1) {
            return NotebookAiException::CAUSE_MODEL;
        }

        return null;
    }

    /**
     * The text blocks of the answer (thinking blocks left out), why it
     * stopped (`stop_reason`), and what it spent (`usage`). An answer cut
     * at `max_tokens` may have no text yet; a refusal without text, like
     * any other empty answer, fails.
     */
    protected function parse(Response $response, string $model): AiResult
    {
        $stopReason = $response->json('stop_reason');
        $stopReason = is_string($stopReason) ? $stopReason : null;
        $content = $response->json('content');
        $text = trim(implode('', array_map(
            static fn (mixed $block): string => is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null) ? $block['text'] : '',
            is_array($content) ? $content : []
        )));
        $usage = $response->json('usage');
        $truncated = $stopReason === 'max_tokens';
        $blocked = $stopReason === 'refusal';

        if ($text === '' && ! $truncated) {
            throw $blocked ? NotebookAiException::blocked() : NotebookAiException::emptyAnswer();
        }

        return new AiResult(
            text: $text,
            finishReason: $stopReason,
            model: $model,
            truncated: $truncated,
            outputTokens: is_array($usage) && is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            inputTokens: is_array($usage) && is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            stop: match (true) {
                $truncated => AiResult::STOP_LENGTH,
                $blocked => AiResult::STOP_BLOCKED,
                default => AiResult::STOP_END,
            },
        );
    }

    /**
     * @return positive-int
     */
    private function maxOutputTokens(): int
    {
        $limit = (int) $this->setting('max_output_tokens', self::MAX_OUTPUT_TOKENS);

        return max(1, $limit > 0 ? min($limit, self::MAX_OUTPUT_TOKENS) : self::MAX_OUTPUT_TOKENS);
    }

    private function thinkingHeadroom(): int
    {
        $headroom = $this->setting('thinking_headroom');

        return is_numeric($headroom) ? max(0, (int) $headroom) : self::DEFAULT_THINKING_HEADROOM;
    }

    private function effort(): ?string
    {
        $effort = $this->setting('effort');

        return is_string($effort) && trim($effort) !== '' ? trim($effort) : null;
    }

    /**
     * The conversation in the Messages API's shape: turns of one role in a
     * row are joined, it starts with the user, and it never ends with an
     * assistant turn (a prefill, which the API refuses).
     *
     * @param  list<array{role: 'user'|'assistant', parts: list<array<string, string>>}>  $messages
     * @return list<AnthropicMessage>
     */
    private function messages(array $messages): array
    {
        $turns = [];

        foreach ($messages as $message) {
            $role = $message['role'] === 'assistant' ? 'assistant' : 'user';
            $blocks = array_values(array_filter(array_map(
                fn (array $part): ?array => $this->block($part, $role),
                $message['parts']
            )));

            if ($blocks === [] || ($turns === [] && $role === 'assistant')) {
                continue;
            }

            $last = array_key_last($turns);

            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] = [...$turns[$last]['content'], ...$blocks];

                continue;
            }

            $turns[] = ['role' => $role, 'content' => $blocks];
        }

        while ($turns !== [] && $turns[array_key_last($turns)]['role'] === 'assistant') {
            array_pop($turns);
        }

        return $turns;
    }

    /**
     * A part as a content block: text, or a file as a `document` (PDF) or
     * `image` block. A file is only ever sent from the user; empty text,
     * which the API refuses, is left out.
     *
     * @param  array<string, string>  $part
     * @return AnthropicBlock|null
     */
    private function block(array $part, string $role): ?array
    {
        if (($part['type'] ?? 'text') !== 'file') {
            return trim($part['text']) !== '' ? ['type' => 'text', 'text' => $part['text']] : null;
        }

        if ($role !== 'user') {
            return null;
        }

        $source = ['type' => 'base64', 'media_type' => $part['mime'], 'data' => $part['base64']];

        return str_starts_with(strtolower($part['mime']), 'image/')
            ? ['type' => 'image', 'source' => $source]
            : ['type' => 'document', 'source' => [...$source, 'media_type' => 'application/pdf']];
    }
}
