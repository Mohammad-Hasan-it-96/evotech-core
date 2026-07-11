<?php

namespace Modules\Licenses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Companies\Domain\Models\Company;
use Modules\Licenses\Domain\Models\License;
use Modules\Products\Domain\Enums\BillingPeriod;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Modules\Subscriptions\Domain\Models\Subscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class LicenseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function plan(BillingPeriod $period = BillingPeriod::Monthly): Plan
    {
        $product = Product::factory()->create();

        return Plan::factory()->create([
            'product_id' => $product->id,
            'billing_period' => $period,
            'price' => 50,
        ]);
    }

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/licenses')->assertUnauthorized();
    }

    public function test_activating_a_subscription_auto_issues_a_license(): void
    {
        $this->actAsStaff();
        $company = Company::factory()->create();
        $plan = $this->plan();

        $this->postJson('/api/v1/subscriptions', [
            'company' => $company->uuid,
            'plan' => $plan->uuid,
        ])->assertCreated();

        $subscription = Subscription::query()->firstOrFail();
        $license = License::query()->firstOrFail();

        $this->assertSame($subscription->id, $license->subscription_id);
        $this->assertSame($company->id, $license->company_id);
        $this->assertSame('active', $license->status->value);
        $this->assertStringStartsWith('EVO-', $license->key);
        $this->assertNotNull($license->expires_at);

        // Issuance is recorded in the immutable ledger.
        $this->assertDatabaseHas('license_events', [
            'license_id' => $license->id,
            'event_type' => 'issued',
            'actor_type' => 'system',
        ]);
    }

    public function test_renewing_a_subscription_extends_the_same_license(): void
    {
        $this->actAsStaff();
        $company = Company::factory()->create();
        $plan = $this->plan();

        $this->postJson('/api/v1/subscriptions', [
            'company' => $company->uuid,
            'plan' => $plan->uuid,
        ])->assertCreated();

        $subscription = Subscription::query()->firstOrFail();
        $originalExpiry = License::query()->firstOrFail()->expires_at;
        $this->assertNotNull($originalExpiry);

        $this->postJson("/api/v1/subscriptions/{$subscription->uuid}/renew")->assertOk();

        // Still exactly one license, with a later expiry and a renewal ledger row.
        $this->assertSame(1, License::query()->count());
        $license = License::query()->firstOrFail();
        $this->assertNotNull($license->expires_at);
        $this->assertTrue($license->expires_at->greaterThan($originalExpiry));
        $this->assertDatabaseHas('license_events', [
            'license_id' => $license->id,
            'event_type' => 'renewed',
        ]);
    }

    public function test_staff_can_manually_issue_a_license_for_a_subscription(): void
    {
        $this->actAsStaff();
        $subscription = Subscription::factory()->create();

        $this->postJson('/api/v1/licenses', [
            'subscription' => $subscription->uuid,
            'max_activations' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.max_activations', 5)
            ->assertJsonPath('data.is_valid', true);

        $this->assertDatabaseHas('licenses', [
            'subscription_id' => $subscription->id,
            'max_activations' => 5,
        ]);
    }

    public function test_suspend_reactivate_and_revoke_transitions(): void
    {
        $this->actAsStaff();
        $license = License::factory()->create();

        $this->postJson("/api/v1/licenses/{$license->uuid}/suspend")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.is_valid', false);

        $this->postJson("/api/v1/licenses/{$license->uuid}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson("/api/v1/licenses/{$license->uuid}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.is_valid', false);

        $this->assertNotNull($license->refresh()->revoked_at);
    }

    public function test_revoked_license_is_not_resurrected_by_renewal(): void
    {
        $this->actAsStaff();
        $subscription = Subscription::factory()->create();
        License::factory()->revoked()->create([
            'subscription_id' => $subscription->id,
            'company_id' => $subscription->company_id,
        ]);

        $this->postJson("/api/v1/subscriptions/{$subscription->uuid}/renew")->assertOk();

        // The revoked license stays revoked; a fresh license is issued instead.
        $this->assertSame(2, License::query()->where('subscription_id', $subscription->id)->count());
        $this->assertSame(
            1,
            License::query()->where('subscription_id', $subscription->id)->where('status', 'active')->count()
        );
    }

    public function test_expire_command_marks_due_licenses_expired(): void
    {
        $due = License::factory()->expired()->create();
        $current = License::factory()->create();

        $this->assertSame(0, Artisan::call('licenses:expire'));

        $this->assertSame('expired', $due->refresh()->status->value);
        $this->assertSame('active', $current->refresh()->status->value);
    }

    public function test_list_includes_company_and_subscription_details(): void
    {
        $this->actAsStaff();
        License::factory()->create();

        $this->getJson('/api/v1/licenses')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'key', 'status', 'max_activations', 'is_valid',
                    'company' => ['id', 'name'],
                    'subscription' => ['id', 'status'],
                ]],
                'meta',
                'links',
            ]);
    }

    public function test_keys_are_unique_across_issued_licenses(): void
    {
        $keys = License::factory()->count(25)->create()->pluck('key');

        $this->assertSame($keys->count(), $keys->unique()->count());
    }
}
