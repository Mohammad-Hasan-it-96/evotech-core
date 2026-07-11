<?php

namespace Modules\Licenses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\Gateway\Application\Services\ProductApiKeyService;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Modules\Subscriptions\Domain\Enums\IdentifierType;
use Modules\Subscriptions\Domain\Models\Subscription;
use Tests\TestCase;

class OfflineTokenTest extends TestCase
{
    use RefreshDatabase;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        config([
            'licenses.offline_tokens.private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'licenses.offline_tokens.public_key' => $this->publicKey,
            'licenses.offline_tokens.key_id' => 'test-key-1',
            'licenses.offline_tokens.issuer' => 'evotech-platform',
            'licenses.offline_tokens.ttl_days' => 14,
        ]);
    }

    /** @return array{0: Product, 1: string} */
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

    private function activate(License $license, string $identifier): LicenseActivation
    {
        return LicenseActivation::factory()->create([
            'license_id' => $license->id,
            'identifier' => $identifier,
            'identifier_type' => IdentifierType::Device,
        ]);
    }

    /** @return non-empty-string */
    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false || $decoded === '') {
            throw new \RuntimeException('Invalid base64url input.');
        }

        return $decoded;
    }

    /** @return non-empty-string */
    private function rawPublicKey(): string
    {
        $key = base64_decode($this->publicKey, true);

        if ($key === false || $key === '') {
            throw new \RuntimeException('Invalid public key.');
        }

        return $key;
    }

    /** @param TestResponse<JsonResponse> $response */
    private function tokenFrom(TestResponse $response): string
    {
        $token = $response->json('data.token');

        return is_string($token) ? $token : '';
    }

    /**
     * Verify a compact JWS offline with the published public key — exactly what a
     * device does — and return its claims.
     *
     * @return array<string, mixed>
     */
    private function verifyOffline(string $jws): array
    {
        [$header, $payload, $signature] = explode('.', $jws);

        $valid = sodium_crypto_sign_verify_detached(
            $this->base64UrlDecode($signature),
            $header.'.'.$payload,
            $this->rawPublicKey(),
        );
        $this->assertTrue($valid, 'The token signature must verify with the published public key.');

        $claims = json_decode($this->base64UrlDecode($payload), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($claims);

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    public function test_issuing_a_token_requires_a_product_api_key(): void
    {
        $this->postJson('/api/v1/product/licenses/token', ['key' => 'EVO-X', 'identifier' => 'd'])
            ->assertUnauthorized();
    }

    public function test_a_product_can_issue_a_verifiable_offline_token(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product);
        $this->activate($license, 'pos-1');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/product/licenses/token', ['key' => $license->key, 'identifier' => 'pos-1'])
            ->assertCreated()
            ->assertJsonPath('data.algorithm', 'EdDSA')
            ->assertJsonPath('data.key_id', 'test-key-1');

        // A device verifies the token offline with only the public key.
        $claims = $this->verifyOffline($this->tokenFrom($response));
        $this->assertSame('evotech-platform', $claims['iss']);
        $this->assertSame($license->key, $claims['sub']);
        $this->assertSame($product->slug, $claims['aud']);
        $this->assertSame('pos-1', data_get($claims, 'device.identifier'));
        $this->assertSame('active', data_get($claims, 'license.status'));

        // Issuance is recorded in the immutable ledger, attributed to the product.
        $this->assertDatabaseHas('license_events', [
            'license_id' => $license->id,
            'event_type' => 'token_issued',
            'actor_type' => 'product',
            'actor_id' => $product->slug,
        ]);
    }

    public function test_token_expiry_is_clamped_to_the_license_expiry(): void
    {
        [$product, $token] = $this->productWithKey();
        // License expires well before the default 14-day token TTL.
        $expiry = Carbon::now()->addDays(3);
        $license = $this->licenseFor($product, ['expires_at' => $expiry]);
        $this->activate($license, 'pos-1');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/product/licenses/token', ['key' => $license->key, 'identifier' => 'pos-1'])
            ->assertCreated();

        $claims = $this->verifyOffline($this->tokenFrom($response));
        $this->assertSame($expiry->getTimestamp(), $claims['exp']);
    }

    public function test_cannot_issue_a_token_for_an_unactivated_device(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/product/licenses/token', ['key' => $license->key, 'identifier' => 'never-activated'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_cannot_issue_a_token_for_a_non_active_license(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product, ['expires_at' => Carbon::now()->subDay()]);
        $this->activate($license, 'pos-1');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/product/licenses/token', ['key' => $license->key, 'identifier' => 'pos-1'])
            ->assertStatus(422);
    }

    public function test_cannot_issue_a_token_for_another_products_license(): void
    {
        [, $tokenA] = $this->productWithKey();
        $productB = Product::factory()->create();
        $licenseB = $this->licenseFor($productB);
        $this->activate($licenseB, 'pos-1');

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/product/licenses/token', ['key' => $licenseB->key, 'identifier' => 'pos-1'])
            ->assertNotFound();
    }

    public function test_the_public_verification_key_is_served_publicly(): void
    {
        $this->getJson('/api/v1/product/keys')
            ->assertOk()
            ->assertJsonPath('data.algorithm', 'EdDSA')
            ->assertJsonPath('data.keys.0.kty', 'OKP')
            ->assertJsonPath('data.keys.0.crv', 'Ed25519')
            ->assertJsonPath('data.keys.0.kid', 'test-key-1')
            ->assertJsonPath('data.keys.0.alg', 'EdDSA');
    }

    public function test_a_tampered_token_fails_verification(): void
    {
        [$product, $token] = $this->productWithKey();
        $license = $this->licenseFor($product);
        $this->activate($license, 'pos-1');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/product/licenses/token', ['key' => $license->key, 'identifier' => 'pos-1']);

        // Flip the payload — the signature must no longer verify.
        [$header, $payload, $signature] = explode('.', $this->tokenFrom($response));
        $forgedPayload = rtrim(strtr(base64_encode(
            str_replace('active', 'revoked', $this->base64UrlDecode($payload))
        ), '+/', '-_'), '=');

        $valid = sodium_crypto_sign_verify_detached(
            $this->base64UrlDecode($signature),
            $header.'.'.$forgedPayload,
            $this->rawPublicKey(),
        );

        $this->assertFalse($valid);
    }
}
