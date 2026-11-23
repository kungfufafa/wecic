<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Support\Ai\AiCapabilities;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Closure;

/**
 * The notebook's model, chosen on first use instead of when a service is
 * built. Which driver answers depends on the student's own settings
 * (NotebookAiOwnerSettings, a database read), and many controllers are
 * built with a notebook service they never call, so constructing one must
 * not read anything. A queue job is also resolved before its middleware
 * names the student (UseNotebookOwnerAiSettings); deciding here, at the
 * first call, picks that student's provider.
 */
class LazyNotebookAiClient implements NotebookAiClient
{
    private ?NotebookAiClient $client = null;

    /**
     * @param  Closure(): NotebookAiClient  $resolve
     */
    public function __construct(private readonly Closure $resolve) {}

    /**
     * The driver behind this client, chosen now if it was not yet.
     */
    public function resolved(): NotebookAiClient
    {
        return $this->client ??= ($this->resolve)();
    }

    public function generate(AiRequest $request): AiResult
    {
        return $this->resolved()->generate($request);
    }

    public function isConfigured(): bool
    {
        return $this->resolved()->isConfigured();
    }

    public function isUsable(): bool
    {
        return $this->resolved()->isUsable();
    }

    public function misconfiguration(): ?string
    {
        return $this->resolved()->misconfiguration();
    }

    public function driver(): string
    {
        return $this->resolved()->driver();
    }

    public function label(): string
    {
        return $this->resolved()->label();
    }

    public function recipient(): string
    {
        return $this->resolved()->recipient();
    }

    public function capabilities(): AiCapabilities
    {
        return $this->resolved()->capabilities();
    }

    public function freeTier(): bool
    {
        return $this->resolved()->freeTier();
    }

    public function settingNames(string $cause): array
    {
        return $this->resolved()->settingNames($cause);
    }
}
