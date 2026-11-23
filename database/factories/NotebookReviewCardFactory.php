<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notebook;
use App\Models\NotebookReviewCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotebookReviewCard>
 */
class NotebookReviewCardFactory extends Factory
{
    protected $model = NotebookReviewCard::class;

    /**
     * A new card, due now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notebook_id' => Notebook::factory(),
            'notebook_message_id' => null,
            'item_index' => null,
            'front' => fake()->sentence().'?',
            'back' => fake()->sentence(),
            'citations' => [],
            'topic' => null,
            'meeting_number' => null,
            'due_at' => now(),
            'interval_days' => 0,
            'ease' => 2.5,
            'reps' => 0,
            'lapses' => 0,
            'first_reviewed_at' => null,
            'last_reviewed_at' => null,
            'suspended' => false,
        ];
    }

    /**
     * A card already learned, reviewed `$interval` days ago and due now.
     */
    public function learned(int $interval = 3): static
    {
        return $this->state(fn (): array => [
            'interval_days' => $interval,
            'reps' => 2,
            'first_reviewed_at' => now()->subDays($interval + 1),
            'last_reviewed_at' => now()->subDays($interval),
            'due_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['suspended' => true]);
    }
}
