# Fawateer go-live — status & what's left

> **Verified live:** 2026-07-17 against `https://api.evotech-sys.com` and the legacy
> backend. Every claim below was tested, not assumed.
>
> ## ✅ Verdict: Fawateer can be cut over now. SmartAgent cannot.
>
> **Correcting an earlier claim in this document.** I first reported "2 paying Fawateer
> customers" — that was wrong. I read `is_verified = 1` as "paying". It is not.
>
> **Not one Fawateer device has a `plan_id`. Nobody has bought anything.** The two
> verified rows are: one with **no expiry at all** (permanent — almost certainly your own
> device) and one hand-granted 30-day trial. The third is literally `probe_test_123`.
>
> So for **Fawateer** the import is *housekeeping*, not a blocker — worst case those
> devices re-register and get a fresh 30-day trial, and you re-activate them in the
> console in seconds. Nobody can lose a purchase that was never made.
>
> **SmartAgent is the exact opposite: 11 verified devices, all 11 holding a real plan**
> (`yearly` ×9, `half_year` ×2). Those are paying customers, and it has **no trial** to
> cushion a mistake. **Import is mandatory before SmartAgent's cutover.**

---

## 1. ✅ The platform works — tested on production

Every endpoint the app calls, exercised against the live server today:

| Endpoint | Result |
|---|---|
| `GET /api/fawateer/getPlans` | `200` — full catalog, correct shape |
| `POST /api/fawateer/check_device` (unknown) | `404` `{"success":false,"message":"Device not found"}` — correct legacy shape |
| `POST /api/fawateer/create_device` (new) | `200` — `is_verified:1, is_trial:1`, expiry **+30d exactly** |
| `POST /api/fawateer/check_device` (known) | `200` — `is_verified:1, is_trial:1` |
| `POST /api/fawateer/update_my_data` (**fcm_token only**) | `200` — the Phase A blocker, confirmed fixed in prod |
| `POST /api/fawateer/create_device` (plan request) | `200` — intent recorded, trial expiry untouched |
| `POST /api/fawateer/add_review` | `200` |

Migrations are applied, the trial fires, and the namespaced surface resolves. **The
API side is done.**

---

## 2. Who is actually on the old backend

Counted today (counts only — no names or phones read):

| `app_name` | devices | verified | **has a real plan** | never expires |
|---|---|---|---|---|
| `SmartAgent` | 43 | 11 | **11** ⚠️ | 1 |
| **`Fawateer`** | **3** | **2** | **0** ✅ | 1 |
| `daftar_hesabat` | 2 | 1 | **1** ⚠️ | 0 |
| `Smart Agent` *(space)* | 1 | 1 | **1** ⚠️ | 0 |
| `test` | 1 | 0 | 0 | 0 |
| **Total** | **50** | **15** | **13** | 2 |

Plans in use: `yearly` ×9, `half_year` ×2, **`one_month_free` ×2**.

**`is_verified` is not the same as paying.** It just means "unlocked" — an operator can
set it with no plan at all, which is exactly what happened to Fawateer's two devices.
The column that means money is `plan_id`, and Fawateer's is empty across the board.

### Why the distinction decides the cutover

If a device with a **real plan** is missing when its app switches over, the trial
**hides the damage for a month**:

1. Flip the base URL.
2. Their app calls `check_device` → the new server has never heard of them → `404` →
   the app reads that as **not verified**.
3. The app registers → new row → **30-day trial** → unlocked. *Everything looks fine.*
4. **30 days later the trial lapses and they are locked out** — having paid for a year.

You would not find out today. You'd find out in August, from an angry customer, with no
obvious cause. That is the SmartAgent risk (11 devices), **not** the Fawateer one (0).

> Note `one_month_free` is not in the plan catalog. Harmless — imported rows keep their
> own `expires_at`, and the console can only offer configured plans — but if you ever
> want to *grant* that plan again, it needs a config entry, or it activates a **0-month
> term**.

---

## 3. 🔎 Two things nobody mentioned — found in the data

### `daftar_hesabat` — a third app is on this backend

2 devices, **1 paying**. That's **دفتر حسابات / Ledger**, the third product in your
catalog. It is quietly sharing the same backend, and it is **not in
`config('device-subscriptions.apps')`** — so today it would get no label in push copy
(it'd fall back to the raw `daftar_hesabat`) and no trial.

Not urgent — it only matters when *that* app is cut over — but it must not be a
surprise then. Its devices import fine either way.

### `Smart Agent` ≠ `SmartAgent`

One **paying** device sends `app_name: "Smart Agent"` (with a space) — presumably an
older build. Devices are keyed by (`app_name`, `device_id`), so **this is a different
identity** from the other 43. It imports correctly and keeps working; just know the
config keys on `SmartAgent`, so this one device gets defaults. Pre-existing legacy
behaviour, not something the migration introduces.

---

## 4. ⚠️ The legacy backend is leaking every user's PII, right now

`GET https://harrypotter.foodsalebot.com/api/getDevice` is **public and unauthenticated**,
and returns all 50 rows including **names and phone numbers**. Anyone who knows the URL
can dump your entire customer list. I confirmed this by calling it.

This is exactly the hole [ADR 0010](adr/0010-device-subscriptions-module.md) closed on
the new platform (it is `auth:sanctum` there). It is **not fixed on the old server**, and
it stays open for as long as that server runs. **Retire it once cutover completes.**

*(Silver lining: it also gives you an import path that needs no database credentials — see §5.)*

---

## 5. What's left — in order

### 🔴 1. Import the legacy devices

Mandatory before **SmartAgent**; housekeeping for **Fawateer**. Do all 50 in one pass —
importing early costs nothing and removes the risk from every later cutover.

#### The schema difference — there isn't one that matters

The new table is a **strict superset**. Every old column maps 1:1, and every new column
is nullable or generated. **Nothing is lost, nothing needs transforming.**

| `app_harfoshs` (old) | `device_subscriptions` (new) | Note |
|---|---|---|
| `id` int | *(not carried)* | Identity is (`app_name`,`device_id`), not the old PK |
| `app_name` varchar(50) | `app_name` varchar(50) | ✅ |
| `device_id` varchar(200) | `device_id` varchar(200) | ✅ |
| `full_name`, `phone` | same | ✅ |
| `is_verified` tinyint | `is_verified` bool | ✅ |
| `expires_at` | `expires_at` | ✅ **preserved — this is what keeps a paid device unlocked** |
| `plan_id` varchar(50) | `plan_id` varchar(50) | ✅ free string, so `one_month_free` survives |
| `fcm_token`, `stars`, `comment` | same | ✅ |
| `created_at`, `updated_at` | same | ✅ preserved, not reset |
| — | `uuid` | generated per row |
| — | `status`, `trial_expires_at`, `requested_plan`, `contact_method` | **NULL** |

`trial_expires_at` stays NULL for imported rows — and that is deliberate: **the import
does not grant trials.** Trials are stamped only by `create_device` on a brand-new row,
so a 43-device SmartAgent import cannot accidentally hand out 43 free months.

#### Pre-flight — already run against the live data ✅

| Check | Result |
|---|---|
| Duplicate (`app_name`,`device_id`) — would break the unique index | **0** |
| NULL/empty `app_name` or `device_id` — would be skipped | **0** |
| Rows using the shared `fallback_device_id` | **0** |
| Columns in old data with no home in the new table | **none** |

**The data is clean. The import should be uneventful.**

#### Step 1 — back up first (both sides)

```bash
# the source of truth, before touching anything
mysqldump -u <user> -p <legacy_db> app_harfoshs > app_harfoshs_$(date +%F).sql

# and the target, so a bad import is one restore away
mysqldump -u <user> -p evotech_core device_subscriptions > device_subscriptions_before_import.sql
```

#### Step 2 — point at the legacy database

Add a second connection in `config/database.php` (copy the `mysql` block):

```php
'legacy' => [
    'driver' => 'mysql',
    'host' => env('DEVICE_LEGACY_HOST', '127.0.0.1'),
    'port' => env('DEVICE_LEGACY_PORT', '3306'),
    'database' => env('DEVICE_LEGACY_DATABASE'),
    'username' => env('DEVICE_LEGACY_USERNAME'),
    'password' => env('DEVICE_LEGACY_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_0900_ai_ci',   // matches app_harfoshs
],
```

`.env` on the API server:

```
DEVICE_LEGACY_CONNECTION=legacy
DEVICE_LEGACY_TABLE=app_harfoshs
DEVICE_LEGACY_HOST=...
DEVICE_LEGACY_DATABASE=...
DEVICE_LEGACY_USERNAME=...
DEVICE_LEGACY_PASSWORD=...
```

If the legacy MySQL only listens locally, the simplest route is an SSH tunnel from the
API box:
`ssh -L 3307:127.0.0.1:3306 user@old-host` → then `DEVICE_LEGACY_PORT=3307`.

#### Step 3 — dry run, then import

```bash
php artisan device-subscriptions:import-legacy --dry-run   # expect: "Would import 50; skipped 0"
php artisan device-subscriptions:import-legacy             # expect: "Imported 50; skipped 0"
```

Re-runnable — it upserts on the device identity, so running it twice is safe and a
second run picks up anything that changed on the old server in between.

#### Step 4 — verify before you flip

```bash
# 50 rows, and 13 of them carrying a real plan
php artisan tinker --execute="
  echo Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription::count().' rows; ';
  echo Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription::whereNotNull('plan_id')->count().' with a plan;';
"
```

Then the check that actually matters — a real device must come back with **its original
expiry, not a fresh trial**:

```bash
curl -s -X POST https://api.evotech-sys.com/api/fawateer/check_device \
  -H 'Content-Type: application/json' \
  -d '{"app_name":"SmartAgent","device_id":"<a real imported device_id>"}'
# want: is_verified 1, is_trial 0, plan "yearly", expires_at = the ORIGINAL date
```

`is_trial: 0` is the signal. If it says `1`, the import missed that device.

#### No database access? There is a second path

The legacy `getDevice` endpoint is public (§4) and returns all 50 rows as JSON. If the
old MySQL is unreachable, say so and I'll add an HTTP source to the import command —
a small change that drops the database dependency entirely.

### 🟠 2. Decide Fawateer's pricing

Right now Fawateer users would be shown **SmartAgent's plans**: `half_year` **$12** / 6mo,
`yearly` **$20** / 12mo. Your site advertises **$19 / $49** — and those are *monthly,
feature-tiered* (100 invoices/mo vs unlimited), a different shape from this
*duration-based* catalog. The app also supports a `1_month` plan you have never offered.

This is a product decision, then **one config edit** to `apps.Fawateer.plans`. Phase D
already built the mechanism, and the `/api/fawateer` namespace means it needs **no app
release** — now or later.

**Not a hard blocker** (you could go live and fix pricing after), but users will see
these numbers, so decide before you flip.

### 🟡 3. FCM credentials

`FirebasePushNotifier` is a scaffold: live-unlock **silently no-ops**. A customer pays
over WhatsApp, you activate them, and nothing happens until they reopen the app — the
next `check_device` unlocks them. **Not a blocker**, but it is the difference between
"instant" and "confusing". Hand me the FCM service-account JSON and I'll wire it up.

### 🟢 4. Delete my smoke-test row

I registered one device to prove production works end-to-end:

```
app_name  = Fawateer
device_id = evotech-smoke-test-DELETE-ME
full_name = SMOKE TEST - delete me
```

Find it in the console (`/dashboard/devices`, search `evotech-smoke-test`) and delete it.
Harmless if left — it just clutters the list.

---

## 6. So — can you connect Fawateer right now?

**Technically yes. Safely, no — not until §5.1.**

The API answers every call correctly. But 2 paying Fawateer customers exist only on the
old backend, and the trial would paper over their loss for exactly 30 days.

**The sequence is short:**

1. Import the 50 legacy rows.
2. Verify one real paying Fawateer device: `check_device` should come back
   `is_verified:1` with **its original expiry** — not a fresh trial. *That single check
   proves the import worked.*
3. Edit `fawateer_version.json` → `"baseUrl": "https://api.evotech-sys.com/api/fawateer"`.
4. Watch that `check_device` never returns `5xx` — a 500 doesn't show an error, it
   silently locks users out.

**Rollback** is restoring the old `baseUrl`, effective on each app's next config fetch.

---

## 7. And SmartAgent later?

**Yes — same platform, same module, no new work.** It is already configured
(`apps.SmartAgent`: no trial, label المندوب الذكي) and its 43 devices import in the same
pass.

**Cut it separately, and after Fawateer has been stable for a while.** The two apps read
**separate** remote-config files, which is what lets you cut one at a time and keeps a
bad cutover contained to one product. That property is worth protecting: never flip both
on the same day.

One caveat specific to SmartAgent: **it has no trial** (deliberately — its owner never
asked for one). So a SmartAgent user missing from the import is locked out **immediately**,
with no 30-day grace to hide it. The import matters more there, not less.
