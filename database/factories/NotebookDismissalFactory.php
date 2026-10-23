<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notebook;
use App\Models\NotebookDismissal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotebookDismissal>
 */
class NotebookDismissalFactory extends Factory
{
    protected $model = NotebookDismissal::class;

    /**
     * Define the model's default state: a course file removed from a course notebook.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notebook_id' => Notebook::factory()->course(),
            'kind' => NotebookDismissal::KIND_ARCHIVED_FILE,
            'reference' => 'https://my.cic.ac.id/portal/files/modul/'.$this->faker->unique()->numerify('modul-####').'.pdf',
        ];
    }

    /**
     * A meeting removed from a course notebook.
     */
    public function meeting(?string $meetingId = null): static
    {
        return $this->state(fn (): array => [
            'kind' => NotebookDismissal::KIND_MEETING,
            'reference' => $meetingId ?? $this->faker->unique()->numerify('4##'),
        ]);
    }
}
