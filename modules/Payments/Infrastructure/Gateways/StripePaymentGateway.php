<?php

namespace Modules\Payments\Infrastructure\Gateways;

use Illuminate\Validation\ValidationException;
use Modules\Payments\Application\DTO\PaymentIntent;
use Modules\Payments\Application\Support\MinorUnits;
use Modules\Payments\Domain\Contracts\GatewayPaymentResult;
use Modules\Payments\Domain\Contracts\PaymentGateway;
use Modules\Payments\Domain\Enums\PaymentMethod;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Infrastructure\Stripe\StripeClient;
use Modules\Payments\Infrastructure\Stripe\StripePayload;

/**
 * Live Stripe adapter (ADR 0009). Card payments settle **asynchronously**:
 * `createPaymentIntent()` starts a charge the browser confirms, and Stripe's
 * `payment_intent.succeeded` webhook drives settlement through the PaymentService.
 *
 * Because of that, the synchronous {@see PaymentGateway::collect()} contract — used
 * by the manual gateway to record an already-received receipt — is intentionally
 * unsupported here: an invoice is never marked paid without a confirmed charge
 * (Commandment #2).
 */
final class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $client) {}

    public function identifier(): string
    {
        return 'stripe';
    }

    public function collect(Invoice $invoice, PaymentMethod $method, ?string $reference): GatewayPaymentResult
    {
        throw ValidationException::withMessages([
            'gateway' => __('Stripe payments are collected via a PaymentIntent and confirmed by webhook; they cannot be recorded synchronously through this endpoint.'),
        ]);
    }

    /**
     * Start a card charge for an open invoice and return the intent the browser
     * confirms. Correlation metadata is echoed back on the settlement webhook.
     */
    public function createPaymentIntent(Invoice $invoice): PaymentIntent
    {
        if (! $this->client->isConfigured()) {
            throw ValidationException::withMessages([
                'gateway' => __('The Stripe gateway is not configured.'),
            ]);
        }

        $data = new StripePayload($this->client->createPaymentIntent(
            MinorUnits::fromDecimalString($invoice->amount),
            $invoice->currency,
            [
                'invoice_id' => $invoice->uuid,
                'invoice_number' => $invoice->number,
            ],
        ));

        return new PaymentIntent(
            id: $data->string('id'),
            clientSecret: $data->string('client_secret'),
            status: $data->string('status'),
        );
    }
}
