<?php

namespace Modules\Downloads\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Enums\ReleaseStatus;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\Release;
use Modules\Gateway\Application\Services\ProductApiKeyService;
use Modules\Products\Domain\Models\Product;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * Two holes in the Download Center, both closed here.
 */
class ArtifactUploadGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('downloads');
        config(['downloads.disk' => 'downloads']);
    }

    private function draftRelease(?Product $product = null): Release
    {
        $product ??= Product::factory()->create(['slug' => 'invoices']);

        return Release::create([
            'product_id' => $product->id,
            'channel' => ReleaseChannel::Stable,
            'version' => '1.0.0',
            'status' => ReleaseStatus::Draft,
        ]);
    }

    // --- Upload allowlist -------------------------------------------------------

    /**
     * The endpoint accepted anything up to 2 GB, and the Download Center then
     * served it from the platform's own origin. An `.html` artifact is script
     * running as this site; nothing downstream objected, because the content type
     * is detected and recorded but never enforced.
     */
    public function test_a_script_bearing_file_cannot_be_uploaded(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $release = $this->draftRelease();

        foreach (['payload.html', 'payload.svg', 'shell.php', 'notes.txt'] as $filename) {
            $this->post(
                "/api/v1/releases/{$release->uuid}/artifacts",
                [
                    'file' => UploadedFile::fake()->createWithContent($filename, '<script>alert(1)</script>'),
                    'platform' => Platform::Any->value,
                ],
                ['Accept' => 'application/json'],
            )
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }

        $this->assertSame(0, Artifact::query()->count());
        Storage::disk('downloads')->assertDirectoryEmpty('/');
    }

    public function test_an_extensionless_file_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $release = $this->draftRelease();

        $this->post(
            "/api/v1/releases/{$release->uuid}/artifacts",
            ['file' => UploadedFile::fake()->createWithContent('installer', 'bytes'), 'platform' => Platform::Any->value],
            ['Accept' => 'application/json'],
        )->assertStatus(422);
    }

    /**
     * An APK is detected as `application/zip`, so a MIME-based allowlist wide
     * enough to admit it admits far more — which is why the guard checks the
     * extension instead. This pins that APKs still get through.
     */
    public function test_real_distributables_are_still_accepted(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        foreach (['app.apk', 'setup.exe', 'firmware.bin', 'bundle.zip'] as $index => $filename) {
            $release = $this->draftRelease(Product::factory()->create(['slug' => "product-{$index}"]));

            $this->post(
                "/api/v1/releases/{$release->uuid}/artifacts",
                ['file' => UploadedFile::fake()->createWithContent($filename, 'bytes'), 'platform' => Platform::Any->value],
                ['Accept' => 'application/json'],
            )->assertCreated();
        }
    }

    // --- Draft leak -------------------------------------------------------------

    /**
     * Owning the product was enough to mint a link for an artifact of a *draft or
     * archived* release — an unreleased build handed to a customer by a product
     * that knew, or guessed, a uuid.
     */
    public function test_a_product_cannot_mint_a_link_for_an_unpublished_release(): void
    {
        $product = Product::factory()->create(['slug' => 'invoices']);
        $release = $this->draftRelease($product);

        $path = UploadedFile::fake()->createWithContent('app.apk', 'SECRET-BUILD')
            ->store("artifacts/invoices/{$release->uuid}", 'downloads');

        $artifact = Artifact::create([
            'release_id' => $release->id,
            'platform' => Platform::Android->value,
            'disk' => 'downloads',
            'path' => (string) $path,
            'filename' => 'app.apk',
            'size' => 12,
            'checksum_sha256' => str_repeat('c', 64),
            'content_type' => 'application/zip',
        ]);

        $token = app(ProductApiKeyService::class)->mint($product, 'test key')->plaintext;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/product/artifacts/{$artifact->uuid}/link")
            ->assertNotFound();

        // Nothing minted means nothing recorded.
        $this->assertSame(0, $artifact->refresh()->download_count);

        // Publishing it makes the same request succeed, so the guard is about the
        // release's state and not something incidental to the request.
        $release->update(['status' => ReleaseStatus::Published, 'published_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/product/artifacts/{$artifact->uuid}/link")
            ->assertOk();
    }
}
