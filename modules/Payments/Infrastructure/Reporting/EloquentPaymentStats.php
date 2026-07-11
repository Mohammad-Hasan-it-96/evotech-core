<?php

namespace Modules\Payments\Infrastructure\Reporting;

use Modules\Payments\Domain\Contracts\PaymentStats;
use Modules\Payments\Domain\Enums\InvoiceStatus;
use Modules\Payments\Domain\Models\Invoice;

final class EloquentPaymentStats implements PaymentStats
{
    public function collectedByCurrency(): array
    {
        return $this->sumByCurrency(InvoiceStatus::Paid);
    }

    public function outstandingByCurrency(): array
    {
        return $this->sumByCurrency(InvoiceStatus::Open);
    }

    public function paidCount(): int
    {
        return Invoice::query()->where('status', InvoiceStatus::Paid->value)->count();
    }

    public function openCount(): int
    {
        return Invoice::query()->where('status', InvoiceStatus::Open->value)->count();
    }

    /**
     * @return array<string, string>
     */
    private function sumByCurrency(InvoiceStatus $status): array
    {
        // Static aggregate, no user input — safe raw expression (§6.8).
        $rows = Invoice::query()
            ->where('status', $status->value)
            ->groupBy('currency')
            ->selectRaw('currency, SUM(amount) as total')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $currency = $row->getAttribute('currency');
            $total = $row->getAttribute('total');

            if (is_string($currency)) {
                $totals[$currency] = is_numeric($total) ? number_format((float) $total, 2, '.', '') : '0.00';
            }
        }

        return $totals;
    }
}
