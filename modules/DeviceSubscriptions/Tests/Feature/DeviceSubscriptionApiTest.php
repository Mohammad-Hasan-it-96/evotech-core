<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * Covers the legacy compatibility shim (ADR 0010): the shipped app calls these
 * exact unversioned paths with no auth and expects the legacy JSON shapes.
 */
class DeviceSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    public function test_create_device_registers_a_new_device_unverified(): void
    {
        $this->postJson('/api/create_device', [
            'app_name' => 'smart_agent',
            'device_id' => 'dev-1',
            'full_name' => 'Sara',
            'phone' => '0999',
            'fcm_token' => 'tok-1',
        ])
            ->assertOk()
            ->assertExactJson([
                'is_verified' => 0,
                'expires_at' => null,
                'plan' => null,
                'fcm_token' => 'tok-1',
            ]);

        $this->assertDatabaseHas('device_subscriptions', [
            'app_name' => 'smart_agent',
            'device_id' => 'dev-1',
            'is_verified' => false,
        ]);
    }

    public function test_create_device_is_idempotent_and_refreshes_token(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'smart_agent',
            'device_id' => 'dev-1',
            'fcm_token' => 'old',
        ]);

        $this->postJson('/api/create_device', [
            'app_name' => 'smart_agent',
            'device_id' => 'dev-1',
            'full_name' => 'Sara',
            'phone' => '0999',
            'fcm_token' => 'new',
        ])->assertOk()->assertJsonPath('fcm_token', 'new');

        $this->assertSame(1, DeviceSubscription::query()->count());
    }

    public function test_check_device_reports_expired_as_unverified(): void
    {
        DeviceSubscription::factory()->expired()->create([
            'app_name' => 'smart_agent',
            'device_id' => 'dev-1',
        ]);

        $this->postJson('/api/check_device', ['app_name' => 'smart_agent', 'device_id' => 'dev-1'])
            ->assertOk()
            ->assertJsonPath('is_verified', 0)
            ->assertJsonPath('success', true);
    }

    public function test_check_device_returns_404_for_unknown_device(): void
    {
        $this->postJson('/api/check_device', ['app_name' => 'smart_agent', 'device_id' => 'nope'])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_get_plans_returns_the_legacy_catalog(): void
    {
        $this->getJson('/api/getPlans')
            ->assertOk()
            ->assertJsonPath('currency.code', 'USD')
            ->assertJsonPath('plans.0.id', 'half_year')
            ->assertJsonPath('plans.1.id', 'yearly')
            ->assertJsonPath('plans.1.recommended', true);
    }

    public function test_add_review_stores_rating(): void
    {
        DeviceSubscription::factory()->create(['app_name' => 'smart_agent', 'device_id' => 'dev-1']);

        $this->postJson('/api/add_review', [
            'app_name' => 'smart_agent',
            'device_id' => 'dev-1',
            'stars' => 5,
            'comment' => 'great',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_subscriptions', ['device_id' => 'dev-1', 'stars' => 5]);
    }

    public function test_activate_device_requires_staff_auth(): void
    {
        DeviceSubscription::factory()->create(['device_id' => 'dev-1']);

        $this->postJson('/api/activateDevice', ['device_id' => 'dev-1', 'plan_id' => 'yearly'])
            ->assertUnauthorized();
    }

    public function test_staff_can_activate_a_device_with_a_yearly_term(): void
    {
        $this->actAsStaff();
        DeviceSubscription::factory()->create(['device_id' => 'dev-1']);

        $this->postJson('/api/activateDevice', ['device_id' => 'dev-1', 'plan_id' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('is_verified', 1)
            ->assertJsonPath('plan', 'yearly');

        $device = DeviceSubscription::query()->firstOrFail();
        $this->assertTrue($device->is_verified);
        $this->assertEqualsWithDelta(365, Carbon::now()->diffInDays($device->expires_at), 2);
    }

    public function test_get_device_listing_requires_staff_auth(): void
    {
        $this->getJson('/api/getDevice')->assertUnauthorized();
    }

    public function test_staff_can_list_devices(): void
    {
        $this->actAsStaff();
        DeviceSubscription::factory()->count(3)->create();

        $this->getJson('/api/getDevice')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    public function test_versioned_staff_listing_uses_the_envelope(): void
    {
        $this->actAsStaff();
        DeviceSubscription::factory()->create();

        $this->getJson('/api/v1/device-subscriptions')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'app_name', 'device_id', 'is_active']],
                'meta',
                'links',
            ]);
    }

    public function test_sweep_expiry_command_runs(): void
    {
        DeviceSubscription::factory()->expired()->create();

        $this->assertSame(0, Artisan::call('device-subscriptions:sweep-expiry'));
    }
}
