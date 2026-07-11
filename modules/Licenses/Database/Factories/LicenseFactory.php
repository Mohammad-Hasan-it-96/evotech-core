<?php

namespace Modules\Licenses\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Companies\Domain\Models\Company;
use Modules\Licenses\Domain\Enums\LicenseStatus;
use Modules\Licenses\Domain\Models\License;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * @extends Factory<License>
 */
class LicenseFactory extends Factory
{
    protected $model = License::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'company_id' => Company::factory(),
            'key' => 'EVO-'.strtoupper(fake()->unique()->bothify('????-????-????')),
            'status' => LicenseStatus::Active,
            'max_activations' => 1,
            'expires_at' => Carbon::now()->addDays(30),
            'issued_at' => Carbon::now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LicenseStatus::Active,
            'expires_at' => Carbon::now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LicenseStatus::Revoked,
            'revoked_at' => Carbon::now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LicenseStatus::Suspended,
        ]);
    }
}
