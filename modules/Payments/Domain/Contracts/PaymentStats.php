<?php

namespace Modules\Payments\Domain\Contracts;

/**
 * Aggregate billing figures for reporting. Owned by the Payments module; consumers
 * depend on this contract, not the models (§2.1/§2.4). Money is summed per
 * currency (never across currencies) and returned as decimal strings.
 */
interface PaymentStats
{
    /** @return array<string, string> currency => total collected (paid invoices) */
    public function collectedByCurrency(): array;

    /** @return array<string, string> currency => total outstanding (open invoices) */
    public function outstandingByCurrency(): array;

    public function paidCount(): int;

    public function openCount(): int;
}
