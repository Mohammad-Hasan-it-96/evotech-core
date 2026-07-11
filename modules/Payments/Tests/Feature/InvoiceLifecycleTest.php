<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Companies\Domain\Models\Company;
use Modules\Payments\Application\Services\PaymentService;
use Modules\Payments\Domain\Events\InvoicePaid;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Domain\Models\Payment;
use Modules\Products\Domain\Enums\BillingPeriod;
use Modules\Products\Domain\Models\Plan;
use Modules\Products\Domain\Models\Product;
use Modules\Subscriptions\Domain\Models\Subscription;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function plan(string $price = '50.00'): Plan
    {
        $product = Product::factory()->create();

        return Plan::factory()->create([
            'product_id' => $product->id,
            'billing_period' => BillingPeriod::Monthly,
            'price' => $price,
        ]);
    }

    private function activeSubscription(string $price = '50.00'): Subscription
    {
        $start = Carbon::now();

        return Subscription::factory()->create([
            'plan_id' => $this->plan($price)->id,
            'price' => $price,
            'currency' => 'USD',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(30),
        ]);
    }

    // --- Auto-issue on subscription activation ---

    public function test_activating_a_subscription_auto_issues_an_invoice(): void
    {
        $this->actAsStaff();
        $company = Company::factory()->create();
        $plan = $this->plan('120.00');

        $this->postJson('/api/v1/subscriptions', [
            'company' => $company->uuid,
            'plan' => $plan->uuid,
        ])->assertCreated();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame($company->id, $invoice->company_id);
        $this->assertSame('open', $invoice->status->value);
        $this->assertSame('120.00', $invoice->amount);
        $this->assertStringStartsWith('INV-', $invoice->number);

        $this->assertDatabaseHas('payment_events', [
            'invoice_id' => $invoice->id,
            'event_type' => 'issued',
        ]);
    }

    public function test_renewing_a_subscription_issues_a_second_invoice(): void
    {
        $this->actAsStaff();
        $company = Company::factory()->create();
        $plan = $this->plan();

        $created = $this->postJson('/api/v1/subscriptions', [
            'company' => $company->uuid,
            'plan' => $plan->uuid,
        ])->assertCreated();

        $subscriptionUuid = $created->json('data.id');
        $this->assertIsString($subscriptionUuid);

        $this->postJson("/api/v1/subscriptions/{$subscriptionUuid}/renew")->assertOk();

        // One invoice per billed period.
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_a_free_plan_raises_no_invoice(): void
    {
        $this->actAsStaff();
        $company = Company::factory()->create();
        $plan = $this->plan('0.00');

        $this->postJson('/api/v1/subscriptions', [
            'company' => $company->uuid,
            'plan' => $plan->uuid,
        ])->assertCreated();

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_issuance_is_idempotent_per_period(): void
    {
        $subscription = $this->activeSubscription();
        $service = app(PaymentService::class);

        $first = $service->issueForSubscription($subscription);
        $second = $service->issueForSubscription($subscription);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::query()->count());
    }

    // --- Manual issuance ---

    public function test_staff_can_manually_issue_an_invoice(): void
    {
        $this->actAsStaff();
        $subscription = $this->activeSubscription('499.00');

        $this->postJson('/api/v1/invoices', ['subscription' => $subscription->uuid])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.amount', '499.00');
    }

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/invoices')->assertUnauthorized();
    }

    // --- Settlement ---

    public function test_recording_a_payment_settles_the_invoice(): void
    {
        $this->actAsStaff();
        Event::fake([InvoicePaid::class]);
        $invoice = Invoice::factory()->create(['amount' => '50.00', 'currency' => 'USD']);

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", [
            'method' => 'bank_transfer',
            'reference' => 'TXN-9001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.method', 'bank_transfer')
            ->assertJsonPath('data.amount', '50.00')
            ->assertJsonPath('data.gateway', 'manual');

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
        $this->assertNotNull($invoice->paid_at);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'reference' => 'TXN-9001',
            'gateway' => 'manual',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'invoice_id' => $invoice->id,
            'event_type' => 'paid',
            'actor_type' => 'user',
        ]);

        Event::assertDispatched(InvoicePaid::class);
    }

    public function test_a_paid_invoice_cannot_be_paid_again(): void
    {
        $this->actAsStaff();
        $invoice = Invoice::factory()->paid()->create();

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", ['method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    // --- Voiding ---

    public function test_staff_can_void_an_open_invoice(): void
    {
        $this->actAsStaff();
        $invoice = Invoice::factory()->create();

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/void")
            ->assertOk()
            ->assertJsonPath('data.status', 'void');

        $this->assertDatabaseHas('payment_events', [
            'invoice_id' => $invoice->id,
            'event_type' => 'voided',
        ]);
    }

    public function test_a_paid_invoice_cannot_be_voided(): void
    {
        $this->actAsStaff();
        $invoice = Invoice::factory()->paid()->create();

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/void")
            ->assertStatus(422);
    }

    // --- Reads ---

    public function test_show_includes_payments_and_company(): void
    {
        $this->actAsStaff();
        $invoice = Invoice::factory()->paid()->create();
        Payment::factory()->create(['invoice_id' => $invoice->id]);

        $this->getJson("/api/v1/invoices/{$invoice->uuid}")
            ->assertOk()
            ->assertJsonPath('data.number', $invoice->number)
            ->assertJsonPath('data.payments_count', 1)
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.company.id', $invoice->company->uuid);
    }
}
