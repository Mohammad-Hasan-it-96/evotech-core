<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Companies\Domain\Models\Company;
use Modules\Products\Domain\Enums\BillingPeriod;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Modules\Subscriptions\Domain\Models\Subscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function plan(BillingPeriod $period = BillingPeriod::Monthly, float $price = 50): Plan
    {
        $product = Product::factory()->create();

        return Plan::factory()->create([
            'product_id' => $product->id,
            'billing_period' => $period,
            'price' => $price,
        ]);
    }

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/subscriptions')->assertUnauthorized();
    }

    public function test_staff_can_create_a_subscription_linking_company_and_plan(): void
    {
        $this->actAsStaff();
        $company = Company::factory()->create();
        $plan = $this->plan(BillingPeriod::Monthly, 50);

        $this->postJson('/api/v1/subscriptions', [
            'company' => $company->uuid,
            'plan' => $plan->uuid,
            'identifier_type' => 'domain',
            'identifier_value' => 'client.example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.company.id', $company->uuid)
            ->assertJsonPath('data.plan.id', $plan->uuid)
            ->assertJsonPath('data.identifier.value', 'client.example.com')
            ->assertJsonPath('data.price', 50);

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        // Monthly plan → ends ~30 days out.
        $subscription = Subscription::query()->firstOrFail();
        $this->assertNotNull($subscription->ends_at);
        $this->assertEqualsWithDelta(30, Carbon::now()->diffInDays($subscription->ends_at), 1);
    }

    public function test_a_lifetime_plan_has_no_end_date(): void
    {
        $this->actAsStaff();
        $company = Company::factory()->create();
        $plan = $this->plan(BillingPeriod::Lifetime);

        $this->postJson('/api/v1/subscriptions', [
            'company' => $company->uuid,
            'plan' => $plan->uuid,
        ])->assertCreated()->assertJsonPath('data.ends_at', null);

        $this->assertNull(Subscription::query()->firstOrFail()->ends_at);
    }

    public function test_validation_uses_the_error_envelope(): void
    {
        $this->actAsStaff();

        $this->postJson('/api/v1/subscriptions', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_renew_extends_the_period(): void
    {
        $this->actAsStaff();
        $subscription = Subscription::factory()->create(['ends_at' => Carbon::now()->addDays(5)]);

        $this->postJson("/api/v1/subscriptions/{$subscription->uuid}/renew")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // Extended from the existing future end date (+5) by one cycle (+30).
        $this->assertTrue($subscription->refresh()->ends_at?->greaterThan(Carbon::now()->addDays(30)));
    }

    public function test_cancel_marks_the_subscription_cancelled(): void
    {
        $this->actAsStaff();
        $subscription = Subscription::factory()->create();

        $this->postJson("/api/v1/subscriptions/{$subscription->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertFalse($subscription->refresh()->auto_renew);
    }

    public function test_expire_command_marks_due_subscriptions_expired(): void
    {
        $due = Subscription::factory()->expired()->create();
        $current = Subscription::factory()->create();

        $this->assertSame(0, Artisan::call('subscriptions:expire'));

        $this->assertSame('expired', $due->refresh()->status->value);
        $this->assertSame('active', $current->refresh()->status->value);
    }

    public function test_list_includes_company_and_plan_details(): void
    {
        $this->actAsStaff();
        Subscription::factory()->create();

        $this->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'status', 'is_active',
                    'company' => ['id', 'name'],
                    'plan' => ['id', 'billing_period', 'product' => ['slug']],
                ]],
                'meta',
                'links',
            ]);
    }
}
