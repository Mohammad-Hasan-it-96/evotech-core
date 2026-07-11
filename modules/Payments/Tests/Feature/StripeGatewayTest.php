<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Payments\Domain\Contracts\PaymentGateway;
use Modules\Payments\Domain\Events\InvoicePaid;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Infrastructure\Gateways\ManualPaymentGateway;
use Modules\Payments\Infrastructure\Gateways\StripePaymentGateway;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class StripeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function enableStripe(): void
    {
        config()->set('payments.gateway', 'stripe');
        config()->set('payments.stripe.secret', 'sk_test_123');
        config()->set('payments.stripe.publishable', 'pk_test_123');
        config()->set('payments.stripe.webhook_secret', 'whsec_test');
        config()->set('payments.stripe.api_base', 'https://api.stripe.example');
    }

    /**
     * Build the raw body + signed `Stripe-Signature` header for a webhook, using
     * the same HMAC scheme the verifier checks.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: string} [rawBody, signatureHeader]
     */
    private function signedEvent(array $event, ?int $timestamp = null, string $secret = 'whsec_test'): array
    {
        $payload = (string) json_encode($event);
        $timestamp ??= Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return [$payload, "t={$timestamp},v1={$signature}"];
    }

    /**
     * @return TestResponse<Response>
     */
    private function postWebhook(string $payload, string $signature): TestResponse
    {
        return $this->call(
            'POST',
            '/api/v1/stripe/webhook',
            server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function succeededEvent(Invoice $invoice, array $object = []): array
    {
        return [
            'id' => 'evt_test_1',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => array_merge([
                    'id' => 'pi_test_1',
                    'amount_received' => 5000,
                    'currency' => 'usd',
                    'metadata' => ['invoice_id' => $invoice->uuid],
                ], $object),
            ],
        ];
    }

    // --- Gateway selection ---

    public function test_manual_is_the_default_gateway(): void
    {
        $this->assertInstanceOf(ManualPaymentGateway::class, app(PaymentGateway::class));
        $this->assertSame('manual', app(PaymentGateway::class)->identifier());
    }

    public function test_stripe_is_bound_when_configured(): void
    {
        $this->enableStripe();

        $this->assertInstanceOf(StripePaymentGateway::class, app(PaymentGateway::class));
        $this->assertSame('stripe', app(PaymentGateway::class)->identifier());
    }

    // --- PaymentIntent creation ---

    public function test_creating_a_payment_intent_calls_stripe_and_returns_a_client_secret(): void
    {
        $this->actAsStaff();
        $this->enableStripe();
        Http::fake([
            'api.stripe.example/*' => Http::response([
                'id' => 'pi_test_abc',
                'client_secret' => 'pi_test_abc_secret_xyz',
                'status' => 'requires_payment_method',
            ], 200),
        ]);

        $invoice = Invoice::factory()->create(['amount' => '50.00', 'currency' => 'USD']);

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payment-intent")
            ->assertCreated()
            ->assertJsonPath('data.payment_intent', 'pi_test_abc')
            ->assertJsonPath('data.client_secret', 'pi_test_abc_secret_xyz')
            ->assertJsonPath('data.publishable_key', 'pk_test_123');

        // Correct minor-unit amount + currency sent to Stripe.
        Http::assertSent(function (ClientRequest $request): bool {
            return str_contains($request->url(), '/v1/payment_intents')
                && $request['amount'] === 5000
                && $request['currency'] === 'usd';
        });

        $invoice->refresh();
        $this->assertSame('pi_test_abc', $invoice->meta['stripe_payment_intent'] ?? null);
    }

    public function test_payment_intent_requires_the_stripe_gateway_to_be_enabled(): void
    {
        $this->actAsStaff();
        $invoice = Invoice::factory()->create();

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payment-intent")
            ->assertStatus(422);
    }

    // --- The synchronous manual endpoint must never fake a Stripe charge ---

    public function test_manual_settlement_is_refused_while_stripe_is_active(): void
    {
        $this->actAsStaff();
        $this->enableStripe();
        $invoice = Invoice::factory()->create();

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", ['method' => 'bank_transfer'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $invoice->refresh();
        $this->assertSame('open', $invoice->status->value);
    }

    public function test_card_is_not_accepted_on_the_manual_endpoint(): void
    {
        $this->actAsStaff();
        $invoice = Invoice::factory()->create();

        $this->postJson("/api/v1/invoices/{$invoice->uuid}/payments", ['method' => 'card'])
            ->assertStatus(422);
    }

    // --- Webhook settlement ---

    public function test_a_verified_webhook_settles_the_invoice(): void
    {
        $this->enableStripe();
        Event::fake([InvoicePaid::class]);
        $invoice = Invoice::factory()->create(['amount' => '50.00', 'currency' => 'USD']);

        [$payload, $signature] = $this->signedEvent($this->succeededEvent($invoice));

        $this->postWebhook($payload, $signature)
            ->assertOk()
            ->assertJsonPath('data.settled', $invoice->uuid);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status->value);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'gateway' => 'stripe',
            'method' => 'card',
            'reference' => 'pi_test_1',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'invoice_id' => $invoice->id,
            'event_type' => 'paid',
            'actor_type' => 'system',
        ]);

        Event::assertDispatched(InvoicePaid::class);
    }

    public function test_an_invalid_signature_is_rejected_and_nothing_is_settled(): void
    {
        $this->enableStripe();
        $invoice = Invoice::factory()->create(['amount' => '50.00', 'currency' => 'USD']);

        [$payload] = $this->signedEvent($this->succeededEvent($invoice));

        $this->postWebhook($payload, 't=0,v1=deadbeef')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');

        $this->assertSame('open', $invoice->refresh()->status->value);
    }

    public function test_a_stale_timestamp_is_rejected_as_a_replay(): void
    {
        $this->enableStripe();
        $invoice = Invoice::factory()->create(['amount' => '50.00', 'currency' => 'USD']);

        $stale = Carbon::now()->getTimestamp() - 3600;
        [$payload, $signature] = $this->signedEvent($this->succeededEvent($invoice), timestamp: $stale);

        $this->postWebhook($payload, $signature)->assertStatus(400);
        $this->assertSame('open', $invoice->refresh()->status->value);
    }

    public function test_an_amount_mismatch_is_rejected(): void
    {
        $this->enableStripe();
        $invoice = Invoice::factory()->create(['amount' => '50.00', 'currency' => 'USD']);

        [$payload, $signature] = $this->signedEvent(
            $this->succeededEvent($invoice, ['amount_received' => 9999]),
        );

        $this->postWebhook($payload, $signature)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WEBHOOK_AMOUNT_MISMATCH');

        $this->assertSame('open', $invoice->refresh()->status->value);
    }

    public function test_webhook_settlement_is_idempotent(): void
    {
        $this->enableStripe();
        $invoice = Invoice::factory()->create(['amount' => '50.00', 'currency' => 'USD']);

        [$payload, $signature] = $this->signedEvent($this->succeededEvent($invoice));

        $this->postWebhook($payload, $signature)->assertOk();
        $this->postWebhook($payload, $signature)->assertOk();

        $this->assertSame(1, $invoice->refresh()->payments()->count());
    }

    public function test_unhandled_event_types_are_acknowledged(): void
    {
        $this->enableStripe();

        [$payload, $signature] = $this->signedEvent([
            'id' => 'evt_x',
            'type' => 'charge.refunded',
            'data' => ['object' => []],
        ]);

        $this->postWebhook($payload, $signature)
            ->assertOk()
            ->assertJsonPath('data.ignored', 'charge.refunded');
    }
}
