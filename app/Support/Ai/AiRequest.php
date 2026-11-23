<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * One request to the notebook's model, in a provider-neutral shape: each
 * driver (NotebookAiClient) turns it into its own payload.
 *
 * `temperature` is a hint: a driver whose model rejects sampling settings
 * drops it. `jsonSchema` is lowercase JSON Schema with an object root; a
 * driver that needs another dialect (Gemini's uppercase types) maps it.
 *
 * @phpstan-type AiTextPart array{type: 'text', text: string}
 * @phpstan-type AiFilePart array{type: 'file', mime: string, base64: string}
 * @phpstan-type AiPart AiTextPart|AiFilePart
 * @phpstan-type AiMessage array{role: 'user'|'assistant', parts: list<AiPart>}
 */
final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  int|null  $maxOutputTokens  The answer's output limit; null leaves the model's default.
     * @param  array<string, mixed>|null  $jsonSchema  Lowercase JSON Schema the answer must follow, as JSON.
     * @param  int|null  $budgetSeconds  Wall-clock budget for every attempt together; requestBudgetSeconds() by default.
     * @param  int|null  $attemptTimeoutSeconds  Longest one attempt may take; the driver's `timeout` setting by default.
     *                                           A queued reading passes its whole budget, so one long generation can finish.
     * @param  bool  $keepBlockedText  Take the text a model wrote before it declined to go on (marked
     *                                 AiResult::STOP_BLOCKED) instead of failing as blocked: a
     *                                 transcription keeps what it has, while an answer cut short by a
     *                                 refusal must not pass for a whole one.
     */
    public function __construct(
        public array $messages,
        public ?string $system = null,
        public ?int $maxOutputTokens = null,
        public int|float|null $temperature = null,
        public ?array $jsonSchema = null,
        public ?int $budgetSeconds = null,
        public ?int $attemptTimeoutSeconds = null,
        public bool $keepBlockedText = false,
    ) {}

    /**
     * @return AiTextPart
     */
    public static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * @return AiFilePart
     */
    public static function file(string $mime, string $base64): array
    {
        return ['type' => 'file', 'mime' => $mime, 'base64' => $base64];
    }

    /**
     * @param  list<AiPart>  $parts
     * @return AiMessage
     */
    public static function user(array $parts): array
    {
        return ['role' => 'user', 'parts' => $parts];
    }

    /**
     * @return AiMessage
     */
    public static function assistant(string $text): array
    {
        return ['role' => 'assistant', 'parts' => [self::text($text)]];
    }

    /**
     * Seconds one web request may spend on every attempt together:
     * `notebook.ai.request_budget`, else the older `services.gemini.request_budget`.
     */
    public static function requestBudgetSeconds(): int
    {
        return (int) (config('notebook.ai.request_budget') ?? config('services.gemini.request_budget') ?? 50);
    }

    /**
     * Seconds a queued reading (PDF or image transcription) may spend:
     * `notebook.ai.ingest_budget`, else the older `services.gemini.ingest_budget`.
     */
    public static function ingestBudgetSeconds(): int
    {
        return (int) (config('notebook.ai.ingest_budget') ?? config('services.gemini.ingest_budget') ?? 240);
    }
}
