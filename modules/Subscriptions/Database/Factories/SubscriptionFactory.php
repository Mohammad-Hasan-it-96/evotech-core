<?php

namespace Modules\Subscriptions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Companies\Domain\Models\Company;
use Modules\Products\Domain\Models\Plan;
use Modules\Subscriptions\Domain\Enums\IdentifierType;
use Modules\Subscriptions\Domain\Enums\SubscriptionStatus;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plan_id' => Plan::factory(),
            'identifier_type' => IdentifierType::Domain,
            'identifier_value' => fake()->domainName(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
            'ends_at' => Carbon::now()->addDays(30),
            'auto_renew' => true,
            'price' => fake()->randomFloat(2, 10, 200),
            'currency' => 'USD',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Active,
            'ends_at' => Carbon::now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Cancelled,
            'auto_renew' => false,
        ]);
    }
}
