<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PortalArchivedFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PortalArchivedFile>
 */
class PortalArchivedFileFactory extends Factory
{
    protected $model = PortalArchivedFile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = 'modul-pertemuan-'.$this->faker->numberBetween(1, 14).'.pdf';

        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'course_key' => (string) $this->faker->numberBetween(1000, 9999),
            'course_name' => 'Basis Data',
            'meeting_id' => (string) $this->faker->numberBetween(100, 999),
            'meeting_number' => $this->faker->numberBetween(1, 14),
            'kind' => 'modul',
            'title' => 'Normalisasi Data',
            'source_url' => 'https://my.cic.ac.id/portal/files/modul/'.$this->faker->unique()->uuid().'.pdf',
            'filename' => $filename,
            'mime' => 'application/pdf',
            'size' => 2048,
            'sha256' => $this->faker->sha256(),
            'archived_at' => now(),
        ];
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }
}
