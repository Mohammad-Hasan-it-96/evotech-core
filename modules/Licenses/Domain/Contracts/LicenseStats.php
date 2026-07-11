<?php

namespace Modules\Licenses\Domain\Contracts;

/**
 * Aggregate license counts for reporting. Owned by the Licenses module; consumers
 * depend on this contract, not the models (§2.1/§2.4).
 */
interface LicenseStats
{
    public function total(): int;

    public function active(): int;

    /** Device/domain activations currently occupying a slot. */
    public function activeActivations(): int;
}
