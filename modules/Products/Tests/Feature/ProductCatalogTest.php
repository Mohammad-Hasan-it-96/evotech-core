<?php

namespace Modules\Products\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Database\Seeders\ProductCatalogSeeder;
use Modules\Products\Domain\Enums\ProductStatus;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_is_public_and_lists_active_products_with_plans(): void
    {
        $product = Product::factory()->create(['slug' => 'demo']);
        Plan::factory()->count(2)->create(['product_id' => $product->id]);

        // No authentication — the catalog is public.
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'slug', 'name', 'plans' => [['id', 'price', 'billing_period']]],
                ],
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'demo')
            ->assertJsonCount(2, 'data.0.plans');
    }

    public function test_show_returns_a_single_product_by_slug(): void
    {
        $product = Product::factory()->create(['slug' => 'restaurant']);
        Plan::factory()->create(['product_id' => $product->id]);

        $this->getJson('/api/v1/products/restaurant')
            ->assertOk()
            ->assertJsonPath('data.slug', 'restaurant')
            ->assertJsonCount(1, 'data.plans');
    }

    public function test_inactive_products_are_excluded(): void
    {
        Product::factory()->create(['status' => ProductStatus::Inactive]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_reference_seeder_is_idempotent(): void
    {
        $this->seed(ProductCatalogSeeder::class);
        $this->seed(ProductCatalogSeeder::class);

        $this->assertSame(5, Product::count());
        $this->assertSame(10, Plan::count());
        $this->assertDatabaseHas('products', ['slug' => 'smart-delegate']);
    }
}
