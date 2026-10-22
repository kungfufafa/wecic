<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notebook;
use App\Models\NotebookMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotebookMessage>
 */
class NotebookMessageFactory extends Factory
{
    protected $model = NotebookMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notebook_id' => Notebook::factory(),
            'role' => NotebookMessage::ROLE_ASSISTANT,
            'kind' => 'chat',
            'content' => 'Normalisasi mengurangi redundansi data [1].',
            'citations' => [],
            'pinned' => false,
        ];
    }
}
