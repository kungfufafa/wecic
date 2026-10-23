<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PortalMobileSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PortalMobileSession>
 */
class PortalMobileSessionFactory extends Factory
{
    protected $model = PortalMobileSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => $this->faker->unique()->regexify('[A-Za-z0-9]{40}'),
            'device_name' => 'Pixel 8',
        ];
    }

    /**
     * With a token last used at the given moment.
     */
    public function withTokenUsedAt(\DateTimeInterface $lastUsedAt): static
    {
        return $this->afterCreating(function (PortalMobileSession $mobileSession) use ($lastUsedAt): void {
            $mobileSession->createToken('mobile')->accessToken->forceFill(['last_used_at' => $lastUsedAt])->save();
        });
    }
}
