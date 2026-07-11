<?php

namespace Modules\Core\Domain\Contracts;

/**
 * Implemented by an authenticatable that belongs to a company, so the tenant
 * context can resolve the current company without Core depending on any
 * specific module's user model.
 */
interface HasCompany
{
    public function companyId(): ?int;
}
