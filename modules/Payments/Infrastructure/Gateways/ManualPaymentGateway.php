<?php

namespace Modules\Payments\Infrastructure\Gateways;

use Modules\Payments\Domain\Contracts\GatewayPaymentResult;
use Modules\Payments\Domain\Contracts\PaymentGateway;
use Modules\Payments\Domain\Enums\PaymentMethod;
use Modules\Payments\Domain\Models\Invoice;

/**
 * Records payments collected offline (bank transfer, cash, or otherwise manually
 * reconciled). There is no external call — the amount is taken as received in
 * full (ADR 0006). A live Stripe adapter is a later, drop-in implementation.
 */
final class ManualPaymentGateway implements PaymentGateway
{
    public function identifier(): string
    {
        return 'manual';
    }

    public function collect(Invoice $invoice, PaymentMethod $method, ?string $reference): GatewayPaymentResult
    {
        return new GatewayPaymentResult(
            succeeded: true,
            method: $method,
            reference: $reference,
            amount: $invoice->amount,
            currency: $invoice->currency,
        );
    }
}
