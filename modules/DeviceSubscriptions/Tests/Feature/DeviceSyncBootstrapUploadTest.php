<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\DeviceSubscriptions\Domain\Models\DeviceJoinToken;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Tests\TestCase;

/**
 * The bootstrap snapshot UPLOAD over HTTP (ADR 0011, Decision 13).
 *
 * The other bootstrap tests attach the snapshot by calling the service directly,
 * so they never exercise the route. This drives the real endpoint the owner device
 * calls: POST /api/v1/sync/join-tokens/{joinToken}/bootstrap. The path segment is
 * the RAW join-token string the mint returned — looked up by hash, scoped to the
 * caller's business — never a record uuid. (The 2026-08-16 Fawateer report: the
 * old uuid binding 404'd for every client, because no client is ever handed the
 * uuid to build the URL from.)
 */
class DeviceSyncBootstrapUploadTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    private const DISK = 'device-sync';

    /**
     * POST a multipart bootstrap upload. `post` (not `postJson`) so the file rides
     * as multipart/form-data; Accept: application/json still yields the envelope.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function uploadBootstrap(string $uri, array $body, string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->post($uri, $body, $this->bearer($token) + ['Accept' => 'application/json']);
    }

    public function test_owner_uploads_a_bootstrap_by_the_plaintext_join_token(): void
    {
        Storage::fake(self::DISK);
        $owner = $this->establishOwner();

        // Mint over HTTP: this is the only value a client is ever given.
        $joinToken = $this->jsonString(
            $this->syncPost('/api/v1/sync/join-tokens', [], $owner->plaintext)->assertCreated(),
            'data.join_token',
        );

        $sha = hash('sha256', 'seed');

        $response = $this->uploadBootstrap(
            "/api/v1/sync/join-tokens/{$joinToken}/bootstrap",
            [
                'cursor' => 7,
                'snapshot_sha256' => $sha,
                'snapshot' => UploadedFile::fake()->create('shop.sqlite', 8),
            ],
            $owner->plaintext,
        )->assertOk();

        $this->assertSame(7, $this->jsonInt($response, 'data.cursor'));

        // The token record now carries the handoff, and the file is on the disk.
        $stored = DeviceJoinToken::query()->firstOrFail();
        $this->assertSame(7, $stored->bootstrap_cursor);
        $this->assertSame($sha, $stored->snapshot_sha256);
        $this->assertNotNull($stored->snapshot_path);
        Storage::disk(self::DISK)->assertExists($stored->snapshot_path);
        $this->assertSame($stored->uuid, $this->jsonString($response, 'data.join_token_uuid'));
    }

    public function test_the_uploaded_snapshot_becomes_the_joiners_bootstrap_url(): void
    {
        Storage::fake(self::DISK);
        $owner = $this->establishOwner();

        $joinToken = $this->jsonString(
            $this->syncPost('/api/v1/sync/join-tokens', [], $owner->plaintext)->assertCreated(),
            'data.join_token',
        );

        $this->uploadBootstrap(
            "/api/v1/sync/join-tokens/{$joinToken}/bootstrap",
            [
                'cursor' => 3,
                'snapshot_sha256' => hash('sha256', 'seed'),
                'snapshot' => UploadedFile::fake()->create('shop.sqlite', 8),
            ],
            $owner->plaintext,
        )->assertOk();

        // The joiner redeems the SAME token and is handed a signed snapshot URL —
        // the upload-before-QR ordering the design assumes.
        $enroll = $this->syncPost('/api/v1/sync/enroll', [
            'join_token' => $joinToken,
            'device_id' => $this->deviceId('member'),
        ])->assertCreated();

        $this->assertSame(3, $this->jsonInt($enroll, 'data.bootstrap.cursor'));
        $this->assertSame(hash('sha256', 'seed'), $this->jsonString($enroll, 'data.bootstrap.snapshot_sha256'));
        $this->assertNotSame('', $this->jsonString($enroll, 'data.bootstrap.snapshot_url'));
    }

    public function test_a_member_may_not_upload_a_bootstrap(): void
    {
        Storage::fake(self::DISK);
        $owner = $this->establishOwner();
        $member = $this->enrollMember($owner->seat->business, 'member');

        $joinToken = $this->jsonString(
            $this->syncPost('/api/v1/sync/join-tokens', [], $owner->plaintext)->assertCreated(),
            'data.join_token',
        );

        // Owner-only, checked before the token is even resolved → 403, not 404.
        $this->uploadBootstrap(
            "/api/v1/sync/join-tokens/{$joinToken}/bootstrap",
            [
                'cursor' => 1,
                'snapshot_sha256' => hash('sha256', 'seed'),
                'snapshot' => UploadedFile::fake()->create('shop.sqlite', 8),
            ],
            $member->plaintext,
        )->assertForbidden();
    }

    public function test_a_token_from_another_business_is_404_not_403(): void
    {
        Storage::fake(self::DISK);
        $ownerA = $this->establishOwner(ownerLabel: 'owner-a');
        $ownerB = $this->establishOwner(app: 'Fawateer', ownerLabel: 'owner-b');

        // A token minted by business B...
        $tokenB = $this->jsonString(
            $this->syncPost('/api/v1/sync/join-tokens', [], $ownerB->plaintext)->assertCreated(),
            'data.join_token',
        );

        // ...is invisible to A's owner: scoped-out, so it reads as "no such token".
        $this->uploadBootstrap(
            "/api/v1/sync/join-tokens/{$tokenB}/bootstrap",
            [
                'cursor' => 1,
                'snapshot_sha256' => hash('sha256', 'seed'),
                'snapshot' => UploadedFile::fake()->create('shop.sqlite', 8),
            ],
            $ownerA->plaintext,
        )->assertNotFound();
    }

    public function test_an_unknown_token_is_404(): void
    {
        Storage::fake(self::DISK);
        $owner = $this->establishOwner();

        $this->uploadBootstrap(
            '/api/v1/sync/join-tokens/evojoin_not-a-real-token/bootstrap',
            [
                'cursor' => 1,
                'snapshot_sha256' => hash('sha256', 'seed'),
                'snapshot' => UploadedFile::fake()->create('shop.sqlite', 8),
            ],
            $owner->plaintext,
        )->assertNotFound();
    }
}
