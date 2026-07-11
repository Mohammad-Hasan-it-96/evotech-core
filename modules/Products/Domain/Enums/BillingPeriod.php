<?php

namespace Modules\Products\Domain\Enums;

enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Lifetime = 'lifetime';

    /** Duration in days, or null for lifetime (no expiry). */
    public function days(): ?int
    {
        return match ($this) {
            self::Monthly => 30,
            self::Yearly => 365,
            self::Lifetime => null,
        };
    }
}
