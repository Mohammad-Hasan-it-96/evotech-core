# Module: Companies

Owns the **company** entity — a tenant organization on the platform (a business that subscribes to EVOTECH products). Companies are **platform-global**: EVOTECH staff manage all of them, so the model is *not* itself company-scoped.

## Endpoints (all `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/companies` | `companies.index` | Paginated list (`per_page`, max 100). |
| POST | `/companies` | `companies.store` | Create (201). |
| GET | `/companies/{company}` | `companies.show` | Show by `uuid`. |
| PATCH/PUT | `/companies/{company}` | `companies.update` | Update. |
| DELETE | `/companies/{company}` | `companies.destroy` | Soft delete (204). |

## Domain

| Class | Notes |
|---|---|
| `Domain\Models\Company` | `HasUuid`, `SoftDeletes`. Fields: name, email, phone, status. |
| `Domain\Enums\CompanyStatus` | `active` / `inactive` / `suspended`. |
| `Application\Services\CompanyService` | list / create / update / delete use-cases. |

## Tenancy note

`company_id` on `users` (added by this module) links a user to the company they belong to — that is what `Core\...\TenantContext` reads to scope tenant-owned data (see [Customers](customers.md)). A user with `company_id = null` is platform staff and sees across all tenants.
