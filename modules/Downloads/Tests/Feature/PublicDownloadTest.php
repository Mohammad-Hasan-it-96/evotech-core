<?php

namespace Modules\Downloads\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Domain\Contracts\ReleaseDownloadLocator;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Enums\ReleaseChannel;
use Modules\Downloads\Domain\Enums\ReleaseStatus;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\Release;
use Modules\Products\Domain\Models\Product;
use Tests\TestCase;

/**
 * The permanent public download URL, and the port that hands it to other modules.
 *
 * Every other route into the Download Center mints a 15-minute signed link, which
 * is right for an authenticated product self-updating and useless for a URL that
 * must survive inside a cached config file. This one never expires because it
 * names the current build for a platform, not a file.
 */
class PublicDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('downloads');
        config(['downloads.disk' => 'downloads']);
    }

    private function publishedRelease(string $slug = 'invoices', string $version = '1.0.0'): Release
    {
        $product = Product::factory()->create(['slug' => $slug]);

        $release = Release::create([
            'product_id' => $product->id,
            'channel' => ReleaseChannel::Stable,
            'version' => $version,
            'status' => ReleaseStatus::Published,
            'published_at' => now(),
        ]);

        $path = UploadedFile::fake()
            ->createWithContent('app.apk', 'APK-BYTES')
            ->store("artifacts/{$slug}/{$release->uuid}", 'downloads');

        Artifact::create([
            'release_id' => $release->id,
            'platform' => Platform::Android->value,
            'disk' => 'downloads',
            'path' => (string) $path,
            'filename' => 'app.apk',
            'size' => 9,
            'checksum_sha256' => str_repeat('a', 64),
            'content_type' => 'application/vnd.android.package-archive',
        ]);

        return $release->refresh();
    }

    /** Adds another build of the same platform, distinguished by its ABI. */
    private function addArtifact(Release $release, string $variant, string $content): Artifact
    {
        $path = UploadedFile::fake()
            ->createWithContent("app-{$variant}.apk", $content)
            ->store("artifacts/invoices/{$release->uuid}", 'downloads');

        return Artifact::create([
            'release_id' => $release->id,
            'platform' => Platform::Android->value,
            'variant' => $variant,
            'disk' => 'downloads',
            'path' => (string) $path,
            'filename' => "app-{$variant}.apk",
            'size' => mb_strlen($content),
            'checksum_sha256' => str_repeat('d', 64),
            'content_type' => 'application/vnd.android.package-archive',
        ]);
    }

    // --- The permanent URL ------------------------------------------------------

    public function test_the_public_url_redirects_to_the_current_build(): void
    {
        $this->publishedRelease();

        $response = $this->get('/api/v1/downloads/latest/invoices/android');

        $response->assertRedirect();

        // The redirect target is the signed delivery route, which actually serves
        // bytes — so one route reads from disk, not two.
        $this->assertStringContainsString('downloads/deliver', (string) $response->headers->get('Location'));
    }

    /** Following it must produce the file, not just a plausible-looking URL. */
    public function test_following_the_redirect_serves_the_file(): void
    {
        $this->publishedRelease();

        $location = (string) $this->get('/api/v1/downloads/latest/invoices/android')
            ->headers->get('Location');

        $delivered = $this->get($location);

        $delivered->assertOk();
        $this->assertSame('APK-BYTES', $delivered->streamedContent());
    }

    public function test_the_public_url_needs_no_authentication(): void
    {
        $this->publishedRelease();

        // No Sanctum user, no product API key.
        $this->get('/api/v1/downloads/latest/invoices/android')->assertRedirect();
    }

    /**
     * The property the whole route exists for: the URL is stable across releases.
     * A link already sitting in a cached config file must start serving the new
     * build on its own, with no config edit and no link to reissue.
     */
    public function test_the_url_is_unchanged_after_publishing_a_newer_release(): void
    {
        $first = $this->publishedRelease();
        $url = '/api/v1/downloads/latest/invoices/android';

        $before = (string) $this->get($url)->headers->get('Location');

        $second = Release::create([
            'product_id' => $first->product_id,
            'channel' => ReleaseChannel::Stable,
            'version' => '2.0.0',
            'status' => ReleaseStatus::Published,
            'published_at' => now()->addMinute(),
        ]);

        $path = UploadedFile::fake()
            ->createWithContent('app.apk', 'NEW-BYTES')
            ->store("artifacts/invoices/{$second->uuid}", 'downloads');

        Artifact::create([
            'release_id' => $second->id,
            'platform' => Platform::Android->value,
            'disk' => 'downloads',
            'path' => (string) $path,
            'filename' => 'app.apk',
            'size' => 9,
            'checksum_sha256' => str_repeat('b', 64),
            'content_type' => 'application/vnd.android.package-archive',
        ]);

        $after = (string) $this->get($url)->headers->get('Location');

        // Same public URL, different artifact behind it.
        $this->assertNotSame($before, $after);
        $this->assertSame('NEW-BYTES', $this->get($after)->streamedContent());
    }

    public function test_an_unpublished_release_is_not_reachable(): void
    {
        $release = $this->publishedRelease();
        $release->update(['status' => ReleaseStatus::Draft]);

        $this->get('/api/v1/downloads/latest/invoices/android')->assertNotFound();
    }

    public function test_unknown_product_platform_and_channel_are_404(): void
    {
        $this->publishedRelease();

        $this->get('/api/v1/downloads/latest/nosuchproduct/android')->assertNotFound();
        $this->get('/api/v1/downloads/latest/invoices/nosuchplatform')->assertNotFound();
        $this->get('/api/v1/downloads/latest/invoices/windows')->assertNotFound();
        $this->get('/api/v1/downloads/latest/invoices/android?channel=nosuchchannel')->assertNotFound();
    }

    /** Public hits belong in the ledger like any other issue. */
    public function test_a_public_download_is_recorded(): void
    {
        $this->publishedRelease();

        $this->get('/api/v1/downloads/latest/invoices/android');

        $this->assertDatabaseHas('download_events', ['actor_type' => 'public']);
        $this->assertSame(1, Artifact::query()->sole()->download_count);
    }

    // --- The Core port ----------------------------------------------------------

    public function test_the_locator_returns_permanent_urls(): void
    {
        $this->publishedRelease();

        $urls = app(ReleaseDownloadLocator::class)->latestDownloadUrls('invoices');

        $this->assertCount(1, $urls);
        $this->assertSame('android', $urls[0]['platform']);
        // Universal build: the caller turns an empty variant into `default`.
        $this->assertSame('', $urls[0]['variant']);
        $this->assertStringContainsString('/downloads/latest/invoices/android', $urls[0]['url']);

        // Not a signed link: it must still work long after it is written into a
        // config file that devices cache.
        $this->assertStringNotContainsString('signature=', $urls[0]['url']);
    }

    // --- Per-ABI variants -------------------------------------------------------

    /**
     * The reason variants exist. Before this, `artifacts` was unique on
     * (release, platform), so the second Android upload silently *replaced* the
     * first instead of sitting beside it.
     */
    public function test_two_android_abis_coexist_in_one_release(): void
    {
        $release = $this->publishedRelease();

        $this->addArtifact($release, 'arm64-v8a', 'ARM64-BYTES');
        $this->addArtifact($release, 'armeabi-v7a', 'ARM32-BYTES');

        // Three: the universal build from publishedRelease() plus both ABIs.
        $this->assertSame(3, $release->artifacts()->count());

        $arm64 = $this->get('/api/v1/downloads/latest/invoices/android/arm64-v8a');
        $arm32 = $this->get('/api/v1/downloads/latest/invoices/android/armeabi-v7a');

        $this->assertSame(
            'ARM64-BYTES',
            $this->get((string) $arm64->headers->get('Location'))->streamedContent(),
        );
        $this->assertSame(
            'ARM32-BYTES',
            $this->get((string) $arm32->headers->get('Location'))->streamedContent(),
        );
    }

    /**
     * Omitting the variant addresses the *universal* build specifically — it does
     * not pick one of the ABIs. Guessing would mean handing an arm64 APK to an
     * armeabi device: an install failure, with nothing explaining why.
     */
    public function test_omitting_the_variant_serves_the_universal_build(): void
    {
        $release = $this->publishedRelease();
        $this->addArtifact($release, 'arm64-v8a', 'ARM64-BYTES');

        $location = (string) $this->get('/api/v1/downloads/latest/invoices/android')
            ->headers->get('Location');

        $this->assertSame('APK-BYTES', $this->get($location)->streamedContent());
    }

    public function test_a_variant_with_no_build_is_a_404(): void
    {
        $this->publishedRelease();

        $this->get('/api/v1/downloads/latest/invoices/android/armeabi-v7a')->assertNotFound();
    }

    /** Each ABI is addressed and counted separately. */
    public function test_the_locator_lists_every_variant(): void
    {
        $release = $this->publishedRelease();
        $this->addArtifact($release, 'arm64-v8a', 'ARM64-BYTES');
        $this->addArtifact($release, 'armeabi-v7a', 'ARM32-BYTES');

        $urls = app(ReleaseDownloadLocator::class)->latestDownloadUrls('invoices');

        $byVariant = [];

        foreach ($urls as $download) {
            $byVariant[$download['variant']] = $download['url'];
        }

        $this->assertSame(['', 'arm64-v8a', 'armeabi-v7a'], array_keys($byVariant));

        // The universal URL carries no trailing segment: `…/android/` is not it.
        $this->assertStringEndsWith('/downloads/latest/invoices/android', $byVariant['']);
        $this->assertStringEndsWith('/android/arm64-v8a', $byVariant['arm64-v8a']);
    }

    public function test_the_locator_is_empty_for_products_with_nothing_published(): void
    {
        $locator = app(ReleaseDownloadLocator::class);

        $this->assertSame([], $locator->latestDownloadUrls('nosuchproduct'));

        $release = $this->publishedRelease();
        $release->update(['status' => ReleaseStatus::Draft]);

        $this->assertSame([], $locator->latestDownloadUrls('invoices'));
    }
}
