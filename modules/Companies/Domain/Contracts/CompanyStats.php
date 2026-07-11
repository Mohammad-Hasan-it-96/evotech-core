<?php

namespace Modules\Companies\Domain\Contracts;

/**
 * Aggregate company counts for reporting. The Companies module owns queries over
 * its own data; consumers (e.g. Reports) depend on this contract, not the model
 * (§2.1/§2.4).
 */
interface CompanyStats
{
    public function total(): int;

    public function active(): int;
}
