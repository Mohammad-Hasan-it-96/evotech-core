<?php

namespace Modules\DeviceSubscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

/**
 * The dashboard catalog editor: apps and their purchasable plans, moved out of
 * config/device-subscriptions.php so pricing changes without a deploy.
 */
class DeviceCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function sharedPlan(string $key): DevicePlan
    {
        return DevicePlan::query()->whereNull('device_app_id')->where('plan_key', $key)->sole();
    }

    /**
     * Validation failures come back in the platform envelope
     * (`{error:{code,details:[{field,issue}]}}`) rather than Laravel's default
     * shape, so assertJsonValidationErrors does not apply here.
     *
     * @param  TestResponse<JsonResponse>  $response
     */
    private function assertRejectedField(TestResponse $response, string $field): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonFragment(['field' => $field]);
    }

    // --- The migration ---------------------------------------------------------

    /**
     * The keys are the contract. Live device rows store `plan_id` as this string and
     * renewal resolves a duration by matching it, so a migration that re-keyed a
     * plan would turn every holder's next renewal into a 0-month term.
     */
    public function test_the_migration_carries_the_config_catalog_over_verbatim(): void
    {
        $shared = DevicePlan::query()
            ->whereNull('device_app_id')
            ->orderBy('sort_order')
            ->get();

        $this->assertSame(['half_year', 'yearly'], $shared->pluck('plan_key')->all());
        $this->assertSame([6, 12], $shared->pluck('duration_months')->all());
        $this->assertSame(['12.00', '20.00'], $shared->pluck('price')->all());

        // Order is what customers have been seeing in the app, not decoration.
        $this->assertSame([false, true], $shared->pluck('recommended')->all());
    }

    public function test_the_migration_preserves_each_apps_trial_terms(): void
    {
        // SmartAgent deliberately has no trial; inheriting Fawateer's 30 days would
        // silently change that product's monetization.
        $this->assertSame(30, DeviceApp::query()->where('name', 'Fawateer')->sole()->trial_days);
        $this->assertSame(0, DeviceApp::query()->where('name', 'SmartAgent')->sole()->trial_days);

        $this->assertTrue(DeviceApp::query()->where('name', 'Fawateer')->sole()->uses_shared_plans);
    }

    // --- Auth ------------------------------------------------------------------

    public function test_the_catalog_editor_requires_authentication(): void
    {
        $plan = $this->sharedPlan('yearly');

        $this->getJson('/api/v1/device-apps')->assertUnauthorized();
        $this->getJson('/api/v1/device-plans')->assertUnauthorized();
        $this->postJson('/api/v1/device-plans', [])->assertUnauthorized();
        $this->patchJson("/api/v1/device-plans/{$plan->uuid}", [])->assertUnauthorized();
        $this->deleteJson("/api/v1/device-plans/{$plan->uuid}")->assertUnauthorized();
    }

    // --- Reading ---------------------------------------------------------------

    public function test_listing_plans_defaults_to_the_shared_catalog(): void
    {
        $this->actAsStaff();

        $this->getJson('/api/v1/device-plans')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', 'half_year')
            ->assertJsonPath('data.0.is_shared', true);
    }

    /** Disabled plans belong in the editor — it is where you go to re-enable one. */
    public function test_listing_includes_disabled_plans(): void
    {
        $this->sharedPlan('yearly')->update(['enabled' => false]);

        $this->actAsStaff();

        $this->getJson('/api/v1/device-plans')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // --- Editing reaches the app ----------------------------------------------

    /**
     * The whole point of the feature: an edit in the dashboard changes what a
     * device is offered, with no deploy and no cache wait.
     */
    public function test_editing_a_price_changes_what_the_device_is_offered(): void
    {
        $this->getJson('/api/getPlans')->assertOk()->assertJsonPath('plans.1.price', 20);

        $this->actAsStaff();
        $this->patchJson("/api/v1/device-plans/{$this->sharedPlan('yearly')->uuid}", ['price' => 25])
            ->assertOk()
            // JSON collapses a whole float, so this arrives as 25 rather than 25.0.
            ->assertJsonPath('data.price', 25);

        $this->getJson('/api/getPlans')
            ->assertOk()
            ->assertJsonPath('plans.1.price', 25);
    }

    /**
     * A whole price must serialize as `20`, not `20.0` or `"20.00"`. The shipped
     * parsers were written against integer prices and cannot be updated remotely.
     */
    public function test_the_device_payload_keeps_whole_prices_as_integers(): void
    {
        $payload = $this->getJson('/api/getPlans')->assertOk();

        $this->assertIsInt($payload->json('plans.0.price'));
        $this->assertNull($payload->json('plans.0.price_after_discount'));

        $this->actAsStaff();
        $this->patchJson("/api/v1/device-plans/{$this->sharedPlan('yearly')->uuid}", ['price' => 19.5]);

        $this->assertSame(19.5, $this->getJson('/api/getPlans')->json('plans.1.price'));
    }

    // --- Creating --------------------------------------------------------------

    public function test_staff_can_add_a_plan_to_an_apps_own_catalog(): void
    {
        $app = DeviceApp::query()->where('name', 'Fawateer')->sole();
        $app->update(['uses_shared_plans' => false]);

        $this->actAsStaff();

        $this->postJson('/api/v1/device-plans', [
            'app' => $app->uuid,
            'key' => 'monthly',
            'title' => 'شهري',
            'duration_months' => 1,
            'price' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'monthly')
            ->assertJsonPath('data.is_shared', false);

        $this->getJson('/api/fawateer/getPlans')
            ->assertOk()
            ->assertJsonCount(1, 'plans')
            ->assertJsonPath('plans.0.id', 'monthly');
    }

    public function test_a_duplicate_key_in_the_shared_scope_is_rejected(): void
    {
        $this->actAsStaff();

        $this->postJson('/api/v1/device-plans', [
            'key' => 'yearly',
            'title' => 'Another yearly',
            'duration_months' => 12,
            'price' => 30,
        ])->assertStatus(422);

        $this->assertSame(1, DevicePlan::query()->whereNull('device_app_id')->where('plan_key', 'yearly')->count());
    }

    /** The same key in a different scope is a different plan, and is allowed. */
    public function test_an_app_may_reuse_a_shared_key_with_its_own_terms(): void
    {
        $app = DeviceApp::query()->where('name', 'Fawateer')->sole();
        $app->update(['uses_shared_plans' => false]);

        $this->actAsStaff();

        $this->postJson('/api/v1/device-plans', [
            'app' => $app->uuid,
            'key' => 'yearly',
            'title' => 'سنوي',
            'duration_months' => 1,
            'price' => 19,
        ])->assertCreated();
    }

    /**
     * A 0-month plan is not a free tier — activation adds the duration to today, so
     * it sells a subscription that has already expired when it is granted.
     */
    public function test_a_zero_month_plan_is_rejected(): void
    {
        $this->actAsStaff();

        $this->assertRejectedField($this->postJson('/api/v1/device-plans', [
            'key' => 'broken',
            'title' => 'Instant expiry',
            'duration_months' => 0,
            'price' => 5,
        ]), 'duration_months');
    }

    public function test_a_discount_above_the_price_is_rejected(): void
    {
        $this->actAsStaff();

        $this->assertRejectedField($this->postJson('/api/v1/device-plans', [
            'key' => 'weird',
            'title' => 'Negative discount',
            'duration_months' => 3,
            'price' => 10,
            'price_after_discount' => 15,
        ]), 'price_after_discount');
    }

    /**
     * The PATCH case the `lte:price` rule cannot catch: the payload carries only
     * the discount, so there is no `price` field to compare against and the rule
     * would pass vacuously. It has to compare against the stored price.
     */
    public function test_a_discount_above_the_stored_price_is_rejected_on_update(): void
    {
        $this->actAsStaff();

        $this->assertRejectedField($this->patchJson("/api/v1/device-plans/{$this->sharedPlan('yearly')->uuid}", [
            'price_after_discount' => 999,
        ]), 'price_after_discount');
    }

    // --- Immutability ----------------------------------------------------------

    /**
     * Re-keying a plan would orphan every device holding the old key. The field is
     * simply not accepted, so a stray `key` in a payload is ignored rather than
     * silently applied.
     */
    public function test_a_plans_key_cannot_be_changed(): void
    {
        $plan = $this->sharedPlan('yearly');

        $this->actAsStaff();
        $this->patchJson("/api/v1/device-plans/{$plan->uuid}", [
            'key' => 'renamed',
            'title' => 'Still editable',
        ])->assertOk();

        $this->assertSame('yearly', $plan->refresh()->plan_key);
        $this->assertSame('Still editable', $plan->title);
    }

    /**
     * Renaming an app orphans every one of its device rows at once: `app_name` is
     * the literal string shipped builds send and is what rows are matched on.
     */
    public function test_an_apps_name_and_slug_cannot_be_changed(): void
    {
        $app = DeviceApp::query()->where('name', 'Fawateer')->sole();

        $this->actAsStaff();
        $this->patchJson("/api/v1/device-apps/{$app->uuid}", [
            'name' => 'Renamed',
            'slug' => 'renamed',
            'label' => 'فواتير برو',
        ])->assertOk();

        $app->refresh();
        $this->assertSame('Fawateer', $app->name);
        $this->assertSame('fawateer', $app->slug);
        $this->assertSame('فواتير برو', $app->label);
    }

    // --- Deleting --------------------------------------------------------------

    /**
     * The failure this guard exists for is silent and deferred: nothing breaks the
     * day the plan is deleted, and then a renewal weeks later resolves to 0 months
     * for someone who has just paid.
     */
    public function test_deleting_a_plan_that_devices_hold_is_refused(): void
    {
        DeviceSubscription::factory()->create([
            'app_name' => 'SmartAgent',
            'device_id' => 'holder-1',
            'plan_id' => 'yearly',
        ]);

        $this->actAsStaff();

        $this->deleteJson("/api/v1/device-plans/{$this->sharedPlan('yearly')->uuid}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Disable it instead'));

        $this->assertDatabaseHas('device_plans', ['plan_key' => 'yearly']);
    }

    public function test_an_unreferenced_plan_can_be_deleted(): void
    {
        $plan = $this->sharedPlan('yearly');

        $this->actAsStaff();
        $this->deleteJson("/api/v1/device-plans/{$plan->uuid}")->assertNoContent();

        $this->assertDatabaseMissing('device_plans', ['id' => $plan->id]);
    }

    /**
     * A holder of a *different* app's identically-keyed plan is not this plan's
     * holder. Over-counting would block a delete that is genuinely safe.
     */
    public function test_a_holder_in_an_app_with_its_own_catalog_does_not_block_the_shared_plan(): void
    {
        $app = DeviceApp::query()->where('name', 'Fawateer')->sole();
        $app->update(['uses_shared_plans' => false]);

        DeviceSubscription::factory()->create([
            'app_name' => 'Fawateer',
            'device_id' => 'own-catalog-holder',
            'plan_id' => 'yearly',
        ]);

        $this->actAsStaff();
        $this->deleteJson("/api/v1/device-plans/{$this->sharedPlan('yearly')->uuid}")->assertNoContent();
    }

    /**
     * The alternative the delete guard points operators at has to actually work:
     * a disabled plan must stay resolvable so existing subscribers still renew at
     * the right term.
     */
    public function test_a_disabled_plan_still_resolves_for_existing_subscribers(): void
    {
        $this->sharedPlan('yearly')->update(['enabled' => false]);

        DeviceSubscription::factory()->create(['app_name' => 'SmartAgent', 'device_id' => 'renewer']);

        $this->actAsStaff();
        $this->postJson('/api/activateDevice', [
            'device_id' => 'renewer',
            'app_name' => 'SmartAgent',
            'plan_id' => 'yearly',
        ])->assertOk();

        $expiresAt = DeviceSubscription::query()->where('device_id', 'renewer')->sole()->expires_at;

        $this->assertNotNull($expiresAt);
        $this->assertEqualsWithDelta(365, Carbon::now()->diffInDays($expiresAt), 2);
    }

    // --- App settings ----------------------------------------------------------

    public function test_changing_trial_days_changes_what_a_new_device_is_granted(): void
    {
        $app = DeviceApp::query()->where('name', 'SmartAgent')->sole();

        $this->actAsStaff();
        $this->patchJson("/api/v1/device-apps/{$app->uuid}", ['trial_days' => 14])->assertOk();

        $this->postJson('/api/create_device', [
            'app_name' => 'SmartAgent',
            'device_id' => 'trial-me',
            'full_name' => 'Sara',
            'phone' => '0999',
        ])->assertOk()->assertJsonPath('is_trial', 1);
    }

    /**
     * Switching an app onto its own catalog while it has no plans must offer zero
     * plans, not silently fall back to the shared prices — "no plans configured"
     * and "defers to shared" are different answers.
     */
    public function test_an_app_with_its_own_empty_catalog_offers_nothing(): void
    {
        $app = DeviceApp::query()->where('name', 'Fawateer')->sole();

        $this->actAsStaff();
        $this->patchJson("/api/v1/device-apps/{$app->uuid}", ['uses_shared_plans' => false])->assertOk();

        $this->getJson('/api/fawateer/getPlans')
            ->assertOk()
            ->assertJsonCount(0, 'plans');
    }
}
