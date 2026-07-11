<?php

namespace Modules\Reports\Application\Services;

use Modules\Companies\Domain\Contracts\CompanyStats;
use Modules\Licenses\Domain\Contracts\LicenseStats;
use Modules\Payments\Domain\Contracts\PaymentStats;
use Modules\Reports\Application\DTO\OverviewReport;
use Modules\Subscriptions\Domain\Contracts\SubscriptionStats;

/**
 * Composes the platform overview from each module's stats contract. Reports owns
 * no data — it depends only on published contracts, never on other modules'
 * models (§2.1/§2.4).
 */
final class ReportService
{
    public function __construct(
        private readonly CompanyStats $companies,
        private readonly SubscriptionStats $subscriptions,
        private readonly LicenseStats $licenses,
        private readonly PaymentStats $payments,
    ) {}

    public function overview(): OverviewReport
    {
        return new OverviewReport(
            companiesTotal: $this->companies->total(),
            companiesActive: $this->companies->active(),
            subscriptionsTotal: $this->subscriptions->total(),
            subscriptionsActive: $this->subscriptions->active(),
            licensesTotal: $this->licenses->total(),
            licensesActive: $this->licenses->active(),
            licenseActivations: $this->licenses->activeActivations(),
            collected: $this->payments->collectedByCurrency(),
            outstanding: $this->payments->outstandingByCurrency(),
            invoicesPaid: $this->payments->paidCount(),
            invoicesOpen: $this->payments->openCount(),
        );
    }
}
