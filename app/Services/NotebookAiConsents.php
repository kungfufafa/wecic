<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotebookAiConsent;
use App\Services\Ai\NotebookAiProviders;

/**
 * Whether a student agreed to send Notebook content to the AI provider(s)
 * the server uses (Apple guideline 5.1.2(i)). A consent names the providers
 * it was given for (NotebookAiProviders::consentKey()); once the server
 * sends content to another recipient, or puts one on a free tier the
 * student was not told about, the student is asked again. A consent stored
 * before consents named a provider was given for Google Gemini's free
 * tier. When a
 * provider runs on its free tier, the student is told before agreeing that
 * the provider may use what is sent to improve its products. Until they
 * agree, and after they withdraw, nothing of theirs reaches the model:
 * questions and studio tools are refused with `ai_consent_required`, and a
 * source that needs the model waits.
 */
class NotebookAiConsents
{
    public const REQUIRED_REASON = 'ai_consent_required';

    public function __construct(private readonly NotebookAiProviders $providers) {}

    /**
     * Why an AI action was refused, naming the provider(s) the content
     * would go to.
     */
    public function requiredMessage(): string
    {
        return 'Fitur AI Notebook mengirim pertanyaan dan isi sumbermu (teks, PDF, gambar) ke '.$this->providers->label().'. Setujui dulu pengiriman data ini sebelum memakai AI.';
    }

    public function granted(string $ownerHash): bool
    {
        $consent = NotebookAiConsent::query()->forOwner($ownerHash)->whereNotNull('granted_at')->first();

        return $consent !== null && $this->coversCurrentProviders($consent);
    }

    /**
     * Two requests at once (a double tap, or the web and the app together)
     * both end with the one consent row: the insert that loses the race
     * reads the row the other stored. A consent for other providers is
     * given anew, for the current ones.
     */
    public function grant(string $ownerHash): NotebookAiConsent
    {
        $provider = $this->providers->consentKey();
        $consent = NotebookAiConsent::query()->createOrFirst(['owner_hash' => $ownerHash], ['provider' => $provider, 'granted_at' => now(), 'withdrawn_at' => null]);

        if ($consent->granted_at === null || ! $this->coversCurrentProviders($consent)) {
            $consent->forceFill(['provider' => $provider, 'granted_at' => now(), 'withdrawn_at' => null])->save();
        }

        return $consent;
    }

    public function withdraw(string $ownerHash): NotebookAiConsent
    {
        $consent = NotebookAiConsent::query()->firstOrNew(['owner_hash' => $ownerHash]);

        if ($consent->exists && $consent->granted_at !== null) {
            $consent->forceFill(['granted_at' => null, 'withdrawn_at' => now()])->save();
        }

        return $consent;
    }

    public function forget(string $ownerHash): int
    {
        return NotebookAiConsent::query()->forOwner($ownerHash)->delete();
    }

    /**
     * What a consent given now is for (NotebookAiProviders::consentKey()).
     * A client sends back the key it showed the student, so a provider
     * switched meanwhile is not agreed to unseen.
     */
    public function currentKey(): string
    {
        return $this->providers->consentKey();
    }

    /**
     * `granted` is false for a consent that does not cover the current
     * providers (NotebookAiProviders::covers()); `provider`, `free_tier`,
     * `processors` (each provider and whether it runs on its free tier) and
     * `consent_key` describe the current ones, which a new consent would be
     * given for. `past_processors` names providers that received Notebook
     * content before and no longer do.
     *
     * @return array{granted: bool, granted_at: string|null, withdrawn_at: string|null, provider: string, free_tier: bool, consent_key: string, processors: list<array{label: string, free_tier: bool}>, past_processors: list<string>}
     */
    public function state(string $ownerHash): array
    {
        $consent = NotebookAiConsent::query()->forOwner($ownerHash)->first();
        $grantedAt = $consent !== null && $consent->granted_at !== null && $this->coversCurrentProviders($consent) ? $consent->granted_at : null;

        return [
            'granted' => $grantedAt !== null,
            'granted_at' => $grantedAt?->toISOString(),
            'withdrawn_at' => $consent?->withdrawn_at?->toISOString(),
            'provider' => $this->providers->label(),
            'free_tier' => $this->providers->freeTier(),
            'consent_key' => $this->providers->consentKey(),
            'processors' => $this->providers->describe()['processors'],
            'past_processors' => $this->providers->pastRecipients(),
        ];
    }

    private function coversCurrentProviders(NotebookAiConsent $consent): bool
    {
        return $this->providers->covers($consent->provider);
    }
}
