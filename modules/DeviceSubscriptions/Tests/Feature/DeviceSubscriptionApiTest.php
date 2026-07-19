<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\DeviceSubscriptions\Application\Services\DeviceCatalogStore;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;
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

    /**
     * Give an app a catalog of its own, overriding the shared list.
     *
     * Writes rows rather than setting config: the catalog moved into the database
     * and config is only the empty-table fallback, so a config override here would
     * assert against a source production never reads.
     *
     * @param  array<int, array<string, mixed>>  $plans
     */
    private function giveAppOwnPlans(string $appName, array $plans): void
    {
        $app = DeviceApp::query()->where('name', $appName)->sole();
        $app->update(['uses_shared_plans' => false]);

        foreach ($plans as $index => $plan) {
            DevicePlan::create([
                'device_app_id' => $app->id,
                'plan_key' => $plan['id'],
                'title' => $plan['title'],
                'description' => $plan['description'] ?? null,
                'duration_months' => $plan['duration_months'],
                'price' => $plan['price'],
                'price_after_discount' => $plan['price_after_discount'] ?? null,
                'enabled' => $plan['enabled'] ?? true,
                'recommended' => $plan['recommended'] ?? false,
                'sort_order' => $index,
            ]);
        }

        app(DeviceCatalogStore::class)->flush();
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
     * The app records which Google account holds the device's Drive backups by
     * sending google_account alone — the same partial shape it already uses to
     * rotate its push token.
     */
    public function test_update_my_data_accepts_a_google_account_alone(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'full_name' => 'Sara',
            'phone' => '0999',
            'fcm_token' => 'keep-me',
        ]);

        $this->postJson('/api/update_my_data', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'google_account' => 'sara.backups@gmail.com',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_subscriptions', [
            'device_id' => 'dev-1',
            'google_account' => 'sara.backups@gmail.com',
            // Untouched: a partial update must not blank what it did not send.
            'full_name' => 'Sara',
            'phone' => '0999',
            'fcm_token' => 'keep-me',
        ]);
    }

    /** A typo'd address would be useless to support and misleading to the user. */
    public function test_update_my_data_rejects_an_invalid_google_account(): void
    {
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'device_id' => 'dev-1']);

        $this->postJson('/api/update_my_data', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'google_account' => 'not-an-email',
        ])
            ->assertStatus(422)
            // The shim keeps the legacy error body, not the platform envelope.
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation error');

        $this->assertDatabaseHas('device_subscriptions', [
            'device_id' => 'dev-1',
            'google_account' => null,
        ]);
    }

    /**
     * Signing out of Drive has to be expressible. This is the one field where an
     * explicit null is a *value* rather than an omission — the others keep
     * filled() semantics so a stray null cannot blank a customer's name on a
     * public endpoint.
     */
    public function test_update_my_data_clears_the_google_account_on_explicit_null(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'full_name' => 'Sara',
            'google_account' => 'sara.backups@gmail.com',
        ]);

        $this->postJson('/api/update_my_data', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'google_account' => null,
        ])->assertOk();

        $this->assertDatabaseHas('device_subscriptions', [
            'device_id' => 'dev-1',
            'google_account' => null,
            'full_name' => 'Sara',
        ]);
    }

    /** Omitting the key leaves a stored account alone — that is the difference. */
    public function test_update_my_data_leaves_the_google_account_when_omitted(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'google_account' => 'sara.backups@gmail.com',
        ]);

        $this->postJson('/api/update_my_data', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'fcm_token' => 'rotated',
        ])->assertOk();

        $this->assertDatabaseHas('device_subscriptions', [
            'device_id' => 'dev-1',
            'google_account' => 'sara.backups@gmail.com',
        ]);
    }

    /**
     * check_device is public and unauthenticated, and a device id is not a secret
     * — the app displays it with a copy button and tells users to send it to
     * support over WhatsApp. Returning the real address here would hand anyone
     * holding an id a customer's email.
     */
    public function test_check_device_masks_the_google_account(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'google_account' => 'sara.backups@gmail.com',
        ]);

        $response = $this->postJson('/api/check_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
        ])->assertOk();

        $response->assertJsonPath('google_account', 's••••backups@gmail.com');

        // The assertion that matters: the raw address must not appear anywhere in
        // the body, however the field is shaped.
        $this->assertStringNotContainsString(
            'sara.backups@gmail.com',
            $response->getContent() ?: '',
        );
    }

    public function test_check_device_returns_null_when_no_google_account_is_set(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'google_account' => null,
        ]);

        $this->postJson('/api/check_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
        ])
            ->assertOk()
            ->assertJsonPath('google_account', null);
    }

    /** Staff are authenticated and need the real address to actually help. */
    public function test_the_staff_console_shows_the_full_google_account(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'google_account' => 'sara.backups@gmail.com',
        ]);

        $this->actAsStaff();

        $this->getJson('/api/v1/device-subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.google_account', 'sara.backups@gmail.com');
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

    /** Phase C: the operator's work queue — devices with an open purchase intent. */
    public function test_staff_can_filter_the_pending_request_queue(): void
    {
        DeviceSubscription::factory()->create(['device_id' => 'wants-to-buy', 'status' => 'pending']);
        DeviceSubscription::factory()->count(2)->create(['status' => null]);

        $this->actAsStaff();
        $this->getJson('/api/v1/device-subscriptions?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_id', 'wants-to-buy')
            ->assertJsonPath('data.0.status', 'pending');
    }

    /** Several apps share this deployment; the console must separate them. */
    public function test_staff_can_filter_by_app(): void
    {
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'device_id' => 'f-1']);
        DeviceSubscription::factory()->create(['app_name' => 'SmartAgent', 'device_id' => 's-1']);

        $this->actAsStaff();
        $this->getJson('/api/v1/device-subscriptions?app_name=Fawateer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_id', 'f-1');
    }

    /** A customer messages on WhatsApp: the operator searches by what they have. */
    public function test_staff_can_search_by_phone_name_or_device_id(): void
    {
        DeviceSubscription::factory()->create([
            'device_id' => 'dev-abc',
            'full_name' => 'Sara Ahmad',
            'phone' => '0999123',
        ]);
        DeviceSubscription::factory()->create(['device_id' => 'other', 'full_name' => 'Ali', 'phone' => '0777']);

        $this->actAsStaff();

        foreach (['0999123', 'Sara', 'dev-abc'] as $term) {
            $this->getJson('/api/v1/device-subscriptions?q='.$term)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.device_id', 'dev-abc');
        }
    }

    /** Activating a device fulfils and closes its request, draining the queue. */
    public function test_activation_closes_the_pending_request(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'status' => 'pending',
            'requested_plan' => '12_months',
            'contact_method' => 'whatsapp',
        ]);

        $this->actAsStaff();
        $this->postJson('/api/activateDevice', ['device_id' => 'dev-1', 'plan_id' => 'yearly'])->assertOk();

        $this->getJson('/api/v1/device-subscriptions?status=pending')->assertOk()->assertJsonCount(0, 'data');

        $device = DeviceSubscription::query()->where('device_id', 'dev-1')->sole();
        $this->assertNull($device->status);
        // Retained: the operator may have sold a plan other than the one requested.
        $this->assertSame('12_months', $device->requested_plan);
    }

    public function test_declining_closes_the_request_and_drains_the_queue(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'status' => 'pending',
            'requested_plan' => 'yearly',
        ]);

        $this->actAsStaff();
        $this->postJson("/api/v1/device-subscriptions/{$device->uuid}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $this->getJson('/api/v1/device-subscriptions?status=pending')->assertOk()->assertJsonCount(0, 'data');

        // Kept as the record of what was asked for, exactly as activation does.
        $device->refresh();
        $this->assertSame('yearly', $device->requested_plan);
    }

    /**
     * The decline must not touch the subscription. A device on a live trial or a
     * paid plan can still file a new request, and refusing to sell someone a plan
     * is not grounds for taking away the one they already have.
     */
    public function test_declining_does_not_revoke_existing_access(): void
    {
        $expiresAt = now()->addDays(200)->startOfSecond();

        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'is_verified' => true,
            'plan_id' => 'half_year',
            'expires_at' => $expiresAt,
            'status' => 'pending',
            'requested_plan' => 'yearly',
        ]);

        $this->actAsStaff();
        $this->postJson("/api/v1/device-subscriptions/{$device->uuid}/decline")->assertOk();

        $device->refresh();
        $this->assertTrue($device->is_verified);
        $this->assertSame('half_year', $device->plan_id);
        $this->assertNotNull($device->expires_at);
        $this->assertTrue($expiresAt->equalTo($device->expires_at));

        // And the device itself is still told it is licensed.
        $this->postJson('/api/check_device', ['device_id' => 'dev-1', 'app_name' => 'Fawateer'])
            ->assertOk()
            ->assertJsonPath('is_verified', 1);
    }

    public function test_a_device_with_no_request_cannot_be_declined(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'status' => null,
        ]);

        $this->actAsStaff();
        $this->postJson("/api/v1/device-subscriptions/{$device->uuid}/decline")->assertStatus(422);

        $device->refresh();
        $this->assertNull($device->status);
    }

    public function test_a_declined_request_cannot_be_declined_again(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'status' => 'declined',
        ]);

        $this->actAsStaff();
        $this->postJson("/api/v1/device-subscriptions/{$device->uuid}/decline")->assertStatus(422);
    }

    /** The customer can always ask again; the row rejoins the queue. */
    public function test_asking_again_after_a_decline_reopens_the_request(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'status' => 'declined',
            'requested_plan' => 'yearly',
        ]);

        $this->postJson('/api/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'full_name' => 'اسم',
            'phone' => '0999',
            'requested_plan' => 'half_year',
            'contact_method' => 'whatsapp',
            'status' => 'pending',
        ])->assertOk();

        $this->actAsStaff();
        $this->getJson('/api/v1/device-subscriptions?status=pending')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_decline_requires_staff_auth(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'status' => 'pending',
        ]);

        $this->postJson("/api/v1/device-subscriptions/{$device->uuid}/decline")->assertStatus(401);

        $device->refresh();
        $this->assertSame('pending', $device->status);
    }

    public function test_staff_can_delete_a_device_with_no_access(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-junk',
            'is_verified' => false,
            'expires_at' => null,
        ]);

        $this->actAsStaff();
        $this->deleteJson("/api/v1/device-subscriptions/{$device->uuid}")->assertStatus(204);

        $this->assertSame(0, DeviceSubscription::query()->count());
    }

    /**
     * Deleting a device that still has access locks it out of the app with no
     * message — check_device returns nothing and the client reads that as
     * unverified. Refused unless the caller says so explicitly.
     */
    public function test_deleting_a_device_with_access_requires_force(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-paid',
            'is_verified' => true,
            'plan_id' => 'yearly',
            'expires_at' => now()->addMonths(6),
        ]);

        $this->actAsStaff();
        $this->deleteJson("/api/v1/device-subscriptions/{$device->uuid}")->assertStatus(422);

        $this->assertSame(1, DeviceSubscription::query()->count());

        $this->deleteJson("/api/v1/device-subscriptions/{$device->uuid}", ['force' => true])
            ->assertStatus(204);

        $this->assertSame(0, DeviceSubscription::query()->count());
    }

    /** A trial counts as access — it is the case the smoke-test rows fall into. */
    public function test_a_trialling_device_also_requires_force(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-trial',
            'is_verified' => true,
            'plan_id' => null,
            'expires_at' => now()->addDays(20),
            'trial_expires_at' => now()->addDays(20),
        ]);

        $this->actAsStaff();
        $this->deleteJson("/api/v1/device-subscriptions/{$device->uuid}")->assertStatus(422);
    }

    public function test_delete_requires_staff_auth(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'is_verified' => false,
        ]);

        $this->deleteJson("/api/v1/device-subscriptions/{$device->uuid}")->assertStatus(401);

        $this->assertSame(1, DeviceSubscription::query()->count());
    }

    /**
     * The delete is recorded before the row goes. Without it a deletion is
     * indistinguishable from a device that never registered.
     */
    public function test_deleting_a_device_is_audited(): void
    {
        $logger = new class implements AuditLogger
        {
            /** @var array<int, array{action: string, subjectId: ?string}> */
            public array $entries = [];

            /** @param array<string, mixed> $context */
            public function log(
                string $action,
                ?string $subjectType = null,
                ?string $subjectId = null,
                array $context = [],
                ?string $actorId = null,
            ): void {
                $this->entries[] = ['action' => $action, 'subjectId' => $subjectId];
            }
        };

        $this->app->instance(AuditLogger::class, $logger);

        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-junk',
            'is_verified' => false,
        ]);

        $this->actAsStaff();
        $this->deleteJson("/api/v1/device-subscriptions/{$device->uuid}")->assertStatus(204);

        $this->assertSame(
            [['action' => 'device_subscription.deleted', 'subjectId' => $device->uuid]],
            $logger->entries,
        );
    }

    /** The console reads the plan request and trial state off the staff resource. */
    public function test_staff_resource_exposes_request_and_trial_fields(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'dev-1',
            'is_verified' => true,
            'plan_id' => null,
            'expires_at' => Carbon::now()->addDays(30),
            'trial_expires_at' => Carbon::now()->addDays(30),
            'status' => 'pending',
            'requested_plan' => '12_months',
            'contact_method' => 'whatsapp',
        ]);

        $this->actAsStaff();
        $this->getJson('/api/v1/device-subscriptions')
            ->assertOk()
            ->assertJsonPath('data.0.is_trial', true)
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.requested_plan', '12_months')
            ->assertJsonPath('data.0.contact_method', 'whatsapp');
    }

    /** The console activates against the same catalog the apps see. */
    public function test_staff_plan_catalog_is_enveloped_and_requires_auth(): void
    {
        $this->getJson('/api/v1/device-subscriptions/plans')->assertUnauthorized();

        $this->actAsStaff();
        $this->getJson('/api/v1/device-subscriptions/plans')
            ->assertOk()
            ->assertJsonPath('data.currency.code', 'USD')
            ->assertJsonPath('data.plans.0.id', 'half_year')
            ->assertJsonPath('data.plans.1.id', 'yearly');
    }

    // --- The shared fallback id is a bucket, not a device --------------------

    /**
     * `fallback_device_id` is what every client sends when it cannot read its real
     * id — one literal, shared by every such device and every app. Trialling it
     * would let the first arrival consume a trial the rest inherit: they would land
     * on day one already holding a stranger's expiry.
     */
    public function test_fallback_device_id_is_never_granted_a_trial(): void
    {
        $this->postJson('/api/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'fallback_device_id',
            'full_name' => 'Unknown Device',
            'phone' => '0999',
        ])->assertOk()->assertJson(['is_verified' => 0, 'is_trial' => 0, 'expires_at' => null]);

        $device = DeviceSubscription::query()->where('device_id', 'fallback_device_id')->sole();
        $this->assertNull($device->trial_expires_at);
    }

    /** A real device beside it still gets its trial — quarantine is not a blanket. */
    public function test_a_real_device_is_still_trialled_alongside_the_fallback(): void
    {
        $this->postJson('/api/create_device', [
            'app_name' => 'Fawateer', 'device_id' => 'fallback_device_id',
            'full_name' => 'A', 'phone' => '01',
        ])->assertOk();

        $this->postJson('/api/create_device', [
            'app_name' => 'Fawateer', 'device_id' => 'real-hashed-id',
            'full_name' => 'B', 'phone' => '02',
        ])->assertOk()->assertJson(['is_verified' => 1, 'is_trial' => 1]);
    }

    /** Activating the bucket would license every device that landed in it. */
    public function test_fallback_device_id_cannot_be_activated_via_the_legacy_shim(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'fallback_device_id',
            'is_verified' => false,
        ]);

        $this->actAsStaff();
        $this->postJson('/api/activateDevice', [
            'device_id' => 'fallback_device_id',
            'plan_id' => 'yearly',
        ])->assertNotFound();

        $device = DeviceSubscription::query()->where('device_id', 'fallback_device_id')->sole();
        $this->assertFalse($device->is_verified);
        $this->assertNull($device->plan_id);
    }

    /** Same rule on the console's own endpoint, which binds the row directly. */
    public function test_fallback_device_id_cannot_be_activated_via_the_console(): void
    {
        $device = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'fallback_device_id',
            'is_verified' => false,
        ]);

        $this->actAsStaff();
        $this->postJson("/api/v1/device-subscriptions/{$device->uuid}/activate", ['plan_id' => 'yearly'])
            ->assertStatus(422);

        $this->assertFalse($device->refresh()->is_verified);
    }

    // --- Activation acts on the row the operator chose -----------------------

    /**
     * The console binds a row, so that row must be the one activated. It used to
     * pass the row's device_id to a service that re-queried by id alone — where an
     * id is not unique that activates a stranger and reports failure for the row
     * the operator picked.
     */
    public function test_console_activates_the_bound_row_not_another_app_sharing_the_id(): void
    {
        $fawateer = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer', 'device_id' => 'shared-id', 'is_verified' => false,
        ]);
        $smartAgent = DeviceSubscription::factory()->create([
            'app_name' => 'SmartAgent', 'device_id' => 'shared-id', 'is_verified' => false,
        ]);

        $this->actAsStaff();
        $this->postJson("/api/v1/device-subscriptions/{$smartAgent->uuid}/activate", ['plan_id' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('data.app_name', 'SmartAgent')
            ->assertJsonPath('data.is_verified', true);

        $this->assertTrue($smartAgent->refresh()->is_verified);
        // The other product's device must be untouched.
        $this->assertFalse($fawateer->refresh()->is_verified);
    }

    /** The legacy shim can be scoped to one product when the caller knows it. */
    public function test_legacy_activation_can_be_scoped_by_app_name(): void
    {
        $fawateer = DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer', 'device_id' => 'shared-id', 'is_verified' => false,
        ]);
        $smartAgent = DeviceSubscription::factory()->create([
            'app_name' => 'SmartAgent', 'device_id' => 'shared-id', 'is_verified' => false,
        ]);

        $this->actAsStaff();
        $this->postJson('/api/activateDevice', [
            'device_id' => 'shared-id',
            'app_name' => 'SmartAgent',
            'plan_id' => 'yearly',
        ])->assertOk();

        $this->assertTrue($smartAgent->refresh()->is_verified);
        $this->assertFalse($fawateer->refresh()->is_verified);
    }

    // --- The anti-farm anchor is enforced by the database --------------------

    /**
     * The trial is unfarmable only because a reinstall finds the existing row.
     * That rests on one row per (app_name, device_id) — so the database, not a
     * find-then-create race, decides it.
     */
    public function test_device_identity_is_unique_per_app(): void
    {
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'device_id' => 'dup']);

        $this->expectException(QueryException::class);
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'device_id' => 'dup']);
    }

    /** The same phone in two products is two subscriptions, not a clash. */
    public function test_the_same_device_id_in_two_apps_is_allowed(): void
    {
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'device_id' => 'same']);
        DeviceSubscription::factory()->create(['app_name' => 'SmartAgent', 'device_id' => 'same']);

        $this->assertSame(2, DeviceSubscription::query()->where('device_id', 'same')->count());
    }

    /**
     * Phase D. getPlans carries no app_name, so a shared base URL cannot serve
     * different plans per app. The slug supplies it instead — and because each app
     * reads its own remote-config base URL, moving onto it needs no store release.
     */
    public function test_namespaced_get_plans_serves_the_apps_own_catalog(): void
    {
        $this->giveAppOwnPlans('Fawateer', [
            ['id' => 'monthly', 'title' => 'شهري', 'duration_months' => 1, 'price' => 19,
                'price_after_discount' => null, 'enabled' => true, 'recommended' => false, 'description' => ''],
        ]);

        $this->getJson('/api/fawateer/getPlans')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'plans')
            ->assertJsonPath('plans.0.id', 'monthly')
            ->assertJsonPath('plans.0.price', 19);

        // The un-namespaced surface is unchanged for builds still pointed at it.
        $this->getJson('/api/getPlans')
            ->assertOk()
            ->assertJsonPath('plans.0.id', 'half_year');
    }

    /** An app with no catalog of its own falls back to the shared list. */
    public function test_namespaced_app_without_own_plans_falls_back_to_shared(): void
    {
        $this->getJson('/api/smartagent/getPlans')
            ->assertOk()
            ->assertJsonPath('plans.0.id', 'half_year')
            ->assertJsonPath('plans.1.id', 'yearly');
    }

    /** A typo'd base URL degrades to the shared catalog, never an error. */
    public function test_unknown_slug_serves_the_shared_catalog(): void
    {
        $this->getJson('/api/not-an-app/getPlans')
            ->assertOk()
            ->assertJsonPath('plans.0.id', 'half_year');
    }

    /** The whole shim is reachable under the namespace — the app moves wholesale. */
    public function test_namespaced_surface_serves_the_whole_shim(): void
    {
        $this->postJson('/api/fawateer/create_device', [
            'app_name' => 'Fawateer',
            'device_id' => 'ns-1',
            'full_name' => 'Sara',
            'phone' => '0999',
        ])->assertOk()->assertJsonPath('is_trial', 1);

        $this->postJson('/api/fawateer/check_device', ['app_name' => 'Fawateer', 'device_id' => 'ns-1'])
            ->assertOk()
            ->assertJsonPath('is_verified', 1);

        $this->postJson('/api/fawateer/update_my_data', [
            'app_name' => 'Fawateer', 'device_id' => 'ns-1', 'fcm_token' => 'tok',
        ])->assertOk();
    }

    /** The {app} segment must never swallow the versioned platform API. */
    public function test_namespace_does_not_shadow_the_v1_api(): void
    {
        $this->getJson('/api/v1/health')->assertOk();

        // v1 is excluded from the slug pattern, so this is a miss, not a plan list.
        $this->getJson('/api/v1/getPlans')->assertNotFound();
    }

    /**
     * The term must be read from the device's own catalog: the same id can mean a
     * different number of months per app, and the wrong one sets the wrong expiry.
     */
    public function test_activation_uses_the_devices_own_app_catalog(): void
    {
        $this->giveAppOwnPlans('Fawateer', [
            ['id' => 'yearly', 'title' => 'سنوي', 'duration_months' => 1, 'price' => 19,
                'price_after_discount' => null, 'enabled' => true, 'recommended' => false, 'description' => ''],
        ]);

        // Same plan id in both apps; Fawateer's is 1 month, the shared one 12.
        DeviceSubscription::factory()->create(['app_name' => 'Fawateer', 'device_id' => 'f-1']);
        DeviceSubscription::factory()->create(['app_name' => 'SmartAgent', 'device_id' => 's-1']);

        $this->actAsStaff();
        $this->postJson('/api/activateDevice', ['device_id' => 'f-1', 'app_name' => 'Fawateer', 'plan_id' => 'yearly'])->assertOk();
        $this->postJson('/api/activateDevice', ['device_id' => 's-1', 'app_name' => 'SmartAgent', 'plan_id' => 'yearly'])->assertOk();

        $fawateer = DeviceSubscription::query()->where('device_id', 'f-1')->sole()->expires_at;
        $smartAgent = DeviceSubscription::query()->where('device_id', 's-1')->sole()->expires_at;
        $this->assertNotNull($fawateer);
        $this->assertNotNull($smartAgent);

        $this->assertEqualsWithDelta(30, Carbon::now()->diffInDays($fawateer), 2);
        $this->assertEqualsWithDelta(365, Carbon::now()->diffInDays($smartAgent), 2);
    }

    /** The console asks for the catalog of the app it is activating. */
    public function test_staff_plan_catalog_can_be_scoped_to_an_app(): void
    {
        $this->giveAppOwnPlans('Fawateer', [
            ['id' => 'monthly', 'title' => 'شهري', 'duration_months' => 1, 'price' => 19,
                'price_after_discount' => null, 'enabled' => true, 'recommended' => false, 'description' => ''],
        ]);

        $this->actAsStaff();
        $this->getJson('/api/v1/device-subscriptions/plans?app_name=Fawateer')
            ->assertOk()
            ->assertJsonCount(1, 'data.plans')
            ->assertJsonPath('data.plans.0.id', 'monthly');

        $this->getJson('/api/v1/device-subscriptions/plans?app_name=SmartAgent')
            ->assertOk()
            ->assertJsonPath('data.plans.0.id', 'half_year');
    }

    public function test_sweep_expiry_command_runs(): void
    {
        DeviceSubscription::factory()->expired()->create();

        $this->assertSame(0, Artisan::call('device-subscriptions:sweep-expiry'));
    }
}
