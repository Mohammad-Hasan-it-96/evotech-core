<?php

namespace Modules\Products\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Products\Domain\Enums\ProductStatus;
use Modules\Products\Domain\Models\Product;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => fake()->unique()->slug(2),
            'name' => ['ar' => $name, 'en' => $name],
            'tagline' => ['ar' => fake()->sentence(3), 'en' => fake()->sentence(3)],
            'description' => ['ar' => fake()->sentence(), 'en' => fake()->sentence()],
            'icon' => 'sparkles',
            'platforms' => ['Web'],
            'is_featured' => false,
            'status' => ProductStatus::Active,
            'sort_order' => 0,
        ];
    }
}
