# Module: Notifications

Cross-channel notification dispatch and the in-app store (constitution §3). It
**reacts to other modules' domain events** and notifies the right recipients over
`database` (the dashboard "bell") and `mail` channels, **queued**. Producers stay
decoupled — they emit events, they never call Notifications directly (§2.1). This
is a **composition consumer** — it references the [Payments](payments.md) events
and [Users](users.md) (to resolve recipients).

## What it sends today

| Trigger (event) | Notification | Recipients |
|---|---|---|
| `Payments\...\InvoicePaid` | `InvoicePaidNotification` (`invoice.paid`) | the billed company's users |

The notification carries only scalars (invoice uuid/number/amount/currency) — not
the Invoice model — so it serializes cleanly on the queue and stays decoupled from
Payments' models. More triggers (license expiring, subscription renewed, payment
failed) are additive: add a listener + a `Notification` class.

## Endpoints (all `auth:sanctum`, under `/api/v1`)

Each user sees and mutates **only their own** notifications.

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/notifications` | `notifications.index` | Paginated list of the user's notifications (newest first). |
| GET | `/notifications/unread-count` | `notifications.unread-count` | `{ data: { unread: N } }`. |
| POST | `/notifications/{notification}/read` | `notifications.read` | Mark one read (by id). `204`. |
| POST | `/notifications/read-all` | `notifications.read-all` | Mark all read. `204`. |

Each item: `{ id, type, data, read, read_at, created_at }` — `type` is the stable
slug from the payload (e.g. `invoice.paid`).

## Domain & application

| Class | Notes |
|---|---|
| `Application\Notifications\InvoicePaidNotification` | `ShouldQueue`; channels `database` + `mail`; scalar payload. |
| `Application\Listeners\SendInvoicePaidNotification` | Listens for `InvoicePaid` → resolves the company's users → sends. No users = no-op. |
| `Http\Controllers\NotificationController` | The authenticated user's bell — list / unread-count / mark-read / mark-all-read, scoped to the user. |
| storage | Laravel's `notifications` table (database channel). The notification `id` is a uuid; the internal `notifiable_id` is never exposed. |

## Infrastructure

Notifications are **queued** (§3) — inline on the `sync` queue in tests/local,
Redis/Horizon in production. Mail uses the configured mailer (`array` in tests).
`broadcast` (real-time) is a later additive channel.

## Tests

- `NotificationTest` — `InvoicePaid` notifies the company's users, no recipients is
  a no-op, and the bell API: list / unread-count / mark-one-read / mark-all-read /
  auth guard / per-user isolation.
