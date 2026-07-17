# EVOTECH — App APIs Roadmap

> Companion to the official [`ROADMAP.md`](./ROADMAP.md); governed by the binding
> constitution [`ARCHITECTURE.md`](./ARCHITECTURE.md).
> **Created:** 2026-07-17 · **Scope:** the two API profiles EVOTECH apps speak, and
> what it takes to put **Fawateer (فواتير / `invoices`)** live.

---

## Why this document exists

`evotech-core` serves two fundamentally different kinds of client, and conflating
them is the main risk to app delivery:

- **Shipped consumer apps** that are already in users' hands, speak a fixed
  unversioned contract, and cannot be changed without a store release.
- **The platform** (dashboard, staff, machine-to-machine products) which speaks the
  versioned, authenticated `/api/v1` contract the constitution mandates (§7).

This document names those two profiles, records which app uses which, and lays out
the work to bring Fawateer live.

---

## 1. The two API profiles

### Profile A — Legacy Device API (`/api/*`, unversioned, unauthenticated)

The contract the **shipped Flutter apps** speak. Introduced for SmartAgent in
[ADR 0010](./adr/0010-device-subscriptions-module.md) as a deliberate, time-boxed
exception to §7, served by `Modules\DeviceSubscriptions`.

| Property | Value |
|---|---|
| Paths | `create_device`, `check_device`, `update_my_data`, `getPlans`, `add_review`, `app-download` |
| Versioning | **None** — unversioned `/api/*`, outside `/v1` |
| Auth | **None.** Identity is `device_id` in the JSON **body** |
| Device id | `SHA-256(ANDROID_ID + app-salt)`; per-app salt, so one phone yields a different id per app |
| App separation | The `app_name` body field |
| Shape | Bare legacy JSON — **not** the platform envelope |
| Admin | `activateDevice` / `getDevice` are **staff-only** (`auth:sanctum`) per ADR 0010 |

**Why unauthenticated is acceptable here — and only here:** these apps are
**local-first**. All business data (invoices, customers, ledger, cashbox) lives in
on-device SQLite. The server holds only the device row: name, phone, plan, expiry.
There is no user data on the server to steal by guessing a `device_id`. The two
real holes (anonymous self-activation, anonymous PII dump) were closed in ADR 0010.

> **This profile's guarantee: no business data may ever be served from it.** The
> moment an app wants server-stored business records, it moves to Profile B. That
> boundary is the whole reason the profile is safe.

### Profile B — Platform API (`/api/v1/*`, versioned, authenticated)

The constitution-compliant surface — ~84 endpoints across 14 modules, already built
and tested through Phases 2–6.

| Property | Value |
|---|---|
| Paths | `/api/v1/**` |
| Auth (humans) | `auth:sanctum` — staff / dashboard |
| Auth (machines) | `auth:product` — per-product API key ([ADR 0004](./adr/0004-product-to-platform-auth-api-keys.md)), `Authorization: Bearer` or `X-Api-Key` |
| Entitlement | Company → Subscription → Plan → Product → License, incl. EdDSA offline tokens ([ADR 0005](./adr/0005-signed-offline-license-tokens.md)) |
| Shape | Platform envelope `{data}` / `{data,meta,links}` |
| Scoping | Tenant (`BelongsToCompany`) or product; cross-product access returns **404** |

### Which app uses which

| App | Profile | Status |
|---|---|---|
| **SmartAgent** (المندوب الذكي) | **A** | Shipped. Migration to evotech-core scaffolded (ADR 0010); cutover pending |
| **Fawateer** (فواتير) | **A** | Shipped/complete. **Deliberately reuses the SmartAgent contract** — see §2 |
| Restaurant / IoT / future SaaS | **B** | Built (Licenses + Gateway + Downloads) |
| Future Fawateer cloud sync / Web | **B** | Not started — see Phase E |

---

## 2. Fawateer — what is actually required

**Fawateer does not need an invoicing domain API.** This was verified against the
shipped app, not assumed:

- The app is **local-first**: `drift` / `sqlite3_flutter_libs` own every business
  table — `sales_invoices`, `sales_items`, `customers`, `products`,
  `ledger_entries`, `cashbox_transactions`, `shop_settings`.
- Its `pubspec.yaml` scopes networking explicitly: *"Networking & Licensing
  (subscription verification)"*. There is **no** invoice/customer/product endpoint
  anywhere in the app, and **no sync layer**. User backup is the user's own Google
  Drive, not the platform.
- `lib/core/network/api_config.dart` states the intent outright:

  > *"Fawateer currently reuses the Smart-Agent backend (the same
  > `create_device`/`check_device`/`getPlans` endpoints), distinguished only by
  > `appName`. When Fawateer's own server is ready, change `defaultBaseUrl` —
  > nothing else needs to move."*

So Fawateer is **not a new-style app**. It is a second Profile A client, separated
from SmartAgent only by `app_name: 'Fawateer'`. `Modules\DeviceSubscriptions` was
built product-agnostic (ADR 0010, Decision 1) precisely for this.

**Consequence: Fawateer go-live is a small, well-bounded piece of work** — closing
the gaps in §3 — not a new module.

### Facts that constrain the implementation

| Fact | Source | Why it matters |
|---|---|---|
| Base URL is remote-config'd from a Google Drive JSON | `remote_config_service.dart` (`fawateer_version.json`) | Cutover = editing one JSON. No store release, instant rollback |
| Fawateer and SmartAgent have **separate** config files | Different Drive file ids | The two apps can be pointed at **different base URLs** — the lever behind Phase D |
| Both apps currently share one backend | Same `defaultBaseUrl` | `getPlans` cannot vary per app today (§3, gap 6) |
| Offline grace **72h**; clock-tamper threshold **5 min** vs `server_time` | `license_guards.dart` | `server_time` in responses is load-bearing, not decorative |
| Non-2xx on `check_device` is silently read as "not verified" | `license_remote_datasource.dart` | A 500 **locks users out silently** — availability is a correctness concern |
| Activation is **operator-driven**; `activateDevice` exists in **neither** app | app source | Do not build a self-serve activate endpoint. The operator flips it server-side |
| Unreadable device id falls back to the literal `'fallback_device_id'` | `device_identity_service.dart` | Every such device collides on one row |

---

## 3. Gap analysis — shipped app vs. `Modules\DeviceSubscriptions`

Verified against both codebases. **Severity** is the effect on a real user.

| # | Gap | Severity | Detail |
|---|---|---|---|
| 1 | `update_my_data` rejects FCM-only rotation | **Blocker** | The app rotates its token sending only `app_name` + `device_id` + `fcm_token`. Our validator requires `full_name` **and** `phone` → **422**. Token rotation fails → the live-unlock push never arrives → a paying user stays locked out |
| 2 | Plan requests silently dropped | **Blocker** | The app files a purchase intent via `create_device` + `requested_plan` + `contact_method` + `status: 'pending'`. We ignore all three → the operator never sees it. **The monetization flow is broken end-to-end** |
| 3 | `is_trial` never returned | **Blocker** (with 4) | The app reads `is_trial` from `create_device` **and** `check_device`. We never send it |
| 4 | No trial system | **Blocker** | The app's own owner-locked design (`docs/plans/006-free-trial.md`) requires: first registration with no plan ⇒ server stamps a **30-day** trial. We have no trial concept. Without it every new install is dead on arrival |
| 5 | `server_time` missing from `create_device` | High | The app takes its trusted clock baseline from **both** responses. Absent ⇒ the 5-min tamper guard and 72h grace degrade |
| 6 | Plans are SmartAgent's, and global | High | We serve `half_year $12` / `yearly $20`. Fawateer's catalog is **$19 / $49**. `getPlans` carries **no** `app_name`, so one backend cannot vary plans per app → see Phase D |
| 7 | `price_after_discount` absent | Low | Expected by the plan parser; nullable, so likely tolerated. Confirm before shipping |
| 8 | `fallback_device_id` collisions | Low | All devices with an unreadable id share one literal id. Needs an explicit server-side policy |
| 9 | `success` flag in `getPlans` | Low | SmartAgent **requires** it; Fawateer ignores it. **Keep sending it** |

> Gaps 1–4 each independently prevent go-live. None is large; they are small because
> the module already exists.

---

## 4. Phases

### Phase A — Fawateer compatibility ✅ (done)

Closed gaps 1, 2, 3, 5, 7, 9 in `Modules\DeviceSubscriptions`.

- `update_my_data` now takes a **partial** update — any of `full_name`, `phone`,
  `fcm_token`; only what's sent is written. *(gap 1)*
- `create_device` accepts and persists `requested_plan`, `contact_method`,
  `status`, **including on an already-registered device** — which is how the app
  actually files a purchase intent. *(gap 2)*
- `is_trial` on both `create_device` and `check_device`; `server_time` on
  `create_device`. *(gaps 3, 5)*
- `price_after_discount` in the plan catalog; `success` retained for SmartAgent. *(gaps 7, 9)*
- Migration `2026_07_17_100000`: `status`, `trial_expires_at`, `requested_plan`,
  `contact_method` — all nullable, so the legacy import is unaffected.
- **Also fixed:** a profile edit used to blank `fcm_token` (it was written
  unconditionally), silently costing the device its live-unlock push.

Tests assert the exact shipped-app payloads, including the FCM-only rotation body.
**19 module tests green (152 suite-wide); Pint + Larastan max clean.**

**Exit met:** every request the shipped app can make is served with the shape it
parses. `is_trial` is wired end-to-end but stays `0` until Phase B grants trials.

### Phase B — Server-granted trial ✅ (done)

Per the app's owner-locked design.

- First registration ⇒ `is_verified = true`, `expires_at = trial_expires_at =
  now + 30d`. The expiry rides **`expires_at`** because that is the field the app
  gates on; it never reads `trial_expires_at`.
- **Per-app, by config** (`device-subscriptions.apps`, keyed on the `app_name` the
  client sends, case-insensitive). **Fawateer 30 days; SmartAgent 0.** The trial is
  Fawateer's design — granting it platform-wide would have silently changed
  SmartAgent's monetization. An unconfigured app inherits nobody's trial.
- **Unfarmable:** granted only on row creation. ANDROID_ID survives uninstall and
  data-clear, so a reinstall finds the existing row and gets nothing. Devices
  already known — including the imported legacy rows — are never retro-granted.
- Conversion needs no new endpoint and no flag to clear: operator activation sets
  `plan_id`, which ends the trial by definition. `trial_expires_at` is retained as
  the record that a trial was spent.
- **Also fixed:** `create_device` answered with the **raw** `is_verified` while
  `check_device` forced it to `0` past expiry. Harmless while every device was
  operator-activated; with trials it meant a lapsed-trial device re-registering was
  told it was verified alongside a past `expires_at`. Both endpoints now answer with
  one definition (`isActive()`).
- **Also fixed:** expiry-reminder push was hardcoded to "المندوب الذكي", so a
  Fawateer user would be told to renew SmartAgent. Now uses the per-app label.

**24 module tests green (157 suite-wide); Pint + Larastan max clean.**

**Exit met:** a fresh Fawateer install is usable for 30 days with no operator action,
and cannot farm a second trial.

### Phase C — Operator console ✅ (done, bar FCM credentials)

The plan requests from Phase A are worthless unless an operator can act on them.

**API** (`auth:sanctum`):
- `GET /api/v1/device-subscriptions` gained the filters a console needs —
  `status=pending` (the work queue), `app_name` (several apps share this
  deployment), and `q` (searches `device_id`, `full_name`, `phone` — what an
  operator has to hand when a customer messages them).
- `GET /api/v1/device-subscriptions/plans` — the catalog to activate against.
  Staff-scoped rather than reusing the shim's public `getPlans`, which Phase E
  retires. Serving the *same* config catalog means the operator cannot pick a
  plan id the device would not recognise (an unknown id yields a **0-month term** —
  an instantly-expired subscription for someone who just paid).
- The staff resource now exposes `is_trial`, `trial_expires_at`, `status`,
  `requested_plan`, `contact_method`.
- **Activation closes the request it fulfils** (`status → null`), so the queue
  drains. `requested_plan` is retained — the operator may sell a different plan.

**Dashboard** (`evotech-web`, `/dashboard/devices`, bilingual ar/en):
- "Pending requests" is the **default tab** — it is the work queue. Rows show the
  contact channel the app funnelled the user to, and one badge for the four states
  an operator must tell apart: awaiting activation / trial / active / expired.
- Activate dialog preselects the plan the user asked for, so the common case is
  one click.

**121 → 163 suite tests; 30 module tests. Pint + Larastan max clean; frontend
lint, typecheck and build clean (route generated for both locales).**

**Exit met** for the software: a sale can be fulfilled without touching the
database. ⚠️ **But live-unlock still no-ops** — `FirebasePushNotifier` is a
scaffold pending real FCM credentials, so an activated customer stays locked until
they next reopen the app (which polls `check_device`). See Risks.

### Phase D — Per-app plans, without an app release 📋

Fawateer and SmartAgent need different prices, but `getPlans` carries no `app_name`.

**Lever:** the two apps read **separate** remote-config files, so they can be given
**different base URLs** — e.g. `…/apps/fawateer/api/*` vs `…/apps/smart-agent/api/*`.
The app is namespaced by URL; the plan catalog resolves per namespace. **Zero app
changes.** (Alternative — an optional `?app_name=` — needs a store release, so it
is a non-starter for the shipped builds.)

**Exit:** each app gets its own catalog and pricing from one backend.

### Phase E — Profile B for new apps 📋

Only when an app needs **server-stored business data** (Fawateer Web, cloud sync,
multi-device). That crosses Profile A's boundary and therefore requires:

- Per-device **authentication** — registration issues a device **secret**, sent as
  a bearer token. A `device_id` in a body is an identifier, never a credential.
- A device-scoped tenancy axis (`BelongsToDevice`), analogous to `BelongsToCompany`.
- **An ADR.** This is a new tenancy model and a §7/§2.4 matter.

**Not required for Fawateer go-live.** Deliberately deferred.

---

## 5. Cutover (per app, unchanged from ADR 0010)

1. Deploy the module to `api.evotech-sys.com`.
2. Verify every endpoint against the shipped app's real payloads.
3. Import the legacy rows (`device-subscriptions:import-legacy`).
4. **Then** edit that app's Drive JSON `baseUrl`.

Rollback is reverting that one value. Cut **SmartAgent and Fawateer separately** —
they have separate config files, so a failure is contained to one app.

---

## 6. Open decisions

| # | Decision | Recommendation |
|---|---|---|
| 1 | ADR for Phase A/B/D? | **Yes — ADR 0011.** ADR 0010 covers a SmartAgent-shaped shim; trial, plan requests and per-app namespacing extend it materially |
| 2 | Fawateer plan prices/ids | Catalog says $19 / $49; the shim currently serves $12 / $20. **Owner must confirm** |
| 3 | `fallback_device_id` policy | Reject it, or accept and quarantine. Needs a call |
| 4 | Trial length | 30 days per the locked design |
| 5 | Own the `harrypotter.foodsalebot.com` backend? | Both apps point at it today. Confirm it is ours and that legacy data is exportable |

## 7. Risks

- **Silent lockout (gap 1 / #5 above).** The app treats any non-2xx `check_device`
  as "not verified". An outage or a 500 doesn't show an error — it locks paying
  users out of their own app. Uptime here is a correctness property.
- **Trial farming on iOS.** `identifierForVendor` resets when all vendor apps are
  removed. Accepted by the locked design; Android is the primary target.
- **Shared-backend coupling.** Until Phase D, both apps read one plan catalog.
- **FCM is a scaffold.** Live-unlock silently no-ops until credentials are supplied.
