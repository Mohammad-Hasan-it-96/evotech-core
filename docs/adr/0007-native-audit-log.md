# ADR 0007 — Audit log: a native immutable ledger and an `AuditLogger` port in Core

- **Status:** Accepted
- **Date:** 2026-07-08
- **Deciders:** Founder/CTO, Chief Software Architect
- **Related:** `docs/ARCHITECTURE.md` §5 (audit tables), §6.14 (audit every security-relevant action), §2.1/§2.3 (module boundaries, contracts), §18 (amendment); Commandment #1 (security) & #2 (integrity).

## Context

The platform must record **every security-relevant action** (login, license
issue/revoke, payment, key mint/revoke, role change) in an immutable audit trail
(§6.14). Today only domain-specific ledgers exist — `license_events` and
`payment_events` — each an append-only table with `const UPDATED_AT = null`,
actor, and context. There is no **general, cross-cutting** audit log.

Two questions:
1. **Storage mechanism.** §5 suggested `spatie/laravel-activitylog`. But the
   codebase has a consistent, proven native pattern already (the two immutable
   ledgers), the project has repeatedly chosen **native, dependency-light**
   solutions (native modules over `nwidart`, native API keys over Passport, plain
   DTOs over `spatie/laravel-data`), and this environment cannot reliably add a
   Composer dependency mid-flight.
2. **How modules record audits without coupling.** Auditing is cross-cutting:
   Auth, Licenses, Payments, Gateway all need to write to it. They must not each
   depend on an `Audit` module (that would make Audit a dependency of nearly
   everything, and couple producers to it).

## Decision

**Build a native immutable `audit_logs` ledger, and expose auditing as a port on
the shared kernel (`Core`).**

- **`AuditLogger` contract lives in `Core`** (`Modules\Core\Domain\Contracts`),
  the one module everyone may depend on. Any module records an audit entry through
  this port — never by depending on the `Audit` module (§2.1). `log()` returns
  `void` so the contract carries no `Audit` types.
- **`Core` binds a safe default** `NullAuditLogger` (no-op). The **`Audit` module
  binds the real** `EloquentAuditLogger`, overriding the default — so the platform
  degrades safely if Audit is ever absent, and producers never hard-fail on a
  missing binding.
- **Storage: native `audit_logs`** — `uuid`, `action` (dotted slug, e.g.
  `auth.login`), `actor_type`/`actor_id`, polymorphic `subject_type`/`subject_id`
  (stored as a label + the subject's **uuid**, never the bigint PK), `context`
  (json), `ip_address`, and `created_at`. Append-only: `const UPDATED_AT = null`,
  never updated or deleted — identical discipline to `license_events` /
  `payment_events`.
- **Two capture paths, both used:**
  - **Explicit** via the port — e.g. `AuthService` logs `auth.login` /
    `auth.logout` (the actor at login is the authenticating user, passed
    explicitly since it is not yet the "current" user).
  - **Event listeners** in the Audit module for domain events already emitted —
    `Payments\InvoicePaid` → `invoice.paid`, `Subscriptions\SubscriptionActivated`
    → `subscription.activated` — capturing them without touching those modules.
- **Actor/IP resolution:** the Eloquent adapter resolves the current actor from
  the authenticated guard (after `auth:sanctum` it is the staff user) and the IP
  from the request, unless an explicit actor is supplied. Audit listeners run
  **synchronously** so this request context is present.
- **Read API:** staff-only (`auth:sanctum`), paginated and filterable by `action`,
  `actor`, and `subject_type`. Write-only from the app's side — there is no
  create/update/delete endpoint.

## Consequences

**Positive**
- One consistent immutable-ledger pattern across the codebase; no new dependency,
  no network install.
- Producers depend only on a **Core port**, not the Audit module — clean
  boundaries, and Audit could later be extracted or its storage swapped (even to
  `spatie/laravel-activitylog` behind the same port) without touching callers.
- Safe-by-default: a missing Audit binding degrades to a no-op, never a 500 on
  login.

**Negative / Risks**
- We forgo `spatie/laravel-activitylog`'s built-in niceties (model event
  auto-logging, batches, tags). Mitigation: the port + explicit/event capture
  covers our needs; the library can be adopted later behind the same port if those
  features are wanted (a superseding ADR).
- **Coverage is incremental.** This increment audits login/logout, invoice
  settlement, and subscription activation. Other §6.14 actions (license
  issue/revoke, key mint/revoke, role changes) are **additive** — a listener or a
  one-line port call each — and are called out as follow-ups, not silently
  assumed covered.

## Amendment to the constitution

`ARCHITECTURE.md` §5's "audit via `spatie/laravel-activitylog` into an
`activity_log`" is amended to reference this ADR: the general audit trail is a
**native `audit_logs` ledger** behind the Core `AuditLogger` port. The
domain-specific ledgers (`license_events`, `payment_events`) and all other §5
rules stand. `spatie/laravel-activitylog` remains a pre-approved option should its
features later be required.
