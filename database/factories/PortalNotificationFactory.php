<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PortalNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PortalNotification>
 */
class PortalNotificationFactory extends Factory
{
    protected $model = PortalNotification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'type' => 'announcement_new',
            'dataset' => 'announcements',
            'record_key' => $this->faker->sha1(),
            'dedupe_key' => $this->faker->unique()->sha1(),
            'title' => 'Jadwal Ujian Tengah Semester Ganjil',
            'body' => 'Ujian berlangsung mulai pekan depan.',
            'url' => '/announcement',
            'urgency' => 'info',
            'created_at' => now(),
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }
}
