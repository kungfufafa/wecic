<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PortalArchiveVersion;
use App\Services\PortalDataCache;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PortalArchiveVersion>
 */
class PortalArchiveVersionFactory extends Factory
{
    protected $model = PortalArchiveVersion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = ['found' => true, 'entries' => [['name' => 'Seminar Nasional', 'status' => 'valid']]];

        return [
            'owner_hash' => 'student_'.$this->faker->regexify('[a-f0-9]{16}'),
            'dataset' => 'skkm',
            'params_hash' => PortalDataCache::paramsHash([]),
            'params' => [],
            'subject' => null,
            'content_hash' => $this->faker->unique()->sha256(),
            'payload' => $payload,
            'status' => PortalArchiveVersion::STATUS_TRUSTED,
            'findings' => null,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
            'seen_count' => 1,
            'trusted_at' => now()->subDay(),
            'resolved_at' => null,
        ];
    }

    public function forOwner(string $ownerHash): static
    {
        return $this->state(fn (): array => ['owner_hash' => $ownerHash]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): static
    {
        return $this->state(fn (): array => ['payload' => $payload]);
    }

    /**
     * @param  list<array{rule: string, label: string, message: string}>  $findings
     */
    public function suspect(array $findings = [['rule' => 'count', 'label' => 'Kegiatan SKKM', 'message' => 'Kegiatan SKKM berkurang dari 2 menjadi 1.']]): static
    {
        return $this->state(fn (): array => [
            'status' => PortalArchiveVersion::STATUS_SUSPECT,
            'findings' => $findings,
            'trusted_at' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
