# Module: Products

Owns the **product catalog** — products and their pricing **plans**. Platform-global reference data (not tenant-scoped) and the **single source** consumed by both the marketing website and the subscriptions dashboard.

## Endpoints (public read, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/products` | `products.index` | Active products, each with their active plans. |
| GET | `/products/{product:slug}` | `products.show` | One product (by slug) with its active plans. |

Public (no auth) — it's catalog data — but still rate-limited via the `api` group.

## Domain

| Class | Notes |
|---|---|
| `Domain\Models\Product` | Keyed by `slug`. Translatable `name`/`tagline`/`description` stored as JSON `{ "ar": ..., "en": ... }`. `plans()` (all) and `activePlans()` (filtered). |
| `Domain\Models\Plan` | Belongs to a product. `HasUuid`. `price` (decimal), `currency`, `billing_period`, translatable `name`, `features` (JSON list of `{ar,en}`), `is_popular`. |
| `Domain\Enums\ProductStatus` | `active` / `inactive`. |
| `Domain\Enums\BillingPeriod` | `monthly` / `yearly` / `lifetime` (+ `days()`). |
| `Application\Services\ProductCatalogService` | Active catalog with active plans. |

## Bilingual content

Translatable fields are JSON `{ar, en}` — mirroring the website's `src/content` shape — so the frontend keeps using its `localized(field, locale)` helper unchanged when it switches from local content to this API (Phase 3.5).

## Seeding

`Database\Seeders\ProductCatalogSeeder` is a **reference seeder** (idempotent via `updateOrCreate`, safe in any environment) holding the real 5 EVOTECH products + plans. Run via `php artisan db:seed` (wired into `DatabaseSeeder`).

## Next

Consumed by the `Subscriptions` module (Phase 3.2): a subscription references a `Plan`. Admin CRUD for products/plans can be added when the dashboard needs catalog editing.
