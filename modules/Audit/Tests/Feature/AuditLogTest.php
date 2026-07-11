<?php

namespace Modules\Audit\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Domain\Models\AuditLog;
use Modules\Companies\Domain\Models\Company;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    // --- Explicit capture via the Core AuditLogger port (Auth) ---

    public function test_a_successful_login_is_audited(): void
    {
        $user = User::factory()->create(['email' => 'staff@evotech.test']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@evotech.test',
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'actor_type' => 'user',
            'actor_id' => $user->uuid,
            'subject_type' => 'user',
            'subject_id' => $user->uuid,
        ]);
    }

    public function test_a_failed_login_is_audited_as_system(): void
    {
        User::factory()->create(['email' => 'staff@evotech.test']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@evotech.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_failed',
            'actor_type' => 'system',
            'actor_id' => null,
        ]);
    }

    public function test_logout_is_audited(): void
    {
        $user = User::factory()->create(['email' => 'staff@evotech.test']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@evotech.test',
            'password' => 'password',
        ])->json('data.token');
        $token = is_string($token) ? $token : '';

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'actor_id' => $user->uuid,
        ]);
    }

    // --- Event-driven capture (no producer changes) ---

    public function test_invoice_settlement_is_audited_with_the_acting_staff(): void
    {
        $staff = $this->actAsStaff();
        $invoice = Invoice::factory()->create();

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", ['method' => 'cash'])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice.paid',
            'actor_type' => 'user',
            'actor_id' => $staff->uuid,
            'subject_type' => 'invoice',
            'subject_id' => $invoice->uuid,
        ]);
    }

    public function test_subscription_activation_is_audited(): void
    {
        $this->actAsStaff();
        $company = Company::factory()->create();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        $this->postJson('/api/v1/subscriptions', [
            'company' => $company->uuid,
            'plan' => $plan->uuid,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'subscription.activated',
            'subject_type' => 'subscription',
        ]);
    }

    // --- Read API ---

    public function test_the_audit_log_requires_authentication(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
    }

    public function test_staff_can_list_and_filter_the_audit_log(): void
    {
        $this->actAsStaff();
        AuditLog::factory()->create(['action' => 'invoice.paid']);
        AuditLog::factory()->count(2)->create(['action' => 'auth.login']);

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/audit-logs?action=auth.login')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'auth.login');
    }
}
