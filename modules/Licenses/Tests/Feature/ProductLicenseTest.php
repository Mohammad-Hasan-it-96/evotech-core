<?php

namespace Modules\Licenses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\Gateway\Application\Services\ProductApiKeyService;
use Modules\Gateway\Domain\Models\ProductApiKey;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Modules\Subscriptions\Domain\Models\Subscription;
use Tests\TestCase;

class ProductLicenseTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Product, 1: string} the product and its one-time plaintext key */
    private function productWithKey(): array
    {
        $product = Product::factory()->create();
        $minted = app(ProductApiKeyService::class)->mint($product, 'Test key');

        return [$product, $minted->plaintext];
    }

    /** @param array<string, mixed> $overrides */
    private function licenseFor(Product $product, array $overrides = []): License
    {
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $subscription = Subscription::factory()->create(['plan_id' => $plan->id]);

        return License::factory()->create(array_merge([
            'subscription_id' => $subscription->id,
            'company_id' => $subscription->company_id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function asProduct(string $token, string $endpoint, array $body): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/product/licenses/{$endpoint}", $body);
    }

    // --- Authentication (the `product` guard, ADR 0004) ---

    public function test_product_endpoints_require_an_api_key(): void
    {
        $this->postJson('/api/v1/product/licenses/validate', ['key' => 'EVO-XXXX'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_an_invalid_key_is_rejected(): void
    {
        $this->asProduct('evo_bogus_nope', 'validate', ['key' => 'EVO-XXXX'])
            ->assertUnauthorized();
    }

    public function test_a_revoked_key_is_rejected(): void
    {
        [$product, $token] = $this->productWithKey();
        ProductApiKey::query()->firstOrFail()->forceFill(['revoked_at' => Carbon::now()])->save();
        $license = $this->licenseFor($product);

        $this->asProduct($token, 'validate', ['key' => $license->key])
            ->assertUnauthorized();
    }

    public function test_an_expired_key_is_rejected(): void
    {
        [$product, $token] = $this->productWithKey();
        ProductApiKey::query()->firstOrFail()->forceFill(['expires_at' => Carbon::now()->subDay()])->save();
        $license = $this->licenseFor($product);

        $this->asProduct($token, 'validate', ['key' => $license->key])
            ->assertUnauthorized();
    }

    public function test_the_key_may_be_sent_via_the_x_api_key_header(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product);

        $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/product/licenses/validate', ['key' => $license->key])
            ->assertOk()
            ->assertJsonPath('data.valid', true);
    }

    // --- Ownership boundary ---

    public function test_a_product_cannot_touch_another_products_license(): void
    {
        [, $tokenA] = $this->productWithKey();
        $productB = Product::factory()->create();
        $licenseB = $this->licenseFor($productB);

        // Product A authenticates fine, but B's license is "not found" to it.
        $this->asProduct($tokenA, 'activate', [
            'key' => $licenseB->key,
            'identifier_type' => 'device',
            'identifier' => 'pos-1',
        ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_an_unknown_license_key_is_not_found(): void
    {
        [, $token] = $this->productWithKey();

        $this->asProduct($token, 'validate', ['key' => 'EVO-DOES-NOT-EXIST'])
            ->assertNotFound();
    }

    // --- Self-activation ---

    public function test_a_product_can_self_activate_its_license(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product, ['max_activations' => 2]);

        $this->asProduct($token, 'activate', [
            'key' => $license->key,
            'identifier_type' => 'device',
            'identifier' => 'pos-terminal-7',
            'name' => 'Terminal 7',
        ])
            ->assertCreated()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.product', $product->slug)
            ->assertJsonPath('data.activations_used', 1)
            ->assertJsonPath('data.activation.identifier', 'pos-terminal-7');

        $this->assertDatabaseHas('license_activations', [
            'license_id' => $license->id,
            'identifier' => 'pos-terminal-7',
            'revoked_at' => null,
        ]);

        // The ledger attributes the event to the product actor, not a user.
        $this->assertDatabaseHas('license_events', [
            'license_id' => $license->id,
            'event_type' => 'activated',
            'actor_type' => 'product',
            'actor_id' => $product->slug,
        ]);
    }

    public function test_self_activation_respects_the_activation_limit(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product, ['max_activations' => 1]);

        $this->asProduct($token, 'activate', [
            'key' => $license->key, 'identifier_type' => 'device', 'identifier' => 'a',
        ])->assertCreated();

        $this->asProduct($token, 'activate', [
            'key' => $license->key, 'identifier_type' => 'device', 'identifier' => 'b',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_product_cannot_activate_an_expired_license(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product, ['expires_at' => Carbon::now()->subDay()]);

        $this->asProduct($token, 'activate', [
            'key' => $license->key, 'identifier_type' => 'device', 'identifier' => 'a',
        ])->assertStatus(422);
    }

    // --- Online validation ---

    public function test_validation_reports_entitlement_and_records_a_heartbeat(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product);
        $activation = LicenseActivation::factory()->create([
            'license_id' => $license->id,
            'identifier' => 'kiosk-3',
            'last_seen_at' => Carbon::now()->subWeek(),
        ]);

        $this->asProduct($token, 'validate', ['key' => $license->key, 'identifier' => 'kiosk-3'])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.activation.identifier', 'kiosk-3');

        $activation->refresh();
        $this->assertNotNull($activation->last_seen_at);
        $this->assertTrue($activation->last_seen_at->isToday());
    }

    public function test_validation_reports_a_revoked_license_as_invalid(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product, [
            'status' => 'revoked',
            'revoked_at' => Carbon::now(),
        ]);

        $this->asProduct($token, 'validate', ['key' => $license->key])
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.status', 'revoked');
    }

    // --- Self-deactivation ---

    public function test_a_product_can_release_its_slot(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product, ['max_activations' => 1]);
        LicenseActivation::factory()->create([
            'license_id' => $license->id,
            'identifier' => 'old-device',
        ]);

        $this->asProduct($token, 'deactivate', ['key' => $license->key, 'identifier' => 'old-device'])
            ->assertNoContent();

        $this->assertDatabaseHas('license_events', [
            'license_id' => $license->id,
            'event_type' => 'deactivated',
            'actor_type' => 'product',
        ]);

        // The freed slot can be reclaimed.
        $this->asProduct($token, 'activate', [
            'key' => $license->key, 'identifier_type' => 'device', 'identifier' => 'new-device',
        ])->assertCreated();
    }
}
