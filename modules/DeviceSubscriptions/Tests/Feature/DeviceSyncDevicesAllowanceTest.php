<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Tests\TestCase;

/**
 * The allowance summary on GET /api/v1/sync/devices (ADR 0011).
 *
 * The seat list is `data`; the allowance figures live in `meta` so a client renders
 * "N of M phones used" from the SERVER's numbers, not a value cached at enrollment.
 * `meta.device_allowance` is the business's current allowance; `meta.seats_used` is
 * the active (non-revoked) seat count — the same figure the allowance check enforces.
 */
class DeviceSyncDevicesAllowanceTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    public function test_devices_meta_reports_the_server_allowance_and_seats_used(): void
    {
        $owner = $this->establishOwner(allowance: 3);
        $this->enrollMember($owner->seat->business, 'member'); // owner + 1 member = 2 seats

        $response = $this->syncGet('/api/v1/sync/devices', $owner->plaintext)->assertOk();

        // The number a client shows must come from meta, not from counting `data`.
        $this->assertSame(3, $this->jsonInt($response, 'meta.device_allowance'));
        $this->assertSame(2, $this->jsonInt($response, 'meta.seats_used'));
    }

    public function test_seats_used_counts_only_active_seats(): void
    {
        $owner = $this->establishOwner(allowance: 3);
        $member = $this->enrollMember($owner->seat->business, 'member');

        // Two live seats before the revoke.
        $before = $this->syncGet('/api/v1/sync/devices', $owner->plaintext)->assertOk();
        $this->assertSame(2, $this->jsonInt($before, 'meta.seats_used'));

        // Revoking a member frees the seat; the count drops, the allowance does not.
        $this->enrollment()->revokeSeat($member->seat);

        $after = $this->syncGet('/api/v1/sync/devices', $owner->plaintext)->assertOk();
        $this->assertSame(1, $this->jsonInt($after, 'meta.seats_used'));
        $this->assertSame(3, $this->jsonInt($after, 'meta.device_allowance'));
    }

    public function test_a_single_owner_reads_one_of_its_allowance(): void
    {
        // A lone owner on an allowance-1 plan: 1 of 1, straight from the server.
        $owner = $this->establishOwner(allowance: 1);

        $response = $this->syncGet('/api/v1/sync/devices', $owner->plaintext)->assertOk();

        $this->assertSame(1, $this->jsonInt($response, 'meta.device_allowance'));
        $this->assertSame(1, $this->jsonInt($response, 'meta.seats_used'));
    }
}
