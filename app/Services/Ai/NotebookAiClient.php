<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\NotebookAiException;
use App\Support\Ai\AiCapabilities;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;

/**
 * The notebook's model, whichever provider serves it
 * (`notebook.ai.provider`; AppServiceProvider binds the driver).
 */
interface NotebookAiClient
{
    /**
     * @throws NotebookAiException When the model could not answer.
     */
    public function generate(AiRequest $request): AiResult;

    /**
     * Whether the settings a request needs (key, model, endpoint) are set.
     */
    public function isConfigured(): bool;

    /**
     * Whether a request may be sent: configured, and neither the key nor
     * the model settings were rejected lately.
     */
    public function isUsable(): bool;

    /**
     * What the API rejected lately with the current settings: the key
     * (`NotebookAiException::CAUSE_KEY`) or the model settings
     * (`NotebookAiException::CAUSE_MODEL`), or null.
     *
     * @return NotebookAiException::CAUSE_*|null
     */
    public function misconfiguration(): ?string;

    /**
     * The driver's id, as `notebook.ai.provider` names it (e.g. `gemini`).
     */
    public function driver(): string;

    /**
     * The provider's name as students read it (e.g. `Google Gemini`).
     */
    public function label(): string;

    /**
     * Who receives what is sent: the driver, with the endpoint's host when
     * it is not the provider's own API (a gateway, a proxy, another
     * company's server), e.g. `gemini`, `anthropic@proxy.example.com` or
     * `openai@api.openai.com`. A consent names it, so a change of recipient
     * asks the student again.
     */
    public function recipient(): string;

    public function capabilities(): AiCapabilities;

    /**
     * Whether the server uses the provider's free tier, under which the
     * provider may keep what is sent and use it to improve its products.
     * Students are told so before they consent.
     */
    public function freeTier(): bool;

    /**
     * The environment settings to check when the API rejected the key or
     * the model settings (e.g. `GEMINI_API_KEY`), for the log and the
     * notebook banners.
     *
     * @param  NotebookAiException::CAUSE_*  $cause
     * @return list<string>
     */
    public function settingNames(string $cause): array;
}
