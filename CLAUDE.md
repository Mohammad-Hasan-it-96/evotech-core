# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## ⚠️ Binding Architecture

**`docs/ARCHITECTURE.md` is the project constitution and is BINDING.** Read it before implementing anything. It governs architecture (Modular Monolith, 4-layer modules under `modules/`), the stack, database rules, security, the API contract, coding standards, git, testing, and deployment. Any deviation requires an ADR in `docs/adr/` and architect sign-off — when code and the constitution disagree, the constitution wins. Use its §16 compliance checklist to self-review every change.

## Project

evotech-core — the EVOTECH Platform: the API-first control plane for all EVOTECH products (Restaurant ERP/POS, mobile/driver apps, accounting, desktop, IoT/smart controllers, future SaaS), plus the public website. The Next.js website + dashboard live in a separate repo (`evotech-web`); this repo is the Laravel 12 API.

**Status (see [`docs/ROADMAP.md`](docs/ROADMAP.md)):** Phases 0–5 complete & verified; **Phase 6 (Expansion) in progress** — the Download Center is done. Feature work is well underway across 14 modules with ~121 backend tests. This is *not* a fresh skeleton.

**PHP runtime:** `composer.json` requires `^8.2` and Laravel 12 runs fine on 8.2 (the common local runtime). **CI runs on PHP 8.4** — the toolchain target. A local 8.4 upgrade is an open item, not a blocker.

## Commands

```bash
composer dev          # Run full dev stack concurrently: php artisan serve + queue:listen + pail (logs) + vite
composer test         # Clears config, then runs php artisan test
composer setup        # First-time setup: install deps, create .env, key:generate, migrate, npm install + build

php artisan test                                 # Run all tests
php artisan test --filter=ExampleTest            # Run a single test class
php artisan test --filter='ExampleTest::test_the_application_returns_a_successful_response'  # Single test method
php artisan test tests/Feature/ExampleTest.php   # Run one file
php artisan test --testsuite=Modules             # Run one suite (Unit | Feature | Modules)
# Most feature tests live in modules/<Module>/Tests/ (the "Modules" suite), not tests/

./vendor/bin/pint                # Format code (Laravel Pint / PSR-12)
./vendor/bin/pint --dirty        # Format only uncommitted changes
./vendor/bin/pint --test         # Check formatting without writing (CI gate)

composer analyse                 # Larastan static analysis at level max (CI gate)
composer openapi                 # Regenerate docs/api/openapi.json (commit it; CI checks drift)

php artisan migrate              # Run migrations (app + all module migrations)
php artisan route:list --path=api
php artisan tinker               # REPL
```

**Databases:** the **test suite** runs on in-memory SQLite (`phpunit.xml`) — no setup needed. **Local development** runs on **MySQL** (`evotech_core`, see `.env`) per [ADR 0003](docs/adr/0003-database-engine-mysql.md); production is MySQL on the VPS. CI runs the suite on both SQLite and MySQL.

## Backend modules

Feature code lives in **`modules/<Module>/`** (namespace `Modules\<Module>\`), auto-discovered by `App\Providers\ModuleServiceProvider` — see [ADR 0002](docs/adr/0002-module-layout-and-autoloading.md). `app/` is a thin shell (just `AppServiceProvider`, `ModuleServiceProvider`, base `Controller`); **do not put feature code there.** A module has a 4-layer layout: `Http/` (controllers, requests, resources) → `Application/` (services, DTOs, listeners) → `Domain/` (models, enums, contracts) → `Infrastructure/` (adapters), plus `Providers/ Console/ Database/ Routes/ Config/ Tests/`. To add a module: create the folder + a `<Name>ServiceProvider extends Modules\Core\Providers\BaseModuleServiceProvider` (no composer/bootstrap edits). Every module has a doc under [`docs/modules/`](docs/modules/) — **read it before touching that module.**

The 14 modules:

- **`Core`** — shared kernel: API success/error envelope, tenancy (`BelongsToCompany`, `HasUuid`), base classes, and cross-module **ports** (contracts other modules depend on instead of each other).
- **`Auth`** (Sanctum) / **`Users`** / **`Companies`** (tenant) / **`Customers`** (tenant-scoped) — Phase 2 foundation.
- **`Products`** — catalog: products + plans + bilingual pricing (public read endpoints).
- **`Subscriptions`** — subscription lifecycle (create/renew/cancel/expire); **subscriber = a Company**, with a plan snapshot + daily expiry sweep.
- **`Licenses`** — a company's product entitlement derived from a subscription; issue/suspend/revoke, device/domain activation slots, immutable event ledger, **EdDSA-signed offline tokens** for devices ([ADR 0005](docs/adr/0005-signed-offline-license-tokens.md)).
- **`Gateway`** — the product-to-platform edge: **per-product API keys** (hashed, revocable) + the `auth:product` guard ([ADR 0004](docs/adr/0004-product-to-platform-auth-api-keys.md)).
- **`Payments`** — invoices derived from subscription periods + immutable `payment_events` ledger behind a config-selected `PaymentGateway` (`PAYMENTS_GATEWAY`): manual/offline live, plus a scaffolded SDK-less **Stripe** adapter (async PaymentIntent + HMAC-verified webhook, pending credentials) ([ADR 0006](docs/adr/0006-billing-invoices-and-payment-gateway.md), [ADR 0009](docs/adr/0009-stripe-live-gateway.md)).
- **`Notifications`** — queued, multi-channel (`database` + `mail`) dispatch that **reacts to domain events** (e.g. `InvoicePaid` → billed company's users).
- **`Audit`** — immutable `audit_logs` trail behind Core's `AuditLogger` port ([ADR 0007](docs/adr/0007-native-audit-log.md)).
- **`Reports`** — read-only KPI aggregations composed from per-module stats contracts.
- **`Downloads`** — the Download Center (Phase 6): versioned releases/artifacts, private storage + short-lived signed URLs, product self-update ([ADR 0008](docs/adr/0008-download-center-delivery.md)).

### Cross-cutting patterns (read multiple modules to see these)

- **Two auth contexts.** `auth:sanctum` guards **staff/dashboard** routes (humans); `auth:product` guards **product/device** routes (machine-to-machine via Gateway API keys). Product-facing routes are scoped to the caller's own product — cross-product access returns **404**, not 403.
- **Modules never touch each other's models (§2.4).** They coordinate through **ports/contracts in `Core`** and **domain events**. Canonical example: any module records actions via the `AuditLogger` port (safe no-op default in Core; `Audit` supplies the persisting adapter) — so nothing depends on `Audit` directly. Likewise `Reports` aggregates via per-module stats contracts, and `Notifications` listens to events rather than being called.
- **API envelope:** return API Resources (`{data}` / `{data,meta,links}`) or `Modules\Core\Http\Responses\ApiResponse`; errors are auto-formatted by `ApiExceptionRenderer`. All routes under `/api/v1`, with `throttle` as needed.
- **Multi-tenancy:** tenant-owned models use `Modules\Core\Domain\Concerns\BelongsToCompany` (global scope by `company_id` + auto-fill). Never expose the bigint PK — models use `HasUuid` (UUIDv7) as the route key.

## Laravel 12 bootstrapping (differs from older Laravel)

This uses Laravel 12's streamlined skeleton. Key differences from Laravel ≤10 that commonly trip up assumptions — these apply to the thin `app/` shell and `bootstrap/`, *not* the modules:

- **No `app/Http/Kernel.php` and no `app/Console/Kernel.php`.** All bootstrapping lives in `bootstrap/app.php` via `Application::configure(...)`. Register middleware in the `->withMiddleware()` closure, exception handling in `->withExceptions()`, and routing in `->withRouting()`.
- **No `app/Http/Middleware/` defaults and no `RouteServiceProvider`.** Route files are wired directly in `bootstrap/app.php`. A `/up` health check endpoint is registered there.
- **Service providers are listed in `bootstrap/providers.php`** (not `config/app.php`). `App\Providers\AppServiceProvider` is the single default provider — register bindings, macros, and boot logic there.
- **Console commands** in `routes/console.php` (closure-based Artisan commands), plus any classes in `app/Console/Commands/` are auto-registered.
- Config files are absent by default (Laravel 12 ships lean); run `php artisan config:publish <name>` to pull a specific config file into `config/` before overriding it, rather than assuming one exists.

Root `routes/api.php` wires shared/versioned route groups; each module also registers its own `Routes/api.php` via its service provider. Migrations, factories, and tests live **inside each module** (`modules/<Module>/Database/`, `.../Tests/`), not in the root `database/` or `tests/` dirs.

> Note: `docs/ARCHITECTURE.md` targets PostgreSQL + Redis for real environments, but per [ADR 0003](docs/adr/0003-database-engine-mysql.md) the project runs on **MySQL** (dev + prod); SQLite is only the in-memory test driver.

## Frontend

**This is an API-only repo — the user-facing website + dashboard live in the separate `evotech-web` (Next.js 16) repo.** The only Blade view here is the default `welcome.blade.php` at `/`. The Vite + Tailwind v4 toolchain is the untouched Laravel default (configured via the `@tailwindcss/vite` plugin in `vite.config.js`; no `tailwind.config.js`) — don't build a Blade UI here; add API endpoints in modules and consume them from `evotech-web`.
