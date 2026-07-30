<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\DeviceSubscriptions\Tests\Feature\Concerns\InteractsWithSync;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * Device tiers wired into provisioning (ADR 0011, Decision 3). A business's seat
 * allowance is derived from the plan its subscription holds — `device_allowance`,
 * a first-class dashboard-provisioned property of the plan — rather than a bolt-on
 * config map. The tier must NOT leak into the shipped `getPlans` wire contract.
 */
class DeviceSyncPlanTiersTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    /** A verified Fawateer device holding `$planId`, ready to onboard as an owner. */
    private function licensedDevice(string $deviceId, ?string $planId): DeviceSubscription
    {
        return DeviceSubscription::factory()->active()->create([
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
            'plan_id' => $planId,
        ]);
    }

    /** Create a shared-catalog plan carrying a device tier. */
    private function sharedPlan(string $key, int $allowance): DevicePlan
    {
        return DevicePlan::query()->create([
            'device_app_id' => null,
            'plan_key' => $key,
            'title' => $key,
            'duration_months' => 12,
            'device_allowance' => $allowance,
            'price' => 20,
            'enabled' => true,
        ]);
    }

    /**
     * Onboard `$deviceId` as an owner over HTTP and hand back the response.
     *
     * @return TestResponse<JsonResponse>
     */
    private function onboard(string $deviceId): TestResponse
    {
        return $this->postJson('/api/v1/sync/business', [
            'app_name' => 'Fawateer',
            'device_id' => $deviceId,
        ]);
    }

    public function test_owner_onboarding_derives_the_allowance_from_the_plan(): void
    {
        $this->sharedPlan('trio', 3);
        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId, 'trio');

        $this->onboard($deviceId)
            ->assertCreated()
            ->assertJsonPath('data.device_allowance', 3);

        $this->assertSame(3, DeviceBusiness::query()->firstOrFail()->device_allowance);
    }

    public function test_a_plan_with_the_default_tier_stays_single_device(): void
    {
        // 'yearly' is seeded from config; its device_allowance takes the column
        // default of 1, so a plan nobody has tiered keeps its old single-device
        // behaviour exactly.
        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId, 'yearly');

        $this->onboard($deviceId)
            ->assertCreated()
            ->assertJsonPath('data.device_allowance', 1);
    }

    public function test_the_legacy_config_override_is_used_when_no_plan_row_matches(): void
    {
        // A plan id present only in the operator override map (no device_plans row).
        Config::set('device-subscriptions.sync.plan_allowance', ['ghost' => 5]);

        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId, 'ghost');

        $this->onboard($deviceId)
            ->assertCreated()
            ->assertJsonPath('data.device_allowance', 5);
    }

    public function test_an_unknown_plan_falls_back_to_the_default_allowance(): void
    {
        $deviceId = $this->deviceId('owner');
        $this->licensedDevice($deviceId, 'no_such_plan');

        $this->onboard($deviceId)
            ->assertCreated()
            ->assertJsonPath('data.device_allowance', 1);
    }

    public function test_a_trial_owner_with_no_plan_takes_the_default(): void
    {
        $deviceId = $this->deviceId('owner');
        // Verified but never bought a plan (plan_id null).
        $this->licensedDevice($deviceId, null);

        $this->onboard($deviceId)
            ->assertCreated()
            ->assertJsonPath('data.device_allowance', 1);
    }

    public function test_the_tier_is_not_leaked_into_the_legacy_getplans_payload(): void
    {
        $this->sharedPlan('trio', 3);

        // The shipped app has no concept of device counts; the wire contract must
        // stay byte-identical. No plan in getPlans carries device_allowance.
        $plans = $this->getJson('/api/getPlans')->assertOk()->json('plans');

        $this->assertIsArray($plans);
        foreach ($plans as $plan) {
            $this->assertIsArray($plan);
            $this->assertArrayNotHasKey('device_allowance', $plan);
        }
    }

    public function test_an_app_with_its_own_catalog_resolves_its_own_tier(): void
    {
        // Same key in two scopes with different tiers: the app's own plan must win
        // for that app, exactly as duration resolution does.
        $app = DeviceApp::query()->create([
            'name' => 'Kaseer',
            'slug' => 'kaseer',
            'label' => 'Kaseer',
            'trial_days' => 0,
            'uses_shared_plans' => false,
        ]);
        DevicePlan::query()->create([
            'device_app_id' => $app->id,
            'plan_key' => 'team',
            'title' => 'Team (app)',
            'duration_months' => 12,
            'device_allowance' => 5,
            'price' => 30,
            'enabled' => true,
        ]);
        $this->sharedPlan('team', 2);

        $this->assertSame(5, DevicePlan::allowanceFor('team', 'Kaseer'));
        $this->assertSame(2, DevicePlan::allowanceFor('team', 'Fawateer')); // shared app
        $this->assertSame(2, DevicePlan::allowanceFor('team', null));       // app-less → shared
    }

    public function test_the_resolver_returns_null_for_an_absent_plan(): void
    {
        $this->assertNull(DevicePlan::allowanceFor(null, 'Fawateer'));
        $this->assertNull(DevicePlan::allowanceFor('missing', 'Fawateer'));
    }

    // --- Dashboard provisioning ------------------------------------------------

    public function test_staff_can_provision_a_tier_on_a_new_plan(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/device-plans', [
            'key' => 'quintet',
            'title' => 'Five devices',
            'duration_months' => 12,
            'device_allowance' => 5,
            'price' => 40,
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'quintet')
            ->assertJsonPath('data.device_allowance', 5);

        $this->assertSame(5, DevicePlan::allowanceFor('quintet', 'Fawateer'));
    }

    public function test_a_new_plan_defaults_to_single_device_when_no_tier_is_given(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/device-plans', [
            'key' => 'plain',
            'title' => 'No tier given',
            'duration_months' => 6,
            'price' => 10,
        ])
            ->assertCreated()
            ->assertJsonPath('data.device_allowance', 1);
    }

    public function test_staff_can_retier_an_existing_plan(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $plan = $this->sharedPlan('trio', 3);

        $this->patchJson("/api/v1/device-plans/{$plan->uuid}", ['device_allowance' => 5])
            ->assertOk()
            ->assertJsonPath('data.device_allowance', 5);

        $this->assertSame(5, DevicePlan::allowanceFor('trio', 'Fawateer'));
    }

    public function test_a_zero_tier_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/device-plans', [
            'key' => 'zero',
            'title' => 'No seats',
            'duration_months' => 12,
            'device_allowance' => 0,
            'price' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
