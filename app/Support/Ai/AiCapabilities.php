<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * What a notebook model can take and give: which files it reads, how it
 * follows a JSON schema, and its limits.
 */
final readonly class AiCapabilities
{
    /**
     * The model follows a JSON schema sent with the request.
     */
    public const STRUCTURED_JSON_SCHEMA = 'json_schema';

    /**
     * The model answers in JSON but takes the schema only in the prompt.
     */
    public const STRUCTURED_JSON_OBJECT = 'json_object';

    /**
     * No structured output: the JSON is read from plain text.
     */
    public const STRUCTURED_NONE = 'none';

    /**
     * @param  self::STRUCTURED_*  $structuredOutput
     * @param  int  $maxOutputTokens  The largest output limit the model takes.
     * @param  int  $maxInlineBytes  The largest file the model reads inline.
     * @param  int|null  $maxImageBytes  The largest image it reads, when smaller than $maxInlineBytes.
     */
    public function __construct(
        public bool $pdfInput,
        public bool $imageInput,
        public string $structuredOutput,
        public int $maxOutputTokens,
        private int $maxInlineBytes,
        private ?int $maxImageBytes = null,
    ) {}

    /**
     * The largest file of this type the model reads inline.
     */
    public function maxBytesFor(string $mime): int
    {
        return str_starts_with(strtolower($mime), 'image/') && $this->maxImageBytes !== null
            ? min($this->maxImageBytes, $this->maxInlineBytes)
            : $this->maxInlineBytes;
    }
}
