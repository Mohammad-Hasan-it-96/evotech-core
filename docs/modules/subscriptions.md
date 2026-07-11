# Module: Subscriptions

The core of the dashboard: links a **Company** (subscriber) to a **Plan** for a period, and manages the lifecycle. Admin/staff-managed. This is a **composition module** — it references the [Companies](companies.md) and [Products](products.md) modules (an accepted, acyclic dependency; `Subscriptions → {Companies, Products} → Core`).

## Endpoints (all `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/subscriptions` | `subscriptions.index` | Paginated list, each with company + plan + product. |
| POST | `/subscriptions` | `subscriptions.store` | Create — body: `company` (uuid), `plan` (uuid), `identifier_type` (`domain`/`device`), `identifier_value`, `starts_at?`, `auto_renew?`. |
| GET | `/subscriptions/{subscription}` | `subscriptions.show` | Show by uuid. |
| PATCH/PUT | `/subscriptions/{subscription}` | `subscriptions.update` | Update identifier / status / auto_renew. |
| DELETE | `/subscriptions/{subscription}` | `subscriptions.destroy` | Soft delete (204). |
| POST | `/subscriptions/{subscription}/renew` | `subscriptions.renew` | Extend one plan cycle & re-activate. |
| POST | `/subscriptions/{subscription}/cancel` | `subscriptions.cancel` | Cancel (status + auto_renew off). |

## Domain & lifecycle

| Class | Notes |
|---|---|
| `Domain\Models\Subscription` | `HasUuid`, `SoftDeletes`. `belongsTo` Company + Plan. Snapshots `price`/`currency` at creation. `isCurrentlyActive()`. |
| `Domain\Enums\SubscriptionStatus` | `pending`/`active`/`expired`/`cancelled`/`suspended`. |
| `Domain\Enums\IdentifierType` | `domain` / `device` (the "domain or mobile id"). |
| `Application\Services\SubscriptionService` | create / update / renew / cancel / `expireDue()`. Derives `ends_at` from the plan's `BillingPeriod` (null for lifetime). |
| `Console\ExpireSubscriptionsCommand` | `php artisan subscriptions:expire` — **scheduled daily** (module `Routes/console.php`) to mark past-due active subscriptions expired. |

- On create, the subscriber (`company`) and `plan` are resolved from their **uuids**; the price is snapshotted so later plan changes don't rewrite history.
- The API returns computed `is_active` and `days_remaining` for the dashboard.

## Tests

`SubscriptionLifecycleTest` covers create (period derivation + price snapshot), lifetime (no end date), validation envelope, renew (extends from the future end date), cancel, the expire command, and the enriched list payload.
