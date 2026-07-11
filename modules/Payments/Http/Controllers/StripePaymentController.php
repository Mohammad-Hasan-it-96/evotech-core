<?php

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Http\Responses\ApiResponse;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Infrastructure\Gateways\StripePaymentGateway;

/**
 * Starts a Stripe card payment for an open invoice (ADR 0009). Returns the
 * PaymentIntent's client secret for the dashboard to confirm the card; the
 * invoice is settled later by the `payment_intent.succeeded` webhook, never here.
 */
final class StripePaymentController extends ApiController
{
    public function __construct(private readonly StripePaymentGateway $stripe) {}

    public function createIntent(Invoice $invoice): JsonResponse
    {
        if (config('payments.gateway') !== 'stripe') {
            throw ValidationException::withMessages([
                'gateway' => __('The Stripe gateway is not enabled.'),
            ]);
        }

        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages([
                'invoice' => __('Only an open invoice can be paid.'),
            ]);
        }

        $intent = $this->stripe->createPaymentIntent($invoice);

        $invoice->forceFill([
            'meta' => array_merge($invoice->meta ?? [], ['stripe_payment_intent' => $intent->id]),
        ])->save();

        $publishable = config('payments.stripe.publishable');

        return ApiResponse::success([
            'payment_intent' => $intent->id,
            'client_secret' => $intent->clientSecret,
            'status' => $intent->status,
            'publishable_key' => is_string($publishable) ? $publishable : '',
        ], status: 201);
    }
}
