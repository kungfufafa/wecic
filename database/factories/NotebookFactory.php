<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notebook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Notebook>
 */
class NotebookFactory extends Factory
{
    protected $model = Notebook::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'title' => 'Belajar Basis Data',
            'kind' => Notebook::KIND_CUSTOM,
            'course_key' => null,
            'course_name' => null,
        ];
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }

    /**
     * The notebook MyCIC makes for one class of the student's class list.
     */
    public function course(string $courseKey = '8810'): static
    {
        return $this->state(fn (): array => [
            'kind' => Notebook::KIND_COURSE,
            'title' => 'Basis Data',
            'course_key' => $courseKey,
            'course_name' => 'Basis Data',
            'course_code' => 'UC01024011',
            'lecturer_name' => 'Dosen A',
            'class_params' => ['id' => '2265', 'dosen_id' => '5240', 'course_id' => $courseKey],
        ]);
    }
}
