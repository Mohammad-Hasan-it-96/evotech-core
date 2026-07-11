<?php

namespace Modules\Payments\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Domain\Models\Payment;

/**
 * Published when an invoice is settled. Other modules (Notifications, Reports)
 * may react via listeners; Payments does not depend on them (§2.1).
 */
final class InvoicePaid
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly Payment $payment,
    ) {}
}
