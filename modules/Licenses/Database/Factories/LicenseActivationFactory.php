<?php

namespace Modules\Licenses\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Subscriptions\Domain\Enums\IdentifierType;

/**
 * @extends Factory<LicenseActivation>
 */
class LicenseActivationFactory extends Factory
{
    protected $model = LicenseActivation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'license_id' => License::factory(),
            'identifier_type' => IdentifierType::Domain,
            'identifier' => fake()->unique()->domainName(),
            'name' => fake()->words(2, true),
            'activated_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ];
    }

    public function device(): static
    {
        return $this->state(fn (array $attributes): array => [
            'identifier_type' => IdentifierType::Device,
            'identifier' => fake()->unique()->uuid(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => Carbon::now(),
        ]);
    }
}
