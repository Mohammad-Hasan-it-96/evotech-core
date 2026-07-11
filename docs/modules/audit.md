# Module: Audit

The platform's **immutable audit trail** (constitution §5/§6.14, [ADR 0007](../adr/0007-native-audit-log.md)) — an append-only record of security-relevant actions with actor, subject, context, and IP. Native, in the same immutable-ledger style as `license_events` / `payment_events`.

## The port lives in Core

Auditing is a cross-cutting capability, so the **`AuditLogger` contract lives in `Core`** (`Modules\Core\Domain\Contracts\AuditLogger`) — the one module everyone may depend on. Any module records an action through the port; it never depends on the Audit module.

- **Core** binds a safe default `NullAuditLogger` (no-op).
- **Audit** overrides it with `EloquentAuditLogger`, which persists to `audit_logs` and resolves the current actor (authenticated guard) + IP (request), unless an actor is passed explicitly.

```php
// any module, via the Core port:
$audit->log('license.revoked', 'license', $license->uuid, ['reason' => $reason]);
```

## What it captures today

| Action | How | Source |
|---|---|---|
| `auth.login` / `auth.login_failed` / `auth.logout` / `auth.registered` | explicit port call | `Auth\AuthService` |
| `invoice.paid` | event listener | `Payments\InvoicePaid` |
| `subscription.activated` | event listener | `Subscriptions\SubscriptionActivated` |

Listeners run **synchronously** so the request's actor/IP are captured. More actions (license issue/revoke, API-key mint/revoke, role changes) are **additive** — a one-line port call or a listener each.

## Endpoint (staff, `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/audit-logs` | `audit-logs.index` | Paginated, newest first. Filters: `action`, `actor` (actor uuid), `subject_type`. |

The log is **append-only** — there is no create/update/delete endpoint. Each entry: `{ id, action, actor_type, actor_id, subject: {type,id}|null, context, ip_address, created_at }`.

## Domain

| Class | Notes |
|---|---|
| `Domain\Models\AuditLog` | `HasUuid`. Immutable — `const UPDATED_AT = null`; never updated/deleted. Subjects referenced by label + public **uuid** (never the bigint PK). |
| `Infrastructure\Logging\EloquentAuditLogger` | Persisting adapter for the Core port; resolves actor + IP. |
| `Application\Listeners\RecordInvoicePaidAudit` / `RecordSubscriptionActivatedAudit` | Capture existing domain events without touching the producers. |

## Tests

- `AuditLogTest` — login / failed-login (system) / logout / register audited via the port; `invoice.paid` and `subscription.activated` captured via listeners with the acting staff as actor; read API auth guard, listing, and filtering by action.
