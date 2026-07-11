# Module: Customers

Owns **customer** records that belong to a company. This is the platform's first **tenant-scoped** module and the reference implementation of multi-tenancy (constitution §5.1).

## Endpoints (all `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/customers` | `customers.index` | Paginated list — **scoped to the caller's company**. |
| POST | `/customers` | `customers.store` | Create; `company_id` is set from the tenant, never client input. |
| GET | `/customers/{customer}` | `customers.show` | Show by `uuid` (404 if not in the caller's company). |
| PATCH/PUT | `/customers/{customer}` | `customers.update` | Update. |
| DELETE | `/customers/{customer}` | `customers.destroy` | Soft delete (204). |

## How tenant scoping works

`Customer` uses `Core\Domain\Concerns\BelongsToCompany`, which:

1. Adds a global scope filtering by `TenantContext::companyId()` — so every query only returns the current company's rows.
2. Fills `company_id` on create from the tenant context. `company_id` is **not fillable**, so a client cannot plant a record in another company (covered by a test).

The tenant is resolved from the authenticated user's `company_id` (`Core\Infrastructure\Tenancy\RequestTenantContext`). Platform staff (`company_id = null`) are unscoped. To deliberately read across tenants: `Customer::withoutGlobalScope('company')`.

## Isolation guarantees (tested)

`CustomerTenantIsolationTest` proves: a user only lists their company's customers, cannot fetch another company's customer (404, no existence leak), new customers auto-assign to the caller's company, and `company_id` cannot be overridden via request body.
