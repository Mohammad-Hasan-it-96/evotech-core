# EVOTECH Platform — Roadmap

> The project's official roadmap. Read alongside the binding constitution [`ARCHITECTURE.md`](./ARCHITECTURE.md).
> **Last updated:** 2026-07-22 · **Status:** Phases 0–5 complete & verified; Phase 6 in progress — the **Download Center** is done & verified (now with per-platform variants + server-side artifact import); **Phase 7 (App APIs & Fawateer go-live) in progress** — Fawateer's Android builds are shippable through the Download Center; the remaining work is repointing the shipped app's config source at the live API. *(Phase 5's live Stripe adapter is scaffolded per [ADR 0009](adr/0009-stripe-live-gateway.md) — enabling it in production needs credentials + a webhook-security review.)*
> **~263 backend test methods** across 15 modules.

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
  - **Releases & artifacts**: a versioned release of a product on a channel (`stable`/`beta`/`alpha`) groups **one artifact per platform *and variant*** — Android's `arm64-v8a` / `armeabi-v7a` sit side by side, a universal build is the empty-variant `default`. Staff CRUD + publish/**archive**/**unarchive** (an archived release can be restored to draft and re-published), publish requires ≥1 artifact. Uploads are content-MIME-detected and **SHA-256 checksummed** (§16.7). ✅
  - **Two ingest paths**: a normal multipart **upload** for small builds, and a **server-side import** (`GET /artifacts/incoming` + `POST /releases/{release}/artifacts/import`) for large ones — an operator drops the file into `storage/app/private/downloads/incoming/` (SFTP / File Manager) and registers it without the bytes crossing the CDN. Import shares the upload's allowlist + checksum and is path-traversal guarded (`basename`). *(See "Known limitations" — this exists because a browser upload cannot survive Cloudflare's 100 s origin timeout on the current uplink.)* ✅
  - **Private storage + signed delivery**: artifacts live on a private disk (`s3`-ready by env), delivered via **short-lived signed URLs** — never a public path; expired/tampered links `403`. There is also a **permanent public `downloads.latest` route** that resolves to whatever is currently published (for config files apps cache and keep on-device for weeks). Every link minted is recorded to an immutable `download_events` ledger. ✅
  - **Product self-update** (`auth:product`, ADR 0004): `GET /api/v1/product/releases/latest` (auto-update check) + a scoped signed-link endpoint; a product only ever sees its own product's artifacts (cross-product `404`). ✅
  - Docs: [`docs/modules/downloads.md`](modules/downloads.md). Quality: **41 module test methods**, Larastan max, Pint, OpenAPI regenerated.
- **API — `DeviceSubscriptions`** module ([ADR 0010](adr/0010-device-subscriptions-module.md)): device-keyed subscriptions for **shipped consumer apps** (subscriber = a **device**, not a Company) — the successor to the legacy SmartAgent backend. Legacy `/api/*` compatibility shim + versioned twins, non-tenant. Built out considerably since scaffolding: **remote-config generation** (`GET /api/{slug}/remote-config` builds the startup payload from the `device_apps` row rather than a hand-edited Drive JSON — `latest_version`, per-ABI download links derived from the Download Center, update notes, support channels, all shaped to the shipped parsers' quirks), a **dashboard catalog editor** (apps + plans, shared vs per-app), **operator fulfilment** (activate/decline/delete a device purchase request), and **real FCM push** per Firebase project. **105 module test methods.** Docs: [`docs/modules/DeviceSubscriptions.md`](modules/DeviceSubscriptions.md). 🚧 *App cutover pending — see Phase 7.*
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

**Progress (2026-07-22):** Fawateer's Android builds (`arm64-v8a` + `armeabi-v7a`) are now
published through the Download Center on production, and the remote-config endpoint derives
their permanent download links automatically. The operator console (activate/decline/delete)
and the plans/apps catalog editor are live.

**The one blocker left is the config *source cutover*.** The shipped Fawateer build reads its
startup config from a **static/Drive JSON** (historically `harrypotter.foodsalebot.com` / a
Google Drive file), *not* from this API's `GET /api/fawateer/remote-config`. Until that source
is repointed — or the static file is generated from the `device_apps` row and served at the
URL the app actually fetches — editing `latest_version`/`downloads`/`uses_shared_plans` in the
dashboard changes the API's answer but **not** what a phone in the field sees. This is exactly
why publishing "did nothing" and `getPlans` still returned shared plans during testing: two
config homes, and the app was reading the other one. **Next step:** confirm the URL the current
build fetches, then either (a) repoint it at `/api/fawateer/remote-config`, or (b) add a job
that renders the builder's payload to that static location on every catalog change.

---

## Execution principle
Each phase ends with a **demoable/deployable output** before moving on. We work **step by step**, reviewing at each stop. Any significant architectural decision is recorded as an ADR in [`adr/`](./adr/) before implementation (constitution §18).

## Open items carried forward
- **Deploy Phase 1 website** to the Contabo VPS (needs your VPS/domain access).
- **PHP 8.4 toolchain upgrade** on the local machine (currently 8.2; Laravel 12 runs fine on 8.2, CI enforces 8.4).
- **Fawateer config-source cutover** (Phase 7 blocker above) — repoint the shipped app's config URL at `/api/fawateer/remote-config`, or generate the static file from the `device_apps` row.

## Known limitations & recommendations (2026-07-22 review)
- **Large-build upload ceiling (Cloudflare).** A browser upload to `api.evotech-sys.com` is relayed through Cloudflare, which caps the *whole request body* at a ~100 s origin timeout — on the current uplink that strands any build over ~13 MB (both Fawateer APKs are 25–29 MB) as a `524`. **No origin (PHP/nginx) setting can fix this.** Today's answer is the **server-side import** path (drop the file on the VPS, register via dashboard). *Recommended permanent fix:* presigned **direct-to-object-storage** upload (browser → S3-compatible bucket, bypassing the origin entirely) or a **chunked/resumable** upload endpoint. The import path stays useful either way for ops without a browser.
- **Soft-delete + unique-index collision (fixed 2026-07-22, [#17]).** `artifacts` is `SoftDeletes` but its unique index on `(release_id, platform, variant)` omits `deleted_at`, so a deleted build kept its slot and re-importing that variant hit a `1062`. `persistArtifact` now resurrects the trashed row. *Recommendation:* audit other `SoftDeletes` models for unique indexes that don't carry `deleted_at` and apply the same `withTrashed()` resurrect-or-create pattern (or a partial index) before they bite in production.
- **Deploy hygiene.** The auto-deploy **refuses on a dirty server working tree** (by design). A stray file created on the VPS (e.g. via CloudPanel File Manager at the wrong path) silently blocks every deploy with a 5 s failure. *Recommendation:* make the deploy step print the offending `git status --porcelain` lines in the failure message, and document the exact incoming path (`storage/app/private/downloads/incoming/`) so File-Manager uploads land where the importer looks.
- **Stray root files.** Three 0-byte files have reached the repo root from over-broad `git add -A`/shell-glob mishaps (cleaned in [#16]). *Recommendation:* a tiny CI guard that fails when an unexpected top-level file appears, and prefer explicit `git add <path>` over `-A`.
- **Version-check is numeric, not semver.** Both shipped apps compare `latest_version` component-wise as integers (`int.tryParse(part) ?? 0`); a `-beta`/`+build` suffix reads as `0` and can hide an update forever. The request layer already enforces digits-and-dots — keep it that way, and remember the Flutter `pubspec` build number (`+N`) is **not** part of this string.
