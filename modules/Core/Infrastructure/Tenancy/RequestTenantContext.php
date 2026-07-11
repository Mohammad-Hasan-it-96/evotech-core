<?php

namespace Modules\Core\Infrastructure\Tenancy;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Core\Domain\Contracts\HasCompany;
use Modules\Core\Domain\Contracts\TenantContext;

/**
 * Default tenant resolution: the current company is the authenticated user's
 * `company_id` (null for platform staff), unless explicitly overridden — which
 * lets platform admins "act as" a company and lets jobs/console set a tenant.
 */
final class RequestTenantContext implements TenantContext
{
    private ?int $companyId = null;

    private bool $overridden = false;

    public function __construct(private readonly AuthFactory $auth) {}

    public function companyId(): ?int
    {
        if ($this->overridden) {
            return $this->companyId;
        }

        $user = $this->auth->guard()->user();

        return $user instanceof HasCompany ? $user->companyId() : null;
    }

    public function setCompanyId(?int $companyId): void
    {
        $this->companyId = $companyId;
        $this->overridden = true;
    }

    public function hasTenant(): bool
    {
        return $this->companyId() !== null;
    }
}
