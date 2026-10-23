<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PortalPushToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PortalPushToken>
 */
class PortalPushTokenFactory extends Factory
{
    protected $model = PortalPushToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'token' => 'ExponentPushToken['.$this->faker->unique()->regexify('[A-Za-z0-9]{22}').']',
            'platform' => 'android',
            'portal_mobile_session_id' => null,
        ];
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }
}
