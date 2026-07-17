# Dashboard Roadmap — running the products from the console

> Companion to [`ROADMAP.md`](./ROADMAP.md) and [`ROADMAP-APP-APIS.md`](./ROADMAP-APP-APIS.md).
> **Created:** 2026-07-17 · **Scope:** what the `evotech-web` dashboard needs before it
> can actually *run* the business — notifications, subscription requests, app releases,
> and release notes.

---

## The good news: this is mostly a frontend project

The backend for almost all of it **already exists and is tested**. Phases 2–6 built the
APIs; the dashboard just never grew screens for them.

| Feature you asked for | Backend | Dashboard | Real work |
|---|---|---|---|
| **Notifications** | ✅ API exists (list / unread-count / mark-read / mark-all) | ❌ nothing | UI + **one new trigger** |
| **Subscription requests** | 🟡 capture + activate exist; **decline does not** | 🟡 queue + activate shipped (Phase C) | Small API + UI |
| **Application updates** | ✅ full Download Center (releases, artifacts, publish, signed URLs) | ❌ nothing | **UI only** |
| **Release notes** | ✅ `releases.notes` column **already there** | ❌ nothing | UI + wire `app-download` |

Nothing here needs a new module. Sizings below are relative, not promises.

---

## Phase 1 — Notifications 🔔

**Why first:** everything else assumes you *find out* something happened. Today a plan
request lands in the database and nothing tells you. You learn about a sale from
WhatsApp, then go hunting in the console.

### Backend (small)
`Modules\Notifications` already dispatches queued, multi-channel (`database` + `mail`)
notifications off domain events, and exposes the per-user API. Only one flow exists:
`InvoicePaid` → the billed company's users.

**Add: a `DevicePlanRequested` event** → notify staff (database + mail).
`DeviceActivated` already exists and is unused by Notifications; the request side has no
event yet. Per §2.4 the listener lives in Notifications and DeviceSubscriptions stays
unaware of it.

### Dashboard
- **Bell in the topbar** with unread count (poll `unread-count`; it is cheap).
- Dropdown list, click-through to the relevant screen, mark-read / mark-all.
- Toast on arrival while the tab is open.

**Exit:** a plan request reaches you without you looking for it.

---

## Phase 2 — Subscription requests: approve **or decline** ✅/❌

Phase C shipped the queue (`status=pending`) and activation. The gap is the other half:
**you can say yes, but not no.**

Today a request you decline sits in "Pending requests" forever, and the queue stops
being a queue — the failure mode of every unfinished inbox.

### Backend (small)
- `POST /api/v1/device-subscriptions/{device}/decline` — clears `status`, keeps
  `requested_plan` as history, records who declined and why.
- Consider a `declined_at`/`declined_reason` pair, or an audit-log entry via the
  existing `AuditLogger` port (no new dependency).

### Dashboard
- **Decline** next to Activate, with an optional reason.
- Filters: pending / active / trial / expired / declined.
- Show **how long a request has waited** — the number that tells you the queue is healthy.

**Exit:** every request reaches a terminal state; the queue drains truthfully.

---

## Phase 3 — Application updates 📦 (Download Center UI)

The whole thing is **built and tested** — versioned releases per product and channel
(`stable`/`beta`/`alpha`), one artifact per platform, private storage, SHA-256
checksums, short-lived signed URLs, an immutable `download_events` ledger, and
`GET /api/v1/product/releases/latest` for self-update ([ADR 0008](adr/0008-download-center-delivery.md)).
There is simply **no screen**, so today it is unusable in practice.

### Dashboard (the bulk of the work)
- Releases list per product × channel; create/edit; **publish** / archive.
- Artifact upload per platform with progress; show size + checksum.
- Publish guard is already server-side (≥1 artifact) — mirror it in the UI so the button
  explains itself rather than failing.
- Download stats from the events ledger.

### Also fix: `app-download` is a placeholder
`GET /api/app-download` still reads `latest_version`/`links` from **config** and
currently returns `{"latest_version": null, "downloads": []}`. It should come from the
Download Center, so publishing a release is what updates the app — not a config edit and
a deploy. *(Flagged as a follow-up since ADR 0010; this is where it gets paid off.)*

**Exit:** you ship an APK from the dashboard and devices see it.

---

## Phase 4 — Release notes 📝

**`releases.notes` already exists** (`text`, nullable) — the schema anticipated this. It
is neither editable nor surfaced anywhere.

- Editor on the release form (Markdown; bilingual ar/en is worth deciding early — the
  column is a single `text`, so per-locale notes need either a JSON column or a
  convention).
- Return notes from `releases/latest` so the app can show "what's new" on update.
- Surface them on the public download page in `evotech-web`.

**Decision needed:** bilingual notes or Arabic-only? Everything else in the catalog is
`{ar, en}`; `notes` is not. Cheap to change now, migration-shaped later.

**Exit:** every release explains itself, in-app and on the site.

---

## Phase 5 — Odds and ends worth doing

- **`daftar_hesabat` (Ledger) has no config entry.** It is quietly live on the legacy
  backend with a paying device. Add `apps.daftar_hesabat` (label + trial policy) before
  its cutover, or it gets defaults and its users see a raw slug in push copy.
- **Per-app plan editing from the UI.** Plans are config today (Phase D). A screen means
  no deploy to change a price — but it means moving the catalog to the database first.
  Not free; worth it only if pricing changes often.
- **Reports screen.** `GET /api/v1/reports/overview` exists and is unused: companies,
  subscriptions, licenses, activations, per-currency collected/outstanding. Cheap win.
- **Audit log viewer.** `GET /api/v1/audit-logs` exists and is unused.

---

## Suggested order

1. **Notifications** — you stop missing sales.
2. **Decline** — the queue becomes trustworthy.
3. **Download Center UI + `app-download`** — you can ship updates.
4. **Release notes** — updates explain themselves.
5. The rest, as they start to hurt.

1 and 2 are small and immediately felt. 3 is the big one, and it is pure frontend.

---

## What this is *not*

Server-stored business data for Fawateer (cloud sync, the Web build) is **Phase E of
[`ROADMAP-APP-APIS.md`](./ROADMAP-APP-APIS.md)** — a different tenancy model needing real
device auth and an ADR. It is not needed for any of the above, and it should not creep
in here.
