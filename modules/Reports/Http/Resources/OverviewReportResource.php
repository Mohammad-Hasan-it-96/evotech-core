<?php

namespace Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Reports\Application\DTO\OverviewReport;

/**
 * @mixin OverviewReport
 */
class OverviewReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'companies' => [
                'total' => $this->companiesTotal,
                'active' => $this->companiesActive,
            ],
            'subscriptions' => [
                'total' => $this->subscriptionsTotal,
                'active' => $this->subscriptionsActive,
            ],
            'licenses' => [
                'total' => $this->licensesTotal,
                'active' => $this->licensesActive,
                'active_activations' => $this->licenseActivations,
            ],
            'billing' => [
                'collected' => $this->collected,
                'outstanding' => $this->outstanding,
                'invoices_paid' => $this->invoicesPaid,
                'invoices_open' => $this->invoicesOpen,
            ],
        ];
    }
}
