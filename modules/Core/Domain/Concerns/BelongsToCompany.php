<?php

namespace Modules\Core\Domain\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Domain\Contracts\TenantContext;

/**
 * Marks a model as tenant-owned (constitution §5.1). Adds a global scope that
 * auto-filters rows by the current company, and auto-fills `company_id` on create.
 *
 * Rule: any query touching tenant data is tenant-scoped. To deliberately bypass
 * (platform-wide admin reads), use `Model::withoutGlobalScope('company')` explicitly.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $tenant = app(TenantContext::class);

            if ($tenant->hasTenant()) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('company_id'),
                    $tenant->companyId(),
                );
            }
        });

        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('company_id'))) {
                $tenant = app(TenantContext::class);

                if ($tenant->hasTenant()) {
                    $model->setAttribute('company_id', $tenant->companyId());
                }
            }
        });
    }
}
