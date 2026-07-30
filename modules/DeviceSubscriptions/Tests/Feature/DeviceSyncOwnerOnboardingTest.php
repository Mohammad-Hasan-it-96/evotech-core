<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Tests\TestCase;

/**
 * Owner onboarding — `POST /api/v1/sync/business` (ADR 0011). This is the head of
 * the enrollment chain: a licensed single device promotes itself into a sync
 * business and receives its owner seat token, authenticated by its licensing
 * identity (it has no sync seat yet). Every other sync flow depends on it.
 */
class DeviceSyncOwnerOnboardingTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function licensedDevice(string $deviceId, array $attributes = []): DeviceSubscription
    {
        return DeviceSubscription::factory()->active()->create(array_merge([
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
        ], $attributes));
    }

    public function test_a_verified_device_establishes_its_business_and_owner_seat(): void
    {
        $deviceId = $this->deviceId('owner-device');
        $this->licensedDevice($deviceId);

        $response = $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
        ])
            ->assertCreated()
            ->assertJsonPath('data.seat.role', 'owner')
            ->assertJsonStructure(['data' => ['sync_token', 'business_uuid', 'device_allowance', 'bootstrap' => ['cursor']]]);

        // The business was created and the licensing row linked to it (Decision 1).
        $this->assertSame(1, DeviceBusiness::query()->count());
        $business = DeviceBusiness::query()->firstOrFail();
        $this->assertSame(
            $business->id,
            DeviceSubscription::query()->where('device_id', $deviceId)->value('business_id'),
        );

        // The returned owner token authenticates against the sync API.
        $this->syncGet('/api/v1/sync/changes', $this->jsonString($response, 'data.sync_token'))->assertOk();
    }

    public function test_establishing_is_idempotent_and_rotates_the_owner_token(): void
    {
        $deviceId = $this->deviceId('owner-device');
        $this->licensedDevice($deviceId);

        $first = $this->postJson('/api/v1/sync/business', ['app_name' => 'Fawateer', 'device_id' => $deviceId])
            ->assertCreated();
        $second = $this->postJson('/api/v1/sync/business', ['app_name' => 'Fawateer', 'device_id' => $deviceId])
            ->assertCreated();

        // No duplicate business, no duplicate owner seat.
        $this->assertSame(1, DeviceBusiness::query()->count());
        $this->assertSame(1, DeviceSeat::query()->count());

        // The credential is rotated: the old token is dead, the new one lives.
        $oldToken = $this->jsonString($first, 'data.sync_token');
        $newToken = $this->jsonString($second, 'data.sync_token');
        $this->assertNotSame($oldToken, $newToken);
        $this->syncGet('/api/v1/sync/changes', $oldToken)->assertUnauthorized();
        $this->syncGet('/api/v1/sync/changes', $newToken)->assertOk();
    }

    public function test_establishing_requires_a_verified_subscription(): void
    {
        // A registered-but-unverified device cannot stand up a business…
        $unverified = $this->deviceId('unverified');
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'device_id' => $unverified]);

        $this->postJson('/api/v1/sync/business', ['app_name' => 'Fawateer', 'device_id' => $unverified])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SUBSCRIPTION_REQUIRED');

        // …nor can a device the platform has never seen.
        $this->postJson('/api/v1/sync/business', ['app_name' => 'Fawateer', 'device_id' => $this->deviceId('ghost')])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SUBSCRIPTION_REQUIRED');

        $this->assertSame(0, DeviceBusiness::query()->count());
    }

    public function test_establishing_rejects_the_fallback_device_id(): void
    {
        // The fallback guard fires before the subscription check, so no row is needed.
        $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => DeviceSubscription::FALLBACK_DEVICE_ID,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FALLBACK_DEVICE_REJECTED');
    }

    public function test_the_allowance_comes_from_the_plan_and_admits_a_member(): void
    {
        // The tier is a property of the plan (Decision 3): retier 'yearly' to 3.
        DevicePlan::query()->whereNull('device_app_id')->where('plan_key', 'yearly')
            ->update(['device_allowance' => 3]);

        $deviceId = $this->deviceId('owner-device');
        $this->licensedDevice($deviceId); // active() puts it on the 'yearly' plan

        $establish = $this->postJson('/api/v1/sync/business', ['app_name' => 'Fawateer', 'device_id' => $deviceId])
            ->assertCreated()
            ->assertJsonPath('data.device_allowance', 3);

        // With a real allowance, the owner can mint a token and a member can join.
        $ownerToken = $this->jsonString($establish, 'data.sync_token');
        $mint = $this->syncPost('/api/v1/sync/join-tokens', [], $ownerToken)->assertCreated();

        $this->postJson('/api/v1/sync/enroll', [
            'join_token' => $this->jsonString($mint, 'data.join_token'),
            'device_id' => $this->deviceId('member-device'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.seat.role', 'member');
    }
}
