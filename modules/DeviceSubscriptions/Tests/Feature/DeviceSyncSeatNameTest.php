<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Tests\TestCase;

/**
 * Owner-assigned seat display names (ADR 0011, seat-name addendum). A nullable,
 * owner-editable label so the device registry can tell two identical tills apart.
 * Proposed at enroll/onboard, edited by the owner via PATCH, returned everywhere a
 * seat is; the server is authoritative over trimming/truncation, and an owner-set
 * name is never clobbered by a later re-enroll. Existing seats stay unnamed.
 */
class DeviceSyncSeatNameTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    /** A verified Fawateer device that can onboard as an owner over HTTP. */
    private function licensedDevice(string $deviceId): DeviceSubscription
    {
        return DeviceSubscription::factory()->active()->create([
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
        ]);
    }

    // --- Proposing a name at onboarding / enrollment ---------------------------

    public function test_owner_onboarding_accepts_and_returns_a_proposed_name(): void
    {
        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId);

        $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
            'name' => 'الكاشير الرئيسي',
        ])
            ->assertCreated()
            ->assertJsonPath('data.seat.name', 'الكاشير الرئيسي');

        $this->assertSame('الكاشير الرئيسي', DeviceSeat::query()->firstOrFail()->name);
    }

    public function test_a_member_proposes_its_name_at_enroll_and_gets_it_back(): void
    {
        $owner = $this->establishOwner(allowance: 3);
        $minted = $this->enrollment()->mintJoinToken($owner->seat->business);

        $this->postJson('/api/v1/sync/enroll', [
            'join_token' => $minted->plaintext,
            'device_id' => $this->deviceId('member'),
            'name' => 'Samsung A12',
        ])
            ->assertCreated()
            ->assertJsonPath('data.seat.role', 'member')
            ->assertJsonPath('data.seat.name', 'Samsung A12');
    }

    public function test_a_name_is_trimmed_and_truncated_to_forty_characters(): void
    {
        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId);

        // 45 Arabic chars wrapped in whitespace: trim first, then cap at 40 chars
        // (characters, not bytes — the multibyte cap is the point).
        $proposed = '  '.str_repeat('ا', 45).'  ';

        $response = $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
            'name' => $proposed,
        ])->assertCreated();

        $name = $this->jsonString($response, 'data.seat.name');
        $this->assertSame(str_repeat('ا', 40), $name);
        $this->assertSame(40, mb_strlen($name));
    }

    public function test_a_whitespace_only_name_is_stored_as_null(): void
    {
        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId);

        $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
            'name' => '   ',
        ])
            ->assertCreated()
            ->assertJsonPath('data.seat.name', null);
    }

    public function test_a_seat_onboarded_without_a_name_is_unnamed(): void
    {
        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId);

        $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
        ])
            ->assertCreated()
            ->assertJsonPath('data.seat.name', null);
    }

    // --- Listing ---------------------------------------------------------------

    public function test_get_devices_returns_the_name_on_every_seat(): void
    {
        $owner = $this->establishOwner(allowance: 3);
        $this->enrollment()->renameSeat($owner->seat, 'الكاشير الرئيسي');
        $this->enrollMember($owner->seat->business, 'member'); // no name — stays null

        $response = $this->syncGet('/api/v1/sync/devices', $owner->plaintext)->assertOk();

        $names = collect($this->jsonArray($response, 'data'))
            ->map(fn (mixed $seat): mixed => is_array($seat) && array_key_exists('name', $seat) ? $seat['name'] : '__missing__')
            ->all();

        $this->assertContains('الكاشير الرئيسي', $names);
        $this->assertContains(null, $names); // the unnamed member seat
        $this->assertNotContains('__missing__', $names); // name key present on every seat
    }

    // --- Owner PATCH -----------------------------------------------------------

    public function test_the_owner_renames_a_member_seat(): void
    {
        $owner = $this->establishOwner(allowance: 3);
        $member = $this->enrollMember($owner->seat->business, 'member');

        $this->syncPatch("/api/v1/sync/devices/{$member->seat->uuid}", ['name' => 'صندوق ٢'], $owner->plaintext)
            ->assertOk()
            ->assertJsonPath('data.name', 'صندوق ٢');

        $this->assertSame('صندوق ٢', $member->seat->refresh()->name);
    }

    public function test_the_owner_can_rename_its_own_seat(): void
    {
        // Unlike revoke, the owner seat is a valid rename target.
        $owner = $this->establishOwner();

        $this->syncPatch("/api/v1/sync/devices/{$owner->seat->uuid}", ['name' => 'المالك'], $owner->plaintext)
            ->assertOk()
            ->assertJsonPath('data.name', 'المالك');
    }

    public function test_renaming_clears_the_name_with_null(): void
    {
        $owner = $this->establishOwner(allowance: 3);
        $member = $this->enrollMember($owner->seat->business, 'member');
        $this->enrollment()->renameSeat($member->seat, 'temp');

        $this->syncPatch("/api/v1/sync/devices/{$member->seat->uuid}", ['name' => null], $owner->plaintext)
            ->assertOk()
            ->assertJsonPath('data.name', null);

        $this->assertNull($member->seat->refresh()->name);
    }

    public function test_a_member_cannot_rename_a_seat(): void
    {
        $owner = $this->establishOwner(allowance: 3);
        $member = $this->enrollMember($owner->seat->business, 'member');

        // Owner-only, enforced at the endpoint (the suite's convention is to assert
        // the 403 status; the envelope code for this guard is HTTP_ERROR).
        $this->syncPatch("/api/v1/sync/devices/{$member->seat->uuid}", ['name' => 'nope'], $member->plaintext)
            ->assertForbidden();

        // The rejected write left the seat untouched.
        $this->assertNull($member->seat->refresh()->name);
    }

    public function test_renaming_a_seat_in_another_business_is_not_found(): void
    {
        $ours = $this->establishOwner(ownerLabel: 'ours');
        $theirs = $this->establishOwner(ownerLabel: 'theirs');

        // A valid owner token, a valid seat uuid — but it belongs to another
        // business, so it is indistinguishable from a seat that does not exist.
        $this->syncPatch("/api/v1/sync/devices/{$theirs->seat->uuid}", ['name' => 'x'], $ours->plaintext)
            ->assertNotFound();

        $this->assertNull($theirs->seat->refresh()->name);
    }

    public function test_the_patch_requires_the_name_key(): void
    {
        $owner = $this->establishOwner();

        $this->syncPatch("/api/v1/sync/devices/{$owner->seat->uuid}", [], $owner->plaintext)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    // --- Invariants ------------------------------------------------------------

    public function test_two_seats_may_share_a_name(): void
    {
        $owner = $this->establishOwner(allowance: 3);
        $a = $this->enrollMember($owner->seat->business, 'till-a');
        $b = $this->enrollMember($owner->seat->business, 'till-b');

        $this->syncPatch("/api/v1/sync/devices/{$a->seat->uuid}", ['name' => 'الكاشير'], $owner->plaintext)->assertOk();
        $this->syncPatch("/api/v1/sync/devices/{$b->seat->uuid}", ['name' => 'الكاشير'], $owner->plaintext)->assertOk();

        $this->assertSame('الكاشير', $a->seat->refresh()->name);
        $this->assertSame('الكاشير', $b->seat->refresh()->name);
    }

    public function test_a_reinstall_does_not_clobber_an_owner_chosen_name(): void
    {
        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId);

        // First onboard, then the owner renames the seat deliberately.
        $establish = $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
        ])->assertCreated();
        $ownerToken = $this->jsonString($establish, 'data.sync_token');
        $seatUuid = $this->jsonString($establish, 'data.seat.uuid');

        $this->syncPatch("/api/v1/sync/devices/{$seatUuid}", ['name' => 'المالك'], $ownerToken)->assertOk();

        // A reinstall re-onboards and re-proposes the phone model — the owner's
        // chosen name must win.
        $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
            'name' => 'Samsung SM-A125',
        ])
            ->assertCreated()
            ->assertJsonPath('data.seat.name', 'المالك');
    }
}
