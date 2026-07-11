<?php

namespace Modules\Customers\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Companies\Domain\Models\Company;
use Modules\Customers\Domain\Models\Customer;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class CustomerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function actAsUserOfCompany(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_a_user_only_sees_customers_of_their_own_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $customerA = Customer::factory()->forCompany($companyA)->create();
        Customer::factory()->forCompany($companyB)->create();

        $this->actAsUserOfCompany($companyA);

        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customerA->uuid);
    }

    public function test_a_user_cannot_access_another_companys_customer(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $customerB = Customer::factory()->forCompany($companyB)->create();

        $this->actAsUserOfCompany($companyA);

        // Scoped out entirely → 404, not 403 (no existence leak).
        $this->getJson("/api/v1/customers/{$customerB->uuid}")->assertNotFound();
    }

    public function test_created_customer_is_auto_assigned_to_the_current_company(): void
    {
        $companyA = Company::factory()->create();
        $this->actAsUserOfCompany($companyA);

        $this->postJson('/api/v1/customers', ['name' => 'Walk-in Client'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Walk-in Client');

        $this->assertDatabaseHas('customers', [
            'name' => 'Walk-in Client',
            'company_id' => $companyA->id,
        ]);
    }

    public function test_company_id_cannot_be_overridden_by_client_input(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->actAsUserOfCompany($companyA);

        // Attempt to plant the customer in company B via mass assignment.
        $this->postJson('/api/v1/customers', [
            'name' => 'Injected',
            'company_id' => $companyB->id,
        ])->assertCreated();

        $this->assertDatabaseHas('customers', [
            'name' => 'Injected',
            'company_id' => $companyA->id,
        ]);
    }
}
