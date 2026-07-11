# EVOTECH Platform — Software Architecture Blueprint (The Constitution)

> **Status:** Phase 0 — Foundational. **Version:** 1.0.0 · **Owner:** Chief Software Architect.
> This document is binding. Every future implementation phase MUST comply with it. Any deviation requires an ADR (Architecture Decision Record, see §18) and architect sign-off. When code and this document disagree, this document wins until formally amended.

---

## 0. How To Read This Document

Each significant choice is written as:

- **Decision** — the single option we commit to.
- **Why** — the reasoning, including what we rejected.
- **Rule** — the non-negotiable constraint implementers must follow.

We deliberately choose **boring, proven, native-Laravel** solutions over clever ones. Cleverness is a liability at the scale we are targeting (100+ products, 1000+ companies, 100k+ users, millions of API requests).

---

## 1. Vision & Non-Negotiable Principles

The EVOTECH Platform is the **central control plane** for the entire EVOTECH product ecosystem — not a website. The website is one delivery surface among many. Every product (Restaurant ERP/POS, mobile & driver apps, accounting, desktop apps, IoT/smart controllers, future SaaS) authenticates against, is licensed by, is billed through, and reports to this platform.

Because the platform is a dependency of everything else, it optimizes for **stability and evolvability over speed of initial delivery.**

**The Ten Commandments (ranked; when two conflict, the higher number wins):**

1. **Security first.** A breach here compromises every product. Security is never traded for convenience.
2. **Data integrity.** Money, licenses, and audit trails must never be silently wrong.
3. **Backward compatibility.** A published API contract or license format is a promise. We version, we don't break.
4. **Modularity & isolation.** Modules own their data and expose contracts. No reaching across boundaries.
5. **Explicitness over magic.** Readable, traceable code beats clever abstractions.
6. **Consistency.** One way to do each thing. Conventions are enforced by tooling, not goodwill.
7. **Testability.** If it can't be tested in isolation, the design is wrong.
8. **Performance by design.** N+1s, unbounded queries, and sync work in the request path are bugs.
9. **Operability.** If we can't observe it, we don't ship it.
10. **Developer experience.** A new engineer is productive in a day because structure is predictable.

---

## 2. Architecture Style

### 2.1 Overall: Modular Monolith (evolving toward selective microservices)

- **Decision:** Build a **Modular Monolith** — a single deployable Laravel application internally partitioned into strongly-isolated, domain-oriented modules with explicit contracts between them.
- **Why:**
  - A microservice-first architecture at Phase 0 would spend our entire budget on distributed-systems plumbing (network failures, eventual consistency, distributed tracing, saga orchestration) before delivering a single feature. That is premature.
  - A traditional "big ball of Laravel" (everything in `app/`) cannot survive 100+ product integrations; boundaries erode and every change becomes risky.
  - A Modular Monolith gives us **microservice-grade boundaries with monolith-grade simplicity**: one deploy, one database transaction scope, in-process calls — but with module seams already drawn. When a module (e.g. Licensing, or the API Gateway) genuinely needs independent scaling, it can be **extracted into a service with minimal refactor** because it already communicates only through contracts and events.
- **Rule:** Modules communicate **only** through (a) published service-interface contracts resolved from the container, or (b) domain events. **Direct use of another module's Eloquent models, migrations, or internal classes is forbidden.**

### 2.2 Internal layering (inside every module)

Each module follows a strict **4-layer** dependency flow. Dependencies point **downward only**; inner layers never know about outer ones.

```
┌──────────────────────────────────────────────────────────┐
│  Presentation   Controllers, API Resources, Requests,     │  HTTP / CLI edge
│                 Console commands, Routes                   │
├──────────────────────────────────────────────────────────┤
│  Application    Services (use-cases), DTOs, Jobs,          │  orchestration
│                 Event/Listener wiring, Policies            │
├──────────────────────────────────────────────────────────┤
│  Domain         Entities/Models, Value Objects, Enums,     │  business rules
│                 Domain Events, Repository *interfaces*     │
├──────────────────────────────────────────────────────────┤
│  Infrastructure Eloquent repository impls, external API    │  the outside world
│                 clients, mailers, gateways, cache adapters │
└──────────────────────────────────────────────────────────┘
```

- **Presentation** is thin: validate input → call one Application service → return a Resource. **No business logic in controllers.**
- **Application (Service layer)** holds use-cases. One public method = one business operation. Services are the transaction boundary.
- **Domain** is framework-light where practical and holds the rules.
- **Infrastructure** implements Domain interfaces.

### 2.3 The patterns, and exactly how far we take each

| Pattern | Decision | Why / Boundary |
|---|---|---|
| **Service Layer** | **Mandatory** for every write use-case and any non-trivial read. | Single home for business logic; keeps controllers, jobs, and commands DRY; the transaction boundary. |
| **Repository Pattern** | **Interface + Eloquent implementation for aggregate roots only.** | We get testability and a swap seam for the Domain, without the dogma of wrapping every model. Simple CRUD reads may use Eloquent directly *inside* a service. We do **not** build repositories to "hide Eloquent everywhere" — that is cargo-culting and we reject it. |
| **DTOs** | **Mandatory** at layer boundaries (request → service, service → response). Use `spatie/laravel-data`. | Typed, immutable data crossing boundaries prevents array-shape drift and doubles as validation + API resource. One library for DTO + validation + resource = less ceremony. |
| **API Resources** | **Mandatory** for every API response. Never return a raw model or array. | Decouples wire format from DB schema; lets the schema evolve without breaking clients. |
| **Dependency Injection** | **Mandatory**, constructor injection, program to interfaces. | Container-managed; enables testing and module extraction. No `new` on services, no facades inside Domain/Application logic (facades allowed only in Presentation/Infrastructure glue). |
| **Domain Events** | **Primary** cross-module communication channel. | Decouples producers from consumers; the seam along which a module later becomes a service. |
| **Value Objects & Enums** | Use native PHP 8.1+ **backed enums** for all fixed sets (statuses, types). VOs for money, license keys, etc. | Eliminates "magic strings"; the compiler and static analysis enforce correctness. |

### 2.4 Microservice readiness (without paying for it now)

We stay extraction-ready by construction: contract-only cross-module calls, event-driven side effects, no shared mutable state between modules, and no cross-module DB joins. **Rule:** if you find yourself wanting a JOIN across two modules' tables, that is a design smell — expose a contract or emit/consume an event instead.

---

## 3. Backend Stack

- **Language:** **PHP 8.4+** (typed properties, readonly, enums, `#[Override]`, property hooks, asymmetric visibility). *Current environment runs PHP 8.2.12 — upgrading the toolchain to 8.4 is a Phase-1 prerequisite and must be done before feature work.*
- **Framework:** **Laravel 12** (streamlined skeleton — see `CLAUDE.md` for the 12-specific structure notes).
- **API style:** **REST + JSON**, API-first (see §7). GraphQL rejected for v1 (adds a large surface and caching/rate-limit complexity we don't need; may be reconsidered as an additive gateway later).

**Core building blocks and the decision behind each:**

| Concern | Decision | Why |
|---|---|---|
| **Queues** | Redis-backed queues via **Laravel Horizon**. | All email, notifications, license generation, report building, webhook dispatch, and any >100ms side effect run async. Horizon gives metrics, retries, and back-pressure visibility for free. **Rule:** nothing slow runs in the HTTP request path. |
| **Caching** | **Redis** (`phpredis`), tagged caches per module. | Shared cache/session/queue store; supports tags for surgical invalidation. |
| **Scheduler** | Laravel Scheduler (`routes/console.php`) run by a single supervised worker / `schedule:work`. | Native, testable, no cron sprawl. Subscription renewals, license-expiry sweeps, report rollups live here. |
| **Storage** | `Storage` filesystem abstraction; **S3-compatible** object storage in every non-local env. | Download Center artifacts, invoices, uploads. Never store binaries on the app server. Private disks + signed URLs by default. |
| **Logging** | Monolog JSON to stdout in prod, shipped to a central store; channels per severity. | Structured logs are queryable; stdout is container-native (§14). |
| **Validation** | **Form Requests** at the edge + DTO-level validation via `spatie/laravel-data`. | Validation never lives in controllers or services' happy path. |
| **Localization** | Laravel localization from day one; all user-facing strings via translation keys; **English + Arabic (RTL)** baseline. | Retrofitting i18n is expensive; RTL affects the frontend design system too. |
| **Mail / Notifications** | `Notification` classes with mail + database + broadcast channels; queued. | One notification, many channels (email, in-app, push later). |
| **Events / Listeners** | Domain events; listeners queued unless they must be synchronous. | See §2.3. |
| **Authorization** | **Policies** + Gates, backed by a roles/permissions module (§4, `spatie/laravel-permission`). | Every authorization check goes through a Policy; controllers call `$this->authorize()` / middleware. |
| **API versioning** | URI-based `/api/v1/...` (see §7). | Explicit, cache-friendly, unambiguous for third-party product teams. |
| **Rate limiting** | Named limiters per audience (§6.13). | Protects the platform as a shared dependency. |
| **Static analysis** | **PHPStan/Larastan level max** + **Laravel Pint** (formatting) + **Rector** (safe upgrades). CI-enforced. | Bugs caught before review; one formatting standard, zero debate. |

**Key packages (approved list; additions require an ADR):** `laravel/horizon`, `laravel/sanctum`, `laravel/passport`, `laravel/telescope` (non-prod), `laravel/pulse`, `spatie/laravel-data`, `spatie/laravel-permission`, `spatie/laravel-query-builder` (filtering/sorting), `spatie/laravel-activitylog` (audit), `larastan/larastan`, `laravel/pint`, `rectorx/rector`, `pestphp/pest` (tests).

---

## 4. Modular Design

- **Decision:** Modules live under a top-level **`modules/`** directory (outside `app/`), each an isolated, PSR-4-namespaced Laravel package autoloaded via a composer path/merge setup. Namespace: `Modules\{Module}\...`.
- **Why not** `nwidart/laravel-modules` or Composer path repositories? We keep the mechanism **native and dependency-light** — a `modules/` folder with explicit PSR-4 entries and one Service Provider per module gives full isolation without a third-party module framework we'd have to track across Laravel upgrades. (This is an ADR-able choice; if team scale demands it, `nwidart` is the pre-approved fallback.)

**Canonical module list (each isolated, each with its own routes/services/models/tests):**

| Module | Responsibility |
|---|---|
| `Core` | Shared kernel: base classes, base DTOs, common VOs, exceptions, traits. The *only* module others may depend on directly. |
| `Auth` | Authentication, tokens, OAuth clients, MFA, sessions. |
| `AccessControl` | Roles, Permissions, Policies wiring. |
| `Users` | Platform users, profiles, preferences. |
| `Companies` | Companies (the **tenant** entity), membership, org settings. |
| `Customers` | Customer records within companies. |
| `Products` | Product catalog, versions, editions, feature flags per product. |
| `Subscriptions` | Plans, subscription lifecycle, renewals, entitlements. |
| `Licenses` | License issuance, validation, activation, revocation, offline signing. |
| `Downloads` | Download Center: artifacts, release channels, signed delivery. |
| `Payments` | Billing-ready: invoices, payment intents, gateway adapters (Stripe-ready). |
| `Notifications` | Cross-channel notification dispatch and templates. |
| `Reports` | Reporting, aggregations, exports. |
| `Audit` | Immutable audit logs for all sensitive actions. |
| `Gateway` | The API Gateway edge: versioning, product-to-platform auth, request shaping. |
| `Settings` | Platform + per-company configuration. |
| `Website` | Public marketing/website + product catalog presentation (server-rendered SEO surface). |

**Module internal structure (identical for every module — predictability is the point):**

> **Amended by [ADR 0002](adr/0002-module-layout-and-autoloading.md):** the `src/` wrapper is dropped and the layers live directly under the module root, enabling a single `Modules\ → modules/` PSR-4 mapping (zero-config new modules) and provider auto-discovery. The 4-layer separation and namespace root `Modules\<Module>\` are unchanged. Canonical layout:

```
modules/<Module>/
├── Providers/          # <Module>ServiceProvider (extends Core BaseModuleServiceProvider)
├── Http/               # Controllers, Requests, Resources, Middleware (API versioned under Controllers/Api/V1)
├── Application/        # Services (use-cases), DTOs, Jobs, Listeners
├── Domain/             # Models, Enums, ValueObjects, Events, Contracts (interfaces)
├── Infrastructure/     # Repository implementations, external clients
├── Console/            # Artisan commands
├── Database/           # Migrations, Factories, Seeders
├── Routes/             # api.php, web.php, console.php
├── Lang/               # translations
└── Tests/              # Feature, Unit
```

**Rule:** A module exposes its capabilities via `Contracts` (interfaces) registered in its Service Provider. Consumers type-hint the contract, never the implementation.

---

## 5. Database

- **Engine:** **MySQL 8** (local dev / prod on MariaDB-compatible) — *amended by [ADR 0003](adr/0003-database-engine-mysql.md), which supersedes the original PostgreSQL 16 choice.*
  - **Why the change:** the Contabo VPS and local XAMPP already run MySQL, and the team is fluent in it — one engine across the whole stack beats a theoretically-nicer second one. MySQL 8 (JSON type, functional/composite indexes, CHECK constraints) is sufficient for our workload; Postgres's JSONB/partitioning edge is not on the critical path. See the ADR for trade-offs (esp. local MariaDB 10.4 vs prod MySQL 8 — mitigated by CI running against the production engine).
  - *(Original rationale, retained for context:) PostgreSQL was first chosen for native `JSONB`, transactional DDL, MVCC concurrency, partial/expression indexes and partitioning.*
- **Naming convention (enforced):**
  - Tables: `snake_case`, **plural** (`companies`, `license_activations`).
  - Pivot tables: singular models alphabetical (`company_user`).
  - Columns: `snake_case`; booleans `is_`/`has_` prefixed; timestamps `_at` suffixed; foreign keys `{singular}_id`.
  - Primary key: `id`. Public identifier: `uuid`. No abbreviations, no reserved words.
- **Identifiers — hybrid strategy (Decision):**
  - **Internal PK:** `BIGINT` auto-increment `id` — index locality and join performance at millions of rows.
  - **Public/API identifier:** a `uuid` column holding a **UUIDv7** (time-ordered → index-friendly, unlike v4), `unique`, and set as the **route key** (`getRouteKeyName`). External systems and URLs **never** see the numeric PK.
  - **Why not UUID-as-PK:** random-UUID PKs fragment B-tree indexes and bloat every FK; the hybrid keeps public opacity without the write-amplification cost.
- **Foreign keys:** Always declared at the DB level with explicit `ON DELETE` behavior (usually `RESTRICT`; `CASCADE` only for true ownership). Every FK column is indexed.
- **Indexes:** Index every FK, every column used in `WHERE`/`ORDER BY`/`JOIN`, and add composite indexes matching real query shapes (esp. `(company_id, ...)` for tenant queries). **Rule:** no query ships without an index plan; verify with `EXPLAIN` for hot paths.
- **Soft deletes:** `deleted_at` on business entities where history matters (companies, subscriptions, licenses, users). **Not** on high-volume append-only tables (audit, events) — those are immutable, never deleted.
- **Audit tables:** Sensitive mutations recorded via `spatie/laravel-activitylog` into an append-only `activity_log`; financial and license events additionally get **domain-specific immutable ledgers** (`license_events`, `payment_events`). Ledgers are never updated or deleted.
- **Migration rules:** Migrations are **forward-only and immutable once merged to `main`.** Never edit a shipped migration — add a new one. Every migration is reversible (`down()`), one logical change per file, no raw data backfills mixed with schema (backfills go in idempotent jobs/commands).
- **Seeders:** Split into **`ReferenceSeeder`** (roles, permissions, plans, product catalog — safe & idempotent for every environment incl. production) and **`DemoSeeder`** (fake data for local/staging only).
- **Factories:** Every model has a factory. Factories are the single source of test data; tests never hand-build models.

### 5.1 Multi-tenancy — readiness now, single-DB shared schema chosen

- **Decision:** **Single database, shared schema, `company_id`-scoped rows.** `Company` is the tenant. Tenant-owned tables carry a non-null `company_id` FK and a **global scope** that auto-filters by the current company context; platform-global tables (products, plans) do not.
- **Why (over database-per-tenant / schema-per-tenant):** 1000+ companies × 100+ products makes per-tenant databases an operational nightmare (migrations × 1000, connection sprawl, backup fan-out). Shared-schema with a mandatory tenant scope + strong composite indexes serves 100k+ users comfortably and keeps operations sane. Isolation is enforced in the application layer (global scope) and defended in depth by Policies.
- **Readiness:** We isolate tenant resolution behind a `TenantContext` contract now, so a future move to `stancl/tenancy` (pre-approved) or sharding by `company_id` is a swap, not a rewrite. **Rule:** every query touching tenant data must be tenant-scoped; writing a query that bypasses the global scope requires an explicit, reviewed justification.

---

## 6. Security Standards

Security is Commandment #1. These are minimums, not aspirations.

1. **Authentication (Decision — layered by audience):**
   - **First-party web (Customer Portal, Admin, Website SPA):** **Laravel Sanctum** (SPA cookie/session + token abilities).
   - **First-party native apps (mobile, driver, desktop):** Sanctum **personal access tokens** with abilities scoped per device.
   - **Product-to-platform / machine-to-machine (ERP, POS, IoT gateways, other backends):** **per-product hashed API keys** — each product holds a revocable, expirable API key; the `Gateway` module owns the credential and the `product` request guard. *Amended by [ADR 0004](adr/0004-product-to-platform-auth-api-keys.md), which supersedes the original OAuth2-Client-Credentials-via-Passport choice for this audience; Passport remains the pre-approved fallback if standardized OAuth scopes are later required.* This is the API Gateway's auth.
   - **Offline / IoT devices:** cryptographically **signed license tokens** (asymmetric; platform signs, device verifies offline) so a controller can validate entitlement without connectivity.
   - **Why this split:** browsers need cookie/CSRF-based sessions; backends and devices need bearer/OAuth. One-size auth would either be insecure for the web or clumsy for machines.
2. **MFA:** TOTP available for all users; **mandatory** for admin/staff roles.
3. **Authorization:** RBAC via `spatie/laravel-permission`; enforced through **Policies** on every resource. Default-deny. Tenant boundary checked in every policy.
4. **Password policy:** Argon2id hashing; min 12 chars; breach-check against known-compromised lists; no forced periodic rotation (NIST guidance); lockout + step-up after repeated failures.
5. **Session handling:** Secure, `HttpOnly`, `SameSite=Lax` (Strict for admin) cookies; server-side session store in Redis; absolute + idle timeouts; session invalidation on password change and logout-all.
6. **CSRF:** Laravel CSRF tokens for all cookie-authenticated (web/SPA) routes. Token-authenticated API routes are exempt by design (no ambient cookie).
7. **XSS:** Blade auto-escaping; frontend never uses `dangerouslySetInnerHTML` without sanitization; strict **Content-Security-Policy** header; all output encoded.
8. **SQL injection:** Eloquent/query-builder bindings only. **Raw SQL with unbound input is forbidden.** Any `DB::raw` requires review and bound parameters.
9. **API security:** TLS everywhere (HSTS); scoped tokens; per-client rate limits; request size limits; strict input validation; no verbose errors in prod; security headers (CSP, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`) via middleware.
10. **Secrets management:** **Never** in the repo. `.env` for local only; production secrets from a secrets manager (Vault / cloud secret store) injected as env vars. `.env.example` documents keys with dummy values. Rotate on exposure. `APP_KEY` and OAuth/signing keys are managed secrets.
11. **Environment variables:** All config via `config/*.php` reading `env()` — **`env()` is never called outside config files** (breaks with config caching). 
12. **File uploads:** Validate MIME **by content**, not extension; enforce size limits; store on private S3 disk with randomized keys; never execute or serve from a public web path; virus-scan pipeline for user-supplied binaries in the Download Center.
13. **Brute-force & rate limiting:** Login/OTP endpoints throttled per-IP **and** per-account with exponential backoff; global named limiters — `web` (per session), `api` (per token), `auth` (strict), `product` (per OAuth client). Failed-auth events audited and alertable.
14. **Audit:** Every security-relevant action (login, permission change, license issue/revoke, payment, role grant) is written to the immutable audit log with actor, IP, and context.
15. **Dependency security:** `composer audit` + `npm audit` in CI; Dependabot/renovate; no unmaintained packages.

---

## 7. API Philosophy (API-First)

The API is the product. The website and every EVOTECH app are just clients.

- **Versioning:** URI prefix **`/api/v1/`**. Breaking changes → `/api/v2/`, with the previous version supported through a published deprecation window. Never break a shipped version.
- **Response envelope (consistent for every endpoint):**
  ```json
  { "data": { }, "meta": { }, "links": { } }
  ```
  Collections put pagination in `meta`/`links`. Single resources use `data`. Empty success uses `204`.
- **Error envelope (RFC-9457 Problem-Details-aligned, consistent):**
  ```json
  {
    "error": {
      "code": "SUBSCRIPTION_EXPIRED",
      "message": "Human-readable, localized summary.",
      "details": [{ "field": "plan_id", "issue": "required" }],
      "trace_id": "..."
    }
  }
  ```
  Machine-readable `code` (a stable enum) drives client logic; `message` is for humans; `trace_id` ties to logs. HTTP status codes are used correctly (`422` validation, `401`/`403` authz, `409` conflict, `429` rate limit).
- **Pagination:** Cursor pagination for large/hot collections (stable under writes); page-based allowed for small admin lists. Always bounded — a default and max `per_page`; **no unbounded list endpoints.**
- **Filtering & sorting:** Standardized via `spatie/laravel-query-builder` — `?filter[status]=active&sort=-created_at`. Only whitelisted fields are filterable/sortable.
- **Validation:** Form Requests + DTO validation; `422` with the error envelope's `details` array.
- **Authentication:** Per §6.1; every endpoint declares its guard and required scopes/abilities.
- **Rate limits:** Per §6.13; limits returned in `X-RateLimit-*` headers; `429` with `Retry-After`.
- **Idempotency:** Mutating product-to-platform endpoints accept an `Idempotency-Key` header (critical for licensing/payments over flaky device networks).
- **Documentation strategy:** **OpenAPI 3.1 is the contract.** Generated/annotated from code, published to an interactive portal (Scalar/Redoc), and **CI fails if the implementation drifts from the spec.** The spec is versioned alongside the code.

---

## 8. Frontend Architecture

- **Decision:** **Next.js 15 (App Router) + TypeScript (strict)** as a **decoupled client** of the REST API, for the Customer Portal and Admin. The public **Website/marketing + SEO catalog** is server-rendered (RSC/SSG) for crawlability and speed.
- **Why decoupled Next.js over Blade/Livewire/Inertia:** the platform serves *many* clients; a first-class API + a standalone JS app proves the API contract is complete and lets web, mobile, and desktop teams share it. RSC gives us SEO where we need it (§ marketing) and app-like interactivity where we need it (portal).
- **Styling & design system:** **Tailwind CSS v4** + a **shadcn/ui**-based component library (owned in-repo, themeable via CSS variables). One design system, tokens for color/spacing/typography, **first-class dark mode** (`class` strategy) and **RTL** support baked into tokens.
- **State:** **TanStack Query** for all server state (caching, retries, invalidation); local UI state stays local; no global store unless a real cross-cutting need appears (then Zustand).
- **Component-driven:** Presentational vs. container separation; every reusable component documented in **Storybook**; accessibility (WCAG 2.2 AA) is a build gate, not a nicety — semantic HTML, keyboard nav, focus management, ARIA where needed.
- **Folder organization (feature-first):**
  ```
  src/
  ├── app/                 # App Router: route segments, layouts, loading/error UI
  │   ├── (marketing)/     # SEO/SSG public site
  │   ├── (portal)/        # authenticated customer portal
  │   └── (admin)/         # staff/admin
  ├── features/<feature>/  # feature-scoped: components, hooks, api, types, schemas
  ├── components/ui/       # design-system primitives (shadcn-based)
  ├── lib/                 # api client, auth, query client, utils
  ├── hooks/               # shared hooks
  ├── styles/              # tokens, tailwind layers
  └── types/               # shared/generated types (from OpenAPI)
  ```
  **Rule:** API types are **generated from the OpenAPI spec** — the frontend never hand-writes request/response types, guaranteeing client/server contract alignment.
- **Repository:** the Next.js app lives in a **separate repository** (`evotech-web`) consuming the versioned API. This backend repo (`evotech-core`) owns the API + marketing SSR only if we later co-locate; default is separation.

---

## 9. UI/UX Principles

Modern, professional, minimal, fast, consistent, accessible, responsive, dark-mode-native. Concretely enforced as:

- **Design tokens** are the single source of truth (color, type scale, spacing, radius, shadow, motion). No hard-coded colors/sizes in components.
- **One component library**, reused everywhere; a new one-off UI pattern requires justification.
- **Performance budgets:** LCP < 2.5s, INP < 200ms, CLS < 0.1 on mid-tier mobile; images optimized and lazy; route-level code splitting.
- **Accessibility (WCAG 2.2 AA)** is CI-gated (axe). Contrast, focus, keyboard, and screen-reader paths are part of "done."
- **Responsive** mobile-first; **RTL** parity for Arabic; dark/light parity for every component.
- **Consistency:** shared empty/error/loading/skeleton states; one toast/notification system; predictable form patterns.

---

## 10. Coding Standards

- **Standard:** PSR-12, enforced by **Laravel Pint** (CI-gated, zero manual formatting debate). PHPStan/Larastan at **max** level.
- **Strictness:** `declare(strict_types=1);` in every PHP file. Full type declarations on all params, returns, and properties. `readonly` for immutable data (DTOs, VOs).
- **Naming:**
  | Kind | Convention | Example |
  |---|---|---|
  | Class | `PascalCase`, intention-revealing | `IssueLicenseService` |
  | Interface | `PascalCase`, **no** `I` prefix; suffix `Contract`/`Repository` | `LicenseRepository` |
  | Trait | `PascalCase`, adjective/capability | `HasUuid` |
  | Enum | `PascalCase` singular; cases `PascalCase` | `LicenseStatus::Active` |
  | Method | `camelCase`, verb phrase | `activateForDevice()` |
  | Variable/property | `camelCase` | `$expiresAt` |
  | Constant | `UPPER_SNAKE_CASE` | `MAX_ACTIVATIONS` |
  | Route name | `dot.case`, resourceful | `api.v1.licenses.activate` |
  | Config key | `snake_case` | `licenses.default_ttl` |
- **Class-type rules:**
  - **Controllers:** thin, single-responsibility; ideally single-action (`__invoke`) for non-CRUD; no business logic; return Resources.
  - **Services:** one public method per use-case; own the DB transaction; throw domain exceptions.
  - **Repositories:** interface in `Domain/Contracts`, Eloquent impl in `Infrastructure`; return domain models/collections, never query builders.
  - **DTOs:** immutable (`readonly`), typed, `spatie/laravel-data`.
  - **Enums:** backed enums for every fixed set; no magic strings/ints anywhere.
  - **Traits:** for genuine cross-cutting reuse only (e.g. `HasUuid`), not to dodge composition.
  - **Requests/Resources:** every write has a Form Request; every response has a Resource.
- **Validation:** at the edge (Form Request/DTO) — never inside services' core logic (services assume valid input and enforce *business* invariants only).
- **Comments:** Explain **why**, not **what**. Code should be self-documenting; comments justify non-obvious decisions. Public methods on contracts get docblocks (for IDE + generated docs). No commented-out code in `main`.
- **Errors:** typed domain exceptions per module (`Modules\Licenses\Domain\Exceptions\LicenseExpiredException`), mapped to the API error envelope by a central handler. No catching `\Exception` broadly; no silent failures.

---

## 11. Git Strategy

- **Branching:** **Trunk-based with short-lived feature branches.** `main` is always releasable and protected (no direct pushes). Branch names: `feat/…`, `fix/…`, `chore/…`, `refactor/…`, `docs/…`.
  - **Why not GitFlow:** long-lived `develop`/`release` branches breed painful merges and slow feedback; trunk-based + CI + feature flags keeps integration continuous.
- **Commits:** **Conventional Commits** (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `build:`, `ci:`). Scope by module: `feat(licenses): …`. Enforced by a commit-lint hook.
- **Pull Requests:** required for every change; must pass CI (Pint, PHPStan, tests, OpenAPI drift, audits); at least one review; small and focused; PR template with context + testing notes + linked issue. Squash-merge to keep `main` history clean and one-commit-per-change.
- **Releases:** **Semantic Versioning** `MAJOR.MINOR.PATCH`. Tags drive deploys. Automated **changelog** from Conventional Commits.
- **API versioning is independent of app versioning** — `/api/v1` may live across many app releases.

---

## 12. Testing Strategy

- **Framework:** **Pest** (concise, first-class Laravel support) over vanilla PHPUnit.
- **Test pyramid & where each fits:**
  | Level | Scope | Rule |
  |---|---|---|
  | **Unit** | Domain logic, VOs, services with mocked repos. Fast, no DB. | Every service use-case has unit coverage of its business rules. |
  | **Feature/Integration** | HTTP → service → real DB (Postgres in CI, migrations run), events, jobs. | Every endpoint has a feature test for success + auth + validation + tenant-isolation paths. |
  | **API contract** | Response shape vs. OpenAPI spec. | CI fails on drift. |
  | **E2E** | Next.js against a running API, via **Playwright**, critical journeys (signup, subscribe, issue license, download). | Runs on staging pre-release. |
- **Tenant isolation tests are mandatory** for any tenant-scoped resource (prove company A cannot read/write company B).
- **Coverage goals:** **≥ 85% overall**, **100% on Domain/Application layers of `Licenses`, `Subscriptions`, `Payments`, `Auth`** (the modules where "silently wrong" is unacceptable). Coverage is a signal, not the goal — critical-path assertions matter more than the number.
- **Data:** factories + `RefreshDatabase`; no shared mutable fixtures; tests are deterministic and parallel-safe.
- **CI gate:** no merge if any test, static-analysis, or contract check fails.

---

## 13. Deployment Strategy

- **Packaging:** **Docker** — multi-stage images (PHP-FPM + Nginx, or FrankenPHP for HTTP/2/3 and workers). Separate images/processes for: web, queue workers (Horizon), scheduler. Identical image across environments; only config differs.
- **Environments:** `local` → `staging` → `production`, strictly separated, never sharing data or secrets. Config parity enforced; prod is not "staging with more RAM" only in scale.
- **CI/CD:** GitHub Actions (or GitLab CI). Pipeline: install → Pint → PHPStan → tests (Postgres+Redis services) → OpenAPI drift → security audits → build image → deploy. **Zero-downtime deploys** (health-gated rolling), migrations run as a controlled step, `config:cache`/`route:cache`/`event:cache` on boot.
- **Backups:** automated, **encrypted**, tested-restore Postgres backups (PITR/WAL), retained per policy; object storage versioned; **a backup that has never been restored does not count.**
- **Monitoring & observability:** **Laravel Pulse** (app health), APM + error tracking (Sentry), uptime checks on the `/up` health endpoint (already wired in `bootstrap/app.php`), queue depth & failed-job alerts via Horizon, DB slow-query monitoring.
- **Logging:** structured JSON to stdout → central log store; correlation via `trace_id` propagated from the API envelope.
- **Health checks:** `/up` (liveness) + a deeper `/health` (DB, Redis, queue, storage reachability) for orchestrator readiness probes.
- **Runtime processes:** never run queues/scheduler inside the web container in prod; each is its own supervised deployment.

---

## 14. Performance Strategy

- **Caching:** Redis, tagged per module; cache expensive reads (catalog, entitlements, permissions) with explicit invalidation on write. Config/route/event caches in prod. HTTP caching (`ETag`/`Cache-Control`) on cacheable GETs.
- **Queues:** everything slow is async (email, notifications, license/report generation, webhooks) — see §3.
- **Eager vs lazy loading:** **Eager-load by design** to kill N+1; `Model::preventLazyLoading()` **enabled in non-production** so N+1s fail the test suite, not production. Lazy loading is a deliberate exception, never the default.
- **Indexes:** per §5; hot queries reviewed with `EXPLAIN`; composite indexes match tenant query shapes.
- **Redis:** cache + sessions + queues + rate-limit counters + locks.
- **Read scaling:** app configured for a **read-replica** connection from day one (writes → primary, heavy reads → replica) even if a single node initially — no rewrite to add replicas later.
- **Pagination everywhere:** no unbounded queries; cursor pagination on hot lists.
- **CDN-ready:** all static assets and Download Center artifacts served via CDN with signed URLs; cache-busting via content hashes.
- **Image optimization:** responsive sizes, modern formats (WebP/AVIF), lazy loading, offloaded transformation.
- **Budgets:** p95 API latency target < 200ms for reads / < 500ms for writes (excluding intentionally async work); regressions caught in load tests before release for critical endpoints.

---

## 15. Documentation Structure

Docs live in **`/docs`**, versioned with the code, reviewed like code:

```
docs/
├── ARCHITECTURE.md        # THIS document — the constitution
├── adr/                   # Architecture Decision Records (0001-*.md ...)
├── api/                   # OpenAPI spec + generated reference + auth/versioning guides
├── modules/<module>.md    # per-module: responsibility, contracts, events, data model
├── deployment/            # environments, CI/CD, runbooks, backup/restore, incident response
├── developer-guide/       # setup, local dev, conventions, how-to-add-a-module
└── coding-standards.md    # the enforced standards (Pint/PHPStan config rationale)
```

- **Every module** ships a `docs/modules/<module>.md` describing its public contracts, emitted/consumed events, and data ownership — this is how other teams integrate without reading its internals.
- **ADRs** record every significant decision (context, options, decision, consequences). This document's choices are the seed ADRs.
- **CLAUDE.md** at the repo root stays the fast-orientation file and points here.

---

## 16. What "Compliant" Means (checklist for every future PR)

A change is compliant only if:

- [ ] It lives in the correct module and respects layer boundaries (no cross-module model use, no business logic in controllers).
- [ ] Writes go through a Service; the service owns the transaction.
- [ ] Cross-module effects use contracts or events, not direct calls.
- [ ] Every response is an API Resource in the standard envelope; errors use the standard error envelope.
- [ ] Tenant-scoped data is tenant-scoped and tested for isolation.
- [ ] Input is validated at the edge; enums replace magic strings.
- [ ] Tests (unit + feature) added; critical modules meet coverage bar; N+1 impossible (lazy-load prevention passes).
- [ ] Pint + PHPStan-max + OpenAPI-drift + security audits pass.
- [ ] Migrations are new (never edited), reversible, indexed, FK-constrained.
- [ ] Secrets are not in code; `env()` only in config; no `DB::raw` with unbound input.
- [ ] Docs/ADR updated when behavior or a decision changes.

---

## 17. Explicitly Rejected (so we don't relitigate)

- **Microservices-first** — premature; we earn them via extraction.
- **GraphQL for v1** — surface/caching/rate-limit cost without current payoff.
- **Repository-wrapping of every model** — dogmatic; we use repositories for aggregate roots only.
- **UUIDv4 as primary key** — index fragmentation at our scale.
- **Database-per-tenant now** — operational cost unjustified at 1000+ companies; shared-schema chosen, extraction-ready.
- **Blade/Livewire as the primary app UI** — undermines the API-first, multi-client mandate (Blade retained only for SSR marketing/emails).
- **`env()` outside config**, **raw unbound SQL**, **business logic in controllers**, **unbounded list endpoints** — banned outright.

---

## 18. Amendment Process

This constitution changes only via an **ADR** in `docs/adr/` (context → options → decision → consequences), reviewed and approved by the architect, then this document is updated and its version bumped. No silent drift: if the code needs to violate a rule, change the rule first — deliberately, on the record.

---

*End of Constitution v1.0.0. Phase 1 (toolchain to PHP 8.4, module scaffolding conventions, and the `Core` + `Auth` + `Companies` modules) may begin only against these rules.*
