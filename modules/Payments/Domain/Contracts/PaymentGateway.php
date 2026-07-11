<?php

namespace Modules\Payments\Domain\Contracts;

use Modules\Payments\Domain\Enums\PaymentMethod;
use Modules\Payments\Domain\Models\Invoice;

/**
 * Collects payment for an invoice (ADR 0006). The manual/offline implementation
 * records a receipt immediately; a future Stripe adapter implements the same
 * contract behind it. The PaymentService — not the gateway — owns persistence and
 * the transaction boundary.
 */
interface PaymentGateway
{
    /** Stable identifier stored on the payment, e.g. "manual", "stripe". */
    public function identifier(): string;

    /** Attempt to collect the invoice's full amount. */
    public function collect(Invoice $invoice, PaymentMethod $method, ?string $reference): GatewayPaymentResult;
}
