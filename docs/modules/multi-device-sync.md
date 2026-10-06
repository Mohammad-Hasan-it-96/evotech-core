# Feature: Multi-device sync (ADR 0011)

Offline-first sync for the shipped **Fawateer** POS so **one subscription can span
several devices** — a shop's phone, tablet, and counter terminal share one set of
invoices and converge within ~30 s of each other. It lives inside
[DeviceSubscriptions](DeviceSubscriptions.md) (same non-tenant world, same shipped
app) and is governed by [ADR 0011](../adr/0011-multi-device-sync.md).

**Everything here is additive.** No legacy shim route changes; Fawateer 1.0.1 —
which is in a real shop today and cannot be updated remotely — keeps working
byte-for-byte against the licensing endpoints. A device that never enrolls a seat
never touches any of this.

## The shape: one business, N devices

The subscriber for sync is a **business** (`device_businesses`), not a device. A
business owns a seat allowance, the subscription state (verification/expiry/plan),
and its own append-only change log. Each enrolled device holds a **seat**
(`device_seats`) — its durable per-device credential — and the first device is the
**owner**.

`device_businesses` is deliberately **non-tenant**, exactly like the rest of the
module: a consumer shop has no Company and no login. It is the **scoping key for
all sync data**, and keeping one business's financial records invisible to every
other is the single most important security property of the whole feature.

The legacy single-device rows link up via a nullable `device_subscriptions.business_id`,
so the existing licensing world and the new sync world coexist without a rewrite.

## Why a third auth guard

Neither existing guard isolates a business:

- `auth:sanctum` is for **humans** (staff/dashboard).
- `auth:product` ([ADR 0004](../adr/0004-product-to-platform-auth-api-keys.md)) is
  **one shared key per product** — every Fawateer install would present the same
  key and could not be told apart, let alone scoped to a shop.

So sync adds a per-device **`device-sync`** guard. It copies Gateway's proven
credential design — a token shown once at enrollment, stored only as a SHA-256
hash, with a clear prefix for display and a `revoked_at` kill switch — but keyed
per **device**, and it resolves a `business_id` **server-side**. A request body's
`business`/`app_name` is **never** trusted for authorization (Decision 4). The
resolved seat is exposed to controllers through the published `SyncContext`
contract, so the `DeviceSeat` model never leaks out of the module (mirroring
Gateway's `ProductContext`).

The token is read from `Authorization: Bearer` then `X-Sync-Token`. A rate limiter
(`sync`) is keyed off the authenticated seat, so one device's polling cannot starve
its siblings.

## Two clocks, kept strictly apart

The design turns on never conflating a server cursor with a device clock:

| Clock | Column | Assigned by | Used for | Never used for |
|---|---|---|---|---|
| **Server sequence** | `device_changes.seq` | the server, under a per-business row lock | paging (the pull cursor) | conflict resolution |
| **Hybrid logical clock** | `device_changes.authored_hlc` | the authoring device | last-writer-wins | paging |

`seq` is monotonic and gap-free **per business**. `authored_hlc` is an opaque
`VARCHAR(40)` that sorts lexicographically. The **node id** — `origin_device`,
`VARCHAR(16)` — is the first 16 hex chars of the device id (`SUBSTR(device_id,1,16)`);
it is deterministic, so it needs no mapping table, and it drives the no-echo pull
filter.

## Endpoints

All under `/api/v1/sync/*`, platform envelope. Two surfaces are deliberately
unguarded; everything else requires the `device-sync` guard, and the owner-only
actions additionally require the owner seat.

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/business` | **licensing identity** | **Owner onboarding** — a licensed single device stands up (or recovers) its sync business and gets its owner seat token. The head of the chain: without it no owner seat, no join token, no member. Requires a verified subscription (`SUBSCRIPTION_REQUIRED` otherwise); idempotent (re-call rotates the owner token). May propose a seat `name` (an owner-set name is never overwritten by a re-onboard). |
| POST | `/enroll` | **public** | A joining device redeems a join token — it has no seat yet, so it proves itself with the single-use token in its body. Returns its `sync_token` (once) + the bootstrap handoff. May propose a seat `name`. |
| GET | `/bootstrap/{joinToken}` | **signed** | The bootstrap snapshot download. Credential-less: the short-lived signature minted for the enrolling device *is* the authorization (ADR 0008 style). Deleted after it is sent. |
| POST | `/join-tokens` | owner | Mint a single-use, short-TTL join token (rendered as a QR). |
| POST | `/join-tokens/{joinToken}/bootstrap` | owner | Attach the bootstrap snapshot + cursor `C` + SHA-256 to a token. `{joinToken}` is the **raw `join_token` string** the mint returned (the same value `/enroll` takes), looked up by hash and scoped to the caller's business — never a record uuid, which is never handed out. Multipart fields: file `snapshot`, `cursor` (int ≥0), `snapshot_sha256` (64-hex). Upload this **before** showing the QR so the joiner's snapshot URL exists at redeem time. |
| GET | `/devices` | seat | The business's seats (any authenticated device). Each seat carries its `name` (nullable). `meta` carries the allowance summary — `device_allowance` and `seats_used` (active, non-revoked count) — so a client renders "N of M phones used" from the **server's** numbers, not a value cached at enrollment. |
| PATCH | `/devices/{seat}` | owner | Set a seat's display `name` (the owner seat included). Trimmed, capped at 40 chars, blank → null; not unique. |
| DELETE | `/devices/{seat}` | owner | Revoke a member seat. |
| POST | `/changes` | seat | Push a batch of local edits. |
| GET | `/changes` | seat | Pull changes this device has not yet seen (`?cursor=&limit=`). |

Cross-business access to a `{seat}` or `{joinToken}` returns **404, never 403** — a
valid id from another business must be indistinguishable from a non-existent
record.

## Owner onboarding

Before any join token can exist, a device must become the **owner** of a business.
A licensed single device calls `POST /api/v1/sync/business`, authenticated by its
**licensing identity** (`app_name` + `device_id`) — the only identity it holds
before it has a sync seat. The server requires a **verified** subscription on that
device (`SUBSCRIPTION_REQUIRED` otherwise), then idempotently: creates the
`device_businesses` row seeded from the device's own subscription (expiry, trial,
plan, verification), links `device_subscriptions.business_id`, and mints the owner
seat — returning its durable sync token once. `device_allowance` comes from the
**plan the subscription holds** — `device_plans.device_allowance` (a dashboard-set
column, default 1) via `DevicePlan::allowanceFor()` — falling back to the legacy
`sync.plan_allowance` override map and then `default_allowance` only when no plan row resolves.
Re-calling it **rotates** the owner token rather than creating a second business, so
a reinstall or lost token self-recovers and the non-revocable owner seat (R1) is
never duplicated. `establishBusiness()` on the service remains a lower-level
test/construction primitive; `onboardOwner()` is the real HTTP-backed path.

## Enrollment and the bootstrap handoff

A joining device presents its owner-minted join token to `/enroll` and gets, in one
step, its durable seat credential **and** the seed it needs so it does not come up
blank in an existing shop (Decision 13).

Enrollment enforces, in order:

- **A stable device id.** The legacy shared `fallback_device_id` is rejected
  (`FALLBACK_DEVICE_REJECTED`, 422) — it cannot be a per-device identity.
- **A usable token.** Unknown/expired/already-used → `INVALID_JOIN_TOKEN` (422). A
  join token is a **single-use binding**, not a credential.
- **The seat allowance** (Decision 3), counted at the **business** level, with
  revoked seats freeing their slot → `ALLOWANCE_EXCEEDED` (409). The allowance is
  the total device count including the owner, so the shipped tiers are **1 / 3 / 5**.

A device re-enrolling under the same id **reuses its slot and rotates its
credential** rather than consuming a second seat. The seat's `app_name` is taken
from the business, never from the joining device.

### One subscription covers the seats (Decision 2)

Enrollment also **binds the joining device to the business's licence**, so a member
is covered by the owner's subscription and never has to buy its own. `enroll()`:

- **links** the device's existing `device_subscriptions` row (it registered first
  and took its own trial) by setting `business_id` — no duplicate; or
- **creates** a minimal row when the device never registered — the first-run "join
  a shop" case. That row carries **no name or phone** (we do not invent them; the
  shop is already registered by the owner) and gets **no trial of its own** (the
  trial path runs only through `create_device`).

From then on `check_device`/`create_device` read the **business** as the
authoritative source of `expires_at`/`plan`/`is_verified` for that device (see the
coupling below), so a lapsed or missing own-trial no longer locks a joined phone
out. The allowance is the only thing that limits seats, which is what the tiers
sell.

### The bootstrap cursor is the owner's own cursor `C`

The seed carries a `cursor` — the seq the joiner pulls from after applying the
snapshot. That cursor is the **owner's own local pull cursor `C`**, captured before
its `VACUUM` after a full push+pull — **not** a server-side `last_seq`.

This is load-bearing (the 2026-07-29 correction). A server `last_seq` can be ahead
of what the owner has actually applied: if a *second* device pushed a change the
owner has not yet pulled, that change is in **neither** the owner's snapshot **nor**
the range above a server `last_seq` cursor — it would be lost forever. Seeding from
the snapshot (up to `C`) and then pulling from `C` recovers exactly that sibling's
unpulled change. The distinguishing test enrolls a 3rd device while a 2nd has an
unpulled change and asserts the 3rd recovers it.

### Snapshot custody (H1/H2)

The snapshot is a transient seed database staged on a **private** disk (`device-sync`),
delivered only through a short-lived **signed** URL and **deleted once consumed** —
never retained (a Decision 13 non-goal). Uploading it requires the **owner** seat
(H1 — the join token is a binding, not a credential to upload under). The SHA-256 is
**owner-computed** and echoed to the joiner unchanged, so the integrity check is
end-to-end owner→joiner rather than merely transit (H2). A first/only device joins
an empty shop with cursor 0 and no snapshot.

## Revocation (Decision 5)

Revoking a member seat (owner-only) takes effect the instant it is set: the
credential stops authenticating (401). Two invariants:

- **The owner seat is never revocable** (R1) — it is the business anchor;
  `OWNER_SEAT_NON_REVOCABLE` (422).
- **Revocation is not a remote wipe** (R2) — it stops the credential and frees the
  allowance slot; it never repossesses the device's local data.

**The one coupling to licensing (D):** a revoked seat for an `(app_name, device_id)`
makes the legacy `check_device` report **NOT verified**. This is purely additive —
a device with no seat (every 1.0.1 install) is unaffected, and a re-admitted device
(active seat) is verified again.

Two halves of the same coupling, both keyed on `business_id` being set (Decision 2):

- **The business is the licence source.** For a linked device, `check_device` and
  `create_device` report the **business's** `expires_at`/`plan`/`is_verified`, not
  the device's own row (`DeviceSubscriptionService::effectiveStatus`). An unlinked
  device (every 1.0.1 install) reads its own row exactly as before.
- **Renewal reaches every seat.** Activating the owner device carries the new
  expiry/plan onto its business (`activate()` → the business), and the allowance
  only ever **grows** there (a tier upgrade adds phones; a downgrade never strands
  an enrolled one mid-shift). Without this, an owner could pay and still watch every
  phone lapse on the expiry seeded at onboarding.

## Push and pull

**Push** (`SyncChangeService::push`) records a batch under a **per-business row
lock**, so two devices pushing at once get disjoint, monotonic seqs and never
collide. It is **idempotent per row** (§F2): the key is `row_uuid + '|' + authored_hlc`,
unique per business, so re-pushing the exact same edit no-ops and returns the seq
already assigned — a client retry after a dropped ACK is safe. `origin_device` is
taken from the **authenticated seat's** node id, never the body — that is what makes
the no-echo filter and attribution trustworthy.

**Pull** (`SyncChangeService::pull`) returns changes above the cursor, with the
watermark set to the **highest seq examined, not the highest returned**. It scans a
contiguous window by seq, advances `next_cursor` to the top of that window, then
drops the caller's own echoed rows (**no-echo**). A page made entirely of the
device's own changes therefore still advances the cursor instead of being re-served
forever.

**Last-writer-wins** is resolved by `authored_hlc`, never by seq or arrival order.
The server stores an append-only oplog and never rewrites; devices resolve LWW
locally, and `latestForRow` exposes the winning version (highest HLC) — so a stale
offline edit that arrives *later* (and gets a higher seq) still loses to the newer
edit.

## The doorbell

After a device pushes, its **siblings** get a **data-only** FCM wake (`SyncDoorbell`)
— a silent message with no `notification` block, `data.type = "sync"`,
`android.priority = high` — so they pull within seconds instead of waiting for the
next poll. It is best-effort: a device that is offline, has no push token, or misses
the wake still converges on its regular poll, so a lost doorbell only adds latency.
The pusher is never rung back.

This added a `sendData()` method to `DevicePushNotifier` alongside the existing
tray-notification `send()`; the `push_token` is stored per seat and refreshed on
enroll and push.

## Schema

| Table | Purpose |
|---|---|
| `device_businesses` | The owner aggregate: `uuid`, `app_name`, `is_verified`, `expires_at`/`trial_expires_at`, `plan_id`, `device_allowance`, `last_seq`. Non-tenant; the scoping key. |
| `device_subscriptions.business_id` | Nullable FK linking a legacy device row to its business. |
| `device_seats` | Per-device seat + sync credential: `node_id`, `role` (`owner`/`member`), `name` (nullable, owner-editable display label), `prefix`+`token_hash`, `push_token`, `revoked_at`. `unique(device_business_id, device_id)`. |
| `device_changes` | Append-only oplog: `seq`, `row_uuid`, `table_name`, `op`, `origin_device`, `authored_hlc`, `idempotency_key`, `payload`. `unique(device_business_id, seq)` and `unique(device_business_id, idempotency_key)`. `created_at` only — no `updated_at`. |
| `device_join_tokens` | Single-use enrollment + bootstrap handoff: `token_hash`, `bootstrap_cursor`, `snapshot_path`, `snapshot_sha256`, `expires_at`, `consumed_at`. |

## Configuration

`config('device-subscriptions.sync.*')`:

| Key | Default | Purpose |
|---|---|---|
| `join_token_ttl_minutes` | 15 | How long an enrollment QR stays redeemable. |
| `snapshot_url_ttl_minutes` | 15 | Lifetime of the signed bootstrap-download URL. |
| `snapshot_disk` | `device-sync` | The **private** disk snapshots are staged on. |
| `snapshot_max_kb` | 51200 | Upload cap for a bootstrap snapshot. |
| `pull_limit` / `pull_max_limit` | 200 / 500 | Default and hard-capped pull page size. |
| `default_allowance` | 1 | Seat allowance when no plan row resolves (last fallback). |
| `plan_allowance` | `[]` | Legacy `plan_id` → allowance override; consulted only when a plan row is unresolvable. The tier now lives on the plan (`device_plans.device_allowance`). |

The `device-sync` disk (`config/filesystems.php`) is private/local by default; point
it at S3 for production, at which point the download would become a streamed
response instead of delete-after-send.

## Domain & extension points

| Class | Notes |
|---|---|
| `Domain\Models\DeviceBusiness` | `isActive()`, `activeSeatCount()`, `hasSeatAvailable()`, `seats()`/`changes()`/`subscriptions()`. |
| `Domain\Models\DeviceSeat` | `AuthenticatableContract` (backs the guard), `isActive()`/`isOwner()`, static `nodeIdFor()`. |
| `Domain\Models\DeviceChange` | append-only (`UPDATED_AT = null`); static `idempotencyKey()`. |
| `Domain\Models\DeviceJoinToken` | `isUsable()` (not consumed, not expired). |
| `Domain\Contracts\SyncContext` / `Infrastructure\Auth\RequestSyncContext` | the request-scoped seat identity. |
| `Application\Services\SyncEnrollmentService` | authenticate (guard resolver), establish business, mint/attach/enroll/revoke/list. |
| `Application\Services\SyncChangeService` | push/pull/`latestForRow`. |
| `Application\Services\SyncDoorbell` | the sibling data-push. |
| `Application\Support\SyncTokenGenerator` | seat + join token minting (`evosync_`/`evojoin_`, SHA-256). |

## Tests

- `DeviceSyncGuardTest` — missing/invalid/revoked token → 401; cross-business seat access → 404; a business scopes only its own seats.
- `DeviceSyncOwnerOnboardingTest` — a verified device establishes its business + owner seat and links the licensing row; idempotent token rotation; `SUBSCRIPTION_REQUIRED` for unverified/unknown devices; fallback-id rejection; plan-derived allowance admitting a member.
- `DeviceSyncEnrollmentTest` — owner-only mint; enroll returns seat + bootstrap cursor; allowance/fallback/single-use/owner-non-revocable rules; revoked seat → `check_device` NOT verified; **a joined member is covered by the owner's subscription (link/create, no own trial), a lapsed own-trial no longer locks it out, and an owner renewal reaches the business** (Decision 2); the 3-device distinguishing bootstrap case.
- `DeviceSyncPushPullTest` — monotonic gap-free seqs; per-row idempotency; no-echo; the examined-not-returned watermark; per-business isolation; LWW by HLC under out-of-order arrival; the data-only doorbell to siblings only.

## Retention (Decision 14)

The oplog is append-only and would grow unbounded, so it is pruned to a window.
Each business carries a `pruned_through_seq` watermark; a daily command
`device-subscriptions:prune-sync-changes` (config `sync.retention_days`, default
60; `--days=` to override; 0 disables) deletes that business's changes **at or
below the highest too-old `seq`** — pruning by seq, not timestamp, so the retained
set stays a **contiguous** range above the watermark. `pull` throws
`cursor_too_old` (409) when `cursor < pruned_through_seq`: a device offline longer
than the window has missed changes the log no longer holds, so it re-bootstraps via
a snapshot (§13) rather than concluding "nothing changed". The watermark only ever
advances, so a device once told to re-bootstrap is never told it is fine again.

## Follow-ups

- **S3 snapshot delivery** — the bootstrap download assumes a local disk for delete-after-send; the single-VPS prod uses local storage, so this is only needed if delivery moves to S3.
