<?php

namespace Modules\Products\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Products\Domain\Enums\BillingPeriod;
use Modules\Products\Domain\Enums\ProductStatus;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Basic', 'Pro', 'Enterprise']);

        return [
            'product_id' => Product::factory(),
            'name' => ['ar' => $name, 'en' => $name],
            'price' => fake()->randomFloat(2, 10, 500),
            'currency' => 'USD',
            'billing_period' => BillingPeriod::Monthly,
            'features' => [
                ['ar' => fake()->words(2, true), 'en' => fake()->words(2, true)],
            ],
            'is_popular' => false,
            'status' => ProductStatus::Active,
            'sort_order' => 0,
        ];
    }
}
