<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotebookAiConsent;
use App\Services\Ai\NotebookAiProviders;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotebookAiConsent>
 */
class NotebookAiConsentFactory extends Factory
{
    protected $model = NotebookAiConsent::class;

    /**
     * Define the model's default state: a consent for the providers the
     * configuration names when it is made.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'provider' => app(NotebookAiProviders::class)->consentKey(),
            'granted_at' => now(),
            'withdrawn_at' => null,
        ];
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }

    /**
     * A consent for another provider set (NotebookAiProviders::consentKey()).
     */
    public function forProvider(?string $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }

    /**
     * A consent stored before consents named their provider (Google
     * Gemini's free tier).
     */
    public function legacy(): static
    {
        return $this->forProvider(null);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (): array => ['granted_at' => null, 'withdrawn_at' => now()]);
    }
}
