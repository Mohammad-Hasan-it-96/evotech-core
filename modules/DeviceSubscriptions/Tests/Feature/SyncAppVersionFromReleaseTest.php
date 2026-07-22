<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\Downloads\Domain\Events\ReleasePublished;
use Modules\Products\Domain\Models\Product;
use Tests\TestCase;

/**
 * Aligning a consumer app's advertised update version to a published release.
 *
 * Publishing a build and telling the app "this is the version to update to" are
 * separate acts; this listener ties them together — but only on the operator's
 * opt-in, and only for a version the shipped parsers can actually compare.
 *
 * Dispatched rather than calling the handler directly, so it also exercises the
 * listener registration in the service provider.
 */
class SyncAppVersionFromReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function appForProduct(Product $product, string $version = '1.0.0'): DeviceApp
    {
        return DeviceApp::create([
            'name' => 'Test '.$product->slug,
            'slug' => 'test-'.$product->slug,
            'label' => 'Test',
            'trial_days' => 0,
            'uses_shared_plans' => true,
            'product_id' => $product->id,
            'latest_version' => $version,
        ]);
    }

    public function test_it_sets_the_app_version_when_the_operator_opted_in(): void
    {
        $product = Product::factory()->create();
        $app = $this->appForProduct($product);

        ReleasePublished::dispatch($product->slug, '1.0.1', true);

        $this->assertSame('1.0.1', $app->refresh()->latest_version);
    }

    public function test_it_leaves_the_version_alone_when_not_opted_in(): void
    {
        $product = Product::factory()->create();
        $app = $this->appForProduct($product, '1.0.0');

        ReleasePublished::dispatch($product->slug, '1.0.1', false);

        $this->assertSame('1.0.0', $app->refresh()->latest_version);
    }

    public function test_it_skips_a_version_the_apps_cannot_compare(): void
    {
        $product = Product::factory()->create();
        $app = $this->appForProduct($product, '1.0.0');

        // A "v" prefix or "-beta" suffix reads as 0 in the shipped parsers, so it
        // would hide the update rather than announce it — decline instead.
        ReleasePublished::dispatch($product->slug, 'v1.0.1-beta', true);

        $this->assertSame('1.0.0', $app->refresh()->latest_version);
    }

    public function test_it_only_touches_apps_of_the_released_product(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $app = $this->appForProduct($product, '1.0.0');
        $otherApp = $this->appForProduct($other, '2.0.0');

        ReleasePublished::dispatch($product->slug, '1.0.1', true);

        $this->assertSame('1.0.1', $app->refresh()->latest_version);
        $this->assertSame('2.0.0', $otherApp->refresh()->latest_version);
    }
}
