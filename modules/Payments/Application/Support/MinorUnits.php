<?php

namespace Modules\Payments\Application\Support;

/**
 * Converts a `decimal(10,2)` money string (as stored/compared on invoices) to the
 * integer minor units Stripe expects (e.g. "50.00" → 5000), using string math
 * only — never floats (ADR 0006 money-integrity rule, Commandment #2).
 *
 * NOTE: assumes 2-decimal ("two exponent") currencies. Zero-decimal currencies
 * (JPY, KRW…) need a per-currency exponent table before going live — see ADR 0009.
 */
final class MinorUnits
{
    public static function fromDecimalString(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        $whole = $whole === '' ? '0' : preg_replace('/\D/', '', $whole);
        $fraction = substr(str_pad(preg_replace('/\D/', '', $fraction) ?? '', 2, '0'), 0, 2);

        $minor = (int) (($whole ?? '0').$fraction);

        return $negative ? -$minor : $minor;
    }
}
