<?php

namespace Modules\Payments\Domain\Enums;

/**
 * How a payment was collected. Offline/manual methods are recorded directly; the
 * gateway-collected `card` method arrives via the Stripe adapter's webhook (ADR
 * 0009) and is not accepted on the manual settlement endpoint.
 */
enum PaymentMethod: string
{
    case Manual = 'manual';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
}
