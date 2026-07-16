<?php

namespace Modules\DeviceSubscriptions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * @extends Factory<DeviceSubscription>
 */
class DeviceSubscriptionFactory extends Factory
{
    protected $model = DeviceSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_name' => 'smart_agent',
            'device_id' => fake()->uuid(),
            'full_name' => fake()->name(),
            'phone' => fake()->numerify('09########'),
            'is_verified' => false,
            'expires_at' => null,
            'plan_id' => null,
            'fcm_token' => fake()->sha256(),
            'stars' => null,
            'comment' => null,
        ];
    }

    /** An active, verified device on the yearly plan. */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_verified' => true,
            'plan_id' => 'yearly',
            'expires_at' => Carbon::now()->addMonths(12),
        ]);
    }

    /** Verified but past its expiry date. */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_verified' => true,
            'plan_id' => 'half_year',
            'expires_at' => Carbon::now()->subDay(),
        ]);
    }
}
