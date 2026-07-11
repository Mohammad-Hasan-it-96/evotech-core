<?php

namespace Modules\Core\Domain\Contracts;

/**
 * Resolves the "current company" (tenant) for the request. Isolating tenant
 * resolution behind this contract keeps the app extraction-ready (constitution
 * §5.1) — a future move to a dedicated tenancy package or sharding is a swap.
 *
 * A null company id means "no tenant" (e.g. platform staff) → queries are not
 * company-scoped and see across all tenants.
 */
interface TenantContext
{
    public function companyId(): ?int;

    public function setCompanyId(?int $companyId): void;

    public function hasTenant(): bool;
}
