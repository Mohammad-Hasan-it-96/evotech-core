# ADR 0010 — Device subscriptions module (SmartAgent migration)

- **Status:** Accepted
- **Date:** 2026-07-16
- **Deciders:** Founder/CTO, Platform architect
- **Supersedes / superseded by:** —
- **Related:** `docs/ARCHITECTURE.md` §2 (module rules), §6.1 (Authentication), §6.13 (Rate limiting), §7 (API contract); ADR 0002 (module layout), ADR 0004 (product-to-platform API keys); the legacy backend `IchancyBot-SmartAgentBack` and its `docs/SUBSCRIPTION_API.md`.

## Context

EVOTECH operates a shipped Android app, **SmartAgent** (المندوب الذكي), whose device
subscriptions are served today by a standalone **Laravel 9** backend
(`IchancyBot-SmartAgentBack`). That backend is a single table (`app_harfoshs`, 50 rows), two
API controllers, and Firebase push for expiry reminders. Its full surface is documented in
that repo's `docs/SUBSCRIPTION_API.md`.

We want to retire that separate deployment and fold the capability into the platform, because
the app already reads its **API base URL from a Google Drive JSON config** — so a cutover is a
single config edit (reversible within the app's cache window), *provided the new target serves
the exact endpoints and holds the data*. This ADR records how that capability lands in
evotech-core without violating the constitution.

Three facts shape the decision:

1. **The subscriber is a device, not a Company.** A row is keyed by the pair
   (`device_id`, `app_name`); it self-registers from the app with no login and no tenant. This
   does **not** fit the existing `Subscriptions` module, whose subscriber is a `Company` and
   whose endpoints are staff-managed (`auth:sanctum`). The `device` value of
   `Subscriptions`' `IdentifierType` enum is still a Company-owned domain/device identifier —
   a different concept from a self-service consumer device.
2. **The shipped app is already in users' hands.** It calls bare, unversioned paths
   (`POST /api/create_device`, `GET /api/getPlans`, …) with **no auth header** and expects
   specific JSON shapes. Anything that breaks those shapes or demands a credential breaks
   existing installs the moment we repoint the Drive JSON — unless a new app version ships in
   lockstep.
3. **The legacy API has real security holes.** `activateDevice` lets anyone grant themselves a
   paid subscription; `getDevice` dumps every user's name/phone/token; `test_send_notifications`
   is a public push-blast. These must be closed, but closing them cannot require the shipped
   app to send a credential it does not have.

## Decision

**1. A dedicated `DeviceSubscriptions` module.**
Create `Modules\DeviceSubscriptions` (4-layer, `BaseModuleServiceProvider`, auto-discovered per
ADR 0002). It owns a `device_subscriptions` table that mirrors the legacy columns
(`app_name`, `device_id`, `full_name`, `phone`, `is_verified`, `expires_at`, `plan_id`,
`fcm_token`, `stars`, `comment`) **plus** platform conventions: a `HasUuid` route key (the
bigint PK is never exposed, §2.4) and an index on `(app_name, device_id)`. The name is
product-agnostic on purpose — the legacy `app_name` column shows the backend was built to serve
multiple apps, so the module is not named "SmartAgent".

**2. These devices are explicitly non-tenant.**
`device_subscriptions` does **not** use `BelongsToCompany`. A consumer device has no company;
forcing a tenant would be fiction. This is the deliberate deviation from the platform's default
tenancy (§2.4) that justifies a separate module rather than an extension of `Subscriptions`.
Cross-module coordination still goes through Core ports/events (§2.1) — the module records
actions via the `AuditLogger` port and emits domain events (e.g. device activated / expiring)
that `Notifications` reacts to, rather than calling other modules directly.

**3. Compatibility-first contract, with a documented legacy shim.**
To let the cutover happen by flipping one Drive-JSON value — no app release — the module
replicates the 10 legacy endpoints **byte-for-byte** (paths, request bodies, JSON keys). The
constitution mandates `/api/v1` (§7); the shipped app calls unversioned `/api/*`. We reconcile
this with a **thin legacy compatibility route group** registered *outside* the `/v1` prefix
that forwards to the same module controllers. This shim is a **temporary, documented exception
to §7**, retired once a new app version adopts `/api/v1` + a product API key. The canonical
`/api/v1/...` routes are added at the same time so new app versions target the versioned API.

**4. Layered auth that does not break the shipped app.**
Authentication is applied by audience, matching §6.1's layered principle:

- **Device self-service endpoints** (`create_device`, `check_device`, `update_my_data`,
  `add_review`, `getPlans`, `app-download`) stay **public** on the legacy shim, so existing
  installs keep working. Their `/api/v1` twins are gated by `auth:product` (ADR 0004) for the
  next app version, throttled by the `product` rate limiter (§6.13).
- **Admin endpoints** (`activateDevice`, `getDevice`) move behind **`auth:sanctum`** — the
  worst holes (self-activation, PII dump) close immediately, and no shipped-app flow depends on
  them (activation is a staff action).
- **`test_send_notifications` is removed**; the expiry blast (`send_plan_notifications`) becomes
  a **scheduled Artisan command** (like `subscriptions:expire`) instead of a public HTTP route.

**5. Plans stay configuration, currency USD.**
`half_year` (6 months, $12) and `yearly` (12 months, $20, recommended) move from hard-coded
controller arrays into module config, preserving the exact `getPlans` response shape.

**6. One-off data migration.**
The 50 `app_harfoshs` rows are copied into `device_subscriptions` by a dedicated Artisan
command (fresh UUIDs, columns mapped 1:1). At this size it is a single reversible run;
production cutover order is: deploy module → verify every endpoint against
`SUBSCRIPTION_API.md` → migrate rows → **then** edit the Drive JSON. Rollback is flipping that
one value back.

## Consequences

**Positive**
- Retires a separate Laravel 9 deployment; one platform, one operational surface.
- The cutover is a single config edit with instant rollback, because the contract is replicated
  exactly and the data is migrated first.
- The two most dangerous legacy holes (anonymous activation, anonymous PII dump) are closed on
  day one without touching the shipped app; the public push-blast endpoint is gone.
- A clean, versioned, `auth:product` API exists from the start for the next app version to adopt.

**Negative / trade-offs**
- We knowingly carry **unversioned, partly-public legacy routes** (the §7 shim) until the app
  is updated. This is the price of a zero-downtime, no-release cutover; it is time-boxed and
  documented, and the versioned secure API ships alongside it.
- The device self-service endpoints remain unauthenticated on the shim, so basic abuse
  (spamming device registrations) is possible until the app moves to API keys. Mitigation:
  per-route throttling and input validation now; `auth:product` on the `/v1` twins.
- A second, non-tenant subscription concept lives beside the Company-based `Subscriptions`
  module. Justified by the genuinely different subscriber, but it is a second thing to
  understand; the module doc will draw the boundary explicitly.

**Deferred (additive, no contract change)**
- Retiring the legacy shim once a new app version ships (adopting `/api/v1` + product API key).
- Folding plans into an admin-editable catalog (today they are config, matching legacy).
- Normalizing the Firebase send path (the legacy code mixes `send()` and `sendNotification()`);
  the module's `Infrastructure/` Firebase adapter exposes one method.

## Amendment to the constitution

None to §6.1's principle (it already layers auth by audience). §7's "all routes under
`/api/v1`" gains a **single, explicit, time-boxed exception**: the `DeviceSubscriptions` legacy
compatibility route group serves the already-shipped SmartAgent app on unversioned `/api/*`
paths until that app adopts the versioned API, at which point the shim is removed and this
exception lapses.
