<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotebookAiConsent;

/**
 * Whether a student agreed to send Notebook content to Google Gemini
 * (Apple guideline 5.1.2(i)). The Gemini API runs on its free tier, so
 * Google may use what is sent to improve its products; the student is told
 * so before agreeing. Until they agree, and after they withdraw, nothing of
 * theirs reaches the model: questions and studio tools are refused with
 * `ai_consent_required`, and a source that needs the model waits.
 */
class NotebookAiConsents
{
    public const REQUIRED_REASON = 'ai_consent_required';

    public const REQUIRED_MESSAGE = 'Fitur AI Notebook mengirim pertanyaan dan isi sumbermu (teks, PDF, gambar) ke Google Gemini. Setujui dulu pengiriman data ini sebelum memakai AI.';

    public function granted(string $ownerHash): bool
    {
        return NotebookAiConsent::query()->forOwner($ownerHash)->whereNotNull('granted_at')->exists();
    }

    /**
     * Two requests at once (a double tap, or the web and the app together)
     * both end with the one consent row: the insert that loses the race
     * reads the row the other stored.
     */
    public function grant(string $ownerHash): NotebookAiConsent
    {
        $consent = NotebookAiConsent::query()->createOrFirst(['owner_hash' => $ownerHash], ['granted_at' => now(), 'withdrawn_at' => null]);

        if ($consent->granted_at === null) {
            $consent->forceFill(['granted_at' => now(), 'withdrawn_at' => null])->save();
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
     * @return array{granted: bool, granted_at: string|null, withdrawn_at: string|null, provider: string}
     */
    public function state(string $ownerHash): array
    {
        $consent = NotebookAiConsent::query()->forOwner($ownerHash)->first();

        return [
            'granted' => $consent?->granted_at !== null,
            'granted_at' => $consent?->granted_at?->toISOString(),
            'withdrawn_at' => $consent?->withdrawn_at?->toISOString(),
            'provider' => 'Google Gemini',
        ];
    }
}
