<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Companies\Domain\Enums\CompanyStatus;
use Modules\Companies\Domain\Models\Company;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Payments\Domain\Enums\InvoiceStatus;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Modules\Subscriptions\Domain\Enums\SubscriptionStatus;
use Modules\Subscriptions\Domain\Models\Subscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class ReportOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_overview_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/overview')->assertUnauthorized();
    }

    public function test_the_overview_aggregates_across_modules(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Companies: 2 active, 1 inactive.
        $company = Company::factory()->create(['status' => CompanyStatus::Active]);
        Company::factory()->create(['status' => CompanyStatus::Active]);
        Company::factory()->create(['status' => CompanyStatus::Inactive]);

        // Subscriptions: 2 active, 1 cancelled (reuse the company — no new ones).
        $plan = Plan::factory()->create(['product_id' => Product::factory()->create()->id]);
        $subscription = Subscription::factory()->create([
            'company_id' => $company->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        ]);
        Subscription::factory()->create([
            'company_id' => $company->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        ]);
        Subscription::factory()->create([
            'company_id' => $company->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Cancelled,
        ]);

        // Licenses: 2 active, 1 revoked.
        $license = License::factory()->create([
            'company_id' => $company->id, 'subscription_id' => $subscription->id,
        ]);
        License::factory()->create([
            'company_id' => $company->id, 'subscription_id' => $subscription->id,
        ]);
        License::factory()->revoked()->create([
            'company_id' => $company->id, 'subscription_id' => $subscription->id,
        ]);

        // Activations: 2 active, 1 revoked.
        LicenseActivation::factory()->count(2)->create(['license_id' => $license->id]);
        LicenseActivation::factory()->revoked()->create(['license_id' => $license->id]);

        // Invoices: paid USD 50 + 120, paid EUR 30, open USD 20.
        Invoice::factory()->paid()->create(['company_id' => $company->id, 'subscription_id' => null, 'amount' => '50.00', 'currency' => 'USD']);
        Invoice::factory()->paid()->create(['company_id' => $company->id, 'subscription_id' => null, 'amount' => '120.00', 'currency' => 'USD']);
        Invoice::factory()->paid()->create(['company_id' => $company->id, 'subscription_id' => null, 'amount' => '30.00', 'currency' => 'EUR']);
        Invoice::factory()->create(['company_id' => $company->id, 'subscription_id' => null, 'amount' => '20.00', 'currency' => 'USD', 'status' => InvoiceStatus::Open]);

        $this->getJson('/api/v1/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.companies.total', 3)
            ->assertJsonPath('data.companies.active', 2)
            ->assertJsonPath('data.subscriptions.total', 3)
            ->assertJsonPath('data.subscriptions.active', 2)
            ->assertJsonPath('data.licenses.total', 3)
            ->assertJsonPath('data.licenses.active', 2)
            ->assertJsonPath('data.licenses.active_activations', 2)
            ->assertJsonPath('data.billing.collected.USD', '170.00')
            ->assertJsonPath('data.billing.collected.EUR', '30.00')
            ->assertJsonPath('data.billing.outstanding.USD', '20.00')
            ->assertJsonPath('data.billing.invoices_paid', 3)
            ->assertJsonPath('data.billing.invoices_open', 1);
    }

    public function test_the_overview_is_zeroed_on_an_empty_platform(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/v1/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.companies.total', 0)
            ->assertJsonPath('data.subscriptions.active', 0)
            ->assertJsonPath('data.licenses.active_activations', 0)
            ->assertJsonPath('data.billing.invoices_paid', 0)
            ->assertJsonPath('data.billing.collected', []);
    }
}
