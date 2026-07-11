<?php

namespace Modules\Payments\Domain\Enums;

/**
 * Event kinds written to the immutable `payment_events` ledger (constitution §5):
 * invoice issuance, settlement, and voiding.
 */
enum PaymentEventType: string
{
    case Issued = 'issued';
    case Paid = 'paid';
    case Voided = 'voided';
}
