<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * The model's answer: its text (thoughts left out), why it stopped, and
 * what it spent.
 *
 * `finishReason` is the provider's own value (Gemini `STOP`, `MAX_TOKENS`,
 * `RECITATION`…); `stop` is the same in a provider-neutral form.
 */
final readonly class AiResult
{
    /**
     * The model finished its answer.
     */
    public const STOP_END = 'end';

    /**
     * The answer stopped at its output limit.
     */
    public const STOP_LENGTH = 'length';

    /**
     * The model withheld (part of) the answer: safety, recitation, refusal.
     */
    public const STOP_BLOCKED = 'blocked';

    /**
     * @param  self::STOP_*  $stop
     */
    public function __construct(
        public string $text,
        public ?string $finishReason,
        public string $model,
        public bool $truncated,
        public ?int $thoughtsTokens = null,
        public ?int $outputTokens = null,
        public ?int $inputTokens = null,
        public string $stop = self::STOP_END,
    ) {}
}
