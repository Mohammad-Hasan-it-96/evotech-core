<?php

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Http\Responses\ApiResponse;
use Modules\Payments\Application\Services\PaymentService;
use Modules\Payments\Application\Support\MinorUnits;
use Modules\Payments\Domain\Contracts\GatewayPaymentResult;
use Modules\Payments\Domain\Enums\PaymentMethod;
use Modules\Payments\Domain\Exceptions\WebhookSignatureException;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Infrastructure\Stripe\StripePayload;
use Modules\Payments\Infrastructure\Stripe\StripeWebhookVerifier;

/**
 * Receives Stripe webhooks (ADR 0009). Unauthenticated by design — trust comes
 * from the HMAC signature, not a session — and settles the matching invoice on
 * `payment_intent.succeeded`. Idempotent, amount-checked, and audited via the
 * PaymentService ledger.
 */
final class StripeWebhookController extends ApiController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = config('payments.stripe.webhook_secret');

        $verifier = new StripeWebhookVerifier(
            is_string($secret) ? $secret : '',
            Config::integer('payments.stripe.webhook_tolerance', 300),
        );

        try {
            $event = new StripePayload($verifier->verify(
                (string) $request->getContent(),
                $request->header('Stripe-Signature'),
                Carbon::now()->getTimestamp(),
            ));
        } catch (WebhookSignatureException $e) {
            return ApiResponse::error('WEBHOOK_SIGNATURE_INVALID', $e->getMessage(), status: 400);
        }

        // Acknowledge (200) any event we don't act on, so Stripe stops retrying.
        $type = $event->string('type', 'unknown');
        if ($type !== 'payment_intent.succeeded') {
            return ApiResponse::success(['ignored' => $type]);
        }

        $intentId = $event->string('data.object.id');
        $invoice = Invoice::query()
            ->where('uuid', $event->string('data.object.metadata.invoice_id'))
            ->first();

        if ($invoice === null) {
            return ApiResponse::success(['ignored' => 'unknown_invoice']);
        }

        // Money-integrity guard: the charged amount must equal the invoice amount.
        $paidMinor = $event->int('data.object.amount_received', $event->int('data.object.amount'));

        if ($paidMinor !== MinorUnits::fromDecimalString($invoice->amount)) {
            return ApiResponse::error('WEBHOOK_AMOUNT_MISMATCH', __('The paid amount does not match the invoice.'), status: 422);
        }

        $this->payments->settleFromWebhook(
            $invoice,
            new GatewayPaymentResult(
                succeeded: true,
                method: PaymentMethod::Card,
                reference: $intentId,
                amount: $invoice->amount,
                currency: $invoice->currency,
            ),
            'stripe',
            [
                'stripe_event' => $event->string('id'),
                'stripe_payment_intent' => $intentId,
            ],
        );

        return ApiResponse::success(['settled' => $invoice->uuid]);
    }
}
