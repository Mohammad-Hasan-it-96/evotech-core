<?php

namespace Modules\Downloads\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\Release;
use Modules\Gateway\Application\Services\ProductApiKeyService;
use Modules\Products\Domain\Models\Product;
use Tests\TestCase;

class ProductDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('downloads');
        config(['downloads.disk' => 'downloads']);
    }

    /** @return array{0: Product, 1: string} the product and its one-time plaintext key */
    private function productWithKey(): array
    {
        $product = Product::factory()->create();
        $minted = app(ProductApiKeyService::class)->mint($product, 'Test key');

        return [$product, $minted->plaintext];
    }

    private function publishedReleaseFor(Product $product, ReleaseChannel $channel = ReleaseChannel::Stable): Release
    {
        $release = Release::factory()->published()->channel($channel)->create(['product_id' => $product->id]);
        Artifact::factory()->platform(Platform::Windows)->create(['release_id' => $release->id]);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function asProduct(string $token, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->json($method, $uri, $body);
    }

    public function test_product_endpoints_require_an_api_key(): void
    {
        $this->getJson('/api/v1/product/releases/latest')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_product_gets_its_latest_published_release(): void
    {
        [$product, $token] = $this->productWithKey();
        $this->publishedReleaseFor($product);

        $this->asProduct($token, 'GET', '/api/v1/product/releases/latest')
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonCount(1, 'data.artifacts');
    }

    public function test_latest_ignores_drafts_and_returns_404_when_none_published(): void
    {
        [$product, $token] = $this->productWithKey();
        Release::factory()->create(['product_id' => $product->id]); // draft

        $this->asProduct($token, 'GET', '/api/v1/product/releases/latest')->assertNotFound();
    }

    public function test_product_can_mint_a_link_for_its_own_artifact(): void
    {
        [$product, $token] = $this->productWithKey();
        $release = $this->publishedReleaseFor($product);
        $artifact = $release->artifacts()->firstOrFail();

        $this->asProduct($token, 'POST', "/api/v1/product/artifacts/{$artifact->uuid}/link")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at']]);

        $this->assertDatabaseHas('download_events', [
            'artifact_id' => $artifact->id,
            'actor_type' => 'product',
            'actor_id' => $product->slug,
        ]);
    }

    public function test_a_product_cannot_touch_another_products_artifact(): void
    {
        [, $token] = $this->productWithKey();
        $other = Product::factory()->create();
        $release = $this->publishedReleaseFor($other);
        $artifact = $release->artifacts()->firstOrFail();

        $this->asProduct($token, 'POST', "/api/v1/product/artifacts/{$artifact->uuid}/link")
            ->assertNotFound();

        $this->assertDatabaseCount('download_events', 0);
    }
}
