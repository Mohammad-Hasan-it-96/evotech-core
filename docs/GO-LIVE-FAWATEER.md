# Fawateer go-live — status & what's left

> **Verified live:** 2026-07-17 against `https://api.evotech-sys.com` and the legacy
> backend. Every claim below was tested, not assumed.
> **Verdict:** ❌ **Do not repoint the app yet.** The platform is ready; the *data* is not.
> One step stands between you and a safe cutover — and skipping it breaks paying
> customers **silently, 30 days from now**.

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

## 2. 🚨 BLOCKER — Fawateer already has paying users on the old backend

Counted on the legacy backend today (counts only, no PII read):

| `app_name` | devices | **`is_verified` (paid)** |
|---|---|---|
| `SmartAgent` | 43 | **11** |
| **`Fawateer`** | **3** | **2** |
| `daftar_hesabat` | 2 | **1** |
| `Smart Agent` *(with a space)* | 1 | **1** |
| `test` | 1 | 0 |
| **Total** | **50** | **15** |

**Fawateer is not a fresh launch — it has 3 devices, 2 of them paid.**

### Why flipping now is worse than it looks

The trial **hides the damage for a month**:

1. Flip the base URL.
2. A paying customer's app calls `check_device` → the new server has never heard of
   them → `404` → the app reads that as **not verified**.
3. The app registers → new row → **30-day trial** → unlocked. *Everything looks fine.*
4. **30 days later their trial lapses and they are locked out** — having paid for a year.

You would not find out today. You'd find out in August, from an angry customer, with
no obvious cause. **Import first and none of this happens.**

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

### 🔴 1. Import the legacy devices — **the only true blocker**

```bash
DEVICE_LEGACY_CONNECTION=legacy php artisan device-subscriptions:import-legacy --dry-run
DEVICE_LEGACY_CONNECTION=legacy php artisan device-subscriptions:import-legacy
```

Re-runnable; upserts on (`app_name`, `device_id`). Bring **all 50** rows, not just
Fawateer's — SmartAgent's 11 paying users need them there before *its* cutover, and
importing early costs nothing.

**Needs a DB connection to the legacy MySQL.** Still unanswered: *is
`harrypotter.foodsalebot.com` yours, and is its database reachable?*

- **If yes** → add a `legacy` connection to `config/database.php` and run the above.
- **If no** → the public `getDevice` endpoint (§4) exposes every row as JSON. Say the
  word and I'll add an HTTP source to the import command; it's a small change and it
  removes the dependency on database access entirely.

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
