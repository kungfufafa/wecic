<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotebookAiSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotebookAiSetting>
 */
class NotebookAiSettingFactory extends Factory
{
    protected $model = NotebookAiSetting::class;

    /**
     * Define the model's default state: nothing of the student's own, so
     * the server's settings hold.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'provider' => null,
            'gemini_api_key' => null,
            'anthropic_api_key' => null,
            'openai_api_key' => null,
            'openai_base_url' => null,
            'openai_model' => null,
        ];
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }

    public function gemini(string $key = 'student-gemini-key-1234'): static
    {
        return $this->state(fn (): array => ['provider' => 'gemini', 'gemini_api_key' => $key]);
    }

    public function anthropic(string $key = 'sk-ant-student-key-5678'): static
    {
        return $this->state(fn (): array => ['provider' => 'anthropic', 'anthropic_api_key' => $key]);
    }

    public function openAi(string $key = 'sk-student-openai-9012', ?string $baseUrl = 'https://llm.example.com/v1', ?string $model = 'student-model'): static
    {
        return $this->state(fn (): array => [
            'provider' => 'openai',
            'openai_api_key' => $key,
            'openai_base_url' => $baseUrl,
            'openai_model' => $model,
        ]);
    }
}
