<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotebookAiConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotebookAiConsent>
 */
class NotebookAiConsentFactory extends Factory
{
    protected $model = NotebookAiConsent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'granted_at' => now(),
            'withdrawn_at' => null,
        ];
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (): array => ['granted_at' => null, 'withdrawn_at' => now()]);
    }
}
