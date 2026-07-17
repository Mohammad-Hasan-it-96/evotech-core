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
        $this->freezeTime();

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
                'is_trial' => 0,
                'expires_at' => null,
                'plan' => null,
                'fcm_token' => 'tok-1',
                'server_time' => Carbon::now()->toISOString(),
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
            // SmartAgent requires `success`; Fawateer ignores it. Keep sending it.
            ->assertJsonPath('success', true)
            ->assertJsonPath('currency.code', 'USD')
            ->assertJsonPath('currency.symbol', '$')
            ->assertJsonPath('plans.0.id', 'half_year')
            ->assertJsonPath('plans.1.id', 'yearly')
            ->assertJsonPath('plans.1.recommended', true);
    }

    /** Every field the shipped Fawateer plan parser reads must be present. */
    public function test_get_plans_carries_every_field_the_app_parses(): void
    {
        $this->getJson('/api/getPlans')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'currency' => ['code', 'symbol'],
                'plans' => [['id', 'title', 'description', 'duration_months', 'price', 'price_after_discount', 'enabled', 'recommended']],
            ]);
    }

    /**
     * The shipped Fawateer app rotates its push token by POSTing update_my_data
     * with ONLY app_name + device_id + fcm_token. Requiring full_name/phone 422'd
     * it, so the token was never refreshed and the live-unlock push silently died.
     */
    public function test_update_my_data_accepts_an_fcm_only_rotation(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'full_name' => 'Sara',
            'phone' => '0999',
            'fcm_token' => 'old',
        ]);

        $this->postJson('/api/update_my_data', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'fcm_token' => 'rotated',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_subscriptions', [
            'device_id' => 'dev-1',
            'fcm_token' => 'rotated',
            // Untouched: a partial update must not blank what it did not send.
            'full_name' => 'Sara',
            'phone' => '0999',
        ]);
    }

    /** Editing the profile must not wipe the push token (it used to). */
    public function test_update_my_data_profile_edit_preserves_the_push_token(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'fcm_token' => 'keep-me',
        ]);

        $this->postJson('/api/update_my_data', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'full_name' => 'Renamed',
            'phone' => '0777',
        ])->assertOk();

        $this->assertDatabaseHas('device_subscriptions', [
            'device_id' => 'dev-1',
            'full_name' => 'Renamed',
            'phone' => '0777',
            'fcm_token' => 'keep-me',
        ]);
    }

    /**
     * The purchase intent the app files via create_device. Dropping these fields
     * meant the operator never learned the user wanted to buy.
     */
    public function test_create_device_records_a_plan_request(): void
    {
        $this->postJson('/api/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'full_name' => 'Sara',
            'phone' => '0999',
            'requested_plan' => '12_months',
            'contact_method' => 'whatsapp',
            'status' => 'pending',
        ])->assertOk();

        $this->assertDatabaseHas('device_subscriptions', [
            'device_id' => 'dev-1',
            'requested_plan' => '12_months',
            'contact_method' => 'whatsapp',
            'status' => 'pending',
        ]);
    }

    /** A plan request normally arrives for an already-registered device. */
    public function test_plan_request_is_recorded_on_an_existing_device(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
        ]);

        $this->postJson('/api/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'full_name' => 'Sara',
            'phone' => '0999',
            'requested_plan' => '6_months',
            'contact_method' => 'telegram',
            'status' => 'pending',
        ])->assertOk();

        $this->assertSame(1, DeviceSubscription::query()->count());
        $this->assertDatabaseHas('device_subscriptions', [
            'device_id' => 'dev-1',
            'requested_plan' => '6_months',
            'status' => 'pending',
        ]);
    }

    /** A device on a trial reports is_trial; a paid one does not. */
    public function test_check_device_reports_trial_state(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'trial-dev',
            'is_verified' => true,
            'plan_id' => null,
            'expires_at' => Carbon::now()->addDays(30),
            'trial_expires_at' => Carbon::now()->addDays(30),
        ]);

        $this->postJson('/api/check_device', ['app_name' => 'Fawateer', 'device_id' => 'trial-dev'])
            ->assertOk()
            ->assertJsonPath('is_verified', 1)
            ->assertJsonPath('is_trial', 1);

        // Converting to a paid plan ends the trial without clearing any flag.
        DeviceSubscription::query()->where('device_id', 'trial-dev')->update(['plan_id' => 'yearly']);

        $this->postJson('/api/check_device', ['app_name' => 'Fawateer', 'device_id' => 'trial-dev'])
            ->assertOk()
            ->assertJsonPath('is_verified', 1)
            ->assertJsonPath('is_trial', 0);
    }

    /** check_device must always carry server_time — the app's trusted clock. */
    public function test_check_device_returns_server_time(): void
    {
        $this->freezeTime();
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'device_id' => 'dev-1']);

        $this->postJson('/api/check_device', ['app_name' => 'Fawateer', 'device_id' => 'dev-1'])
            ->assertOk()
            ->assertJsonPath('server_time', Carbon::now()->toISOString());
    }

    /**
     * Phase B: a fresh Fawateer install is unlocked for 30 days with no operator
     * action. The app gates on is_verified + expires_at, so the trial has to land
     * in those fields, not in a bespoke one.
     */
    public function test_first_registration_grants_a_thirty_day_trial(): void
    {
        $this->freezeTime();

        $this->postJson('/api/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'new-dev',
            'full_name' => 'Sara',
            'phone' => '0999',
        ])
            ->assertOk()
            ->assertJsonPath('is_verified', 1)
            ->assertJsonPath('is_trial', 1)
            ->assertJsonPath('plan', null)
            // startOfSecond: the timestamp column has second precision.
            ->assertJsonPath('expires_at', Carbon::now()->addDays(30)->startOfSecond()->toJSON());

        $device = DeviceSubscription::query()->where('device_id', 'new-dev')->sole();
        $this->assertNotNull($device->trial_expires_at);
        $this->assertTrue($device->isOnTrial());
    }

    /**
     * The anti-abuse anchor: ANDROID_ID survives uninstall and data-clear, so a
     * reinstall re-registers the same device_id and must NOT mint a second trial.
     */
    public function test_reinstall_cannot_farm_a_second_trial(): void
    {
        $expired = Carbon::now()->subDay()->startOfSecond();
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'is_verified' => true,
            'plan_id' => null,
            'expires_at' => $expired,
            'trial_expires_at' => $expired,
        ]);

        $this->postJson('/api/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'full_name' => 'Sara',
            'phone' => '0999',
        ])
            ->assertOk()
            // Trial already spent: still expired, still locked.
            ->assertJsonPath('is_verified', 0)
            ->assertJsonPath('is_trial', 0);

        $this->assertSame(1, DeviceSubscription::query()->count());

        $expiresAt = DeviceSubscription::query()->where('device_id', 'dev-1')->sole()->expires_at;
        $this->assertNotNull($expiresAt);
        $this->assertTrue(
            $expired->equalTo($expiresAt),
            'The spent trial expiry must not be extended by re-registering.',
        );
    }

    /** SmartAgent configures no trial — registering must not grant one. */
    public function test_smart_agent_registration_grants_no_trial(): void
    {
        $this->postJson('/api/create_device', [
            'app_name' => 'SmartAgent',
            'device_id' => 'sa-1',
            'full_name' => 'Ali',
            'phone' => '0111',
        ])
            ->assertOk()
            ->assertJsonPath('is_verified', 0)
            ->assertJsonPath('is_trial', 0)
            ->assertJsonPath('expires_at', null);

        $this->assertNull(DeviceSubscription::query()->where('device_id', 'sa-1')->sole()->trial_expires_at);
    }

    /** An app with no config entry inherits nobody's trial. */
    public function test_unknown_app_grants_no_trial(): void
    {
        $this->postJson('/api/create_device', [
            'app_name' => 'SomeFutureApp',
            'device_id' => 'x-1',
            'full_name' => 'Nobody',
            'phone' => '0000',
        ])->assertOk()->assertJsonPath('is_trial', 0);
    }

    /** Operator activation converts a trial to paid — no new endpoint, no flag to clear. */
    public function test_operator_activation_converts_a_trial_to_paid(): void
    {
        $trialEnd = Carbon::now()->addDays(30);
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'is_verified' => true,
            'plan_id' => null,
            'expires_at' => $trialEnd,
            'trial_expires_at' => $trialEnd,
            'status' => 'pending',
            'requested_plan' => '12_months',
        ]);

        $this->actAsStaff();
        $this->postJson('/api/activateDevice', ['device_id' => 'dev-1', 'plan_id' => 'yearly'])->assertOk();

        $this->postJson('/api/check_device', ['app_name' => 'Fawateer', 'device_id' => 'dev-1'])
            ->assertOk()
            ->assertJsonPath('is_verified', 1)
            ->assertJsonPath('is_trial', 0)
            ->assertJsonPath('plan', 'yearly');

        // trial_expires_at is retained as the record that a trial was already used.
        $this->assertNotNull(DeviceSubscription::query()->where('device_id', 'dev-1')->sole()->trial_expires_at);
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
