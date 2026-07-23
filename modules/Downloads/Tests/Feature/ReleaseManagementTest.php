<?php

namespace Modules\Downloads\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Enums\ReleaseStatus;
use Modules\Downloads\Domain\Events\ReleasePublished;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\Release;
use Modules\Products\Domain\Models\Product;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class ReleaseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('downloads');
        config(['downloads.disk' => 'downloads']);
    }

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    /** @return TestResponse<JsonResponse> */
    private function upload(Release $release, UploadedFile $file, Platform $platform): TestResponse
    {
        return $this->post(
            "/api/v1/releases/{$release->uuid}/artifacts",
            ['file' => $file, 'platform' => $platform->value],
            ['Accept' => 'application/json'],
        );
    }

    public function test_managing_releases_requires_authentication(): void
    {
        $this->getJson('/api/v1/releases')->assertUnauthorized();
    }

    public function test_staff_can_create_a_draft_release(): void
    {
        $this->actAsStaff();
        $product = Product::factory()->create();

        $this->postJson('/api/v1/releases', [
            'product' => $product->slug,
            'channel' => 'stable',
            'version' => '1.2.0',
            'name' => 'Spring release',
        ])
            ->assertCreated()
            ->assertJsonPath('data.version', '1.2.0')
            ->assertJsonPath('data.channel', 'stable')
            ->assertJsonPath('data.status', ReleaseStatus::Draft->value);

        $this->assertDatabaseHas('releases', [
            'product_id' => $product->id,
            'version' => '1.2.0',
            'status' => 'draft',
        ]);
    }

    public function test_uploading_an_artifact_records_checksum_size_and_content_type(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();
        $file = UploadedFile::fake()->createWithContent('setup.exe', 'binary-payload');

        $response = $this->upload($release, $file, Platform::Windows)->assertCreated();

        $artifact = Artifact::query()->firstOrFail();
        $response->assertJsonPath('data.platform', 'windows')
            ->assertJsonPath('data.filename', 'setup.exe')
            ->assertJsonPath('data.checksum_sha256', hash('sha256', 'binary-payload'));

        Storage::disk('downloads')->assertExists($artifact->path);
        $this->assertSame(strlen('binary-payload'), $artifact->size);
    }

    public function test_a_release_cannot_be_published_without_an_artifact(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();

        $this->postJson("/api/v1/releases/{$release->uuid}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_staff_can_publish_a_release_with_an_artifact(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();
        Artifact::factory()->create(['release_id' => $release->id]);

        $this->postJson("/api/v1/releases/{$release->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', ReleaseStatus::Published->value);

        $this->assertNotNull($release->refresh()->published_at);
    }

    public function test_publishing_can_request_an_app_version_sync(): void
    {
        Event::fake([ReleasePublished::class]);
        $this->actAsStaff();
        $release = Release::factory()->create(['version' => '1.0.1']);
        Artifact::factory()->create(['release_id' => $release->id]);

        $this->postJson("/api/v1/releases/{$release->uuid}/publish", [
            'sync_app_version' => true,
        ])->assertOk();

        Event::assertDispatched(
            ReleasePublished::class,
            fn (ReleasePublished $event): bool => $event->version === '1.0.1'
                && $event->productSlug === $release->product->slug
                && $event->syncAppVersion === true,
        );
    }

    public function test_publishing_without_the_flag_does_not_request_a_sync(): void
    {
        Event::fake([ReleasePublished::class]);
        $this->actAsStaff();
        $release = Release::factory()->create();
        Artifact::factory()->create(['release_id' => $release->id]);

        $this->postJson("/api/v1/releases/{$release->uuid}/publish")->assertOk();

        Event::assertDispatched(
            ReleasePublished::class,
            fn (ReleasePublished $event): bool => $event->syncAppVersion === false,
        );
    }

    public function test_an_archived_release_can_be_restored_and_published_again(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();
        Artifact::factory()->create(['release_id' => $release->id]);

        $this->postJson("/api/v1/releases/{$release->uuid}/publish")->assertOk();
        $this->postJson("/api/v1/releases/{$release->uuid}/archive")->assertOk();

        // Landing in draft rather than published: restoring is undoing a mistake,
        // and archiving the wrong row must not silently republish a build.
        $this->postJson("/api/v1/releases/{$release->uuid}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.status', ReleaseStatus::Draft->value);

        // The point of restoring — an archived release was otherwise stranded,
        // since publishing is gated on draft and nothing could reach it.
        $this->postJson("/api/v1/releases/{$release->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', ReleaseStatus::Published->value);
    }

    public function test_deleting_an_artifact_removes_the_stored_file(): void
    {
        $this->actAsStaff();
        $release = Release::factory()->create();
        $file = UploadedFile::fake()->createWithContent('app.zip', 'zip-bytes');
        $this->upload($release, $file, Platform::Any)->assertCreated();

        $artifact = Artifact::query()->firstOrFail();
        Storage::disk('downloads')->assertExists($artifact->path);

        $this->deleteJson("/api/v1/artifacts/{$artifact->uuid}")->assertNoContent();

        Storage::disk('downloads')->assertMissing($artifact->path);
        $this->assertSoftDeleted($artifact);
    }
}
