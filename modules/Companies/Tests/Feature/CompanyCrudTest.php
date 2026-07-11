<?php

namespace Modules\Companies\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Companies\Domain\Models\Company;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class CompanyCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        // No company_id → platform staff, sees across all tenants.
        $admin = User::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/companies')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_can_list_companies_in_the_standard_envelope(): void
    {
        $this->actAsAdmin();
        Company::factory()->count(3)->create();

        $this->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'status']],
                'meta' => ['current_page', 'total'],
                'links',
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_a_company(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/companies', [
            'name' => 'Acme Restaurants',
            'email' => 'ops@acme.test',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Restaurants')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('companies', ['name' => 'Acme Restaurants']);
    }

    public function test_can_show_update_and_soft_delete_a_company(): void
    {
        $this->actAsAdmin();
        $company = Company::factory()->create();

        $this->getJson("/api/v1/companies/{$company->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $company->uuid);

        $this->patchJson("/api/v1/companies/{$company->uuid}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->deleteJson("/api/v1/companies/{$company->uuid}")
            ->assertNoContent();

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_validation_errors_use_the_error_envelope(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/companies', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
