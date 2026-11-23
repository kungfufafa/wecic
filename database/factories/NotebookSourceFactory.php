<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notebook;
use App\Models\NotebookSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotebookSource>
 */
class NotebookSourceFactory extends Factory
{
    protected $model = NotebookSource::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notebook_id' => Notebook::factory(),
            'kind' => NotebookSource::KIND_TEXT,
            'title' => 'Catatan normalisasi',
            'mime' => 'text/plain',
            'status' => NotebookSource::STATUS_READY,
            'char_count' => 0,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => NotebookSource::STATUS_PENDING]);
    }

    /**
     * A source read with its guide: a summary, topics and understanding questions.
     *
     * @param  list<string>|null  $questions
     */
    public function withGuide(?array $questions = null): static
    {
        return $this->state(fn (): array => [
            'summary' => 'Sumber ini membahas normalisasi basis data.',
            'topics' => ['Normalisasi', 'Redundansi', 'Bentuk normal'],
            'suggested_questions' => $questions ?? [
                'Mengapa normalisasi mengurangi redundansi?',
                'Apa beda 2NF dan 3NF?',
                'Bagaimana contoh tabel yang belum normal?',
            ],
        ]);
    }
}
