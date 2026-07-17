# EVOTECH Platform — Roadmap

> The project's official roadmap. Read alongside the binding constitution [`ARCHITECTURE.md`](./ARCHITECTURE.md).
> **Last updated:** 2026-07-11 · **Status:** Phases 0–5 complete & verified; Phase 6 in progress — the **Download Center** is done & verified. *(Phase 5's live Stripe adapter is scaffolded per [ADR 0009](adr/0009-stripe-live-gateway.md) — enabling it in production needs credentials + a webhook-security review.)*

---

## Vision

`evotech-core` is the **central platform** for EVOTECH — not just a marketing site. It:
1. **Showcases** the company, services, products and prices (professional website).
2. **Manages** subscriptions, customers and licenses via an authenticated dashboard.
3. **Connects** every EVOTECH product (Smart Delegate, Invoices, Ledger, Restaurant suite, Pharma Warehouse, and future products) through one central API.

---

## Architecture

Decoupled, on a **single domain** via free subdomains:

| Part | Address | Tech | Repo | Hosting |
|------|---------|------|------|---------|
| Website | `evotech.<domain>` | Next.js 16 | `evotech-web` | Contabo VPS (PM2 + Nginx) |
| API | `api.evotech.<domain>` | Laravel 12 | `evotech-core` | Contabo VPS |
| Dashboard | `app.evotech.<domain>` | Next.js 16 | `evotech-web` (route group) | Contabo VPS |

**Deployment:** entirely on the company's Contabo VPS (4 vCPU / 8 GB / 150 GB SSD) — no extra cloud cost, isolated from the live restaurant system on the same server.

---

## Phases

### ✅ Phase 0 — Constitution
Complete. See [`ARCHITECTURE.md`](./ARCHITECTURE.md) + ADRs in [`adr/`](./adr/).

### ✅ Phase 1 — Marketing website (built & verified)
Professional bilingual (ar/en, RTL/LTR) site, full dark/light, violet/white brand.
- **Stack:** Next.js 16 (App Router) + TypeScript strict + Tailwind CSS v4 + shadcn/ui + next-intl + a custom (dependency-free) theme provider + `motion`.
- **Pages:** home, services, products (+ per-product), pricing, about, contact.
- **Content:** professional placeholder in `src/content` — designed to switch to the Products API in Phase 3 without a rewrite.
- **Verified:** SSG build for both locales, ESLint clean, runtime RTL/LTR + dark/light confirmed.
- **⏳ Remaining deliverable:** deploy to the Contabo VPS (deferred by choice; can run anytime — needs VPS + domain access).

### ✅ Phase 2 — Backend foundation + Auth (done & verified)
Modular monolith (`modules/` auto-discovery, [ADR 0002](adr/0002-module-layout-and-autoloading.md)), MySQL ([ADR 0003](adr/0003-database-engine-mysql.md)), standard API success/error envelopes, `Core` shared kernel (tenancy + `HasUuid` UUIDv7), `Users` + `Auth` (Sanctum: register/login/logout/me, password policy, per-account/IP throttling), `Companies` (tenant) + `Customers` (tenant-scoped, global-scope isolation). Quality gates: **Pint** + **Larastan level max** + **22 tests** + **OpenAPI 3.1** (Scramble, drift-checked) + **GitHub Actions CI** (PHP 8.4, SQLite + MySQL). *Local runtime is still PHP 8.2; CI validates 8.4.*

### ✅ Phase 3 — Subscriptions dashboard (done & verified)
A working, authenticated dashboard to manage subscriptions end-to-end.
- **API — `Products`** module: catalog (products + plans + bilingual pricing), public read endpoints, reference seeder. ✅
- **API — `Subscriptions`** module: full lifecycle (create/renew/cancel/expire), subscriber = Company, plan snapshot, domain/device identifier, daily expiry schedule. ✅
- **Dashboard** (`evotech-web`, `/dashboard`): token-based login, protected shell (sidebar + topbar), and real screens — subscriptions (list/create/renew/cancel/delete), clients, products — via TanStack Query. ✅
- **Website wired to the Products API** (single source): home/products/detail/pricing fetch live from the API with a local fallback; live fetch verified. ✅
- Quality: 34 backend tests, Larastan max, Pint, OpenAPI drift-checked; frontend build + lint clean. Decisions in [ADR-less] memory: subscriber = Company/Client, token-based dashboard auth.

### ✅ Phase 4 — Product integration + Licensing (done & verified)
Connect the real EVOTECH products to the platform: license issue/activate/revoke, per-product API keys, online validation + signed offline tokens for IoT/devices.
- **API — `Licenses`** module: the credential proving a company's product entitlement, derived from a subscription. ✅
  - Lifecycle: auto-issue/renew on subscription activation, manual issue, suspend/reactivate/revoke, daily expiry sweep, immutable event ledger, admin CRUD. ✅
  - **Device/domain activations**: up to `max_activations` slots per license — idempotent per identifier, limit-enforced, deactivate frees a slot; admin-managed endpoints. ✅
  - Docs: [`docs/modules/licenses.md`](modules/licenses.md).
- **API — `Gateway`** module: the product-to-platform edge. **Per-product API keys** (hashed, revocable, expirable) + a `product` request guard, decided in [ADR 0004](adr/0004-product-to-platform-auth-api-keys.md) (supersedes §6.1's Passport-OAuth2 choice for M2M). Staff mint/revoke keys; the plaintext is shown once. Docs: [`docs/modules/gateway.md`](modules/gateway.md). ✅
  - **Product-facing self-activation + online validation**: products call `/api/v1/product/licenses/{activate,validate,deactivate}` with their API key, scoped to their own licenses (cross-product access is `404`); validation heartbeats the device. ✅
  - **Signed offline tokens** ([ADR 0005](adr/0005-signed-offline-license-tokens.md)): `POST /api/v1/product/licenses/token` issues an **EdDSA (Ed25519) JWS** (native ext-sodium, no new dependency) that a device verifies **offline** with the platform's public key (`GET /api/v1/product/keys`); TTL clamped to the license expiry, issuance audited. `php artisan licenses:keygen` generates the signing keypair. ✅
  - Quality: **78 backend tests** (incl. offline signature-verification + tamper-rejection), Larastan max, Pint, OpenAPI regenerated.

### ✅ Phase 5 — Billing + Notifications + Reports (done & verified)
`Payments` (Stripe-ready), `Notifications` (multi-channel), `Audit`, `Reports`.
- **API — `Payments`** module ([ADR 0006](adr/0006-billing-invoices-and-payment-gateway.md)): invoices derived from subscription periods, an immutable `payment_events` ledger, and a `PaymentGateway` adapter. ✅
  - **Auto-issue on activation**: activating/renewing a subscription bills that period once (idempotent per period); free periods raise no invoice. Staff can also issue manually. ✅
  - **Collection**: `POST /api/v1/invoices/{invoice}/payments` settles an open invoice through the **manual/offline** gateway (bank transfer/cash); `void` cancels an unpaid one; every transition is audited; `InvoicePaid` emitted. ✅
  - Docs: [`docs/modules/payments.md`](modules/payments.md). **89 backend tests**, Larastan max, Pint, OpenAPI regenerated.
- **API — `Notifications`** module (constitution §3): cross-channel, queued dispatch that **reacts to domain events** and notifies recipients over `database` (dashboard bell) + `mail`. First flow: `InvoicePaid` → the billed company's users. Per-user in-app API (list / unread-count / mark-read / mark-all). Docs: [`docs/modules/notifications.md`](modules/notifications.md). ✅
  - **97 backend tests**, Larastan max, Pint, OpenAPI regenerated.
- **API — `Audit`** module ([ADR 0007](adr/0007-native-audit-log.md)): a native immutable `audit_logs` trail behind an **`AuditLogger` port in `Core`** (safe no-op default; Audit provides the persisting adapter) so any module records actions without depending on Audit. Captures `auth.*` (explicit) + `invoice.paid` / `subscription.activated` (event listeners); staff read-only, filterable API. Docs: [`docs/modules/audit.md`](modules/audit.md). ✅
- **API — `Reports`** module: read-only KPI aggregations (`GET /api/v1/reports/overview`) — companies/subscriptions/licenses counts, activations in use, and per-currency collected/outstanding billing — composed from **per-module stats contracts** (each module owns queries over its own data; Reports touches no other model, §2.4). Docs: [`docs/modules/reports.md`](modules/reports.md). ✅
  - **107 backend tests**, Larastan max, Pint, OpenAPI regenerated.
- **Live Stripe adapter — scaffolded ([ADR 0009](adr/0009-stripe-live-gateway.md)), pending credentials.** A config-selected (`PAYMENTS_GATEWAY=stripe`) adapter behind the same `PaymentGateway` contract: async PaymentIntent + webhook settlement, **SDK-less** (Stripe REST over Laravel HTTP — no new dependency), **native HMAC webhook verification** (constant-time + replay tolerance), amount-integrity guard, and idempotent settlement. Verified via faked HTTP + signed test payloads (12 tests). **Remaining to go live:** real keys + production webhook-security review; failure/refund/dispute events; zero-decimal currencies.
- **Deferred (additive):**
  - **More audit/notification triggers** (license issue/revoke, key mint/revoke, role changes; license-expiring notice) — additive listeners/port calls; plus a `broadcast` channel.

### 🚧 Phase 6 — Expansion (in progress)
Download Center, IoT / smart controllers, future SaaS products — per the constitution's module list.

- **API — `Downloads`** module ([ADR 0008](adr/0008-download-center-delivery.md)): the **Download Center** — where products publish versioned artifacts and **self-update**. ✅
  - **Releases & artifacts**: a versioned release of a product on a channel (`stable`/`beta`/`alpha`) groups one artifact per platform; staff CRUD + publish/archive, publish requires ≥1 artifact. Uploads are content-MIME-detected and **SHA-256 checksummed** (§16.7). ✅
  - **Private storage + signed delivery**: artifacts live on a private disk (`s3`-ready by env), delivered only via **short-lived signed URLs** — never a public path; expired/tampered links `403`. Every link minted is recorded to an immutable `download_events` ledger. ✅
  - **Product self-update** (`auth:product`, ADR 0004): `GET /api/v1/product/releases/latest` (auto-update check) + a scoped signed-link endpoint; a product only ever sees its own product's artifacts (cross-product `404`). ✅
  - Docs: [`docs/modules/downloads.md`](modules/downloads.md). Quality: **121 backend tests**, Larastan max, Pint, OpenAPI regenerated.
- **API — `DeviceSubscriptions`** module ([ADR 0010](adr/0010-device-subscriptions-module.md)): device-keyed subscriptions for **shipped consumer apps** (subscriber = a **device**, not a Company) — the successor to the legacy SmartAgent backend. Scaffolded: legacy `/api/*` compatibility shim + versioned twins, non-tenant, push port, legacy import command. Docs: [`docs/modules/DeviceSubscriptions.md`](modules/DeviceSubscriptions.md). 🚧 *Cutover pending.*
- **Remaining in Phase 6:** IoT / smart controllers (largely covered by Phase 4's signed offline tokens + device activation — additive telemetry/firmware channels), future SaaS products.

### 🚧 Phase 7 — App APIs & Fawateer go-live (in progress)
Putting the shipped consumer apps (**SmartAgent**, **Fawateer**) on the platform, and
naming the **two API profiles** every EVOTECH client speaks — **Profile A** (legacy
device API: unversioned, unauthenticated, local-first apps) and **Profile B** (the
versioned, authenticated `/api/v1` platform API).

**→ See [`ROADMAP-APP-APIS.md`](./ROADMAP-APP-APIS.md) for the full plan, gap analysis and cutover.**

Headline: **Fawateer needs no invoicing domain API** — the app is local-first (Drift/SQLite
owns every business table) and deliberately reuses the SmartAgent contract, distinguished
only by `app_name`. Go-live is closing a small set of compatibility gaps in
`DeviceSubscriptions` (partial `update_my_data`, plan requests, `is_trial`, a server-granted
30-day trial), plus an operator console to fulfil sales.

---

## Execution principle
Each phase ends with a **demoable/deployable output** before moving on. We work **step by step**, reviewing at each stop. Any significant architectural decision is recorded as an ADR in [`adr/`](./adr/) before implementation (constitution §18).

## Open items carried forward
- **Deploy Phase 1 website** to the Contabo VPS (needs your VPS/domain access).
- **PHP 8.4 toolchain upgrade** on the local machine (currently 8.2; Laravel 12 runs fine on 8.2, CI enforces 8.4).
