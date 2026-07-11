<?php

namespace Modules\Payments\Domain\Enums;

/**
 * Lifecycle state of an invoice. `Open` is issued-and-unpaid, `Paid` is settled,
 * `Void` is cancelled. Only an `Open` invoice may be paid or voided.
 */
enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
