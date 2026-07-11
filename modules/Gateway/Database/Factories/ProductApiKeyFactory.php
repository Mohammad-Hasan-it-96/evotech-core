<?php

namespace Modules\Gateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Gateway\Application\Support\ApiKeyGenerator;
use Modules\Gateway\Domain\Models\ProductApiKey;
use Modules\Products\Domain\Models\Product;

/**
 * @extends Factory<ProductApiKey>
 */
class ProductApiKeyFactory extends Factory
{
    protected $model = ProductApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A random, discarded plaintext — tests that need to authenticate mint via
        // the service (which returns the one-time plaintext) instead.
        $generated = app(ApiKeyGenerator::class)->generate();

        return [
            'product_id' => Product::factory(),
            'name' => ucfirst(fake()->word()).' key',
            'prefix' => $generated->prefix,
            'key_hash' => $generated->hash,
            'last_used_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => Carbon::now()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => Carbon::now()->subDay()]);
    }
}
