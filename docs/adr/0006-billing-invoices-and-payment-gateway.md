# ADR 0006 — Billing: invoices, immutable payment ledger, and a gateway adapter (manual-first, Stripe-ready)

- **Status:** Accepted
- **Date:** 2026-07-08
- **Deciders:** Founder/CTO, Chief Software Architect
- **Related:** `docs/ARCHITECTURE.md` §3 (Payments/Stripe-ready), §5 (`payment_events` ledger, money integrity), §6.14 (audit), §7 (API), Commandment #2 (data integrity — money must never be silently wrong); `docs/ROADMAP.md` Phase 5.

## Context

Phase 5 opens the **Billing** capability. Subscriptions already snapshot a `price`
and `currency` per period but nothing invoices or collects them. We need a
`Payments` module that turns an active subscription period into a **bill** and
records how it was **paid**, without silently losing or double-counting money
(Commandment #2).

Two forces shape the first increment:
- The constitution (§3) mandates the module be **"Stripe-ready"** with **gateway
  adapters** — but this environment has no live payment credentials, and wiring a
  live gateway is not needed to prove the billing model.
- Money records must be **immutable and auditable** (§5 explicitly names a
  domain-specific `payment_events` ledger alongside the general activity log).

## Decision

Build the `Payments` module around **Invoices** and **Payments**, an immutable
`payment_events` ledger, and a **`PaymentGateway` contract** whose first
implementation collects payments **manually/offline** (bank transfer, cash) —
keeping a live Stripe adapter a later, drop-in swap behind the same contract.

- **Invoice** — a bill for one subscription billing period. Carries the
  subscriber (`company_id`), an optional `subscription_id` (nullable so an invoice
  outlives a deleted subscription — financial records are never destroyed), a
  unique human `number` (`INV-000001`), a **snapshot** `amount` + `currency`, the
  billed `period_start`/`period_end`, and a lifecycle status.
  - **Status:** `open` → `paid` | `void`. Invoices are **never soft-deleted or
    edited** after issue; corrections are new invoices / voids. `(subscription_id,
    period_start)` is unique so a period is billed **at most once** (idempotent
    issuance under retried activation events).
- **Payment** — a recorded receipt against an invoice (`amount`, `currency`,
  `method`, `gateway`, external `reference`, `paid_at`). This increment records a
  **full** payment that settles the invoice; partial payments and refunds are
  deferred (out of scope, additive later).
- **`payment_events`** — append-only ledger (`const UPDATED_AT = null`, never
  updated/deleted), one row per lifecycle event (`issued`, `paid`, `voided`) with
  actor + context (§5, §6.14).
- **`PaymentGateway` contract** (`Domain/Contracts`) with a **`ManualPaymentGateway`**
  implementation (`Infrastructure/Gateways`) bound as the default. A future
  `StripePaymentGateway` implements the same contract; the `PaymentService` is the
  transaction boundary and is gateway-agnostic. Stripe live integration (SDK,
  webhooks, PaymentIntents) is **explicitly deferred** — it needs credentials and
  its own webhook-security review.
- **Auto-issue on activation.** A listener on the existing
  `Subscriptions\...\SubscriptionActivated` event issues an invoice for the just
  activated/renewed period when `price > 0` (mirrors how `Licenses` auto-issues) —
  cross-module via events only (§2.1). Zero-price periods (e.g. free/lifetime)
  raise no invoice. Staff can also issue manually.
- **Money handling.** Amounts are `decimal(10,2)` stored/compared as strings
  (never floats); this increment performs **no arithmetic** on money (a full
  payment settles the invoice by status), so no rounding risk. Partial-payment
  summation, when added, will use integer-minor-unit or string-decimal math, never
  floats.
- **API.** Admin/staff endpoints (`auth:sanctum`) to list/show/issue invoices,
  record a payment, and void; standard envelope + resources (§7).

## Consequences

**Positive**
- Real, demoable billing now (a subscription auto-produces an invoice; staff mark
  it paid) with **no new dependency and no external credentials**.
- Money is immutable and audited: invoices are never edited/deleted, every
  transition is on the `payment_events` ledger, and per-period issuance is
  idempotent — no double-billing.
- Stripe (and any future gateway) is a contract-conformant adapter swap, not a
  rewrite (§2.4 extraction-readiness).

**Negative / Risks**
- **No live payment capture yet** — collection is manual/offline until the Stripe
  adapter lands. Accepted: it proves the model and matches current operations.
- **Full-payment-only** in this increment — partial payments/refunds deferred;
  documented as out of scope, additive.
- We must keep money math off floats as features grow; enforced by the
  decimal-string/minor-unit rule above and reviewed per §16.

## Compliance note

This implements §3's Payments/"Stripe-ready" and §5's `payment_events` ledger; it
does not amend a constitutional decision. The invoice `number` and the
`payment_events` shape become stable contracts (Commandment #3) — breaking either
requires a superseding ADR.
