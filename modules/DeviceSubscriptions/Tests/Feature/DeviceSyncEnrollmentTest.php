<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Tests\TestCase;

/**
 * Enrollment and the bootstrap handoff (ADR 0011, Decisions 3, 5, 13). Covers the
 * owner-only mint, the allowance ceiling, the fallback-id rejection, the
 * non-revocable owner, the revoked-seat coupling to check_device, and — the case
 * the whole design turns on — a device joining an existing shop while a sibling
 * has an unpulled change.
 */
class DeviceSyncEnrollmentTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    /** A syntactically valid 64-hex SHA-256, as the snapshot integrity hash. */
    private function sha256(string $label): string
    {
        return hash('sha256', $label);
    }

    public function test_the_owner_mints_a_single_use_join_token(): void
    {
        $owner = $this->establishOwner();

        $this->postJson('/api/v1/sync/join-tokens', [], $this->bearer($owner->plaintext))
            ->assertCreated()
            ->assertJsonStructure(['data' => ['join_token', 'expires_at', 'business_uuid']]);
    }

    public function test_a_member_device_cannot_mint_a_join_token(): void
    {
        $owner = $this->establishOwner();
        $member = $this->enrollMember($owner->seat->business, 'member');

        $this->postJson('/api/v1/sync/join-tokens', [], $this->bearer($member->plaintext))
            ->assertForbidden();
    }

    public function test_a_device_enrolls_and_receives_a_seat_and_bootstrap(): void
    {
        $owner = $this->establishOwner();
        $minted = $this->enrollment()->mintJoinToken($owner->seat->business);

        // The owner attached a snapshot at cursor C=7 (its own local pull cursor).
        $this->enrollment()->attachBootstrap($minted->token, 7, 'snapshots/seed.sqlite', $this->sha256('seed'));

        $this->postJson('/api/v1/sync/enroll', [
            'join_token' => $minted->plaintext,
            'device_id' => $this->deviceId('joiner'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.seat.role', 'member')
            ->assertJsonPath('data.bootstrap.cursor', 7)
            ->assertJsonPath('data.bootstrap.snapshot_sha256', $this->sha256('seed'))
            ->assertJsonStructure(['data' => ['sync_token', 'bootstrap' => ['snapshot_url']]]);
    }

    public function test_enrollment_is_refused_when_the_allowance_is_full(): void
    {
        // Allowance 1 = the owner seat alone; there is no free slot.
        $owner = $this->establishOwner(allowance: 1);
        $minted = $this->enrollment()->mintJoinToken($owner->seat->business);

        $this->postJson('/api/v1/sync/enroll', [
            'join_token' => $minted->plaintext,
            'device_id' => $this->deviceId('extra'),
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALLOWANCE_EXCEEDED');
    }

    public function test_enrollment_rejects_the_legacy_fallback_device_id(): void
    {
        $owner = $this->establishOwner();
        $minted = $this->enrollment()->mintJoinToken($owner->seat->business);

        $this->postJson('/api/v1/sync/enroll', [
            'join_token' => $minted->plaintext,
            'device_id' => DeviceSubscription::FALLBACK_DEVICE_ID,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FALLBACK_DEVICE_REJECTED');
    }

    public function test_a_join_token_is_single_use(): void
    {
        $owner = $this->establishOwner();
        $minted = $this->enrollment()->mintJoinToken($owner->seat->business);

        $this->postJson('/api/v1/sync/enroll', [
            'join_token' => $minted->plaintext,
            'device_id' => $this->deviceId('first'),
        ])->assertCreated();

        // Redeeming it a second time is refused — a token is a one-shot binding.
        $this->postJson('/api/v1/sync/enroll', [
            'join_token' => $minted->plaintext,
            'device_id' => $this->deviceId('second'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_JOIN_TOKEN');
    }

    public function test_the_owner_seat_cannot_be_revoked(): void
    {
        $owner = $this->establishOwner();

        $this->deleteJson(
            "/api/v1/sync/devices/{$owner->seat->uuid}",
            [],
            $this->bearer($owner->plaintext),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OWNER_SEAT_NON_REVOCABLE');

        $this->assertTrue($owner->seat->refresh()->isActive());
    }

    public function test_a_revoked_seat_makes_check_device_report_not_verified(): void
    {
        $owner = $this->establishOwner();
        $memberDeviceId = $this->deviceId('member');

        // The device also holds a live, verified licensing subscription…
        DeviceSubscription::factory()->active()->create([
            'app_name' => 'Fawateer',
            'device_id' => $memberDeviceId,
        ]);

        $minted = $this->enrollment()->mintJoinToken($owner->seat->business);
        $member = $this->enrollment()->enroll($minted->plaintext, $memberDeviceId);

        // …so check_device reports verified while the seat is live.
        $this->postJson('/api/check_device', ['app_name' => 'Fawateer', 'device_id' => $memberDeviceId])
            ->assertOk()
            ->assertJsonPath('is_verified', 1);

        $this->enrollment()->revokeSeat($member->seat->refresh());

        // The one coupling (Decision 5/D): a revoked sync seat forces NOT verified,
        // even though the subscription itself is untouched.
        $this->postJson('/api/check_device', ['app_name' => 'Fawateer', 'device_id' => $memberDeviceId])
            ->assertOk()
            ->assertJsonPath('is_verified', 0);
    }

    public function test_a_device_joining_an_existing_shop_recovers_a_siblings_unpulled_change(): void
    {
        // The case the design turns on (Decision 13, the 2026-07-29 fix): the
        // bootstrap cursor is the OWNER'S OWN pull cursor C, not a server last_seq.
        $owner = $this->establishOwner(allowance: 3);
        $business = $owner->seat->business;
        $member2 = $this->enrollMember($business, 'member-2');

        // member-2 makes an edit the owner then pulls (owner's cursor becomes C=1).
        $this->syncPost('/api/v1/sync/changes', [
            'changes' => [['row_uuid' => 'row-x', 'table_name' => 'invoices', 'authored_hlc' => 'hlc-1', 'payload' => []]],
        ], $member2->plaintext)->assertCreated();

        $ownerPull = $this->syncGet('/api/v1/sync/changes', $owner->plaintext)->assertOk();
        $ownerCursor = $this->jsonInt($ownerPull, 'meta.next_cursor');
        $this->assertSame(1, $ownerCursor);

        // member-2 then makes a SECOND edit the owner has NOT yet pulled.
        $this->syncPost('/api/v1/sync/changes', [
            'changes' => [['row_uuid' => 'row-y', 'table_name' => 'invoices', 'authored_hlc' => 'hlc-2', 'payload' => []]],
        ], $member2->plaintext)->assertCreated();

        // The owner mints a bootstrap for a 3rd device using its OWN cursor C=1
        // (its snapshot reflects the shop up to seq 1 only).
        $minted = $this->enrollment()->mintJoinToken($business->refresh());
        $this->enrollment()->attachBootstrap($minted->token, $ownerCursor, 'snapshots/seed.sqlite', $this->sha256('seed'));

        $enroll = $this->syncPost('/api/v1/sync/enroll', [
            'join_token' => $minted->plaintext,
            'device_id' => $this->deviceId('member-3'),
        ])->assertCreated();

        $cursor = $this->jsonInt($enroll, 'data.bootstrap.cursor');
        $syncToken = $this->jsonString($enroll, 'data.sync_token');
        $this->assertSame(1, $cursor);

        // Seeding from the snapshot (seq ≤ 1) then pulling from C=1, member-3
        // recovers row-y — the sibling's unpulled change that a server-last_seq
        // cursor would have skipped forever.
        $member3Pull = $this->syncGet("/api/v1/sync/changes?cursor={$cursor}", $syncToken)->assertOk();

        $rows = array_column($this->jsonArray($member3Pull, 'data'), 'row_uuid');
        $this->assertContains('row-y', $rows);
        $this->assertNotContains('row-x', $rows);
    }
}
