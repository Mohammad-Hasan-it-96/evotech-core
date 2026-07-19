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

    public function test_the_locator_returns_permanent_urls_keyed_by_platform(): void
    {
        $this->publishedRelease();

        $urls = app(ReleaseDownloadLocator::class)->latestDownloadUrls('invoices');

        $this->assertArrayHasKey('android', $urls);
        $this->assertStringContainsString('/downloads/latest/invoices/android', $urls['android']);

        // Not a signed link: it must still work long after it is written into a
        // config file that devices cache.
        $this->assertStringNotContainsString('signature=', $urls['android']);
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
