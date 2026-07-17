# Fawateer ⇄ EVOTECH — Device API contract

> The contract the shipped **Fawateer** app speaks to `evotech-core`
> (`Modules\DeviceSubscriptions`, [ADR 0010](../adr/0010-device-subscriptions-module.md)).
> **Verified:** 2026-07-17 — every sample below is a real response captured from a
> running API, not a sketch.
> **Audience:** whoever points the app at the new server, and anyone touching either side.

> ## 🚨 NOT DEPLOYED YET — do not repoint the app
>
> As of 2026-07-17, `https://api.evotech-sys.com` is **healthy but running older code**:
> `/api/v1/health` and `/api/v1/products` answer `200`, while **every endpoint in this
> document returns `404`.** The VPS has not pulled `main`.
>
> **Repointing the Drive JSON now would lock out every user.** `check_device` would
> 404, the app reads any non-2xx as "not verified", and the entire install base gates
> to the activation screen — silently, with no error to explain it.
>
> Deploy and re-verify first (§8). The commands are in that section.

---

## 0. TL;DR — the app needs no code changes

The platform was built to match the app **exactly as shipped**. Going live is
**one edit** to the app's remote-config JSON:

```jsonc
// fawateer_version.json — Drive file id 1pVMkNYKAGjiO8tRSG3nEcVGvEQS8xcVk
{
  "baseUrl": "https://api.evotech-sys.com/api/fawateer"
}
```

Nothing else moves. No store release. **Rollback is putting the old value back.**

Use the **`/api/fawateer`** namespace, not bare `/api` — same contract, but it lets the
platform serve Fawateer its own plans later without ever touching the app again
(§7). Both work today and return identical data.

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

**Order is not negotiable.** Step 4 is the only irreversible-feeling one, and it is the
last for a reason: everything before it is invisible to users.

### 1. Deploy the API — **not done yet**

On the VPS, in the Laravel site (`api.evotech-sys.com`):

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

### 3. Import the legacy rows

```bash
DEVICE_LEGACY_CONNECTION=legacy php artisan device-subscriptions:import-legacy --dry-run
DEVICE_LEGACY_CONNECTION=legacy php artisan device-subscriptions:import-legacy
```

Re-runnable (upserts on the device identity). Do this **before** step 4 so existing
subscribers are already known the moment their app switches over — otherwise a paying
customer's first `check_device` on the new server 404s and locks them out.

### 4. Flip the base URL

Only now, edit `fawateer_version.json` (Drive id `1pVMkNYKAGjiO8tRSG3nEcVGvEQS8xcVk`):

```jsonc
{ "baseUrl": "https://api.evotech-sys.com/api/fawateer" }
```

**Cut Fawateer and SmartAgent separately** — they read separate config files, so a bad
cutover stays contained to one app. **Rollback is restoring the one old value**, and it
takes effect on each app's next config fetch.

### 5. Watch

The first thing to check is that `check_device` keeps answering `200`/`404` and never
`5xx`. A 500 here does not show an error to users — it silently locks them out.
