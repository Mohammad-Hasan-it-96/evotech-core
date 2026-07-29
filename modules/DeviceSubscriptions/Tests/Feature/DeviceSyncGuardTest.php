<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Tests\TestCase;

/**
 * The `device-sync` guard (ADR 0011): a per-device credential that, unlike
 * `auth:product`, resolves a business scope — so it is where cross-business
 * isolation begins. These tests pin that only a live seat authenticates and that
 * a seat can never reach another business's records.
 */
class DeviceSyncGuardTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    public function test_pull_requires_a_token(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/sync/changes')->assertUnauthorized();
    }

    public function test_pull_rejects_an_unknown_token(): void
    {
        $this->syncGet('/api/v1/sync/changes', 'evosync_deadbeef_notarealtokenatall')
            ->assertUnauthorized();
    }

    public function test_a_valid_seat_token_authenticates(): void
    {
        $owner = $this->establishOwner();

        $this->syncGet('/api/v1/sync/changes', $owner->plaintext)
            ->assertOk()
            ->assertJsonPath('meta.next_cursor', 0);
    }

    public function test_a_revoked_seat_token_is_rejected(): void
    {
        $owner = $this->establishOwner();
        $member = $this->enrollMember($owner->seat->business, 'member');

        // The member authenticates before revocation…
        $this->syncGet('/api/v1/sync/changes', $member->plaintext)->assertOk();

        $this->enrollment()->revokeSeat($member->seat->refresh());

        // …and is locked out the instant its seat is revoked (no wipe, just auth).
        $this->syncGet('/api/v1/sync/changes', $member->plaintext)
            ->assertUnauthorized();
    }

    public function test_a_seat_cannot_revoke_a_device_in_another_business(): void
    {
        $businessA = $this->establishOwner(ownerLabel: 'owner-a');
        $businessB = $this->establishOwner(ownerLabel: 'owner-b');
        $victim = $this->enrollMember($businessB->seat->business, 'victim-b');

        // Owner A presents a real seat uuid from business B: it must be
        // indistinguishable from a non-existent record — 404, never 403.
        $this->syncDelete("/api/v1/sync/devices/{$victim->seat->uuid}", $businessA->plaintext)
            ->assertNotFound();

        // And the victim's seat is untouched.
        $this->assertTrue($victim->seat->refresh()->isActive());
    }

    public function test_a_business_scopes_its_own_seats_only(): void
    {
        $businessA = $this->establishOwner(ownerLabel: 'owner-a');
        $this->establishOwner(ownerLabel: 'owner-b');

        $response = $this->syncGet('/api/v1/sync/devices', $businessA->plaintext)
            ->assertOk();

        // Business A lists exactly its own single (owner) seat, never B's.
        $response->assertJsonCount(1, 'data');
        $this->assertSame(2, DeviceBusiness::query()->count());
    }
}
