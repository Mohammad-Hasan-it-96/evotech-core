# Fawateer ⇄ EVOTECH — Device API contract

> The contract the shipped **Fawateer** app speaks to `evotech-core`
> (`Modules\DeviceSubscriptions`, [ADR 0010](../adr/0010-device-subscriptions-module.md)).
> **Verified:** 2026-07-17 — every sample below is a real response captured from a
> running API, not a sketch.
> **Audience:** whoever points the app at the new server, and anyone touching either side.

> ## ✅ Live and verified — 2026-07-17
>
> Every endpoint below was tested against `https://api.evotech-sys.com` and returns the
> documented shape. Migrations are applied and the trial fires. **Fawateer is clear to
> connect** — it is unreleased, so there is no install base to protect.

---

## 0. TL;DR — change two constants and rebuild

The platform already matches what the app sends, so **no request or parsing code
changes**. Because Fawateer is **not yet released**, point it at the new server in the
build itself rather than via a remote flip:

```dart
// lib/core/network/api_config.dart:15
static const String defaultBaseUrl = 'https://api.evotech-sys.com/api/fawateer';

// lib/core/config/remote_config_service.dart:27  — off Google Drive, onto your own domain
static const String _configUrl = 'https://evotech-sys.com/config/fawateer.json';
```

That is the whole integration. **Keep the remote-config mechanism** — it is what lets you
move servers later without a store release — just host the JSON yourself, on the **web**
host rather than the API host (so an API outage cannot take down the config that says
where the API is).

> Editing the Drive JSON (`fawateer_version.json`, id `1pVMkNYKAGjiO8tRSG3nEcVGvEQS8xcVk`)
> also still works, and is handy for testing against the new server before you rebuild.
> Just don't let the **released** app depend on Drive — that dependency is only
> removable while the app is unreleased.
>
> ⚠️ **The URL lives at `api.base_url`, not a top-level `baseUrl`** — see the schema in
> §9. Get that key wrong and the parse yields an empty string, the assignment is skipped
> (`if (cfg.baseUrl.isNotEmpty)`), and the app **silently keeps using the old server**
> while the edit looks successful. Edit the existing file's value; never replace the file
> wholesale, or you also drop `latest_version`, `downloads` and `support`.

Use the **`/api/fawateer`** namespace, not bare `/api` — same contract, but it lets the
platform serve Fawateer its own plans later without ever touching the app again (§7).
Both work today and return identical data.

---

## 1. Basics

| | |
|---|---|
| Base URL (new) | `https://api.evotech-sys.com/api/fawateer` |
| Base URL (legacy, still live) | `https://api.evotech-sys.com/api` |
| Auth | **None.** Identity is `device_id` in the JSON **body** |
| Headers | `Content-Type: application/json`, `Accept: application/json` |
| `app_name` | Always the literal **`"Fawateer"`** (case-sensitive; it keys the device row) |
| Encoding | UTF-8; Arabic returned as-is |
| Timestamps | ISO-8601 UTC, `2026-08-16T12:55:56.000000Z` |

**Why no auth is safe here:** the app is local-first — invoices, customers, ledger and
cashbox live in on-device SQLite. The server holds only the device row (name, phone,
plan, expiry). There is no business data to reach by guessing a `device_id`.

> ⚠️ **This is a hard boundary.** If Fawateer ever stores business data server-side
> (cloud sync, the Web build), it must move to an authenticated API first — a
> `device_id` in a body is an identifier, never a credential. See
> [`ROADMAP-APP-APIS.md`](../ROADMAP-APP-APIS.md) Phase E.

---

## 2. Endpoints

All are `POST` with a JSON body except where noted.

### 2.1 `POST create_device`

Registers a device, refreshes its push token, **and** files a purchase intent — the app
uses this one endpoint for all three.

**Request**

| Field | Req | Notes |
|---|---|---|
| `app_name` | ✅ | `"Fawateer"` |
| `device_id` | ✅ | `SHA-256(ANDROID_ID + "fawateer_pos_app")` |
| `full_name` | ✅ | |
| `phone` | ✅ | |
| `fcm_token` | — | Attach when known, so activation can push a live unlock |
| `requested_plan` | — | Purchase intent, e.g. `"12_months"` |
| `contact_method` | — | `whatsapp` \| `telegram` \| `email` |
| `status` | — | `"pending"` when filing a plan request |

**Response `200`** — first registration, trial granted:

```json
{
  "is_verified": 1,
  "is_trial": 1,
  "expires_at": "2026-08-16T12:55:56.000000Z",
  "plan": null,
  "fcm_token": "tok-1",
  "server_time": "2026-07-17T12:55:56.982882Z"
}
```

- **Idempotent** on (`app_name`, `device_id`) — enforced by a unique index, not just
  by convention.
- A returning device gets its current state back; **the trial is not re-granted** (§4).
- Sending `requested_plan`/`contact_method`/`status` on an existing device records the
  request without disturbing anything else.

### 2.2 `POST check_device`

The status poll the gate depends on.

**Request:** `app_name`, `device_id` — both required.

**Response `200`**

```json
{
  "success": true,
  "is_verified": 1,
  "is_trial": 1,
  "plan": null,
  "expires_at": "2026-08-16T12:55:56.000000Z",
  "server_time": "2026-07-17T12:55:57.111701Z"
}
```

**Response `404`** — unknown device (never registered, or a fresh install offline):

```json
{ "success": false, "message": "Device not found" }
```

- `is_verified` is **`0` once `expires_at` has passed**, even for a paid plan. The server
  decides; the client never has to.
- `plan` is `null` on a trial, and the plan id once activated.

### 2.3 `POST update_my_data`

**Partial** — send only what changed.

| Field | Req |
|---|---|
| `app_name`, `device_id` | ✅ |
| `full_name`, `phone`, `fcm_token` | any subset |

```json
{ "success": true }
```

Both real call shapes work and **neither disturbs the other's fields**:

```jsonc
// token rotation
{"app_name":"Fawateer","device_id":"…","fcm_token":"rotated"}
// profile edit from Settings
{"app_name":"Fawateer","device_id":"…","full_name":"…","phone":"…"}
```

`404` if the device is unknown.

### 2.4 `POST add_review`

`app_name`, `device_id`, `stars` (**1–5**), `comment` (optional, ≤1000) → `{"success": true}`.

### 2.5 `GET getPlans`

No parameters. Real response:

```json
{
  "success": true,
  "currency": { "code": "USD", "symbol": "$" },
  "plans": [
    {
      "id": "half_year",
      "title": "الخطة نصف السنوية",
      "duration_months": 6,
      "price": 12,
      "price_after_discount": null,
      "enabled": true,
      "recommended": false,
      "description": "أفضل خيار للتجربة طويلة المدى"
    },
    {
      "id": "yearly",
      "title": "الخطة السنوية",
      "duration_months": 12,
      "price": 20,
      "price_after_discount": null,
      "enabled": true,
      "recommended": true,
      "description": "الأكثر توفيراً"
    }
  ]
}
```

- `price_after_discount: null` means no discount.
- The app filters out `enabled: false` client-side.
- ⚠️ **These are SmartAgent's plans** — Fawateer's own pricing is still an open owner
  decision (§7).

### 2.6 `GET app-download`

```json
{ "success": true, "latest_version": null, "downloads": [] }
```

Placeholder until wired to the Download Center. Harmless to call.

### 2.7 Not available — by design

- **`activateDevice` / `getDevice`** exist but are **staff-only** (`auth:sanctum`).
  In the legacy backend they were public: anyone could self-activate or dump every
  user's phone number. The app must never call them — activation stays operator-driven.
- **`test_send_notifications`** is not ported. Expiry reminders are a scheduled job.

---

## 3. Errors

| Status | Body | Meaning |
|---|---|---|
| `200` | endpoint shape | OK |
| `404` | `{"success":false,"message":"Device not found"}` | Unknown (`app_name`,`device_id`) |
| `422` | see below | Validation |

```json
{
  "success": false,
  "message": "Validation error",
  "errors": { "device_id": ["The device id field is required."] }
}
```

These are **bare legacy shapes on purpose** — not the platform's `{data}` envelope — so
the shipped app parses them unchanged.

> ⚠️ **The app treats any non-2xx `check_device` as "not verified".** A 500 or an outage
> does not surface an error; it **locks paying users out of their own app**. Uptime on
> this endpoint is a correctness property, not an ops nicety.

---

## 4. The trial

**30 days, granted by the server**, no operator action, on first registration.

- Arrives as `is_verified: 1` + `expires_at: now+30d` + `is_trial: 1`, so the existing
  gate unlocks with no new logic.
- **Unfarmable.** Granted only when the row is created, and `ANDROID_ID` survives
  uninstall and data-clear — a reinstall re-registers the same `device_id`, finds the
  row, and gets nothing. Uniqueness is enforced by a database index.
- **Conversion:** the operator activates a plan → `plan` is set, `is_trial` → `0`,
  `expires_at` becomes the paid expiry. Same gate, no reinstall, no data loss.
- Per-app: **SmartAgent gets no trial.**

---

## 5. `server_time` — load-bearing

Present on `create_device` **and** `check_device`. The app anchors its 72h offline grace
and 5-minute clock-rollback guard to it. It is not decorative: without it those guards
degrade. Always sent.

---

## 6. Buying — the real flow

1. App files intent: `create_device` + `requested_plan` + `contact_method` + `status:"pending"`.
2. App funnels the user to WhatsApp/Telegram/email.
3. The device appears in the **operator console** (`/dashboard/devices`, "Pending
   requests"). The operator takes payment and activates.
4. Server pushes `type: new_plan_activated` → the app unlocks live.
5. Failing that, the next `check_device` picks it up.

> ⚠️ **Step 4 does not work yet** — FCM credentials are pending, so the push silently
> no-ops. Until then a paying customer waits until they reopen the app. Step 5 still
> saves it.

---

## 7. Open items

| # | Item | Status |
|---|---|---|
| 1 | **Fawateer pricing** | **Open — owner.** The site advertises $19/$49 *monthly, feature-tiered*; this catalog is *duration-based* (6/12 mo). Different product shapes, so it is a product decision, then one config edit to `apps.Fawateer.plans`. The `/api/fawateer` namespace exists so this needs **no app release** |
| 2 | **FCM credentials** | **Open.** Live-unlock no-ops until supplied |
| 3 | **Legacy data import** | Pending: is `harrypotter.foodsalebot.com` ours, and is its data exportable? |

---

## 8. Cutover

### Fawateer — there is no cutover

It is unreleased with no install base, so §0 *is* the integration: set the two
constants, rebuild, ship. Nothing to import, nothing to flip, nothing to roll back.

### SmartAgent — later, and carefully

That one **is** shipped: 43 devices, **11 holding real paid plans**, and **no trial** to
soften a mistake. Its config URL is baked into a released build, so the Drive JSON is the
only lever — and its devices **must be imported before** its base URL moves, or paying
customers are locked out on their next check. See
[`GO-LIVE-FAWATEER.md`](../GO-LIVE-FAWATEER.md) §5.1.

**Cut the two apps separately.** They read separate config files; that is what keeps a
bad cutover contained to one product.

### 1. Deploy the API — ✅ done (verified 2026-07-17)

For reference, on the VPS in the Laravel site (`api.evotech-sys.com`):

```bash
git pull origin main          # brings the DeviceSubscriptions module
composer install --no-dev --optimize-autoloader
php artisan migrate --force   # device_subscriptions + trial/plan-request columns
php artisan config:cache && php artisan route:cache
```

`route:cache` matters — new routes will not resolve while an old route cache is loaded.

### 2. Verify — from anywhere

```bash
curl -s https://api.evotech-sys.com/api/fawateer/getPlans          # expect the §2.5 JSON
curl -s -X POST https://api.evotech-sys.com/api/fawateer/check_device \
  -H 'Content-Type: application/json' \
  -d '{"app_name":"Fawateer","device_id":"deploy-smoke-test"}'      # expect 404 "Device not found"
```

That 404 is the **success** signal: the route exists and answered in the legacy shape.
A 404 with HTML, or any 500, means step 1 is incomplete — stop.

### 3. Import the legacy rows — **SmartAgent only**

```bash
DEVICE_LEGACY_CONNECTION=legacy php artisan device-subscriptions:import-legacy --dry-run
DEVICE_LEGACY_CONNECTION=legacy php artisan device-subscriptions:import-legacy
```

Re-runnable (upserts on the device identity). **Fawateer needs none of this** — its three
legacy rows are test devices for an unreleased app. This exists for SmartAgent's 11
paying customers, and it must run **before** their base URL moves: otherwise their first
`check_device` on the new server 404s, and with no trial to soften it they are locked out
immediately.

### 4. Point the app at the new server

**Fawateer:** in the build (§0). Ship it pointing at the right server.

**SmartAgent:** the Drive JSON is the only lever for a released app — and only after
step 3. **Rollback is restoring the one old value**, effective on each app's next config
fetch.

### 5. Watch

The first thing to check is that `check_device` keeps answering `200`/`404` and never
`5xx`. A 500 here does not show an error to users — it silently locks them out.

---

## 9. The remote-config file

> **No longer hand-edited.** `evotech-web/public/config/fawateer.json` is gone; the
> same URL is now a route handler that proxies `GET /api/{slug}/remote-config` and
> is edited from the dashboard (`/dashboard/plans` → **App config**). The URL, the
> shape, and the 5-minute cache are unchanged — the app cannot tell the difference.
>
> The proxy answers **200 even when the API is unreachable**, falling back to a
> committed copy (`src/content/device-config-fallback.ts`) and flagging it with an
> `X-Config-Source: fallback` response header. It returns 200 because both deploy
> health checks assert on status and one hard-fails the workflow — and because a
> slightly stale config serves an app far better than no config at all.

Fetched at startup from `_configUrl` and parsed by `RemoteConfig.fromJson`
(`lib/core/config/remote_config.dart`). It drives **two** things: the API base URL, and
the in-app update prompt.

```json
{
  "latest_version": "1.0.0",
  "api": {
    "base_url": "https://api.evotech-sys.com/api/fawateer"
  },
  "downloads": {
    "arm64-v8a": "https://evotech-sys.com/downloads/fawateer-1.0.0-arm64-v8a.apk",
    "armeabi-v7a": "https://evotech-sys.com/downloads/fawateer-1.0.0-armeabi-v7a.apk"
  },
  "update_notes": [
    "أول إصدار عام",
    "تجربة مجانية 30 يوماً"
  ],
  "support": {
    "email": "mohamad.hasan.it.96@gmail.com",
    "whatsapp": "963959027196",
    "telegram": "https://t.me/+963959027196"
  }
}
```

| Key | Effect if wrong/missing |
|---|---|
| `api.base_url` | **The dangerous one.** Empty ⇒ assignment skipped ⇒ app silently keeps `defaultBaseUrl` |
| `latest_version` | Update prompt never fires |
| `downloads` | Update prompt has no APK to offer |
| `update_notes` | "What's new" is empty |
| `support` | Falls back to the constants baked into `ApiConfig` |

Parsing is **defensive by design** — a malformed field degrades to a default rather than
throwing, so a bad publish cannot hard-fail startup. The flip side is that mistakes are
**silent**: after editing, verify from the app, not from the file.

> **Status: the generator exists, the links do not yet.** `latest_version`,
> `update_notes`, `support` and `downloads` are dashboard-editable, but `downloads`
> is still populated by hand. Wiring it to the Download Center's published
> artifacts — so publishing a release updates the links with no config edit at all
> — is the remaining step.
>
> **This is the same data the Download Center already models** — releases, per-platform
> artifacts, and a `notes` column. Generating this JSON from the dashboard, rather than
> hand-editing it, is the natural end state. See
> [`ROADMAP-DASHBOARD.md`](../ROADMAP-DASHBOARD.md) Phases 3–4.
