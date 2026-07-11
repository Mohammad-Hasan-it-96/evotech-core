<?php

namespace Modules\Gateway\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Gateway\Domain\Models\ProductApiKey;
use Modules\Products\Domain\Models\Product;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class ProductApiKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    public function test_minting_requires_authentication(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/v1/products/{$product->slug}/api-keys", ['name' => 'POS'])
            ->assertUnauthorized();
    }

    public function test_staff_can_mint_a_key_and_plaintext_is_returned_once(): void
    {
        $this->actAsStaff();
        $product = Product::factory()->create();

        $response = $this->postJson("/api/v1/products/{$product->slug}/api-keys", [
            'name' => 'Front-of-house POS',
        ])->assertCreated();

        $plaintext = $response->json('data.key');
        $this->assertIsString($plaintext);
        $this->assertStringStartsWith('evo_', $plaintext);
        $response->assertJsonPath('data.name', 'Front-of-house POS');
        $response->assertJsonPath('data.is_active', true);

        // The plaintext is stored only as a hash, never in the clear.
        $key = ProductApiKey::query()->firstOrFail();
        $this->assertNotSame($plaintext, $key->key_hash);
        $this->assertSame(hash('sha256', $plaintext), $key->key_hash);

        // Listing never re-exposes the plaintext.
        $this->getJson("/api/v1/products/{$product->slug}/api-keys")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Front-of-house POS')
            ->assertJsonMissingPath('data.0.key');
    }

    public function test_keys_are_scoped_to_their_product(): void
    {
        $this->actAsStaff();
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        ProductApiKey::factory()->count(2)->create(['product_id' => $productA->id]);
        ProductApiKey::factory()->create(['product_id' => $productB->id]);

        $this->getJson("/api/v1/products/{$productA->slug}/api-keys")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_staff_can_revoke_a_key(): void
    {
        $this->actAsStaff();
        $key = ProductApiKey::factory()->create();

        $this->deleteJson("/api/v1/product-api-keys/{$key->uuid}")
            ->assertNoContent();

        $this->assertNotNull($key->refresh()->revoked_at);
        $this->assertFalse($key->isActive());
    }

    public function test_expiry_must_be_in_the_future(): void
    {
        $this->actAsStaff();
        $product = Product::factory()->create();

        $this->postJson("/api/v1/products/{$product->slug}/api-keys", [
            'name' => 'POS',
            'expires_at' => now()->subDay()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
