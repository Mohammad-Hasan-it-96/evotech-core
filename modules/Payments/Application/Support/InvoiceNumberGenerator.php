<?php

namespace Modules\Payments\Application\Support;

use Modules\Payments\Domain\Models\Invoice;

/**
 * Produces sequential human invoice numbers (`INV-000001`). The `number` column's
 * unique index is the integrity backstop against a concurrent collision.
 */
final class InvoiceNumberGenerator
{
    public function next(): string
    {
        $max = Invoice::max('id');
        $next = (is_numeric($max) ? (int) $max : 0) + 1;

        return 'INV-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
