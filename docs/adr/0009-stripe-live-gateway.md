# ADR 0009 — Live Stripe gateway: async PaymentIntent flow, SDK-less REST, and webhook security

- **Status:** Accepted (scaffold landed; live enablement pending credentials + security sign-off)
- **Date:** 2026-07-11
- **Deciders:** Founder/CTO, Chief Software Architect
- **Related:** [ADR 0006](0006-billing-invoices-and-payment-gateway.md) (invoices + `PaymentGateway` contract, which deferred this), `docs/ARCHITECTURE.md` §3 (Payments/Stripe-ready), §5 (money integrity, `payment_events`), §7 (API), Commandment #2 (money must never be silently wrong); `docs/ROADMAP.md` Phase 5 deferred item.

## Context

ADR 0006 built billing around a `PaymentGateway` contract with a manual/offline
implementation and **explicitly deferred** the live Stripe adapter (SDK,
PaymentIntents, webhooks) pending credentials and a webhook-security review. This
ADR records how that adapter is built so it stays a drop-in behind the same
contract without weakening money integrity.

Three forces shape it:
- **The contract is synchronous.** `PaymentGateway::collect()` records an
  already-received receipt and returns success immediately — which fits manual
  reconciliation but **not** card payments, which Stripe settles asynchronously
  (customer confirms client-side; a webhook confirms capture).
- **Money integrity (Commandment #2).** An invoice must never be marked paid
  without a confirmed charge, and amounts must never be silently wrong.
- **Dependency weight.** The constitution wants "Stripe-ready", not necessarily
  the full `stripe-php` SDK, which is a large transitive dependency.

## Decision

Ship a **live Stripe adapter driven by config**, selected by
`config('payments.gateway')` (`manual` default, `stripe` opt-in) — both behind the
existing `PaymentGateway` contract.

- **SDK-less, over the REST API.** The adapter talks to Stripe's REST API through
  Laravel's HTTP client (`StripeClient`) and verifies webhooks with native
  HMAC-SHA256 (`StripeWebhookVerifier`). **No new Composer dependency** — keeps the
  install lean and Larastan/analysis unaffected. Trade-off: we own the thin surface
  we use (PaymentIntent create + webhook verify) instead of the SDK owning it;
  accepted because that surface is tiny and stable.
- **Async settlement, not synchronous `collect()`.** For the Stripe gateway,
  `collect()` is intentionally **unsupported** (throws) — an invoice is never
  settled by the manual endpoint while Stripe is active. Instead:
  1. `POST /invoices/{invoice}/payment-intent` creates a Stripe PaymentIntent for
     the invoice amount and returns its `client_secret` for the dashboard to
     confirm the card. The intent id is stored on `invoice.meta`.
  2. Stripe calls `POST /api/v1/stripe/webhook`; on `payment_intent.succeeded` the
     matching invoice is settled through `PaymentService::settleFromWebhook()`,
     reusing the existing ledger + `InvoicePaid` event (system actor).
- **Webhook security.** The webhook route is **unauthenticated by design** — trust
  comes from the signature, not a session. Verification enforces: signing-secret
  present, HMAC-SHA256 over `"{timestamp}.{body}"`, **constant-time** compare
  (`hash_equals`), and a **timestamp tolerance** (default 300s) for replay
  protection. A bad/absent signature is a `400` and settles nothing.
- **Money integrity guards.**
  - **Amount check:** the webhook rejects (`422`) unless the charged minor-unit
    amount equals the invoice amount.
  - **Idempotency:** re-delivered events settle at most once — settlement is a
    no-op on an already-paid invoice (matched by gateway reference).
  - **No float math:** decimal-string amounts convert to Stripe's integer minor
    units via string manipulation (`MinorUnits`), never floats.
- **Card method.** `PaymentMethod::Card` is added for gateway-collected payments and
  is **not accepted** on the manual settlement endpoint (`RecordPaymentRequest`
  excludes it).

## Consequences

**Positive**
- A real, contract-conformant Stripe adapter with **no new dependency**; enabling
  it is a config/env change, not a code change.
- Money stays immutable and audited: same `PaymentService` ledger path, per-event
  idempotency, and an amount-equality guard — no double-settlement, no silent
  mismatch.
- The synchronous `collect()` contract is preserved for the manual gateway; Stripe's
  async reality is modelled explicitly rather than faked.

**Negative / Risks**
- **Not live yet.** Needs real keys (`STRIPE_SECRET`, `STRIPE_KEY`,
  `STRIPE_WEBHOOK_SECRET`) and a production webhook-security review before
  `PAYMENTS_GATEWAY=stripe` in prod. Until then it is exercised only via faked HTTP
  + signed test payloads.
- **Happy-path event only.** Handles `payment_intent.succeeded`; failures, disputes,
  refunds, and `payment_intent.payment_failed` are additive follow-ups.
- **Two-decimal currencies assumed.** `MinorUnits` assumes an exponent of 2;
  zero-decimal currencies (JPY, KRW) need a per-currency exponent table before use.
- **No stored-customer / off-session** charging yet (one-off PaymentIntents only).

## Compliance note

This implements ADR 0006's deferred Stripe adapter and §3's "Stripe-ready"; it does
not amend a constitutional decision. The `stripe/webhook` route contract, the
`payment_intent.succeeded` handling, and the `payment_events` shape become stable
contracts (Commandment #3) — breaking them requires a superseding ADR. Going live
additionally requires the webhook-security review named above.
