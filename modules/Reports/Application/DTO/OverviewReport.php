<?php

namespace Modules\Reports\Application\DTO;

/**
 * The platform overview report — headline KPIs composed from each module's stats
 * contract. Money is kept per currency (never summed across currencies).
 */
final readonly class OverviewReport
{
    /**
     * @param  array<string, string>  $collected
     * @param  array<string, string>  $outstanding
     */
    public function __construct(
        public int $companiesTotal,
        public int $companiesActive,
        public int $subscriptionsTotal,
        public int $subscriptionsActive,
        public int $licensesTotal,
        public int $licensesActive,
        public int $licenseActivations,
        public array $collected,
        public array $outstanding,
        public int $invoicesPaid,
        public int $invoicesOpen,
    ) {}
}
