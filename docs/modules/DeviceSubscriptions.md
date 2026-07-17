# Module: DeviceSubscriptions

Device-keyed subscriptions for **shipped consumer apps** — the successor to the legacy
**SmartAgent** (`app_harfoshs`) backend ([ADR 0010](../adr/0010-device-subscriptions-module.md)).
Unlike [Subscriptions](subscriptions.md) (subscriber = a **Company**, staff-managed), the
subscriber here is a **device** that self-registers from the app with no login and **no
tenant**. That different subscriber is why this is its own module rather than an extension of
Subscriptions.

A device is identified by the **(`app_name`, `device_id`) pair** — the `app_name` column means
one deployment can serve several apps, which is why the module is named generically. The model
is deliberately **non-tenant**: `device_subscriptions` has **no `company_id` / `BelongsToCompany`**.

## Why a compatibility shim

The SmartAgent app is **already in users' hands** and reads its API base URL from a Google
Drive JSON config, so the platform can take over by editing **one config value** — *if* it
serves the exact endpoints the app calls. The shipped app calls **unversioned `/api/*` paths
with no auth token**, so the module exposes three route groups:

1. **Legacy shim** (`/api/*`, unversioned) — the 10 endpoints replicated byte-for-byte in the
   legacy JSON shapes (not the platform envelope). This is the documented, **time-boxed
   exception to §7**; it is removed once a new app version adopts the versioned API.
2. **Versioned device API** (`/api/v1/device/*`, `auth:product` — [ADR 0004](../adr/0004-product-to-platform-auth-api-keys.md))
   — the same controllers/shapes, authenticated, for the next app release.
3. **Versioned staff API** (`/api/v1/device-subscriptions`, `auth:sanctum`) — platform
   envelope, for the dashboard.

## Endpoints

### Legacy shim — device self-service (public)

| Method | Path | Controller | Notes |
|---|---|---|---|
| POST | `/api/create_device` | `DeviceController@createDevice` | Register a device, refresh its `fcm_token`, **or file a plan request** (`requested_plan` + `contact_method` + `status`). Idempotent on the pair. Returns `is_verified`, `is_trial`, `expires_at`, `plan`, `fcm_token`, `server_time`. |
| POST | `/api/check_device` | `DeviceController@checkDevice` | Status; `is_verified` forced `0` past `expires_at`. Returns `is_trial` + `server_time`. `404` if unknown. |
| POST | `/api/update_my_data` | `DeviceController@updateMyData` | **Partial** update — any of name/phone/token. Only what's sent is written. |
| POST | `/api/add_review` | `DeviceController@addReview` | Store `stars` (1–5) + `comment`. |
| GET | `/api/getPlans` | `PlanController@index` | Static plan catalog from config. |
| GET | `/api/app-download` | `AppDownloadController@index` | Version + APK links (JSON; the HTML page lives in evotech-web). |

### Legacy shim — admin (now `auth:sanctum`)

| Method | Path | Controller | Notes |
|---|---|---|---|
| POST | `/api/activateDevice` | `DeviceAdminController@activate` | Activate/extend by **`device_id` only** (legacy behavior); unknown plan → 0-month term. |
| GET | `/api/getDevice` | `DeviceAdminController@index` | List all devices (legacy shape). |

> **Security fix ([ADR 0010](../adr/0010-device-subscriptions-module.md)):** in the legacy
> backend `activateDevice` and `getDevice` were **public** (anyone could self-activate or dump
> every user's phone). They now require staff auth. The legacy public
> `test_send_notifications` blast is **not ported** — expiry reminders are a scheduled command.

### Versioned twins

`/api/v1/device/{register,check,profile,review,plans}` (`auth:product`, `throttle:product`) mirror
the device endpoints for future app versions. `/api/v1/device-subscriptions` (index) and
`/api/v1/device-subscriptions/{deviceSubscription}/activate` (`auth:sanctum`) are the enveloped
staff API, keyed by the model's `uuid`.

## Plans

Static in `config/device-subscriptions.php` (`half_year` — 6 months, $12; `yearly` — 12 months,
$20, recommended), preserving the exact `getPlans` payload. Prices change via config, no deploy.

## Domain, jobs & extension points

| Class | Notes |
|---|---|
| `Domain\Models\DeviceSubscription` | `HasUuid` route key; **no** tenancy. `isActive()` = verified **and** unexpired. `isOnTrial()` = has a `trial_expires_at`, no `plan_id` yet, still active — so activation ends the trial by setting `plan_id`, with no flag to rewrite. `scopeForDevice()`. |
| `Domain\Enums\DevicePlan` | `half_year` / `yearly` + `durationMonths()`. |
| `Application\Services\DeviceSubscriptionService` | register/check/update/review/activate/list + `sweepExpiryReminders()`. Emits `DeviceActivated`. |
| `Application\Services\DevicePlanCatalog` | read model over the config plans. |
| `Domain\Contracts\DevicePushNotifier` | push abstraction; `NullPushNotifier` (safe default), `FirebasePushNotifier` (scaffold, pending FCM creds — set `DEVICE_PUSH_NOTIFIER=firebase`). |
| `Console\SweepDeviceExpiryCommand` | `device-subscriptions:sweep-expiry` — **scheduled daily**; sends expiry pushes at expired/7/3/1 days (replaces the legacy cron endpoint). |
| `Console\ImportLegacyDevicesCommand` | `device-subscriptions:import-legacy` — one-off, re-runnable import of `app_harfoshs` from a separate DB connection (`DEVICE_LEGACY_CONNECTION`); `--dry-run` supported. |

- Cross-module coordination is by **event** only (§2.1): `DeviceActivated` is available for
  `Notifications`/`Audit` to listen to; the module depends on neither.

## Cutover

Deploy the module → verify each endpoint against the legacy `docs/SUBSCRIPTION_API.md` →
`device-subscriptions:import-legacy` the 50 rows → **then** repoint the Drive JSON base URL.
Rollback is flipping that one value back.

## Tests

`DeviceSubscriptionApiTest` covers the shim (register new/idempotent, check active/expired/404,
plans, review), the admin auth gate + activation math, the enveloped staff listing, and the
expiry-sweep command.

## Follow-ups

- Retire the legacy shim once a new app version ships (versioned API + product key).
- Implement real FCM sending in `FirebasePushNotifier` (pending credentials).
- Source `app-download` version/links from the Download Center ([ADR 0008](../adr/0008-download-center-delivery.md)) instead of a placeholder config.
