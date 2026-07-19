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
the device endpoints for future app versions.

The enveloped staff API (`auth:sanctum`), keyed by the model's `uuid`, backs the operator console
at `evotech-web` `/dashboard/devices`:

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/device-subscriptions` | Paginated. Filters: `status` (`pending` = the work queue), `app_name`, `q` (searches `device_id`, `full_name`, `phone`). |
| GET | `/api/v1/device-subscriptions/plans` | The catalog to activate against — the same one the apps see, so an operator cannot pick a plan id the device would not recognise. |
| POST | `/api/v1/device-subscriptions/{deviceSubscription}/activate` | Activate/extend. **Closes the pending request** (`status → null`); `requested_plan` is kept, since the operator may sell a different plan. |
| POST | `/api/v1/device-subscriptions/{deviceSubscription}/decline` | Reject the request (`status → declined`). **Touches nothing else** — a device holding a trial or a paid plan keeps it. 422 unless a request is actually open. |
| DELETE | `/api/v1/device-subscriptions/{deviceSubscription}` | Remove a row. 422 for a device that still has access unless `?force=true`. |

The catalog editor behind `/dashboard/plans`:

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/device-apps` | Every app with its terms, plan count, and remote config. |
| PATCH | `/api/v1/device-apps/{deviceApp}` | `label`, `trial_days`, `uses_shared_plans`, `product`, plus the remote-config fields below. **`name`/`slug` are not accepted.** |
| GET | `/api/v1/device-plans` | One scope: `?app=<uuid>` for that app's own plans, omitted for the shared catalog. **Includes disabled plans** — unlike `device-subscriptions/plans`, which mirrors what the device sees. |
| POST | `/api/v1/device-plans` | `key` must be unique within its scope. `duration_months` ≥ 1; a 0-month plan expires the moment it is sold. |
| PATCH | `/api/v1/device-plans/{devicePlan}` | **`key` and `app` are not accepted** — both would orphan existing holders. |
| DELETE | `/api/v1/device-plans/{devicePlan}` | 422 when any device holds the plan, naming the count. **No `force` flag**: unlike a device delete the damage is silent and deferred, and disabling achieves the operator's actual goal. |

### The purchase-intent lifecycle

`status` tracks the *request*, never the subscription — a device can hold a paid
plan and still have an open request, and the two answer different questions.

```
        app files intent            operator activates
null ──────────────────► pending ──────────────────────► null   (+ plan, expiry, push)
 ▲                          │
 │                          └──────► declined  (operator declines; access untouched)
 │                                      │
 └──────────────────────────────────────┘  customer asks again → pending
```

Declining exists because the console could previously only say *yes*: the only way
to clear a request the operator would never fulfil was to activate it — selling a
plan to close a ticket — so junk accumulated and `status=pending` stopped meaning
"work to do". No push is sent: neither shipped app understands a "declined" type,
and the app has already funnelled the user to WhatsApp/Telegram, so the operator
is in conversation with them anyway.

## Plans

Stored in **`device_plans`** and edited from the dashboard (`/dashboard/plans`) — they began as a
literal array in `config/device-subscriptions.php` and were migrated out verbatim (`half_year` —
6 months, $12; `yearly` — 12 months, $20, recommended). Prices change with **no deploy at all**.
The `getPlans` payload shape is unchanged and pinned in `DevicePlan::toLegacyArray()`.

**`plan_key` is a contract, not a label.** Device rows store it in `plan_id` and renewal resolves
a term by matching it, so it is immutable after creation and a referenced plan cannot be deleted
(the API refuses with a 422 naming the subscriber count). Retiring a price means **disabling** the
plan: hidden from the store, still resolvable for existing holders. Re-keying or deleting one
would turn a paying customer's next renewal into a 0-month term — expired the moment it is
granted.

`config` remains as the **fallback** when the tables are empty or unmigrated, so the apps keep
selling through a deploy window and an accidentally-emptied catalog degrades to the last
known-good prices rather than offering nothing. The catalog is cached (`DeviceCatalogStore`,
300 s) and flushed on every write, so an operator's edit is live on the next device poll.

**Per-app catalogs.** `getPlans` is the one device endpoint carrying no `app_name`, so on the
shared `/api/*` surface it cannot tell the apps apart. The whole shim is therefore also served
under **`/api/{slug}/*`** (`device_apps.slug`) from one route definition; pointing an app's
remote-config `baseUrl` at `…/api/fawateer` namespaces every call it makes, with **no store
release**. Clear an app's `uses_shared_plans` to give it its own catalog — leave it set and it
reads the shared list (what both apps do today). That flag is deliberate rather than inferred
from "has no plan rows": *defers to shared* and *sells nothing* are different answers, and
collapsing them would hand an app the wrong prices. An unknown slug serves the shared catalog
rather than erroring, so a typo'd base URL degrades to current behaviour. `{app}` excludes
version segments (`v1`, `v2`…), so the namespace can never shadow the platform API.

`device_apps.name` and `.slug` are **immutable** — `name` is the literal string shipped builds
send and every row is matched on it; `slug` is the base URL they are pointed at. Neither is
recoverable from the dashboard, so neither is offered there.

Activation resolves a plan's term in **the device's own** app catalog — the same id may mean a
different number of months per app.

## The startup remote-config

`GET /api/{slug}/remote-config` (public, unauthenticated — the app calls it before
it has a base URL, let alone a token). Returned **bare**, with no `{data}` envelope:
the shipped parsers read the top-level keys directly, so this is the one place in
the module where the platform envelope is deliberately not used. Full contract:
[`docs/api/fawateer-device-contract.md`](../api/fawateer-device-contract.md) §9.

Stored on `device_apps` and edited from `/dashboard/plans` → **App config**. It
replaces a hand-edited static file in evotech-web, which now proxies this endpoint
at the same URL.

**Everything here fails silently.** Both apps parse defensively — a malformed field
degrades to a default rather than throwing — so a mistake surfaces as an update
prompt that never fires, not as an error anyone sees. Hence:

| Field | Guard, and what it prevents |
|---|---|
| `latest_version` | Digits and dots only. Compared component-wise with `int.tryParse(part) ?? 0`, so `1.2.0-beta` reads that component as **0** and the update becomes permanently invisible. |
| `api.base_url` | **Never emitted empty** — falls back to `{app.url}/api/{slug}`. Fawateer would silently keep its compiled-in default; SmartAgent is worse and *resets* to the legacy `harrypotter.foodsalebot.com` host. |
| `downloads` | Keys restricted to the ABIs the apps actually look up. A key is matched exactly against the device's reported ABI, so a typo is an update no device can find. `default` is the only route to an APK for an x86 device. |
| `update_notes` | Strings only, serialized as a JSON list. Fawateer's parser drops a bare string outright. |

Firebase credentials are **not** part of this — see the note below.

## Per-app settings & the free trial

One deployment serves several shipped apps, told apart only by the `app_name` they send.
They do **not** share policy — **`device_apps`** keys settings per app (matched
case-insensitively), editable from the dashboard:

| App | `trial_days` | `label` |
|---|---|---|
| `Fawateer` | 30 | فواتير |
| `SmartAgent` | 0 (none) | المندوب الذكي |

Firebase credentials stay in **config** and are deliberately *not* part of the editable catalog:
the value is a path to a service-account private key, which has no business being writable from a
browser session or readable out of the database.

An app absent from the table gets **no trial** and falls back to its raw `app_name` as the label.
The trial is Fawateer's design; granting it platform-wide would silently change SmartAgent's
monetization.

**The trial is a server-stamped expiry, not a second system.** First registration sets
`is_verified`, `expires_at` **and** `trial_expires_at` to `now + trial_days`; the app gates on
`expires_at` exactly as it would a paid one. It is **granted only on row creation**, which is
what makes it unfarmable — Android's `ANDROID_ID` survives uninstall/data-clear, so a reinstall
finds the existing row and gets nothing (imported legacy rows are never retro-granted). Operator
activation converts it by setting `plan_id`, which ends the trial by definition;
`trial_expires_at` is kept as the record that a trial was spent.

## Domain, jobs & extension points

| Class | Notes |
|---|---|
| `Domain\Models\DeviceSubscription` | `HasUuid` route key; **no** tenancy. `isActive()` = verified **and** unexpired. `isOnTrial()` = has a `trial_expires_at`, no `plan_id` yet, still active — so activation ends the trial by setting `plan_id`, with no flag to rewrite. `scopeForDevice()`. |
| `Domain\Enums\DevicePlan` | `half_year` / `yearly` + `durationMonths()`. |
| `Application\Services\DeviceSubscriptionService` | register/check/update/review/activate/decline/list + `sweepExpiryReminders()`. Emits `DeviceActivated`. |
| `Domain\Models\DeviceApp` / `DevicePlan` | the editable catalog. `DevicePlan::toLegacyArray()` pins the `getPlans` shape, including emitting a whole price as an int (`12`, not `12.0`) — the shipped parsers were written against integer prices and cannot be updated remotely. |
| `Application\Services\DevicePlanCatalog` | read model over the plan catalog. |
| `Application\Services\DeviceCatalogStore` | loads + caches the catalog; falls back to config when the tables are empty/unmigrated. |
| `Application\Services\DeviceCatalogService` | catalog writes, audit trail, cache flush, and the delete guard (`subscriberCount()`). |
| `Domain\Contracts\DevicePushNotifier` | push abstraction; `NullPushNotifier` (safe default), `FirebasePushNotifier` (FCM HTTP v1 — set `DEVICE_PUSH_NOTIFIER=firebase`). |
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

## Push notifications (FCM)

Sends over the **FCM HTTP v1** API, matching the payload the shipped apps already
parse — `data.type` plus `notification.title/body`:

```json
{"message":{"token":"…","notification":{"title":"…","body":"…"},
            "data":{"type":"new_plan_activated"},"android":{"priority":"high"}}}
```

**Each app is its own Firebase project**, so credentials are per-app, not global —
a service account for one cannot reach the other's devices (FCM answers `404
UNREGISTERED`). That is why `DevicePushNotifier::send()` takes `$appName` first.

| App | Firebase project | Env var for the service-account path |
| --- | --- | --- |
| Fawateer | `fawateer-4c9bc` | `FIREBASE_CREDENTIALS_FAWATEER` |
| SmartAgent | `smart-agent-5b153` | `FIREBASE_CREDENTIALS_SMARTAGENT` |

Set `DEVICE_PUSH_NOTIFIER=firebase` to enable. The JSON key holds a private key:
store it **outside the repo** (e.g. `~/secrets/`, mode 600) and never commit it.
An app with no credential logs a warning and sends nothing, so a half-configured
deployment degrades instead of erroring.

The OAuth2 token is cached per app for 55 minutes — the expiry sweep sends to many
devices per run, and minting a JWT per message (as the legacy backend did) would
turn one token exchange into hundreds.

**Failures are logged, never thrown.** The apps re-check `check_device` on resume,
so a lost push delays an unlock rather than losing it — while throwing would fail
the operator's activation request *after* the subscription was already committed.

### Which types each app understands

| `type` | SmartAgent | Fawateer |
| --- | --- | --- |
| `new_plan_activated` | ✅ | ✅ |
| `still_7_days` / `still_3_days` / `still_1_day` | ✅ | ❌ ignored |
| `plan_deactivated` | ✅ | ❌ ignored |

Activation — the live unlock — uses the one type both accept. The expiry reminders
still reach Fawateer as a **tray banner** (Android renders the `notification`
block regardless), but its handler ignores the type, so nothing is shown while the
app is in the foreground. Fawateer is unreleased, so this is fixable on either side.

`android.notification.channel_id` is deliberately **not** sent: SmartAgent already
routes to `subscription_channel` via a manifest default, and Fawateer declares no
channel at all — naming a channel that does not exist makes Android drop the
notification outright.

## Follow-ups

- Retire the legacy shim once a new app version ships (versioned API + product key).
- Prune `fcm_token` when FCM reports `UNREGISTERED`, so dead tokens stop being retried
  every sweep (currently logged only — pruning is a data change, not a transport concern).
- Decide whether Fawateer should learn the four expiry `type`s, or the sweep should
  skip types an app cannot handle.
- Source `app-download` version/links from the Download Center ([ADR 0008](../adr/0008-download-center-delivery.md)) instead of a placeholder config.
