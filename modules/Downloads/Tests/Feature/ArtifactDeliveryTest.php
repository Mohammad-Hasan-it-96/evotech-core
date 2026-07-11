<?php

namespace Modules\Downloads\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Downloads\Domain\Enums\Platform;
use Modules\Downloads\Domain\Models\Artifact;
use Modules\Downloads\Domain\Models\Release;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class ArtifactDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('downloads');
        config(['downloads.disk' => 'downloads']);
    }

    private function uploadedArtifact(string $content = 'the-payload'): Artifact
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $release = Release::factory()->create();

        $this->post(
            "/api/v1/releases/{$release->uuid}/artifacts",
            ['file' => UploadedFile::fake()->createWithContent('app.bin', $content), 'platform' => Platform::Any->value],
            ['Accept' => 'application/json'],
        )->assertCreated();

        return Artifact::query()->firstOrFail();
    }

    public function test_delivery_requires_a_valid_signature(): void
    {
        $artifact = $this->uploadedArtifact();

        $this->get("/api/v1/downloads/deliver/{$artifact->uuid}")->assertStatus(403);
    }

    public function test_minting_a_link_records_the_download_and_serves_the_file(): void
    {
        $artifact = $this->uploadedArtifact('exact-bytes');

        $response = $this->postJson("/api/v1/artifacts/{$artifact->uuid}/link")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_at']]);

        $this->assertDatabaseHas('download_events', [
            'artifact_id' => $artifact->id,
            'actor_type' => 'staff',
        ]);
        $this->assertSame(1, $artifact->refresh()->download_count);

        $url = $response->json('data.url');
        $this->assertIsString($url);
        $delivered = $this->get($url)->assertOk();
        $this->assertSame('exact-bytes', $delivered->streamedContent());
    }

    public function test_minting_a_link_requires_authentication(): void
    {
        $artifact = Artifact::factory()->create();

        $this->postJson("/api/v1/artifacts/{$artifact->uuid}/link")->assertUnauthorized();
    }
}
