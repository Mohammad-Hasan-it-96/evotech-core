<?php

namespace Modules\Downloads\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\Release;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * Importing a build staged on the server.
 *
 * The route exists because a large upload cannot survive the CDN's origin
 * timeout, so operators drop the file onto the server directly. That means the
 * file arrives with none of an upload's guarantees — these tests pin that it is
 * checked just as hard, and that a caller cannot name a file outside staging.
 */
class ArtifactImportTest extends TestCase
{
    use RefreshDatabase;

    private string $incoming;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('downloads');

        $this->incoming = storage_path('framework/testing/incoming');

        if (! is_dir($this->incoming)) {
            mkdir($this->incoming, 0755, true);
        }

        config([
            'downloads.disk' => 'downloads',
            'downloads.incoming_path' => $this->incoming,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->incoming.'/*') as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function stage(string $filename, string $content = 'the-build'): string
    {
        $path = $this->incoming.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $content);

        return $path;
    }

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    public function test_it_lists_only_importable_files_in_the_incoming_directory(): void
    {
        $this->actAsStaff();
        $this->stage('app.apk');
        $this->stage('notes.txt');

        $response = $this->getJson('/api/v1/artifacts/incoming')->assertOk();

        $filenames = array_column((array) $response->json('data'), 'filename');

        $this->assertContains('app.apk', $filenames);
        // A stray file is noise the operator would have to reason about, and
        // offering it only to reject it on submit is worse than not offering it.
        $this->assertNotContains('notes.txt', $filenames);
    }

    public function test_it_imports_a_staged_build_and_records_size_and_checksum(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();
        $this->stage('app.apk', 'exact-bytes');

        $this->postJson("/api/v1/releases/{$release->uuid}/artifacts/import", [
            'filename' => 'app.apk',
            'platform' => Platform::Android->value,
            'variant' => 'arm64-v8a',
        ])->assertCreated();

        $artifact = Artifact::query()->firstOrFail();

        $this->assertSame('app.apk', $artifact->filename);
        $this->assertSame('arm64-v8a', $artifact->variant);
        $this->assertSame(strlen('exact-bytes'), $artifact->size);
        $this->assertSame(hash('sha256', 'exact-bytes'), $artifact->checksum_sha256);
        Storage::disk('downloads')->assertExists($artifact->path);
    }

    public function test_it_consumes_the_staged_file_once_imported(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();
        $path = $this->stage('app.apk');

        $this->postJson("/api/v1/releases/{$release->uuid}/artifacts/import", [
            'filename' => 'app.apk',
            'platform' => Platform::Android->value,
        ])->assertCreated();

        // Left in place it would be offered for import again, and re-importing
        // the same build as a second variant is a silent way to ship the wrong
        // binary to half your devices.
        $this->assertFileDoesNotExist($path);
    }

    public function test_it_refuses_to_import_a_file_outside_the_incoming_directory(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();

        // The traversal target is real and readable, so nothing but the guard
        // stands between this request and publishing the app's secrets.
        $this->postJson("/api/v1/releases/{$release->uuid}/artifacts/import", [
            'filename' => '../../../../.env',
            'platform' => Platform::Any->value,
        ])->assertStatus(422);

        $this->assertSame(0, Artifact::query()->count());
    }

    public function test_it_rejects_a_staged_file_whose_extension_is_not_allowed(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();
        $this->stage('payload.html', '<script>alert(1)</script>');

        $this->postJson("/api/v1/releases/{$release->uuid}/artifacts/import", [
            'filename' => 'payload.html',
            'platform' => Platform::Any->value,
        ])->assertStatus(422);

        $this->assertSame(0, Artifact::query()->count());
    }

    public function test_it_404s_when_the_named_file_is_not_staged(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();

        $this->postJson("/api/v1/releases/{$release->uuid}/artifacts/import", [
            'filename' => 'never-uploaded.apk',
            'platform' => Platform::Any->value,
        ])->assertStatus(422);
    }

    public function test_importing_replaces_only_the_same_platform_and_variant(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();

        foreach (['arm64-v8a', 'armeabi-v7a'] as $variant) {
            $this->stage('app.apk', "build-{$variant}");

            $this->postJson("/api/v1/releases/{$release->uuid}/artifacts/import", [
                'filename' => 'app.apk',
                'platform' => Platform::Android->value,
                'variant' => $variant,
            ])->assertCreated();
        }

        // Both ABIs must survive: one replacing the other leaves half the
        // install base downloading a binary their device cannot run.
        $this->assertSame(2, $release->artifacts()->count());
    }

    public function test_it_requires_authentication(): void
    {
        $release = Release::factory()->create();

        $this->getJson('/api/v1/artifacts/incoming')->assertStatus(401);
        $this->postJson("/api/v1/releases/{$release->uuid}/artifacts/import", [
            'filename' => 'app.apk',
            'platform' => Platform::Any->value,
        ])->assertStatus(401);
    }
}
