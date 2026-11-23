<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\NotebookAiException;
use App\Support\Ai\AiCapabilities;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Illuminate\Http\Client\Response;

/**
 * Any OpenAI-compatible Chat Completions endpoint
 * (`POST {base_url}/chat/completions`, `services.openai_compatible`):
 * OpenAI itself, a gateway, or a local server.
 *
 * The endpoint does not say what its model can do, so the settings do:
 * whether it reads images (`image_input`, sent as `image_url` data URIs)
 * and PDFs (`pdf_input`, sent as `file` parts), how it answers in JSON
 * (`structured_output`: a strict `json_schema`, `json_object` with the
 * schema in the prompt, or `none`, read from plain text), which token
 * parameter it takes (`token_param`) and its largest output limit. The key
 * is sent as a bearer token when set; a server on localhost or a `*.test`
 * host may go without one.
 *
 * @phpstan-type OpenAiMessage array{role: 'system'|'user'|'assistant', content: string|list<array<string, mixed>>}
 */
class OpenAiCompatibleDriver extends HttpAiClient
{
    /**
     * The message of a 400 that says the model id itself is wrong: unknown,
     * missing (e.g. Ollama's "model \"x\" not found, try pulling it first"),
     * or no longer served.
     */
    private const MODEL_ERROR_MESSAGE = '/\bmodel\b[^\n]{0,120}?\b(not found|does not exist|unknown|is not available|no longer available|not available|deprecated|decommissioned|invalid model)\b|\b(unknown|invalid|nonexistent) model\b/i';

    /**
     * The `json_schema` name the answer's schema is sent under.
     */
    private const SCHEMA_NAME = 'notebook_output';

    /**
     * Files go base64-encoded in the request body.
     */
    private const MAX_INLINE_BYTES = 19 * 1024 * 1024;

    /**
     * Hosts that may serve a model without a key: this machine, and the
     * local development domains.
     */
    private const KEYLESS_HOSTS = '/^(localhost|127\.0\.0\.1|\[::1\]|::1)$|\.(localhost|test)$/i';

    public function driver(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        $label = $this->setting('label');

        return is_string($label) && trim($label) !== '' ? trim($label) : 'OpenAI';
    }

    public function capabilities(): AiCapabilities
    {
        $structured = $this->setting('structured_output');

        return new AiCapabilities(
            pdfInput: (bool) $this->setting('pdf_input', false),
            imageInput: (bool) $this->setting('image_input', false),
            structuredOutput: in_array($structured, [AiCapabilities::STRUCTURED_JSON_SCHEMA, AiCapabilities::STRUCTURED_JSON_OBJECT, AiCapabilities::STRUCTURED_NONE], true)
                ? $structured
                : AiCapabilities::STRUCTURED_JSON_SCHEMA,
            maxOutputTokens: max(256, (int) $this->setting('max_output_tokens', 16384)),
            maxInlineBytes: self::MAX_INLINE_BYTES,
        );
    }

    /**
     * A model and an endpoint are set, and a key too unless the endpoint
     * is on this machine or a `*.test` host.
     */
    public function isConfigured(): bool
    {
        $baseUrl = trim((string) $this->setting('base_url'));

        if (trim((string) $this->setting('model')) === '' || $baseUrl === '') {
            return false;
        }

        return trim($this->key()) !== '' || preg_match(self::KEYLESS_HOSTS, (string) parse_url($baseUrl, PHP_URL_HOST)) === 1;
    }

    protected function settingsKey(): string
    {
        return 'services.openai_compatible';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(AiRequest $request): array
    {
        $capabilities = $this->capabilities();
        $structured = $request->jsonSchema !== null ? $capabilities->structuredOutput : null;
        $system = $request->system;

        if ($request->jsonSchema !== null && $structured !== AiCapabilities::STRUCTURED_JSON_SCHEMA) {
            $system = trim(($system ?? '')."\n\nJawab hanya dengan satu objek JSON (tanpa teks lain dan tanpa blok kode) yang mengikuti JSON Schema ini:\n"
                .json_encode($request->jsonSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $messages = array_map($this->message(...), $request->messages);

        if ($system !== null && $system !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
        }

        return array_filter([
            'messages' => $messages,
            $this->tokenParameter() => $request->maxOutputTokens !== null ? min($capabilities->maxOutputTokens, $request->maxOutputTokens) : null,
            'temperature' => (bool) $this->setting('send_temperature', true) ? $request->temperature : null,
            'response_format' => match ($structured) {
                AiCapabilities::STRUCTURED_JSON_SCHEMA => ['type' => 'json_schema', 'json_schema' => ['name' => self::SCHEMA_NAME, 'strict' => true, 'schema' => $request->jsonSchema]],
                AiCapabilities::STRUCTURED_JSON_OBJECT => ['type' => 'json_object'],
                default => null,
            },
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
        return rtrim((string) $this->setting('base_url'), '/').'/chat/completions';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $key = trim($this->key());

        return $key !== '' ? ['Authorization' => 'Bearer '.$key] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function outputLimit(array $payload): ?int
    {
        $limit = $payload[$this->tokenParameter()] ?? null;

        return is_int($limit) ? $limit : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withOutputLimit(array $payload, int $limit): array
    {
        $payload[$this->tokenParameter()] = $limit;

        return $payload;
    }

    /**
     * The settings that shape every request, so changing one tries the
     * model again at once.
     */
    protected function reasoningSetting(): mixed
    {
        return [$this->tokenParameter(), $this->capabilities()->structuredOutput];
    }

    /**
     * @return list<string>
     */
    public function settingNames(string $cause): array
    {
        return $cause === NotebookAiException::CAUSE_KEY
            ? ['OPENAI_COMPATIBLE_API_KEY']
            : ['OPENAI_COMPATIBLE_MODEL', 'OPENAI_COMPATIBLE_FALLBACK_MODEL', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_TOKEN_PARAM', 'OPENAI_COMPATIBLE_STRUCTURED_OUTPUT'];
    }

    public function freeTier(): bool
    {
        return (bool) $this->setting('free_tier', false);
    }

    /**
     * The error text in OpenAI's shape (`error.message`), or a bare
     * `error` or `detail` string as other servers send it.
     */
    protected function errorMessage(Response $response): mixed
    {
        $error = $response->json('error');

        return match (true) {
            is_array($error) => $error['message'] ?? null,
            is_string($error) => $error,
            default => $response->json('detail'),
        };
    }

    /**
     * The key (401/403), the model settings (404, `model_not_found`, or a
     * 400 saying the model id is unknown), or nothing (null). A 400 about
     * this one request (a prompt over the context length, a parameter the
     * model does not take) is not about the settings, even when it names
     * the model, so it fails that request alone and is not remembered.
     *
     * @return NotebookAiException::CAUSE_*|null
     */
    protected function configurationProblem(Response $response): ?string
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return NotebookAiException::CAUSE_KEY;
        }

        if ($status === 404 || $response->json('error.code') === 'model_not_found') {
            return NotebookAiException::CAUSE_MODEL;
        }

        $message = $this->errorMessage($response);

        if ($status !== 400 || ! is_string($message) || $this->isRequestProblem($response, $message)) {
            return null;
        }

        return preg_match(self::MODEL_ERROR_MESSAGE, $message) === 1 ? NotebookAiException::CAUSE_MODEL : null;
    }

    /**
     * A 400 about the request rather than the settings: the prompt and
     * output limit over the model's context (`context_length_exceeded`), or
     * a parameter or value the model does not take.
     */
    private function isRequestProblem(Response $response, string $message): bool
    {
        $code = $response->json('error.code');

        if (is_string($code) && in_array($code, ['context_length_exceeded', 'unsupported_parameter', 'unsupported_value', 'string_above_max_length'], true)) {
            return true;
        }

        return preg_match('/context (length|window)|maximum context|too many tokens|\btokens?\b|unsupported (value|parameter)|does not support|not supported with this model/i', $message) === 1;
    }

    /**
     * The first choice's text, why it stopped (`finish_reason`), and what
     * it spent (`usage`). A refusal (`message.refusal`, or
     * `content_filter`) without text is blocked; an answer cut at its
     * limit (`length`) may have no text yet; any other empty answer fails.
     */
    protected function parse(Response $response, string $model): AiResult
    {
        $choice = $response->json('choices.0');
        $choice = is_array($choice) ? $choice : [];
        $finishReason = is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null;
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $text = trim($this->text($message['content'] ?? null));
        $usage = $response->json('usage');
        $truncated = $finishReason === 'length';
        $blocked = $finishReason === 'content_filter' || (is_string($message['refusal'] ?? null) && trim($message['refusal']) !== '');

        if ($text === '' && ($blocked || ! $truncated)) {
            throw $blocked ? NotebookAiException::blocked() : NotebookAiException::emptyAnswer();
        }

        $reasoning = is_array($usage) ? ($usage['completion_tokens_details']['reasoning_tokens'] ?? null) : null;

        return new AiResult(
            text: $text,
            finishReason: $finishReason,
            model: $model,
            truncated: $truncated,
            thoughtsTokens: is_int($reasoning) ? $reasoning : null,
            outputTokens: is_array($usage) && is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : null,
            inputTokens: is_array($usage) && is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : null,
            stop: match (true) {
                $blocked => AiResult::STOP_BLOCKED,
                $truncated => AiResult::STOP_LENGTH,
                default => AiResult::STOP_END,
            },
        );
    }

    private function tokenParameter(): string
    {
        return $this->setting('token_param') === 'max_tokens' ? 'max_tokens' : 'max_completion_tokens';
    }

    /**
     * Message content as a string, or the text of its parts.
     */
    private function text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        return implode('', array_map(
            static fn (mixed $part): string => is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : '',
            $content
        ));
    }

    /**
     * A message in Chat Completions' shape: plain text as a string (which
     * every server takes), with files as content parts.
     *
     * @param  array{role: 'user'|'assistant', parts: list<array<string, string>>}  $message
     * @return OpenAiMessage
     */
    private function message(array $message): array
    {
        $role = $message['role'] === 'assistant' ? 'assistant' : 'user';
        $files = array_filter($message['parts'], static fn (array $part): bool => ($part['type'] ?? 'text') === 'file');

        if ($files === [] || $role !== 'user') {
            return [
                'role' => $role,
                'content' => implode("\n\n", array_map(
                    static fn (array $part): string => $part['text'] ?? '',
                    array_filter($message['parts'], static fn (array $part): bool => ($part['type'] ?? 'text') !== 'file')
                )),
            ];
        }

        return [
            'role' => $role,
            'content' => array_map(static fn (array $part): array => match (true) {
                ($part['type'] ?? 'text') !== 'file' => ['type' => 'text', 'text' => $part['text']],
                str_starts_with(strtolower($part['mime']), 'image/') => ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$part['mime'].';base64,'.$part['base64']]],
                default => ['type' => 'file', 'file' => ['filename' => 'dokumen.pdf', 'file_data' => 'data:application/pdf;base64,'.$part['base64']]],
            }, $message['parts']),
        ];
    }
}
